<?php
/**
 * Smoke — Cross-tenant intercompany approval workflow (Batch 3, 2026-02).
 *
 * Locks the propose → counterparty-approve → post-to-leg workflow added
 * to:
 *   - core/migrations/104_intercompany_xtenant_queue.sql (schema)
 *   - modules/accounting/lib/cross_tenant_intercompany.php (helpers)
 *   - modules/accounting/api/intercompany.php (xtenant_* API actions)
 *   - modules/accounting/ui/XTenantIntercompany.jsx (counterparty UI)
 *   - modules/accounting/ui/AccountingModule.jsx (route wiring)
 *   - cron/intercompany_xtenant_expire_worker.php (TTL sweep)
 *
 * Source-level assertions (function shape + literal-string checks) per
 * the project convention; functional posting flow is covered by the
 * live MySQL integration suite.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    return is_file($full) ? (string) file_get_contents($full) : '';
};

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 104 schema
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 104 schema ──\n";
$mig = $read('core/migrations/104_intercompany_xtenant_queue.sql');
$a('migration file readable',                        is_string($mig) && strlen($mig) > 200);
$a('creates table intercompany_xtenant_queue',       str_contains($mig, 'CREATE TABLE IF NOT EXISTS intercompany_xtenant_queue'));
$a('has intercompany_ref column',                    str_contains($mig, 'intercompany_ref'));
$a('has source_tenant_id + source_je_id',            str_contains($mig, 'source_tenant_id') && str_contains($mig, 'source_je_id'));
$a('has target_tenant_id + target_je_id',            str_contains($mig, 'target_tenant_id') && str_contains($mig, 'target_je_id'));
$a('has multi-currency columns (amount, currency, fx_rate, target_amount, target_currency)',
    str_contains($mig, 'amount') && str_contains($mig, 'fx_rate')
    && str_contains($mig, 'target_amount') && str_contains($mig, 'target_currency'));
$a('status enum includes pending/approved/declined/expired/reversed',
    preg_match("/ENUM\(\s*'pending','approved','declined','expired','reversed'\s*\)/i", $mig) === 1);
$a('expires_at column + status/expires index',
    str_contains($mig, 'expires_at') && str_contains($mig, 'ix_xtenant_expires'));
$a('uq_xtenant_ref unique on intercompany_ref',      str_contains($mig, 'uq_xtenant_ref'));
$a('ix_xtenant_target_status index defined',         str_contains($mig, 'ix_xtenant_target_status'));

// ──────────────────────────────────────────────────────────────────────
// 2) Lib helpers (cross_tenant_intercompany.php)
// ──────────────────────────────────────────────────────────────────────
echo "\n── Library helpers ──\n";
$lib = $read('modules/accounting/lib/cross_tenant_intercompany.php');
$a('accountingProposeCrossTenantIntercompany() defined',
    str_contains($lib, 'function accountingProposeCrossTenantIntercompany('));
$a('accountingApproveCrossTenantIntercompany() defined',
    str_contains($lib, 'function accountingApproveCrossTenantIntercompany('));
$a('accountingDeclineCrossTenantIntercompany() defined',
    str_contains($lib, 'function accountingDeclineCrossTenantIntercompany('));
$a('accountingListCrossTenantIntercompanyInbox() defined',
    str_contains($lib, 'function accountingListCrossTenantIntercompanyInbox('));
$a('accountingListCrossTenantIntercompanyOutbox() defined',
    str_contains($lib, 'function accountingListCrossTenantIntercompanyOutbox('));
$a('accountingExpireCrossTenantIntercompanyPending() defined',
    str_contains($lib, 'function accountingExpireCrossTenantIntercompanyPending('));

// Propose contract
$a('propose enforces same-master parent guard',
    str_contains($lib, 'cross-tenant intercompany requires the same master parent'));
$a('propose posts FROM leg immediately via accountingPostJe',
    str_contains($lib, "'idempotency_key' => \"cross_intercompany_propose:{\$ref}:from\""));
$a('propose inserts queue row with status pending',
    str_contains($lib, 'INSERT INTO intercompany_xtenant_queue')
    && str_contains($lib, '"pending"'));
$a('propose computes expires_at from ttl_days (default 14)',
    str_contains($lib, "ttl_days'] ?? 14")
    && str_contains($lib, '+{$ttlDays} days'));
$a('propose audits both sides (proposed + awaiting_approval)',
    str_contains($lib, "'cross_tenant.intercompany.proposed'")
    && str_contains($lib, "'cross_tenant.intercompany.awaiting_approval'"));
$a('propose rolls back FROM leg if queue insert fails',
    preg_match("/catch \(\\\\Throwable \\\$e\)[\s\S]+?accountingReverseJe\([\s\S]+?queue insert failed/", $lib) === 1);

// Approve contract
$a('approve is idempotent on already-approved rows',
    str_contains($lib, "'idempotent_replay' => true,")
    && str_contains($lib, "'approved'"));
$a('approve posts TO leg via accountingPostJe with cross_intercompany_approve key',
    str_contains($lib, "'idempotency_key' => \"cross_intercompany_approve:{\$ref}:to\""));
$a('approve updates queue row to status approved + stamps target_je_id',
    str_contains($lib, 'UPDATE intercompany_xtenant_queue')
    && str_contains($lib, 'status = "approved"')
    && str_contains($lib, 'target_je_id = :tje'));
$a('approve emits cross_tenant.intercompany.approved audit event',
    str_contains($lib, "'cross_tenant.intercompany.approved'"));
$a('approve refuses non-pending rows',
    str_contains($lib, "queue row in status '{\$row['status']}' — cannot approve"));

// Decline contract
$a('decline requires reason',
    str_contains($lib, "decline reason is required"));
$a('decline reverses source JE via accountingReverseJe',
    str_contains($lib, 'accountingReverseJe($sourceTenant, $sourceJeId,'));
$a('decline updates queue row to status declined + reason',
    str_contains($lib, 'status = "declined"')
    && str_contains($lib, 'decline_reason = :r'));
$a('decline emits cross_tenant.intercompany.declined audit event',
    str_contains($lib, "'cross_tenant.intercompany.declined'"));

// Inbox / outbox listers
$a('inbox filters by target_tenant_id',
    str_contains($lib, 'WHERE q.target_tenant_id = :t'));
$a('outbox filters by source_tenant_id',
    str_contains($lib, 'WHERE q.source_tenant_id = :t'));
$a('inbox/outbox both join tenants for human-readable names',
    substr_count($lib, 'LEFT JOIN tenants ts ON ts.id = q.source_tenant_id') >= 2);
$a('listers hydrate numeric columns + amount/fx',
    str_contains($lib, "'amount','target_amount','fx_rate'"));

// Expire sweep
$a('expire sweep filters status=pending + expires_at <= now',
    str_contains($lib, 'WHERE status = "pending" AND expires_at IS NOT NULL AND expires_at <= :now'));
$a('expire sweep reverses source JE per row',
    str_contains($lib, 'accountingReverseJe($stid, $sjeid,'));
$a('expire sweep stamps status=expired + auto-expire reason',
    str_contains($lib, 'status = "expired"')
    && str_contains($lib, 'auto-expired: counterparty did not respond before TTL'));
$a('expire sweep emits cross_tenant.intercompany.expired audit event',
    str_contains($lib, "'cross_tenant.intercompany.expired'"));
$a('expire sweep returns {scanned, expired, errors} summary',
    str_contains($lib, "'scanned'") && str_contains($lib, "'expired'") && str_contains($lib, "'errors'"));

// ──────────────────────────────────────────────────────────────────────
// 3) API surface
// ──────────────────────────────────────────────────────────────────────
echo "\n── API actions ──\n";
$api = $read('modules/accounting/api/intercompany.php');
$a('lib included',
    str_contains($api, "require_once __DIR__ . '/../lib/cross_tenant_intercompany.php'"));
$a('xtenant_inbox action wired',
    str_contains($api, "'GET' && \$action === 'xtenant_inbox'"));
$a('xtenant_outbox action wired',
    str_contains($api, "'GET' && \$action === 'xtenant_outbox'"));
$a('xtenant_propose action wired',
    str_contains($api, "'POST' && \$action === 'xtenant_propose'"));
$a('xtenant_approve action wired',
    str_contains($api, "'POST' && \$action === 'xtenant_approve'"));
$a('xtenant_decline action wired',
    str_contains($api, "'POST' && \$action === 'xtenant_decline'"));
$a('xtenant_expire_sweep action wired',
    str_contains($api, "'POST' && \$action === 'xtenant_expire_sweep'"));

// Authority gates
$a('propose requires accounting.je.post permission',
    preg_match("/xtenant_propose[\s\S]{0,400}rbac_legacy_require\(\\\$user, 'accounting.je.post'\)/", $api) === 1);
$a('approve verifies caller is the target_tenant',
    str_contains($api, 'Only the counterparty tenant can approve this entry'));
$a('decline verifies caller is the target_tenant',
    str_contains($api, 'Only the counterparty tenant can decline this entry'));
$a('propose rejects same-tenant proposals',
    str_contains($api, 'to_tenant_id must differ from the active tenant'));
$a('expire_sweep gated to admin roles',
    preg_match("/xtenant_expire_sweep[\s\S]{0,400}Forbidden — admin only/", $api) === 1);

// Input validation
$a('propose requires to_tenant_id, amount, memo',
    str_contains($api, "['to_tenant_id','amount','memo']"));
$a('decline requires reason',
    preg_match("/xtenant_decline[\s\S]{0,400}reason required/", $api) === 1);

// ──────────────────────────────────────────────────────────────────────
// 4) Cron worker
// ──────────────────────────────────────────────────────────────────────
echo "\n── Cron worker ──\n";
$cron = $read('cron/intercompany_xtenant_expire_worker.php');
$a('cron worker file exists',
    is_string($cron) && strlen($cron) > 200);
$a('cron worker invokes accountingExpireCrossTenantIntercompanyPending()',
    str_contains($cron, 'accountingExpireCrossTenantIntercompanyPending('));
$a('cron worker emits a complete log line',
    str_contains($cron, '[intercompany_xtenant_expire_worker] complete.'));

// ──────────────────────────────────────────────────────────────────────
// 5) React UI
// ──────────────────────────────────────────────────────────────────────
echo "\n── React UI ──\n";
$ui = $read('modules/accounting/ui/XTenantIntercompany.jsx');
$a('XTenantIntercompany.jsx readable',
    is_string($ui) && strlen($ui) > 1000);
$a('imports api + useApi from shared helper',
    str_contains($ui, "from '../../../dashboard/src/lib/api'"));
$a('hits xtenant_inbox endpoint',
    str_contains($ui, '?action=xtenant_inbox'));
$a('hits xtenant_outbox endpoint',
    str_contains($ui, '?action=xtenant_outbox'));
$a('hits xtenant_propose endpoint',
    str_contains($ui, '?action=xtenant_propose'));
$a('hits xtenant_approve endpoint',
    str_contains($ui, '?action=xtenant_approve'));
$a('hits xtenant_decline endpoint',
    str_contains($ui, '?action=xtenant_decline'));

// data-testid coverage
foreach ([
    'xtenant-ic-page',
    'xtenant-ic-status-filter',
    'xtenant-ic-propose-form',
    'xtenant-ic-propose-to-tenant',
    'xtenant-ic-propose-amount',
    'xtenant-ic-propose-memo',
    'xtenant-ic-propose-posting-date',
    'xtenant-ic-propose-from-acct',
    'xtenant-ic-propose-to-acct',
    'xtenant-ic-propose-from-offset',
    'xtenant-ic-propose-to-offset',
    'xtenant-ic-propose-from-currency',
    'xtenant-ic-propose-to-currency',
    'xtenant-ic-propose-fx-rate',
    'xtenant-ic-propose-ttl',
    'xtenant-ic-propose-submit',
] as $tid) {
    $a("testid '{$tid}' present", str_contains($ui, "data-testid=\"{$tid}\""));
}

// Dynamic per-row + per-tab testids — assert the templates render the right ids.
foreach ([
    'xtenant-ic-tab-${id}',
    'xtenant-ic-approve-${row.id}',
    'xtenant-ic-decline-open-${row.id}',
    'xtenant-ic-decline-reason-${row.id}',
    'xtenant-ic-decline-confirm-${row.id}',
    'xtenant-ic-row-${row.id}',
] as $template) {
    $a("testid template '{$template}' present",
        str_contains($ui, "data-testid={`{$template}`}"));
}

// ──────────────────────────────────────────────────────────────────────
// 6) AccountingModule route wiring
// ──────────────────────────────────────────────────────────────────────
echo "\n── AccountingModule wiring ──\n";
$mod = $read('modules/accounting/ui/AccountingModule.jsx');
$a('XTenantIntercompany import present',
    str_contains($mod, "import XTenantIntercompany from './XTenantIntercompany'"));
$a('Cross-tenant IC tab label present',
    str_contains($mod, 'label="Cross-tenant IC"'));
$a('Cross-tenant IC route mounted',
    str_contains($mod, 'path="xtenant-ic"')
    && str_contains($mod, '<XTenantIntercompany'));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Cross-tenant IC approval workflow smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
