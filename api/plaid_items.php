<?php
/**
 * Plaid Items — list / disconnect connected institutions for the current tenant.
 *
 *   GET    /api/plaid_items.php             → list connected institutions
 *   DELETE /api/plaid_items.php?id={pk}     → disconnect: revoke at Plaid via
 *                                              /item/remove, then cascade-hide
 *                                              every mirrored deposit + liability
 *                                              that referenced this item. Plaid
 *                                              accounts + statement lines stay
 *                                              for historical/audit purposes.
 *
 * Permission: `accounting.bank.manage`. Audit: payment_rails.plaid.item_disconnected.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/plaid_service.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'accounting.bank.manage');

$method = api_method();

if ($method === 'GET') {
    // Per-item account counts so the UI can show "3 deposits, 2 cards" inline.
    $rows = scopedQuery(
        "SELECT pi.id, pi.item_id, pi.institution_id, pi.institution_name,
                pi.purpose, pi.status, pi.last_webhook_at, pi.last_error_code,
                pi.last_error_message, pi.created_at, pi.updated_at,
                (SELECT COUNT(*) FROM plaid_accounts pa
                  WHERE pa.tenant_id = pi.tenant_id AND pa.plaid_item_pk = pi.id) AS account_count,
                (SELECT COUNT(*) FROM accounting_bank_accounts ba
                  WHERE ba.tenant_id = pi.tenant_id
                    AND ba.status = 'active'
                    AND ba.plaid_account_id IN (
                      SELECT pa.account_id FROM plaid_accounts pa
                       WHERE pa.tenant_id = pi.tenant_id AND pa.plaid_item_pk = pi.id
                    )) AS mirrored_deposit_count,
                (SELECT COUNT(*) FROM treasury_liability_accounts tla
                   JOIN accounting_accounts aa ON aa.id = tla.account_id
                  WHERE tla.tenant_id = pi.tenant_id
                    AND aa.active = 1
                    AND tla.plaid_account_id IN (
                      SELECT pa.account_id FROM plaid_accounts pa
                       WHERE pa.tenant_id = pi.tenant_id AND pa.plaid_item_pk = pi.id
                    )) AS mirrored_liability_count
           FROM plaid_items pi
          WHERE pi.tenant_id = :tenant_id AND pi.purpose = 'bank_feed'
          ORDER BY pi.id DESC"
    );
    api_ok(['rows' => $rows, 'count' => count($rows)]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);

    $item = scopedFind(
        'SELECT id, item_id, institution_name, access_token_ct
           FROM plaid_items WHERE tenant_id = :tenant_id AND id = :id AND purpose = :p',
        ['id' => $id, 'p' => 'bank_feed']
    );
    if (!$item) api_error('Plaid item not found', 404);

    // 1) Revoke at Plaid. Best-effort: if Plaid returns ITEM_NOT_FOUND or the
    //    token is already invalid we still proceed with local cleanup.
    $plaidErr = null;
    try {
        $token = plaidDecryptAccessToken($item['access_token_ct']);
        if ($token) {
            plaidPost('/item/remove', ['access_token' => $token]);
        }
    } catch (PlaidApiException $e) {
        $plaidErr = $e->getMessage();
    } catch (\Throwable $e) {
        $plaidErr = $e->getMessage();
    }

    $pdo = getDB();
    $cascadedDeposits   = [];
    $cascadedLiabilities = [];

    // Pull every plaid_account for this item so we can cascade-hide downstream.
    $accs = scopedQuery(
        'SELECT account_id FROM plaid_accounts
          WHERE tenant_id = :tenant_id AND plaid_item_pk = :pk',
        ['pk' => $id]
    );
    $accIds = array_values(array_filter(array_map(fn ($r) => (string) $r['account_id'], $accs)));

    if ($accIds) {
        $in = implode(',', array_fill(0, count($accIds), '?'));

        // Cascade-hide deposit mirrors.
        $stmt = $pdo->prepare(
            "SELECT id FROM accounting_bank_accounts
              WHERE tenant_id = ? AND status = 'active' AND plaid_account_id IN ($in)"
        );
        $stmt->execute(array_merge([$tenantId], $accIds));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $depId) {
            scopedUpdate('accounting_bank_accounts', (int) $depId, ['status' => 'closed']);
            $cascadedDeposits[] = (int) $depId;
        }

        // Cascade-hide liability mirrors (deactivate the COA row).
        try {
            $stmt = $pdo->prepare(
                "SELECT aa.id
                   FROM treasury_liability_accounts tla
                   JOIN accounting_accounts aa ON aa.id = tla.account_id
                  WHERE tla.tenant_id = ? AND aa.active = 1 AND tla.plaid_account_id IN ($in)"
            );
            $stmt->execute(array_merge([$tenantId], $accIds));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aaId) {
                scopedUpdate('accounting_accounts', (int) $aaId, ['active' => 0]);
                $cascadedLiabilities[] = (int) $aaId;
            }
        } catch (\Throwable $_) { /* tla.plaid_account_id column may be missing; non-fatal */ }
    }

    // 2) Mark the item disconnected (keep the row for audit + diagnostics).
    scopedUpdate('plaid_items', $id, [
        'status'             => 'disconnected',
        'last_error_code'    => $plaidErr ? 'item_remove_warning' : null,
        'last_error_message' => $plaidErr,
    ]);

    plaidAudit('payment_rails.plaid.item_disconnected', [
        'item_id'                 => $item['item_id'],
        'institution'             => $item['institution_name'] ?? null,
        'cascaded_deposit_ids'    => $cascadedDeposits,
        'cascaded_liability_ids'  => $cascadedLiabilities,
        'plaid_remove_warning'    => $plaidErr,
    ], $id);

    api_ok([
        'ok'                     => true,
        'cascaded_deposit_ids'   => $cascadedDeposits,
        'cascaded_liability_ids' => $cascadedLiabilities,
        'plaid_remove_warning'   => $plaidErr,
    ]);
}

api_error('Method not allowed', 405);
