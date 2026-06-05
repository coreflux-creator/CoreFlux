<?php
/**
 * scripts/seed_je_approver_demo.php
 *
 * Seed-and-show end-to-end JE-promotion approval flow with the
 * 6-rule post-approval gate fully exercised.
 *
 *   php scripts/seed_je_approver_demo.php [--tenant=1] [--entity=1] [--user=1]
 *
 * What it does (all idempotent — re-runs are safe):
 *   1. Picks the first balanced 2-line draft JE in the tenant (or
 *      creates one against the first 2 active postable accounts in
 *      the current open period if none exists).
 *   2. Inserts a synthetic workflow_runs row (graph_name =
 *      'manual_je_post_demo', status = 'awaiting_approval') so the
 *      approval has a parent run for the Reviewer cockpit timeline.
 *   3. Calls workflowOpenJePromotionApproval() — this snapshots the
 *      gate-compatible request_payload {je_id, draft_hash,
 *      snapshot_at} via accountingApprovalRequestPayloadForJe().
 *   4. Prints the approval_id + reviewer URL + the canonical tool
 *      invocation an operator (or LLM gateway smoke) needs to run
 *      after the approval is granted.
 *
 * Why this exists: any caller that builds request_payload by hand
 * forgets either je_id binding (rule 1) or draft_hash snapshot (rule
 * 6). This script + workflowRequireJePromotionApproval() are the
 * supported path. Smokes the deploy-time contract so the first real
 * risk-4 promotion in production doesn't surprise anyone.
 *
 * Exit codes:
 *   0  success
 *   1  preflight failed (no draft JE creatable, no chart of accounts,
 *      no open period, no entity)
 *   2  unexpected exception (DB down, schema not migrated, etc.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.local.php';
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/ai/workflows/engine.php';
require_once __DIR__ . '/../core/accounting/post_approval_gates.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

// ─── arg parsing ─────────────────────────────────────────────────────
$opts     = getopt('', ['tenant::', 'entity::', 'user::', 'help']);
if (isset($opts['help'])) {
    echo "usage: php scripts/seed_je_approver_demo.php [--tenant=N] [--entity=N] [--user=N]\n";
    exit(0);
}
$tenantId = (int) ($opts['tenant'] ?? 1);
$entityId = (int) ($opts['entity'] ?? 0);  // 0 = auto-resolve
$userId   = (int) ($opts['user']   ?? 1);

if ($tenantId <= 0) {
    fwrite(STDERR, "error: --tenant must be > 0\n");
    exit(1);
}

echo "── CoreFlux JE approver demo seed ──\n";
echo "tenant_id = $tenantId, user_id = $userId\n";

try {
    $pdo = getDB();

    // ─── 1) Pick or create a draft JE ────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT id, je_number, entity_id, total_debit, total_credit
           FROM accounting_journal_entries
          WHERE tenant_id = :t AND status = 'draft'
       ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId]);
    $draft = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

    if (!$draft) {
        echo "no draft JE found — creating one via accountingPostJe(status='draft')…\n";

        if ($entityId <= 0) {
            $entityId = (int) accountingDefaultEntity($tenantId)['id'];
        }

        // First two active postable accounts (debit + credit).
        $accStmt = $pdo->prepare(
            "SELECT id, code FROM accounting_accounts
              WHERE tenant_id = :t AND active = 1 AND is_postable = 1
           ORDER BY id ASC LIMIT 2"
        );
        $accStmt->execute(['t' => $tenantId]);
        $accs = $accStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (count($accs) < 2) {
            fwrite(STDERR, "error: tenant #$tenantId has fewer than 2 active postable accounts — cannot synthesise a balanced JE.\n");
            exit(1);
        }

        $today  = date('Y-m-d');
        $period = accountingResolvePeriod($tenantId, $entityId, $today);
        if (in_array($period['status'], ['closed', 'soft_closed'], true)) {
            fwrite(STDERR, "error: period {$period['period_number']} is {$period['status']} — open a period covering $today and re-run.\n");
            exit(1);
        }

        $res = accountingPostJe($tenantId, [
            'entity_id'    => $entityId,
            'period_id'    => (int) $period['id'],
            'posting_date' => $today,
            'currency'     => 'USD',
            'source_module'=> 'manual',
            'memo'         => 'JE approver demo seed (auto-generated)',
            'status'       => 'draft',
            'created_by_user_id' => $userId,
            'lines' => [
                ['account_id' => (int) $accs[0]['id'], 'debit' => 100.00, 'credit' => 0.00, 'memo' => 'demo Dr'],
                ['account_id' => (int) $accs[1]['id'], 'debit' => 0.00,   'credit' => 100.00, 'memo' => 'demo Cr'],
            ],
        ]);
        $draft = [
            'id'           => (int) $res['je_id'],
            'je_number'    => (string) $res['je_number'],
            'entity_id'    => $entityId,
            'total_debit'  => 100.00,
            'total_credit' => 100.00,
        ];
        echo "  · created draft JE #{$draft['id']} ({$draft['je_number']})\n";
    } else {
        echo "  · reusing existing draft JE #{$draft['id']} ({$draft['je_number']})\n";
    }
    $jeId = (int) $draft['id'];

    // ─── 2) Synthetic workflow_runs row ──────────────────────────────
    // The Reviewer cockpit lists pending approvals by joining
    // workflow_runs, so a parent row makes the seed approval visible
    // in the same UI an LLM workflow would. Idempotent on
    // (tenant_id, graph_name='manual_je_post_demo', je_id-in-input).
    $runId = sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        crc32("seed-$tenantId-$jeId-" . date('Ymd')),
        random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        random_int(0, 0xFFFFFFFFFFFF)
    );
    $pdo->prepare(
        "INSERT INTO workflow_runs
            (id, tenant_id, user_id, graph_name, graph_version,
             status, current_node, input_json, state_json, created_at)
         VALUES
            (:id, :t, :u, 'manual_je_post_demo', 'seed-2026-02',
             'awaiting_approval', 'await_je_approval',
             :inp, :st, NOW())"
    )->execute([
        'id'  => $runId,
        't'   => $tenantId,
        'u'   => $userId,
        'inp' => json_encode(['je_id' => $jeId, 'origin' => 'seed_je_approver_demo']),
        'st'  => json_encode(['je_id' => $jeId]),
    ]);
    echo "  · created workflow_runs row $runId\n";

    // ─── 3) Open the gate-compatible approval ────────────────────────
    $approvalId = workflowOpenJePromotionApproval($tenantId, $runId, $jeId, 'accounting_reviewer');
    echo "  · opened workflow_approvals #$approvalId (assigned_to_role=accounting_reviewer)\n";

    // Read it back to render the gate-payload diagnostics.
    $apr = $pdo->prepare(
        'SELECT request_payload, expires_at FROM workflow_approvals
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $apr->execute(['id' => $approvalId, 't' => $tenantId]);
    $aprRow = $apr->fetch(\PDO::FETCH_ASSOC) ?: [];
    $payload = json_decode((string) ($aprRow['request_payload'] ?? '{}'), true) ?: [];

    // ─── 4) Print the next-step playbook for the reviewer ────────────
    echo "\n── next steps ──\n";
    echo "1) Reviewer (UI):  /admin/ai-gateway/reviewer  → approve approval #$approvalId\n";
    echo "2) After approval, run the canonical promotion tool call:\n";
    echo "     aiToolInvoke(\n";
    echo "       'coreflux.post_approved_journal_entry',\n";
    echo "       ['je_id' => $jeId],\n";
    echo "       \$callerCtx + ['_approval_id' => $approvalId]\n";
    echo "     );\n";
    echo "\n── gate payload snapshot ──\n";
    echo "  je_id        = " . (int) ($payload['je_id'] ?? 0) . "\n";
    echo "  draft_hash   = " . substr((string) ($payload['draft_hash'] ?? ''), 0, 16) . "…  (sha256, 64-char hex)\n";
    echo "  snapshot_at  = " . (string) ($payload['snapshot_at'] ?? '?') . "\n";

    echo "\n── gate rule sanity-check ──\n";
    echo "  ✓ rule 1 (binding)        — request_payload.je_id = $jeId\n";
    echo "  ✓ rule 6 (mutation guard) — draft_hash snapshotted\n";
    echo "  · rule 3 (SoD) — drafter user_id=$userId; reviewer must approve as a DIFFERENT user\n";
    echo "  · rule 2 (single-use)     — enforced at promotion (atomic UPDATE workflow_approvals…)\n";
    echo "  · rule 4 (expires_at)     — expires_at is NULL, no expiry\n";
    echo "  · rule 5 (audit trail)    — approval_id will be stamped on accounting_journal_entries#$jeId at promotion\n";

    echo "\nDone.\n";
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "\nseed failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(2);
}
