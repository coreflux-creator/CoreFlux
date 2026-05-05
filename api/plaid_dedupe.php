<?php
/**
 * /api/plaid_dedupe.php — one-click cleanup for duplicated Treasury rows
 * created by the older Plaid-Link path (which spawned a fresh
 * accounting_bank_accounts / treasury_liability_accounts row on every
 * reconnect because Plaid issues a new account_id each time).
 *
 *   GET  /api/plaid_dedupe.php  → preview duplicate clusters
 *   POST /api/plaid_dedupe.php?action=run
 *
 * Strategy: cluster active rows by (tenant_id, bank_name, last4) — every
 * cluster of size > 1 is a dupe set. Pick the survivor with the most-recent
 * Plaid feed sync (or, if none synced, the most-recent updated_at). Update
 * the survivor's plaid_account_id to the most recent value, then mark the
 * other cluster members status='closed'. Liability dupes use the same logic
 * keyed on (institution_name, last4) and deactivate the COA row.
 *
 * No journal entries are mutated — the GL codes on closed rows still resolve
 * back to existing JEs because hide is reversible. Audit is written.
 *
 * Permission: `accounting.bank.manage`. Read-only for GET, write for POST.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
RBAC::requirePermission($ctx['user'], 'accounting.bank.manage');
$pdo = getDB();

$method = api_method();
$action = (string) ($_GET['action'] ?? '');

// ── Preview clusters ──────────────────────────────────────────────────
function _dedupeFindDepositClusters(PDO $pdo, int $tenantId): array {
    // Active rows only — closed/disconnected rows are already out of the way.
    $stmt = $pdo->prepare(
        "SELECT id, name, gl_account_code, bank_name, last4, plaid_account_id,
                last_feed_synced_at, updated_at, created_at
           FROM accounting_bank_accounts
          WHERE tenant_id = :t AND status = 'active'
            AND last4 IS NOT NULL AND last4 <> ''
            AND bank_name IS NOT NULL AND bank_name <> ''
          ORDER BY bank_name, last4, last_feed_synced_at DESC, updated_at DESC, id DESC"
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clusters = [];
    foreach ($rows as $r) {
        $key = strtolower(trim($r['bank_name'])) . '|' . $r['last4'];
        $clusters[$key][] = $r;
    }
    return array_values(array_filter($clusters, fn ($c) => count($c) > 1));
}

function _dedupeFindLiabilityClusters(PDO $pdo, int $tenantId): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT tla.id, tla.account_id, tla.institution_name, tla.last4,
                    tla.plaid_account_id, tla.updated_at, tla.created_at,
                    aa.code, aa.name, aa.active
               FROM treasury_liability_accounts tla
               JOIN accounting_accounts aa ON aa.id = tla.account_id
              WHERE tla.tenant_id = :t AND aa.active = 1
                AND tla.last4 IS NOT NULL AND tla.last4 <> ''
                AND tla.institution_name IS NOT NULL AND tla.institution_name <> ''
              ORDER BY tla.institution_name, tla.last4, tla.updated_at DESC, tla.id DESC"
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }

    $clusters = [];
    foreach ($rows as $r) {
        $key = strtolower(trim($r['institution_name'])) . '|' . $r['last4'];
        $clusters[$key][] = $r;
    }
    return array_values(array_filter($clusters, fn ($c) => count($c) > 1));
}

if ($method === 'GET') {
    api_ok([
        'deposit_clusters'   => _dedupeFindDepositClusters($pdo, $tenantId),
        'liability_clusters' => _dedupeFindLiabilityClusters($pdo, $tenantId),
    ]);
}

if ($method === 'POST' && $action === 'run') {
    $hiddenDeposits   = [];
    $hiddenLiabilities = [];

    foreach (_dedupeFindDepositClusters($pdo, $tenantId) as $cluster) {
        // Survivor: most recently synced (already first because of ORDER BY).
        $survivor = $cluster[0];
        $latestPaid = $survivor['plaid_account_id'];
        // If the survivor doesn't have a Plaid account but a sibling does, lift it.
        foreach ($cluster as $row) {
            if (!$latestPaid && !empty($row['plaid_account_id'])) {
                $latestPaid = $row['plaid_account_id'];
                break;
            }
        }
        if ($latestPaid && $latestPaid !== $survivor['plaid_account_id']) {
            $pdo->prepare(
                "UPDATE accounting_bank_accounts
                    SET plaid_account_id = :pa, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['t' => $tenantId, 'pa' => $latestPaid, 'id' => (int) $survivor['id']]);
        }
        // Hide the rest.
        for ($i = 1; $i < count($cluster); $i++) {
            $rowId = (int) $cluster[$i]['id'];
            $pdo->prepare(
                "UPDATE accounting_bank_accounts
                    SET status = 'closed', updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['t' => $tenantId, 'id' => $rowId]);
            $hiddenDeposits[] = $rowId;
        }
    }

    foreach (_dedupeFindLiabilityClusters($pdo, $tenantId) as $cluster) {
        $survivor = $cluster[0];
        $latestPaid = $survivor['plaid_account_id'];
        foreach ($cluster as $row) {
            if (!$latestPaid && !empty($row['plaid_account_id'])) {
                $latestPaid = $row['plaid_account_id'];
                break;
            }
        }
        if ($latestPaid && $latestPaid !== $survivor['plaid_account_id']) {
            $pdo->prepare(
                "UPDATE treasury_liability_accounts
                    SET plaid_account_id = :pa, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['t' => $tenantId, 'pa' => $latestPaid, 'id' => (int) $survivor['id']]);
        }
        for ($i = 1; $i < count($cluster); $i++) {
            $aaId = (int) $cluster[$i]['account_id'];
            $pdo->prepare(
                "UPDATE accounting_accounts
                    SET active = 0, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['t' => $tenantId, 'id' => $aaId]);
            $hiddenLiabilities[] = $aaId;
        }
    }

    plaidAudit('payment_rails.plaid.dedupe_run', [
        'hidden_deposit_ids'    => $hiddenDeposits,
        'hidden_liability_ids'  => $hiddenLiabilities,
    ], null);

    api_ok([
        'ok'                    => true,
        'hidden_deposit_ids'    => $hiddenDeposits,
        'hidden_liability_ids'  => $hiddenLiabilities,
        'remaining_dupes'       => [
            'deposits'   => count(_dedupeFindDepositClusters($pdo, $tenantId)),
            'liabilities'=> count(_dedupeFindLiabilityClusters($pdo, $tenantId)),
        ],
    ]);
}

api_error('Method not allowed (use GET to preview, POST?action=run to merge)', 405);
