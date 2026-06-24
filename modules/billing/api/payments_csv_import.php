<?php
/**
 * Billing module — payments (AR receipts) CSV bulk import.
 *
 *   GET  /api/billing/payments_csv_import?action=template
 *   GET  /api/billing/payments_csv_import?action=sample
 *   POST /api/billing/payments_csv_import?action=inspect
 *   POST /api/billing/payments_csv_import?action=dry_run
 *   POST /api/billing/payments_csv_import?action=commit (+ ?skip_invalid=1)
 *   POST /api/billing/payments_csv_import?action=ai_suggest_map
 *
 * Invoice allocations stay out of scope — done via the Payment Detail UI
 * where the user can match against open invoices.
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';

use Core\CsvImportService;

CsvImportService::registerSchema('billing_payments', [
    'fields' => [
        // payment_id wins over the (client_name + received_at + reference)
        // composite for update-existing matching. Optional; leave blank
        // for new payments.
        'payment_id'  => ['label' => 'Payment ID',  'type' => 'integer'],
        'client_name' => ['label' => 'Client name', 'required' => true],
        'received_at' => ['label' => 'Received at', 'required' => true, 'type' => 'date'],
        'method'      => ['label' => 'Method',
                          'enum'  => ['ach','wire','check','card','cash','other']],
        'reference'   => ['label' => 'Reference'],
        // external_id + source_system: stable per-row id from the
        // source-of-truth receipts feed (Mercury/Plaid txn id, QBO
        // receipt id, lockbox file id, etc.). When supplied, becomes
        // the upsert key so re-uploading the same export does not
        // double-credit the client.
        'external_id'  => ['label' => 'External ID (audit / integration)'],
        'source_system'=> ['label' => 'Source system',
                          'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'amount'      => ['label' => 'Amount',      'required' => true, 'type' => 'number'],
        'currency'    => ['label' => 'Currency'],
        'notes'       => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['external_id'],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'billing.payments.record');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="billing_payments_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('billing_payments');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'billing.payments.record');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="billing_payments_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('billing_payments', [
        ['client_name'=>'Globex Industries','received_at'=>'2026-02-12','method'=>'ach','reference'=>'WIRE-26021201','amount'=>6020,'currency'=>'USD','notes'=>'INV-4001 paid early'],
        ['client_name'=>'Initech','received_at'=>'2026-02-25','method'=>'check','reference'=>'CHK-0917','amount'=>4875,'currency'=>'USD','notes'=>'INV-4002'],
        ['client_name'=>'Wayne Enterprises','received_at'=>'2026-02-28','method'=>'wire','reference'=>'WIRE-26022801','amount'=>14000,'currency'=>'USD'],
    ]);
    exit;
}

if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'billing.payments.record');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('billing_payments', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'billing.payments.record');
    rbac_legacy_require($user, 'ai.use');
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
    $ins        = CsvImportService::inspect('billing_payments', $csv);

    try {
        api_ok(aiSuggestColumnMap([
            'feature_key'   => 'csv.mapping.billing_payments',
            'entity_label'  => 'Billing Payments',
            'schema_fields' => $ins['fields'],
            'headers'       => $headers,
            'sample_rows'   => $samples,
            'already_mapped'=> $alreadyMap,
        ]));
    } catch (AIDisabledException $e) { api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503); }
    catch (\Throwable $e)            { api_error('AI suggestion failed: ' . $e->getMessage(), 502); }
}

if ($method === 'POST' && $action === 'dry_run') {
    rbac_legacy_require($user, 'billing.payments.record');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    api_ok(CsvImportService::dryRun('billing_payments', $csv, $columnMap));
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'billing.payments.record');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $skipInvalid = !empty($_GET['skip_invalid']);
    $columnMap   = CsvImportService::readRequestColumnMap();

    $result = CsvImportService::commit('billing_payments', $csv, function (array $row) use ($user) {
        $amount = (float) $row['amount'];
        $externalId   = isset($row['external_id'])   && $row['external_id']   !== '' ? (string) $row['external_id']   : null;
        $sourceSystem = isset($row['source_system']) && $row['source_system'] !== '' ? (string) $row['source_system'] : 'manual';

        // Idempotent re-import: when external_id is supplied, update the
        // existing (tenant, source_system, external_id) row instead of
        // double-crediting the client. Falls through to a plain INSERT
        // for manual one-offs.
        if ($externalId !== null) {
            $existing = scopedFind(
                'SELECT id FROM billing_payments
                  WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e',
                ['s' => $sourceSystem, 'e' => $externalId]
            );
            if ($existing) {
                scopedUpdate('billing_payments', (int) $existing['id'], [
                    'client_name'        => $row['client_name'],
                    'received_at'        => $row['received_at'],
                    'method'             => $row['method']    ?? 'ach',
                    'reference'          => $row['reference'] ?? null,
                    'amount'             => $amount,
                    'currency'           => $row['currency']  ?? 'USD',
                    'notes'              => $row['notes']     ?? null,
                ]);
                return (int) $existing['id'];
            }
        }

        return scopedInsert('billing_payments', [
            'client_name'        => $row['client_name'],
            'received_at'        => $row['received_at'],
            'method'             => $row['method']    ?? 'ach',
            'reference'          => $row['reference'] ?? null,
            'external_id'        => $externalId,
            'source_system'      => $sourceSystem,
            'amount'             => $amount,
            'currency'           => $row['currency']  ?? 'USD',
            'unallocated_amount' => $amount,
            'notes'              => $row['notes']     ?? null,
            'created_by_user_id' => $user['id']       ?? null,
        ]);
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|dry_run|commit|ai_suggest_map', 400);
