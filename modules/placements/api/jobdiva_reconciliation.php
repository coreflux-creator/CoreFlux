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
$sourceWorkspace = (int)$ctx['tenant_id'];

if ($action === 'stored_preview') {
    rbac_legacy_require($user, 'placements.financials.view');
    $page = max(1, (int) ($body['page'] ?? 1));
    $perPage = max(1, min(20, (int) ($body['per_page'] ?? 20)));
    try{$plan = jobdivaStoredAssignmentProjectionPlan(
        $sourceWorkspace,
        $perPage,
        [],
        false,
        ($page - 1) * $perPage
    );}catch(PDOException $e){error_log('[stored JobDiva preview] '.$e->getMessage());api_error('The source records are busy or changed. Refresh the preview.',409);}
    catch(RuntimeException $e){api_error($e->getMessage(),409);}
    api_ok([
        'summary' => $plan['summary'],
        'rows' => $plan['public_rows'],
        'dry_run_token' => $plan['dry_run_token'],
        'pagination' => $plan['pagination'] + ['page' => $page, 'per_page' => $perPage],
        'safety' => $plan['safety'],
        'permissions' => ['apply' => rbac_legacy_can($user, 'placements.financials.manage')],
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
    $page = max(1, (int) ($body['page'] ?? 1));
    $perPage = max(1, min(20, (int) ($body['per_page'] ?? 20)));
    try {
        $result = jobdivaApplyStoredAssignmentProjection(
            $sourceWorkspace,
            isset($user['id']) ? (int) $user['id'] : null,
            $selected,
            (string) ($body['dry_run_token'] ?? ''),
            $perPage,
            ($page - 1) * $perPage
        );
        api_ok($result + [
            'ok' => true,
            'message' => 'Selected stored JobDiva assignments were projected through the canonical CoreFlux graph.',
        ]);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\PDOException $e) {
        error_log('[stored JobDiva reconciliation] ' . $e->getMessage());
        api_error('The reviewed records are busy or changed. Refresh the preview and try again.', 409);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 409);
    } catch (\Throwable $e) {
        api_error('Stored assignment projection failed; no selected rows were written: ' . $e->getMessage(), 500);
    }
}

require_once __DIR__.'/../lib/jobdiva_csv_write.php';
$csv=(string)($body['csv']??'');
if($csv==='')api_error('No CSV body received',400);
if(strlen($csv)>25*1024*1024||substr_count($csv,"\n")>5001)api_error('CSV is limited to 5,000 rows and 25 MB',413);
if($action==='inspect'){
    try{api_ok(jobdivaReconciliationInspect($csv));}
    catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
}
if($action==='dry_run')rbac_legacy_require($user,'placements.financials.view');
elseif($action==='apply')rbac_legacy_require($user,'placements.financials.manage');
else api_error('Unknown action',404);
$columnMap=is_array($body['column_map']??null)?$body['column_map']:null;
try{
    if($action==='dry_run'){
        $plan=jobdivaReconciliationBuildPlan($pdo,$sourceWorkspace,null,$csv,$columnMap);
        api_ok(['summary'=>$plan['summary'],'rows'=>$plan['public_rows'],'headers'=>$plan['headers'],
            'column_map'=>$plan['column_map'],'mapping_errors'=>$plan['mapping_errors'],'dry_run_token'=>$plan['dry_run_token'],
            'safety'=>$plan['safety'],'permissions'=>['apply'=>rbac_legacy_can($user,'placements.financials.manage')]]);
    }
    if(($body['confirm']??'')!=='APPLY_EXACT_START_ID_RECONCILIATION')api_error('Explicit reconciliation confirmation is required',422);
    $selected=is_array($body['selected_start_ids']??null)?$body['selected_start_ids']:[];
    $result=jobdivaReconciliationApply($pdo,$sourceWorkspace,isset($user['id'])?(int)$user['id']:null,$csv,$columnMap,$selected,(string)($body['dry_run_token']??''));
    api_ok($result+['ok'=>true,'message'=>'Selected exact-Start-ID changes applied. No records were deleted or archived.']);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(PDOException $e){error_log('[JobDiva CSV reconciliation] '.$e->getMessage());api_error('The reviewed records are busy or changed. Refresh the CSV preview.',409);}
catch(RuntimeException $e){api_error($e->getMessage(),409);}
