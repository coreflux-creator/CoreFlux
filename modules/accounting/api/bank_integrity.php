<?php
/** Read-only bank history exceptions from the shared CoreFlux integrity graph. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/business_integrity.php';

$ctx = api_require_auth();
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($ctx['user'], 'accounting.coa.view');

$accountingTenantId = (int) (effectiveTenantIdForModule('accounting', (int) $ctx['tenant_id'])
    ?? $ctx['tenant_id']);
$checks = businessIntegrityBankChecks($accountingTenantId);
$status = 'clear';
foreach ($checks as $check) {
    if ($check['status'] === 'error' || $check['status'] === 'skipped') {
        $status = 'incomplete';
        break;
    }
    if ($check['status'] === 'fail') $status = 'exceptions';
}

api_ok([
    'status' => $status,
    'issue_count' => array_sum(array_map(static fn (array $check): int =>
        (int) ($check['issue_count'] ?? 0), $checks)),
    'checks' => $checks,
    'ran_at' => date(DATE_ATOM),
]);
