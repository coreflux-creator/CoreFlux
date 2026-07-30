<?php
/**
 * Controlled JobDiva placement reconciliation API.
 *
 * POST ?action=stored_preview
 * POST ?action=stored_apply {dry_run_token, selected_start_ids, confirm}
 *
 * CSV fallback:
 * POST ?action=inspect  {csv}
 * POST ?action=dry_run {csv, column_map}
 * POST ?action=apply   {csv, column_map, dry_run_token, selected_start_ids, confirm}
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../../core/integrations/entity_mappings.php';
require_once __DIR__ . '/../../../core/jobdiva/sync.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';
require_once __DIR__ . '/../lib/jobdiva_reconciliation.php';
require_once __DIR__ . '/../../people/lib/companies.php';
require_once __DIR__ . '/../../staffing/lib/clients.php';

use Core\CsvImportService;

CsvImportService::registerSchema(
    'jobdiva_placement_reconciliation',
    jobdivaReconciliationSchema()
);

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');
if ($method !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'placements.manage');

$body = api_json_body();
$pdo = getDB();
$tenantId = placementsGraphTenantId() ?? currentTenantId();
$peopleTenantId = effectiveTenantIdForModule('people') ?? $tenantId;

if ($action === 'stored_preview') {
    rbac_legacy_require($user, 'placements.financials.view');
    $plan = jobdivaStoredAssignmentProjectionPlan($tenantId, 5000);
    api_ok([
        'summary' => $plan['summary'],
        'rows' => $plan['public_rows'],
        'dry_run_token' => $plan['dry_run_token'],
        'safety' => $plan['safety'],
    ]);
}

if ($action === 'stored_apply') {
    rbac_legacy_require($user, 'placements.financials.manage');
    if (($body['confirm'] ?? '') !== 'APPLY_STORED_JOBDIVA_ASSIGNMENTS') {
        api_error('Explicit stored-assignment projection confirmation is required', 422);
    }
    $selected = is_array($body['selected_start_ids'] ?? null)
        ? array_values(array_unique(array_map('strval', $body['selected_start_ids'])))
        : [];
    try {
        $result = jobdivaApplyStoredAssignmentProjection(
            $tenantId,
            isset($user['id']) ? (int) $user['id'] : null,
            $selected,
            (string) ($body['dry_run_token'] ?? ''),
            5000
        );
        api_ok($result + [
            'ok' => true,
            'message' => 'Selected stored JobDiva assignments were projected through the canonical CoreFlux graph.',
        ]);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 409);
    } catch (\Throwable $e) {
        api_error('Stored assignment projection failed; no selected rows were written: ' . $e->getMessage(), 500);
    }
}

$csv = (string) ($body['csv'] ?? '');
if ($csv === '') api_error('No CSV body received', 400);
if (strlen($csv) > 25 * 1024 * 1024) api_error('CSV exceeds the 25 MB limit', 413);
if (substr_count($csv, "\n") > 5001) api_error('Reconciliation is limited to 5,000 data rows per preview', 413);

if ($action === 'inspect') {
    api_ok(jobdivaReconciliationInspect($csv));
}
if ($action === 'dry_run') {
    rbac_legacy_require($user, 'placements.financials.view');
} elseif ($action === 'apply') {
    rbac_legacy_require($user, 'placements.financials.manage');
} else {
    api_error('Unknown action', 404);
}

$columnMap = is_array($body['column_map'] ?? null) ? $body['column_map'] : null;
$plan = jobdivaReconciliationBuildPlan($pdo, $tenantId, $peopleTenantId, $csv, $columnMap);

if ($action === 'dry_run') {
    api_ok([
        'summary' => $plan['summary'],
        'rows' => $plan['public_rows'],
        'headers' => $plan['headers'],
        'column_map' => $plan['column_map'],
        'mapping_errors' => $plan['mapping_errors'],
        'dry_run_token' => $plan['dry_run_token'],
        'safety' => $plan['safety'],
    ]);
}

if (($body['confirm'] ?? '') !== 'APPLY_EXACT_START_ID_RECONCILIATION') {
    api_error('Explicit reconciliation confirmation is required', 422);
}
$providedToken = (string) ($body['dry_run_token'] ?? '');
if ($providedToken === '' || !hash_equals($plan['dry_run_token'], $providedToken)) {
    api_error('The data or CoreFlux records changed after preview. Run the dry-run again before applying.', 409);
}
$selected = is_array($body['selected_start_ids'] ?? null)
    ? array_values(array_unique(array_map(
        static fn($id): string => jobdivaAssignmentIdentityNormaliseId((string) $id),
        $body['selected_start_ids']
    )))
    : [];
$selected = array_values(array_filter($selected, static fn(string $id): bool => $id !== ''));
if (!$selected) api_error('Select at least one previewed Start ID', 422);
if (count($selected) > 500) api_error('Apply is limited to 500 selected rows at a time', 422);
$selectedLookup = array_fill_keys($selected, true);

$applyRows = [];
foreach ($plan['rows'] as $row) {
    if (!isset($selectedLookup[$row['start_id']])) continue;
    if (!$row['selectable']) {
        api_error("Start ID {$row['start_id']} is not applyable; preview it again.", 409);
    }
    $applyRows[] = $row;
}
if (count($applyRows) !== count($selected)) {
    api_error('One or more selected Start IDs were not present in this exact preview', 409);
}

$created = 0;
$updated = 0;
$rateDrafts = 0;
$mappingWrites = 0;
$applied = [];

try {
    $pdo->beginTransaction();
    foreach ($applyRows as $row) {
        $before = $row['placement_id'] ? placementAuditRow((int) $row['placement_id']) : null;
        $patch = $row['placement_patch'];
        $startId = (string) $row['start_id'];

        if ($row['is_create']) {
            $patch['tenant_id'] = $tenantId;
            $patch['created_by_user_id'] = $user['id'] ?? null;
            $columns = array_keys($patch);
            $params = [];
            $placeholders = [];
            foreach ($columns as $column) {
                $params[$column] = $patch[$column];
                $placeholders[] = ':' . $column;
            }
            $pdo->prepare(
                'INSERT INTO placements (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $placeholders) . ')'
            )->execute($params);
            $placementId = (int) $pdo->lastInsertId();
            $created++;
        } else {
            $placementId = (int) $row['placement_id'];
            if ($patch) {
                $sets = [];
                $params = ['tenant_id' => $tenantId, 'id' => $placementId];
                foreach ($patch as $column => $value) {
                    $sets[] = "`{$column}` = :{$column}";
                    $params[$column] = $value;
                }
                $pdo->prepare(
                    'UPDATE placements SET ' . implode(', ', $sets) . '
                      WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL'
                )->execute($params);
            }
            $updated++;
        }

        $source = $row['source'];
        $protectedFields = array_column($row['protected_changes'], 'field');
        if (!empty($source['end_client_name']) && !in_array('end_client_name', $protectedFields, true)) {
            $companyId = companiesUpsertByName($tenantId, (string) $source['end_client_name'], [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['client']);
            $client = staffingClientEnsureForCompany(
                $tenantId,
                $companyId,
                (string) $source['end_client_name'],
                ['created_by_user_id' => $user['id'] ?? null]
            );
            $pdo->prepare(
                'UPDATE placements
                    SET end_client_name = :name,
                        end_client_company_id = :company_id,
                        client_id = :client_id
                  WHERE tenant_id = :tenant_id AND id = :id'
            )->execute([
                'name' => $client['name'],
                'company_id' => $client['company_id'],
                'client_id' => $client['client_id'],
                'tenant_id' => $tenantId,
                'id' => $placementId,
            ]);
        }

        if ($row['rate_plan']) {
            $rate = $row['rate_plan'];
            $payload = $rate['payload'];
            if ($rate['action'] === 'update_draft' && $rate['rate_id']) {
                $sets = [];
                $params = ['tenant_id' => $tenantId, 'id' => (int) $rate['rate_id']];
                foreach ($payload as $column => $value) {
                    $sets[] = "`{$column}` = :{$column}";
                    $params[$column] = $value;
                }
                $pdo->prepare(
                    'UPDATE placement_rates SET ' . implode(', ', $sets) . '
                      WHERE tenant_id = :tenant_id AND id = :id AND approved_at IS NULL'
                )->execute($params);
            } else {
                $payload['tenant_id'] = $tenantId;
                $payload['placement_id'] = $placementId;
                $payload['created_by_user_id'] = $user['id'] ?? null;
                $payload['ot_multiplier'] = 1.5;
                $payload['dt_multiplier'] = 2.0;
                $columns = array_keys($payload);
                $params = [];
                $placeholders = [];
                foreach ($columns as $column) {
                    $params[$column] = $payload[$column];
                    $placeholders[] = ':' . $column;
                }
                $pdo->prepare(
                    'INSERT INTO placement_rates (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $placeholders) . ')'
                )->execute($params);
            }
            $rateDrafts++;
        }

        $snapshot = array_merge($source, [
            '__cf_jobdiva_source_object' => 'assignment',
            '__cf_jobdiva_assignment_id' => $startId,
            '__cf_reconciliation_channel' => 'controlled_csv_dry_run',
        ]);
        mappingUpsert(
            $tenantId,
            'jobdiva',
            'placement',
            $startId,
            $placementId,
            $snapshot,
            'pull',
            isset($user['id']) ? (int) $user['id'] : null
        );
        $mappingWrites++;
        $economics = placementEconomicsReconcile($tenantId, $placementId);
        if (empty($economics['available'])) {
            throw new \RuntimeException(
                'Economic graph reconciliation failed for Start ID ' . $startId . ': '
                . implode('; ', $economics['errors'] ?? ['unknown error'])
            );
        }

        $after = placementAuditRow($placementId);
        placementsAudit('placement.jobdiva_reconciliation.applied', [
            'start_id' => $startId,
            'row_number' => $row['row_number'],
            'mode' => $row['is_create'] ? 'create' : 'update',
            'changed_fields' => array_values(array_unique(array_column($row['changes'], 'field'))),
            'protected_fields' => array_values(array_unique(array_column($row['protected_changes'], 'field'))),
            'rate_action' => $row['rate_plan']['action'] ?? null,
            'before' => $before,
            'after' => $after,
            'csv_sha256' => hash('sha256', $csv),
            'dry_run_token' => $plan['dry_run_token'],
        ], $placementId);
        $applied[] = ['start_id' => $startId, 'placement_id' => $placementId, 'mode' => $row['is_create'] ? 'create' : 'update'];
    }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_error('Reconciliation apply failed; no selected rows were written: ' . $e->getMessage(), 500);
}

api_ok([
    'ok' => true,
    'created' => $created,
    'updated' => $updated,
    'rate_drafts' => $rateDrafts,
    'mapping_writes' => $mappingWrites,
    'applied' => $applied,
    'message' => 'Selected exact-Start-ID changes applied. No records were deleted or archived.',
]);
