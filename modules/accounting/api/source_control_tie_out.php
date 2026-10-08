<?php
/** Read-only, entity-specific AR/AP subledger-to-control-account comparison. */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/source_control_tie_out.php';

$ctx = api_require_auth();
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($ctx['user'], 'accounting.reports.view');

try {
    $rawEntityId = $_GET['entity_id'] ?? null;
    if ($rawEntityId !== null && !is_scalar($rawEntityId)) {
        api_error('Choose one legal entity for this comparison.', 422);
    }
    $entityId = accountingValidateActiveEntityId((int) $ctx['tenant_id'], $rawEntityId);
    if ($entityId === null) api_error('Choose one legal entity for this comparison.', 422);
    $asOf = $_GET['as_of'] ?? date('Y-m-d');
    if (!is_string($asOf)) api_error('Choose a valid as-of date.', 422);
    api_ok(accountingSourceControlTieOut((int) $ctx['tenant_id'], $entityId, $asOf));
} catch (\InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('[accounting.source_control_tie_out] ' . $e->getMessage());
    api_error('Unable to compare subledgers with the ledger right now.', 500);
}
