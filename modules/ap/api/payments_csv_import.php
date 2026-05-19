<?php
/**
 * AP module — payments CSV bulk import.
 *
 *   GET  /api/ap/payments_csv_import?action=template
 *   GET  /api/ap/payments_csv_import?action=sample
 *   POST /api/ap/payments_csv_import?action=inspect
 *   POST /api/ap/payments_csv_import?action=dry_run
 *   POST /api/ap/payments_csv_import?action=commit (+ ?skip_invalid=1)
 *   POST /api/ap/payments_csv_import?action=ai_suggest_map
 *
 * Bulk-loads historical AP payments. Bill allocations are NOT in scope
 * for the bulk-import — those need the secure Payment Detail UI where
 * the user can reconcile against open AP bills.
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/ap.php';

use Core\CsvImportService;

CsvImportService::registerSchema('ap_payments', [
    'fields' => [
        'vendor_name' => ['label' => 'Vendor name',  'required' => true],
        'pay_date'    => ['label' => 'Pay date',     'required' => true, 'type' => 'date'],
        'method'      => ['label' => 'Method',
                          'enum'  => ['ach','wire','check','card','cash','plaid','other']],
        'reference'   => ['label' => 'Reference'],
        'amount'      => ['label' => 'Amount',       'required' => true, 'type' => 'number'],
        'currency'    => ['label' => 'Currency'],
        'status'      => ['label' => 'Status',
                          'enum'  => ['draft','queued','sent','cleared','failed','void']],
        'cleared_at'  => ['label' => 'Cleared at',   'type' => 'date'],
        'sent_at'     => ['label' => 'Sent at',      'type' => 'date'],
        'notes'       => ['label' => 'Notes'],
    ],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'ap.payment.create');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ap_payments_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('ap_payments');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'ap.payment.create');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ap_payments_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('ap_payments', [
        ['vendor_name'=>'Northwind Cloud Services','pay_date'=>'2026-02-05','method'=>'ach','reference'=>'ACH-26020501','amount'=>5120,'currency'=>'USD','status'=>'cleared','cleared_at'=>'2026-02-07','notes'=>'Feb compute bill'],
        ['vendor_name'=>'Diego Ramirez (1099)','pay_date'=>'2026-02-14','method'=>'ach','reference'=>'ACH-26021402','amount'=>3990,'currency'=>'USD','status'=>'sent','sent_at'=>'2026-02-14'],
        ['vendor_name'=>'PG&E','pay_date'=>'2026-02-12','method'=>'ach','reference'=>'AUTOPAY','amount'=>418.55,'currency'=>'USD','status'=>'cleared','cleared_at'=>'2026-02-13','notes'=>'Utility autopay'],
    ]);
    exit;
}

if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'ap.payment.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('ap_payments', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'ap.payment.create');
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv); rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) { $row = fgetcsv($stream); if ($row === false) break; $samples[] = $row; }
    fclose($stream);

    $body       = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];
    $ins        = CsvImportService::inspect('ap_payments', $csv);

    try {
        api_ok(aiSuggestColumnMap([
            'feature_key'   => 'csv.mapping.ap_payments',
            'entity_label'  => 'AP Payments',
            'schema_fields' => $ins['fields'],
            'headers'       => $headers,
            'sample_rows'   => $samples,
            'already_mapped'=> $alreadyMap,
        ]));
    } catch (AIDisabledException $e) { api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503); }
    catch (\Throwable $e)            { api_error('AI suggestion failed: ' . $e->getMessage(), 502); }
}

if ($method === 'POST' && $action === 'dry_run') {
    rbac_legacy_require($user, 'ap.payment.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    api_ok(CsvImportService::dryRun('ap_payments', $csv, $columnMap));
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'ap.payment.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $skipInvalid = !empty($_GET['skip_invalid']);
    $columnMap   = CsvImportService::readRequestColumnMap();

    $result = CsvImportService::commit('ap_payments', $csv, function (array $row) use ($user) {
        $amount = (float) $row['amount'];
        return scopedInsert('ap_payments', [
            'vendor_name'        => $row['vendor_name'],
            'pay_date'           => $row['pay_date'],
            'method'             => $row['method']     ?? 'ach',
            'reference'          => $row['reference']  ?? null,
            'amount'             => $amount,
            'currency'           => $row['currency']   ?? 'USD',
            'unallocated_amount' => $amount,  // imported payments start fully unallocated
            'status'             => $row['status']     ?? 'cleared',  // historical → assume cleared
            'cleared_at'         => $row['cleared_at'] ?? null,
            'sent_at'            => $row['sent_at']    ?? null,
            'notes'              => $row['notes']      ?? null,
            'created_by_user_id' => $user['id']        ?? null,
        ]);
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    apAudit('ap.payment.csv_imported', [
        'imported' => $result['imported_count'],
        'skipped'  => $result['skipped_count'],
        'errors'   => count($result['errors']),
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|dry_run|commit|ai_suggest_map', 400);
