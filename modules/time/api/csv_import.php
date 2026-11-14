<?php
/**
 * Time API - CSV bulk import.
 *
 * Canonical input is one row with Placement ID, Work date, and Hours. The
 * displayed CoreFlux ID (PL-####) and the legacy bare integer are both
 * accepted. The date may be a daily work date or an upstream timesheet's
 * week-ending date; omitted time type defaults to regular.
 * The placement supplies the person; the importer creates/links the weekly
 * staffing timesheet and time period so imported rows appear everywhere.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/time.php';
require_once __DIR__ . '/../../staffing/lib/timesheets.php';

use Core\CsvImportService;

require_once __DIR__.'/../lib/csv_intake.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method==='GET' && $action==='external_approval_template') {
    timeCsvRequireUpload($user);timeCsvStream('time_external_approval.csv',['Placement ID','Work date','Hours','External approver email','External approval time','External approval reference','Source system','External ID (source row)']);
}

if ($method === 'GET' && $action === 'template') {
    timeCsvRequireUpload($user);
    timeCsvStream('time_import_by_placement.csv', [
        'Placement ID', 'Work date', 'Hours',
    ]);
}

if ($method === 'GET' && $action === 'sample') {
    timeCsvRequireUpload($user);
    // This endpoint intentionally uses live placement IDs instead of the
    // generic CsvImportService::buildSample fixture, so the downloaded sample
    // validates in the tenant that requested it.
    $references = timeCsvReferenceRows();
    $sampleRows = [];
    foreach (array_slice($references, 0, 2) as $i => $placement) {
        $date = date('Y-m-d');
        if (!empty($placement['start_date']) && $date < $placement['start_date']) $date = $placement['start_date'];
        if (!empty($placement['end_date']) && $date > $placement['end_date']) $date = $placement['end_date'];
        $sampleRows[] = [
            timeCsvPlacementCode($placement['placement_id']), $date, $i === 0 ? '8.00' : '7.50',
        ];
    }
    if (!$sampleRows) {
        $sampleRows[] = ['PL-1001', date('Y-m-d'), '8.00'];
    }
    timeCsvStream('time_import_sample.csv', [
        'Placement ID', 'Work date', 'Hours',
    ], $sampleRows);
}

if ($method === 'GET' && $action === 'placement_reference') {
    timeCsvRequireUpload($user);
    $rows = array_map(static fn (array $row): array => [
        timeCsvPlacementCode($row['placement_id']),
        $row['placement_external_id'],
        $row['person_name'],
        $row['person_email'],
        $row['title'],
        $row['end_client_name'],
        $row['status'],
        $row['start_date'],
        $row['end_date'],
    ], timeCsvReferenceRows());
    timeCsvStream('placement_id_reference_' . date('Y-m-d') . '.csv', [
        'Placement ID', 'Source placement ID', 'Person name', 'Person email',
        'Title', 'End client', 'Status', 'Start date', 'End date',
    ], $rows);
}

if ($method === 'POST' && $action === 'inspect') {
    timeCsvRequireUpload($user);
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('time', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    timeCsvRequireUpload($user);
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $row = fgetcsv($stream);
        if ($row === false) break;
        $samples[] = $row;
    }
    fclose($stream);

    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];
    $ins = CsvImportService::inspect('time', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.time',
            'entity_label'   => 'Time entries',
            'schema_fields'  => $ins['fields'],
            'headers'        => $headers,
            'sample_rows'    => $samples,
            'already_mapped' => $alreadyMap,
        ]);
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
    api_ok($result);
}

if ($method==='GET' && $action==='source') {
    api_require_legacy_permission($ctx,'time.view');
    try {
        timeCsvRequireSchema();$q=getDB()->prepare('SELECT source_ct,source_hash FROM time_csv_imports WHERE tenant_id=? AND id=?');
        $q->execute([(int)$ctx['tenant_id'],(int)($_GET['id']??0)]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_error('Import source not found in this workspace.',404);
        $source=decryptField(base64_decode($row['source_ct'],true));
        if (!is_string($source) || !hash_equals($row['source_hash'],hash('sha256',$source))) throw new RuntimeException('Import source verification failed.',503);
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="time-import-source.csv"');header('Cache-Control: private, no-store');header('X-CoreFlux-Tenant-Id: '.(int)$ctx['tenant_id']);echo $source;exit;
    } catch(Throwable $e) {timeCsvApiFailure($e);}
}
if ($method==='POST' && in_array($action,['dry_run','commit'],true)) {
    timeCsvRequireUpload($user);
    $csv=CsvImportService::readRequestCsv();if (!$csv) api_error('No CSV body received',400);
    $options=['external_approval'=>!empty($_GET['already_approved']),'skip_invalid'=>!empty($_GET['skip_invalid']),'update_existing'=>!empty($_GET['update_existing'])];
    $body=json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($body)) $body=$_POST;
    $revision=$body['review_revision']??($_SERVER['HTTP_X_TIME_REVIEW_REVISION']??null);
    $map=CsvImportService::readRequestColumnMap();
    try {
        if ($action==='dry_run') api_ok(timeCsvImportPreview((int)$ctx['tenant_id'],(int)$user['id'],$csv,$map,$options));
        api_ok(timeCsvImportCommit((int)$ctx['tenant_id'],(int)$user['id'],$csv,$map,$options,is_string($revision)?$revision:null));
    } catch(Throwable $e) {timeCsvApiFailure($e);}
}
api_error('Unknown action. Use template, sample, placement_reference, inspect, dry_run, commit or source.',400);

function timeCsvApiFailure(Throwable $e): void {
    $code=(int)$e->getCode();
    if (!($e instanceof PDOException) && ($e instanceof RuntimeException || $e instanceof InvalidArgumentException)) api_error($e->getMessage(),in_array($code,[403,404,409,422,503],true)?$code:422);
    error_log('Time CSV import failed: '.get_class($e));api_error('The import could not be recorded. Review its saved result before trying again.',503);
}
