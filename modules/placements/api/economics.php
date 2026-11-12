<?php
/** Placement economic parties, terms, PWP, and readiness. */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';
require_once __DIR__ . '/../lib/economics_edit.php';
require_once __DIR__ . '/../lib/economics_participants.php';
require_once __DIR__ . '/../lib/commercial_ownership.php';

$ctx = api_require_auth();
$user = $ctx['user'];
// Contract parties and cycles belong to the same shared placement catalog
// as the overview. Keep the authenticated tenant unchanged for RBAC.
$tenantId = (int) placementsGraphTenantId((int) $ctx['tenant_id']);
$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$placementId = (int) ($_GET['placement_id'] ?? 0);

/** Screen affordances use the same dual permission checks as their writers. */
function placementEconomicsApiPermissions(array $user): array
{
    $permissions = [];
    foreach (['manage','financials.manage','financials.approve','commissions.manage','referrals.manage','corp.manage','portal_credentials.view'] as $name) {
        $permissions[str_replace('.', '_', $name)] = rbac_legacy_can($user, 'placements.' . $name);
    }
    return $permissions;
}

if (($method === 'GET' && $action === 'ownership_review') || ($method === 'POST' && $action === 'release_ownership')) {
    rbac_legacy_require($user, $method === 'GET' ? 'placements.view' : 'placements.manage');
    if ($placementId <= 0) api_error('placement_id required', 400);
    try {
        if ($method === 'GET') api_ok(placementCommercialOwnershipReview($tenantId, $placementId) + [
            'permissions' => ['release' => rbac_legacy_can($user, 'placements.manage')],
        ]);
        $body = api_json_body();
        if (($body['confirm'] ?? '') !== 'RELEASE_COMMERCIAL_OWNERSHIP') api_error('Confirm the reviewed ownership release.', 422);
        api_ok(placementReleaseCommercialOwnership($tenantId, $placementId,
            is_array($body['groups'] ?? null) ? $body['groups'] : [], (string)($body['review_token'] ?? '')));
    } catch (PDOException $e) {
        error_log('[commercial ownership release] ' . $e->getMessage());
        api_error('The contract review could not be completed. Refresh it and try again.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.view');
    if ($placementId <= 0) api_error('placement_id required', 400);
    // Viewing a contract never reconciles, creates or updates its graph.
    // Editors use the explicit reconcile action below when a refresh is needed.
    try {
        $context = placementEconomicsContext($tenantId, $placementId, false);
    } catch (PDOException $e) {
        error_log('[placement contract read] ' . $e->getMessage());
        $context = ['available' => false, 'reconcile' => ['errors' => [$e->getMessage()]]];
    }
    if (empty($context['available'])) {
        $failure = $context['reconcile'] ?? [];
        $detail = (string) ($failure['errors'][0] ?? '');
        $schemaMissing = preg_match('/SQLSTATE\[(42S02|42S22)\]/', $detail) === 1;
        $message = $schemaMissing
            ? 'Placement contract setup is incomplete. Run pending database updates.'
            : 'Placement contract could not be loaded. Please retry or contact your administrator.';
        if ($detail === 'placement not found') {
            api_error('Placement not found', 404);
        }
        if (($user['global_role'] ?? $user['role'] ?? '') === 'master_admin' && $detail !== '') {
            $message .= ' Details: ' . substr($detail, 0, 500);
        }
        api_error($message, $schemaMissing ? 409 : 503, array_merge($failure, [
            'migration_pending' => $schemaMissing,
        ]));
    }
    api_ok($context + ['permissions' => placementEconomicsApiPermissions($user)]);
}

if ($method === 'POST' && $action === 'reconcile') {
    rbac_legacy_require($user, 'placements.manage');
    if ($placementId <= 0) api_error('placement_id required', 400);
    try {
        $response = placementWithLockedRecord($tenantId, $placementId, static function () use ($tenantId, $placementId): array {
            $result = placementEconomicsReconcile($tenantId, $placementId);
            if (empty($result['available'])) throw new RuntimeException('Contract participants could not be refreshed. Review the placement setup and try again.', 503);
            placementRequiredAudit('placement.economics.reconciled', $result, $placementId);
            return ['result' => $result, 'context' => placementEconomicsContext($tenantId, $placementId, false)];
        });
        api_ok($response);
    } catch (PDOException $e) {
        error_log('[placement contract refresh] ' . $e->getMessage());
        api_error('Contract participants could not be refreshed. Please try again.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
}

if ($method === 'POST' && $action === 'item') {
    rbac_legacy_require($user, 'placements.financials.manage');
    if ($placementId <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['description', 'settlement_channel', 'amount', 'apply_on']);
    $description = trim((string) $body['description']);
    $channel = strtolower(trim((string) $body['settlement_channel']));
    $direction = strtolower(trim((string) ($body['direction'] ?? 'charge')));
    $amount = is_numeric($body['amount']) ? round((float) $body['amount'], 2) : 0.0;
    $currency = strtoupper(trim((string) ($body['currency'] ?? 'USD')));
    $applyOn = trim((string) $body['apply_on']);
    if ($description === '') api_error('Description required', 422);
    if (!in_array($channel, ['ar','ap','payroll','none'], true)) api_error('Invalid destination', 422);
    if (!in_array($direction, ['charge','credit'], true)) api_error('Invalid item effect', 422);
    if ($channel === 'payroll' && $direction === 'credit') {
        api_error('Payroll deductions require a payroll deduction rule; this item can only add earnings', 422);
    }
    if ($channel === 'ap' && $direction === 'credit') {
        api_error('Vendor credits must use the vendor-credit workflow; this item can only increase a payable', 422);
    }
    if ($amount <= 0) api_error('Amount must be greater than zero', 422);
    if (!preg_match('/^[A-Z]{3}$/', $currency)) api_error('Currency must be a three-letter code', 422);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $applyOn);
    if (!$date || $date->format('Y-m-d') !== $applyOn) api_error('Apply date must be a valid date', 422);

    $rateCurrency = scopedFind(
        'SELECT currency FROM placement_rates
          WHERE tenant_id = :tenant_id AND placement_id = :placement_id
          ORDER BY (approved_at IS NOT NULL) DESC, effective_from DESC, id DESC
          LIMIT 1',
        ['placement_id' => $placementId]
    );
    if ($rateCurrency && strtoupper((string) $rateCurrency['currency']) !== $currency) {
        api_error('One-time items must use the placement contract currency', 422);
    }

    $partyId = !empty($body['economic_party_id']) ? (int) $body['economic_party_id'] : null;
    if ($channel !== 'none') {
        if (!$partyId && $channel === 'ar') {
            $st = getDB()->prepare(
                'SELECT id FROM placement_economic_parties
                  WHERE tenant_id = :t AND placement_id = :p AND active = 1
                    AND settlement_channel = "ar" ORDER BY id LIMIT 2'
            );
            $st->execute(['t' => $tenantId, 'p' => $placementId]);
            $ids = array_map('intval', array_column($st->fetchAll(\PDO::FETCH_ASSOC), 'id'));
            if (count($ids) === 1) $partyId = $ids[0];
        }
        if (!$partyId) api_error('Choose the recipient for this item', 422);
        $party = scopedFind(
            'SELECT id, settlement_channel FROM placement_economic_parties
              WHERE tenant_id = :tenant_id AND placement_id = :placement_id
                AND id = :id AND active = 1',
            ['placement_id' => $placementId, 'id' => $partyId]
        );
        if (!$party || (string) $party['settlement_channel'] !== $channel) {
            api_error('The selected recipient does not match this item destination', 422);
        }
    } else {
        $partyId = null;
    }

    $id = placementEconomicsCreateItem($tenantId, $placementId, [
        'economic_party_id' => $partyId,
        'description' => $description,
        'settlement_channel' => $channel,
        'direction' => $direction,
        'amount' => $amount,
        'currency' => $currency,
        'apply_on' => $applyOn,
        'taxable' => $channel === 'ar' && !empty($body['taxable']),
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    placementsAudit('placement.economic_item.added', [
        'placement_id' => $placementId,
        'economic_item_id' => $id,
        'settlement_channel' => $channel,
        'economic_party_id' => $partyId,
        'amount' => $amount,
        'direction' => $direction,
    ], $placementId);
    api_ok(['id' => $id, 'context' => placementEconomicsContext($tenantId, $placementId, false)], 201);
}

if ($method === 'POST' && $action === 'party') {
    rbac_legacy_require($user, 'placements.manage');
    if ($placementId <= 0) api_error('placement_id required', 400);
    try {
        $result = placementEconomicsCreateNativeParty($tenantId, $placementId, api_json_body(), $user);
    } catch (PDOException $e) {
        error_log('[native participant create] ' . $e->getMessage());
        api_error('The participant could not be saved. Refresh the contract and try again.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
    api_ok($result, 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    try {
        $result = placementEconomicsApplyNativePartyEdit($tenantId, $id, api_json_body());
    } catch (PDOException $e) {
        error_log('[native economic party edit] ' . $e->getMessage());
        api_error('The contract could not be saved. Refresh it and try again.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
    api_ok(['ok' => true, 'changed' => $result['changed'], 'party' => $result['party']]);
}

if ($method === 'DELETE' && $action === 'item') {
    rbac_legacy_require($user, 'placements.financials.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $item = scopedFind(
        'SELECT * FROM placement_economic_items WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$item) api_error('One-time item not found', 404);
    if (!empty($item['source_managed'])) api_error('This item is managed by its source integration', 409);
    $used = getDB()->prepare(
        'SELECT 1 FROM placement_economic_obligations
          WHERE tenant_id = :t AND source_type = "economic_item"
            AND source_ref_id = :id AND status <> "void" LIMIT 1'
    );
    $used->execute(['t' => $tenantId, 'id' => $id]);
    if ($used->fetchColumn()) api_error('This item has already been applied and cannot be removed', 409);
    getDB()->prepare(
        'UPDATE placement_economic_items SET status = "void"
          WHERE tenant_id = :t AND id = :id'
    )->execute(['t' => $tenantId, 'id' => $id]);
    placementsAudit('placement.economic_item.removed', [
        'placement_id' => (int) $item['placement_id'], 'economic_item_id' => $id,
    ], (int) $item['placement_id']);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'placements.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    try {
        $result = placementEconomicsRemoveNativeParty($tenantId, $id);
    } catch (PDOException $e) {
        error_log('[native participant removal] ' . $e->getMessage());
        api_error('The participant could not be removed. Refresh the contract and try again.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
    api_ok($result);
}

api_error('Method not allowed', 405);
