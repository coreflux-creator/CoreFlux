<?php
/**
 * Resolve the canonical reporting context inherited from one assignment.
 * Manual journals use this instead of asking operators to re-key every
 * staffing dimension independently.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';
require_once __DIR__ . '/../lib/dimensions.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.je.create');

$rawPlacementId = trim((string) (api_query('placement_id') ?? api_query('id') ?? ''));
$placementId = (int) preg_replace('/^PL-/i', '', $rawPlacementId);
if ($placementId <= 0) api_error('Choose a valid assignment.', 422);

$asOfDate = trim((string) (api_query('as_of') ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
    api_error('as_of must be YYYY-MM-DD', 422);
}

try {
    $requestedEntityId = accountingValidateActiveEntityId($tenantId, api_query('entity_id'));
    $requestedEntityId ??= (int) accountingDefaultEntity($tenantId)['id'];
    $context = staffingAssignmentDimensionContext(
        $tenantId,
        $placementId,
        $requestedEntityId,
        $asOfDate
    );
} catch (\Throwable $e) {
    api_error($e->getMessage(), 422);
}

$dimensions = (array) ($context['dimensions'] ?? []);
$resolvedEntityId = (int) ($context['event_entity_id'] ?? 0);
$placement = (array) ($context['placement'] ?? []);

api_ok([
    'placement_id' => $placementId,
    'assignment' => [
        'id' => $placementId,
        'external_id' => $placement['external_id'] ?? null,
        'engagement_type' => $placement['engagement_type'] ?? null,
        'end_client_name' => $placement['end_client_name'] ?? null,
    ],
    'dimensions' => $dimensions,
    'vendor_dimension' => $context['vendor_dimension'] ?? null,
    'resolved_entity_id' => $resolvedEntityId ?: null,
    'requested_entity_id' => $requestedEntityId,
    'entity_matches_requested' => $resolvedEntityId > 0 && $resolvedEntityId === $requestedEntityId,
    'missing' => array_values((array) ($context['missing'] ?? [])),
    'missing_labels' => staffingDimensionMissingLabels((array) ($context['missing'] ?? [])),
]);
