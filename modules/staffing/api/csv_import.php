<?php
/**
 * Staffing module — CSV bulk client import.
 *
 *   GET  /api/staffing/csv_import?action=template
 *   POST /api/staffing/csv_import?action=dry_run
 *   POST /api/staffing/csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX):
 * every primary-entity module MUST expose a CSV import flow.
 *
 * Scope: imports staffing_clients rows. MSA dates and back-billing must
 * be entered via the Clients UI (out of scope for bulk).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/client_write.php';
require_once __DIR__ . '/../lib/client_audit.php';

use Core\CsvImportService;

CsvImportService::registerSchema('staffing_clients', [
    'fields' => [
        // client_id (staffing_clients.id) wins over fuzzy name match for
        // update-existing rows.
        'client_id'             => ['label' => 'Client ID',             'type' => 'integer', 'reference_type' => 'client', 'aliases' => ['client_number', 'Client Number']],
        'name'                  => ['label' => 'Client name',           'required' => true],
        // external_id + source_system: stable per-row id from the
        // source-of-truth ATS/CRM (JobDiva client id, Bullhorn id,
        // HubSpot company id, etc.). When supplied, becomes the upsert
        // key so re-uploading the same export doesn't duplicate firms.
        'external_id'           => ['label' => 'External ID (audit / integration)'],
        'source_system'         => ['label' => 'Source system',
                                    'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'legal_name'            => ['label' => 'Legal name'],
        'industry'              => ['label' => 'Industry'],
        'primary_contact_name'  => ['label' => 'Primary contact name'],
        'primary_contact_email' => ['label' => 'Primary contact email', 'type' => 'email'],
        'primary_contact_phone' => ['label' => 'Primary contact phone'],
        'billing_address_line1' => ['label' => 'Billing address line 1'],
        'billing_address_line2' => ['label' => 'Billing address line 2'],
        'billing_city'          => ['label' => 'Billing city'],
        'billing_state'         => ['label' => 'Billing state'],
        'billing_postal_code'   => ['label' => 'Billing postal code'],
        'billing_country'       => ['label' => 'Billing country (2-letter)'],
        'payment_terms_days'    => ['label' => 'Payment terms (days)',  'type' => 'number'],
        'status'                => ['label' => 'Status',
                                    'enum'  => ['active','prospect','on_hold','inactive','closed']],
        'notes'                 => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['external_id', 'name'],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$workspaceId=(int)$ctx['tenant_id'];
$tid    = staffingClientCatalogTenantId($workspaceId);
setRequestModuleScope('placements');
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    api_require_legacy_permission($ctx, 'placements.view');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('staffing_clients');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    api_require_legacy_permission($ctx, 'placements.view');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('staffing_clients', $samples['staffing_clients'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    api_require_legacy_permission($ctx, 'placements.view');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('staffing_clients', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    api_require_legacy_permission($ctx, 'placements.view');
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

    // Read up to 3 sample rows alongside the header.
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

    $body         = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap   = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];

    $ins = CsvImportService::inspect('staffing_clients', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.staffing_clients',
            'entity_label'   => 'Clients',
            'schema_fields'  => $ins['fields'],
            'headers'        => $headers,
            'sample_rows'    => $samples,
            'already_mapped' => $alreadyMap,
        ]);
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (\Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
    api_ok($result);
}
if ($method === 'POST' && $action === 'dry_run') {
    api_require_legacy_permission($ctx, 'placements.view');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('staffing_clients', $csv, $columnMap);

    $scope=companyRelationshipScope(getDB(),$workspaceId,'placements',false);
    foreach($result['rows'] as$number=>$row){
        if(isset($result['errors'][$number]))continue;
        try{staffingClientImportPlan(getDB(),$scope,$row,!empty($_GET['update_existing']));}
        catch(Throwable $e){$result['errors'][$number][]=$e->getMessage();}
    }
    $result['error_count']=count($result['errors']);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    api_require_legacy_permission($ctx, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $result = CsvImportService::commit('staffing_clients',$csv,
        static fn(array $row):int=>staffingClientImportNative($workspaceId,$row,(int)$user['id'],$updateExisting),
        ['skip_invalid'=>$skipInvalid,'column_map'=>$columnMap,'atomic'=>true,'row_atomic'=>true]);

    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
