<?php
/** Placement economic parties, terms, PWP, and readiness. */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';
require_once __DIR__ . '/../lib/economics_edit.php';
require_once __DIR__ . '/../lib/economic_items_edit.php';
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
    try {
        $result = placementEconomicsCreateNativeItem($tenantId, $placementId, api_json_body(), $user);
    } catch (PDOException $e) {
        error_log('[native one-time item create] ' . $e->getCode());
        api_error('The item could not be saved. Retry this save to confirm its result.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
    api_ok($result, $result['changed'] ? 201 : 200);
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
    try {
        api_ok(placementEconomicsRemoveNativeItem($tenantId, $id));
    } catch (PDOException $e) {
        error_log('[native one-time item removal] ' . $e->getCode());
        api_error('The item could not be removed. Refresh the contract and try again.', 503);
    } catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
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
