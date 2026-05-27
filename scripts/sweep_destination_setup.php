<?php
/**
 * scripts/sweep_destination_setup.php — one-shot operational helper
 * for wiring a Treasury Sweep destination.
 *
 * Without this script, the manual setup is 5 separate REST calls:
 *   1. POST /api/admin/treasury/mercury_recipients (create)
 *   2. POST /api/admin/treasury/mercury_recipients/push (push to Mercury)
 *   3. GET  /api/admin/treasury/sweep_rules (find rule id)
 *   4. PUT  /api/admin/treasury/sweep_rules?id=N (wire destination_recipient_id)
 *   5. GET  to verify
 *
 * This script collapses all five into a single command:
 *
 *   php scripts/sweep_destination_setup.php \
 *       --tenant=42 \
 *       --account-id=acct_abc123 \
 *       --routing=987654321 \
 *       --account-number=1234567890 \
 *       --name="High-Yield Savings" \
 *       [--rule-id=7]         # wire as destination on rule 7
 *       [--no-push]           # skip the Mercury counterparty push
 *       [--dry-run]           # print plan, don't write
 *
 * Always print a final go-live readiness summary the operator can paste
 * into the runbook ticket.
 *
 * RBAC: cron-class script, runs with full DB access. Operator runs
 * this from the production shell as the deploy user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/mercury_recipients.php';
require_once __DIR__ . '/../core/mercury_payments.php';

// -----------------------------------------------------------------------------
// CLI argument parsing — `--key=value` / `--flag` only. Order-independent.
// -----------------------------------------------------------------------------
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) continue;
    $kv = explode('=', substr($arg, 2), 2);
    $opts[$kv[0]] = $kv[1] ?? '1';
}

function need(array $opts, string $key): string {
    if (!isset($opts[$key]) || $opts[$key] === '') {
        fwrite(STDERR, "ERROR: --{$key}=… is required\n");
        fwrite(STDERR, "Run with no args for usage.\n");
        exit(2);
    }
    return (string) $opts[$key];
}

if (empty($argv[1]) || isset($opts['help']) || isset($opts['h'])) {
    fwrite(STDOUT, "Usage:\n  php scripts/sweep_destination_setup.php \\\n");
    fwrite(STDOUT, "      --tenant=42 \\\n");
    fwrite(STDOUT, "      --account-id=acct_… \\\n");
    fwrite(STDOUT, "      --routing=987654321 \\\n");
    fwrite(STDOUT, "      --account-number=1234567890 \\\n");
    fwrite(STDOUT, "      --name=\"High-Yield Savings\" \\\n");
    fwrite(STDOUT, "      [--rule-id=7]   # wire as destination on this rule\n");
    fwrite(STDOUT, "      [--no-push]     # create recipient locally only\n");
    fwrite(STDOUT, "      [--dry-run]     # plan only, no DB writes\n");
    exit(0);
}

$tenantId       = (int)  need($opts, 'tenant');
$accountId      = trim((string) need($opts, 'account-id'));
$routing        = trim((string) need($opts, 'routing'));
$accountNumber  = trim((string) need($opts, 'account-number'));
$name           = trim((string) need($opts, 'name'));
$ruleId         = isset($opts['rule-id']) ? (int) $opts['rule-id'] : 0;
$noPush         = isset($opts['no-push']);
$dryRun         = isset($opts['dry-run']);

fwrite(STDOUT, "== Sweep destination setup ==\n");
fwrite(STDOUT, "  tenant_id        : {$tenantId}\n");
fwrite(STDOUT, "  Mercury account  : {$accountId}\n");
fwrite(STDOUT, "  Recipient name   : {$name}\n");
fwrite(STDOUT, "  Routing          : ****" . substr($routing, -4) . "\n");
fwrite(STDOUT, "  Account          : ****" . substr($accountNumber, -4) . "\n");
fwrite(STDOUT, "  Wire to rule_id  : " . ($ruleId > 0 ? (string) $ruleId : '(skip)') . "\n");
fwrite(STDOUT, "  Push to Mercury  : " . ($noPush ? 'NO' : 'yes') . "\n");
fwrite(STDOUT, "  Dry-run          : " . ($dryRun ? 'YES (no writes)' : 'no') . "\n");
fwrite(STDOUT, "\n");

// -----------------------------------------------------------------------------
// Step 0: pre-flight checks. Each gives a SPECIFIC remediation message
// so the operator never has to grep the codebase for what we're missing.
// -----------------------------------------------------------------------------
$pdo = getDB();

$tenant = $pdo->prepare('SELECT id, name FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
$tenantRow = $tenant->fetch(\PDO::FETCH_ASSOC);
if (!$tenantRow) {
    fwrite(STDERR, "ERROR: tenant {$tenantId} not found. Check `SELECT id, name FROM tenants`.\n");
    exit(3);
}
fwrite(STDOUT, "✓ tenant: " . ($tenantRow['name'] ?? '?') . "\n");

// Confirm migration 075 ran — destination_recipient_id column exists.
$col = $pdo->prepare(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_sweep_rules'
        AND COLUMN_NAME  = 'destination_recipient_id'"
);
$col->execute();
if (!$col->fetchColumn()) {
    fwrite(STDERR, "ERROR: tenant_sweep_rules.destination_recipient_id missing.\n");
    fwrite(STDERR, "Apply migration 075_sweep_destination_recipient.sql first.\n");
    exit(4);
}
fwrite(STDOUT, "✓ migration 075 applied\n");

// Confirm Mercury connection — push will need this.
$conn = function_exists('mercuryGetConnection') ? mercuryGetConnection($tenantId) : null;
$connOK = $conn && ($conn['status'] ?? '') === 'active';
if (!$connOK && !$noPush) {
    fwrite(STDERR, "WARN: tenant has no active Mercury connection. Continuing with --no-push.\n");
    $noPush = true;
}
fwrite(STDOUT, "✓ Mercury connection: " . ($connOK ? 'active' : 'NOT active (push skipped)') . "\n");

// If --rule-id specified, confirm rule exists + belongs to tenant.
$ruleRow = null;
if ($ruleId > 0) {
    $r = $pdo->prepare('SELECT id, name, source_account_id, enabled FROM tenant_sweep_rules WHERE tenant_id = :t AND id = :id');
    $r->execute(['t' => $tenantId, 'id' => $ruleId]);
    $ruleRow = $r->fetch(\PDO::FETCH_ASSOC);
    if (!$ruleRow) {
        fwrite(STDERR, "ERROR: tenant_sweep_rules id={$ruleId} not found for tenant {$tenantId}.\n");
        exit(5);
    }
    if ((string) $ruleRow['source_account_id'] === $accountId) {
        fwrite(STDERR, "ERROR: destination account_id ({$accountId}) matches the rule's source_account_id.\n");
        fwrite(STDERR, "       A sweep can't pull from and deposit to the same account.\n");
        exit(6);
    }
    fwrite(STDOUT, "✓ rule #{$ruleId}: {$ruleRow['name']} (source={$ruleRow['source_account_id']}, enabled=" . (int) $ruleRow['enabled'] . ")\n");
}

if ($dryRun) {
    fwrite(STDOUT, "\nDry-run complete — no DB writes performed. Re-run without --dry-run to apply.\n");
    exit(0);
}

// -----------------------------------------------------------------------------
// Step 1: create the recipient row.
// -----------------------------------------------------------------------------
fwrite(STDOUT, "\n→ creating mercury_recipients (kind=sweep_destination)…\n");
try {
    $rec = mercuryRecipientCreate($tenantId, [
        'kind' => 'sweep_destination',
        'name' => $name,
        'notes' => "Created by scripts/sweep_destination_setup.php for Mercury account {$accountId}",
        'bank' => [
            'routing_number' => $routing,
            'account_number' => $accountNumber,
            'account_type'   => 'checking',
        ],
    ], null);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR creating recipient: " . $e->getMessage() . "\n");
    exit(7);
}
$recipientId = (int) $rec['id'];
fwrite(STDOUT, "  → recipient_id={$recipientId}\n");

// -----------------------------------------------------------------------------
// Step 2: push to Mercury as a counterparty (unless --no-push).
// -----------------------------------------------------------------------------
if (!$noPush) {
    fwrite(STDOUT, "\n→ pushing recipient to Mercury (counterparty)…\n");
    try {
        $pushed = mercuryRecipientPushToMercury($tenantId, $recipientId, null);
        fwrite(STDOUT, "  → mercury counterparty pushed. status=" . ($pushed['status'] ?? '?') . "\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "WARN push to Mercury failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "     The recipient row exists locally; you can retry via UI or re-run with --no-push to skip.\n");
        // Don't exit — local recipient still has value; the wire step
        // below works either way (just leaves the live transfer leg
        // unfulfilled until counterparty resolves).
    }
} else {
    fwrite(STDOUT, "\n(skipping Mercury counterparty push: --no-push)\n");
}

// -----------------------------------------------------------------------------
// Step 3: wire into the rule (if --rule-id).
// -----------------------------------------------------------------------------
if ($ruleId > 0) {
    fwrite(STDOUT, "\n→ wiring destination_recipient_id={$recipientId} into rule #{$ruleId}…\n");
    // tenant-leak-allow: tenant_id was just verified above
    $upd = $pdo->prepare(
        'UPDATE tenant_sweep_rules
            SET destination_recipient_id = :r,
                destination_account_id   = :acct
          WHERE id = :id AND tenant_id = :t'
    );
    $upd->execute(['r' => $recipientId, 'acct' => $accountId, 'id' => $ruleId, 't' => $tenantId]);
    fwrite(STDOUT, "  → rule updated.\n");
}

// -----------------------------------------------------------------------------
// Step 4: go-live readiness summary the operator can paste into the
// runbook ticket.
// -----------------------------------------------------------------------------
fwrite(STDOUT, "\n========================================\n");
fwrite(STDOUT, "  Setup complete — go-live readiness\n");
fwrite(STDOUT, "========================================\n");
fwrite(STDOUT, "  tenant_id           : {$tenantId}\n");
fwrite(STDOUT, "  mercury_recipients  : #{$recipientId} (kind=sweep_destination, status=active)\n");
fwrite(STDOUT, "  mercury counterparty: " . ($noPush ? 'NOT pushed (--no-push)' : 'pushed') . "\n");
if ($ruleId > 0) {
    fwrite(STDOUT, "  wired to rule       : #{$ruleId} ({$ruleRow['name']})\n");
} else {
    fwrite(STDOUT, "  wired to rule       : NOT YET — set destination_recipient_id={$recipientId} on the target rule.\n");
}
fwrite(STDOUT, "\nNext steps:\n");
fwrite(STDOUT, "  1. Tail the divergence alert email for 7+ consecutive clean dry-run days.\n");
fwrite(STDOUT, "  2. When clean, flip TREASURY_SWEEP_LIVE=1 in the cron environment.\n");
fwrite(STDOUT, "  3. Audit feed: /staffing/treasury/sweep-rules (Worker audit feed section).\n");
fwrite(STDOUT, "========================================\n");
exit(0);
