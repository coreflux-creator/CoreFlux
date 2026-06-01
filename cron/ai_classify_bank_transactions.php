<?php
/**
 * cron/ai_classify_bank_transactions.php
 *
 * Slice 6 — auto-classify cron worker. Picks up bank transactions
 * the AI hasn't seen yet and runs each one through the
 * `transaction_classification` workflow. Tenant-scoped, idempotent
 * via the `ai_classified_at` columns added in migration 094.
 *
 * Cron entry (Cloudways):
 *   Every 5 minutes:  * /5 * * * * php /home/master/applications/<app>/public_html/cron/ai_classify_bank_transactions.php
 *
 * Per-iteration:
 *   1. SELECT up to N pending bank statement lines (Plaid) ∪ mercury
 *      transactions, scoped per-tenant.
 *   2. For each row, start a workflow with the txn embedded in input.
 *   3. UPDATE the source row with ai_classified_at + ai_workflow_run_id
 *      so the next cron pass doesn't reprocess it.
 *
 * Defensive: per-tenant try/catch — one tenant's failure never
 * blocks another tenant from being processed. Per-row try/catch —
 * one row's failure never blocks the rest of the batch.
 *
 * The workflow itself decides whether to use the LLM (state.use_llm)
 * based on a tenant feature flag table that lands in Slice 7 — for
 * now the cron always sets `use_llm = false` so the deterministic
 * stub runs and the run pauses for human approval as needed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/ai/workflows/engine.php';
require_once __DIR__ . '/../core/ai/workflows/graphs/transaction_classification.php';

$BATCH_PER_SOURCE = 50;   // soft cap per tenant per source per run
$TOTAL_LIMIT      = 500;  // safety cap across all tenants in one cron tick

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "[ai_classify_cron] DB unavailable — skipping run\n");
    exit(0);
}

$started = microtime(true);
$totals  = ['plaid' => 0, 'mercury' => 0, 'failed' => 0, 'tenants' => 0];

// Discover tenants with pending Plaid OR Mercury transactions.
try {
    $stmt = $pdo->query(
        "SELECT tenant_id FROM (
            SELECT DISTINCT tenant_id FROM accounting_bank_statement_lines WHERE ai_classified_at IS NULL
            UNION
            SELECT DISTINCT tenant_id FROM mercury_transactions             WHERE ai_classified_at IS NULL
         ) t"
    );
    $tenantIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
} catch (\Throwable $e) {
    fwrite(STDERR, "[ai_classify_cron] tenant discovery failed: " . $e->getMessage() . "\n");
    exit(0);
}

echo "[ai_classify_cron] processing " . count($tenantIds) . " tenant(s)\n";

foreach ($tenantIds as $tenantId) {
    if (($totals['plaid'] + $totals['mercury']) >= $TOTAL_LIMIT) {
        echo "[ai_classify_cron] total batch cap reached — yielding\n";
        break;
    }
    $totals['tenants']++;
    echo "[ai_classify_cron] tenant {$tenantId} →\n";

    try {
        // Plaid lane.
        $plaidStmt = $pdo->prepare(
            "SELECT id, bank_account_id, transaction_date, amount_cents,
                    description, merchant_name, currency, posted_at
               FROM accounting_bank_statement_lines
              WHERE tenant_id = :t AND ai_classified_at IS NULL
              ORDER BY id ASC
              LIMIT :n"
        );
        $plaidStmt->bindValue('t', $tenantId, PDO::PARAM_INT);
        $plaidStmt->bindValue('n', $BATCH_PER_SOURCE, PDO::PARAM_INT);
        $plaidStmt->execute();
        foreach (($plaidStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rowId = (int) $row['id'];
            try {
                $res = workflowStart($tenantId, /* user */ null, 'transaction_classification',
                    [
                        'use_llm' => false,
                        'transaction' => [
                            'source'       => 'plaid',
                            'id'           => $rowId,
                            'amount_cents' => (int) ($row['amount_cents'] ?? 0),
                            'currency'     => $row['currency'] ?? 'USD',
                            'description'  => $row['description'] ?? $row['merchant_name'] ?? '',
                            'posted_at'    => $row['posted_at'] ?? $row['transaction_date'] ?? null,
                        ],
                    ],
                    ['sub_tenant_id' => null]
                );
                // tenant-leak-allow: tenant_id is already in the
                // WHERE clause below and matches the workflow's
                // tenant context.
                $pdo->prepare(
                    'UPDATE accounting_bank_statement_lines
                        SET ai_classified_at = NOW(), ai_workflow_run_id = :w
                      WHERE id = :id AND tenant_id = :t'
                )->execute(['w' => $res['workflow_run_id'], 'id' => $rowId, 't' => $tenantId]);
                $totals['plaid']++;
            } catch (\Throwable $e) {
                $totals['failed']++;
                error_log("[ai_classify_cron] plaid row #{$rowId} failed: " . $e->getMessage());
            }
        }

        // Mercury lane.
        $mercStmt = $pdo->prepare(
            "SELECT id, mercury_txn_id, amount_cents, currency,
                    counterparty_name, bank_description, posted_at, received_at
               FROM mercury_transactions
              WHERE tenant_id = :t AND ai_classified_at IS NULL
              ORDER BY id ASC
              LIMIT :n"
        );
        $mercStmt->bindValue('t', $tenantId, PDO::PARAM_INT);
        $mercStmt->bindValue('n', $BATCH_PER_SOURCE, PDO::PARAM_INT);
        $mercStmt->execute();
        foreach (($mercStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rowId = (int) $row['id'];
            try {
                $res = workflowStart($tenantId, null, 'transaction_classification',
                    [
                        'use_llm' => false,
                        'transaction' => [
                            'source'       => 'mercury',
                            'id'           => $rowId,
                            'amount_cents' => (int) ($row['amount_cents'] ?? 0),
                            'currency'     => $row['currency'] ?? 'USD',
                            'description'  => $row['counterparty_name'] ?? $row['bank_description'] ?? '',
                            'posted_at'    => $row['posted_at'] ?? $row['received_at'] ?? null,
                        ],
                    ],
                    ['sub_tenant_id' => null]
                );
                // tenant-leak-allow: scoped by (id, tenant_id) and
                // mercury_transactions IS tenant-scoped.
                $pdo->prepare(
                    'UPDATE mercury_transactions
                        SET ai_classified_at = NOW(), ai_workflow_run_id = :w
                      WHERE id = :id AND tenant_id = :t'
                )->execute(['w' => $res['workflow_run_id'], 'id' => $rowId, 't' => $tenantId]);
                $totals['mercury']++;
            } catch (\Throwable $e) {
                $totals['failed']++;
                error_log("[ai_classify_cron] mercury row #{$rowId} failed: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log("[ai_classify_cron] tenant {$tenantId} aborted: " . $e->getMessage());
    }
}

$elapsed = round(microtime(true) - $started, 2);
echo "[ai_classify_cron] done — plaid={$totals['plaid']} mercury={$totals['mercury']} failed={$totals['failed']} tenants={$totals['tenants']} elapsed={$elapsed}s\n";
exit($totals['failed'] > 0 ? 1 : 0);
