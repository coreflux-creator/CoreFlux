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
 * Bulk-loads AP payment drafts. Bill allocations and lifecycle changes are
 * intentionally handled by the payment workflow so a CSV cannot mark bills
 * paid or bypass release and ledger controls.
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
        // payment_id wins over the (vendor_name + pay_date + reference)
        // composite for update-existing matching. Optional; leave blank
        // for new payments.
        'payment_id'  => ['label' => 'Payment ID',   'type' => 'integer'],
        'entity_id'   => ['label' => 'Entity ID',    'type' => 'integer'],
        'vendor_name' => ['label' => 'Vendor name',  'required' => true],
        'pay_date'    => ['label' => 'Pay date',     'required' => true, 'type' => 'date'],
        'method'      => ['label' => 'Method',
                          'enum'  => ['ach','wire','check','card','cash','plaid','mercury','other']],
        'reference'   => ['label' => 'Reference'],
        // external_id + source_system: stable per-row id from the
        // source-of-truth payment system (Mercury/Plaid txn id, QBO
        // bill payment id, etc.). When supplied, becomes the upsert
        // key so re-uploading the same export does not duplicate.
        'external_id'  => ['label' => 'External ID (audit / integration)'],
        'source_system'=> ['label' => 'Source system',
                          'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'amount'      => ['label' => 'Amount',       'required' => true, 'type' => 'number'],
        'currency'    => ['label' => 'Currency'],
        'status'      => ['label' => 'Status',
                          'enum'  => ['draft','queued','sent','cleared','failed','void']],
        'cleared_at'  => ['label' => 'Cleared at',   'type' => 'date'],
        'sent_at'     => ['label' => 'Sent at',      'type' => 'date'],
        'notes'       => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['external_id'],
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
        ['vendor_name'=>'Northwind Cloud Services','pay_date'=>'2026-02-05','method'=>'ach','reference'=>'ACH-26020501','amount'=>5120,'currency'=>'USD','status'=>'draft','notes'=>'Feb compute bill'],
        ['vendor_name'=>'Diego Ramirez (1099)','pay_date'=>'2026-02-14','method'=>'ach','reference'=>'ACH-26021402','amount'=>3990,'currency'=>'USD','status'=>'draft'],
        ['vendor_name'=>'PG&E','pay_date'=>'2026-02-12','method'=>'ach','reference'=>'AUTOPAY','amount'=>418.55,'currency'=>'USD','status'=>'draft','notes'=>'Utility autopay'],
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
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);
    $columnMap      = CsvImportService::readRequestColumnMap();
    $created = 0;
    $updated = 0;

    $result = CsvImportService::commit('ap_payments', $csv, function (array $row) use ($user, $updateExisting, &$created, &$updated) {
        $amount = (float) $row['amount'];
        $externalId = isset($row['external_id']) && trim((string) $row['external_id']) !== ''
            ? trim((string) $row['external_id'])
            : null;
        $sourceSystemInput = isset($row['source_system']) ? trim((string) $row['source_system']) : '';
        $sourceSystem = $sourceSystemInput !== '' ? $sourceSystemInput : 'manual';
        $paymentId = isset($row['payment_id']) && $row['payment_id'] !== '' ? (int) $row['payment_id'] : 0;
        $requestedStatus = isset($row['status']) ? trim((string) $row['status']) : '';
        if (($requestedStatus !== '' && $requestedStatus !== 'draft')
            || !empty($row['sent_at']) || !empty($row['cleared_at'])) {
            throw new RuntimeException('CSV import creates payment drafts only. Release and clear payments through the payment workflow.');
        }
        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            // Stable internal ID wins. External-system identity is the
            // fallback for idempotent integration re-imports.
            $existing = null;
            if ($paymentId > 0) {
                $existing = scopedFind(
                    'SELECT id, status, amount, unallocated_amount, external_id, source_system,
                            journal_entry_id, sent_at, cleared_at, plaid_transfer_id,
                            rail_external_ref, rail_status, rail_originated_at
                       FROM ap_payments
                      WHERE tenant_id = :tenant_id AND id = :id
                      FOR UPDATE',
                    ['id' => $paymentId]
                );
                if (!$existing) {
                    throw new RuntimeException("Payment ID {$paymentId} was not found in this workspace");
                }
            } elseif ($externalId !== null) {
                $existing = scopedFind(
                    'SELECT id, status, amount, unallocated_amount, external_id, source_system,
                            journal_entry_id, sent_at, cleared_at, plaid_transfer_id,
                            rail_external_ref, rail_status, rail_originated_at
                       FROM ap_payments
                      WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e
                      FOR UPDATE',
                    ['s' => $sourceSystem, 'e' => $externalId]
                );
            }

            if ($existing) {
                if (!$updateExisting) {
                    throw new RuntimeException('Payment #' . $existing['id'] . ' already exists; enable Update matching editable records to change it');
                }
                $allocStmt = $pdo->prepare('SELECT COUNT(*) FROM ap_payment_allocations WHERE payment_id = :id');
                $allocStmt->execute(['id' => (int) $existing['id']]);
                $hasAllocations = (int) $allocStmt->fetchColumn() > 0;
                $hasDownstreamActivity = !empty($existing['journal_entry_id'])
                    || !empty($existing['sent_at'])
                    || !empty($existing['cleared_at'])
                    || !empty($existing['plaid_transfer_id'])
                    || !empty($existing['rail_external_ref'])
                    || !empty($existing['rail_status'])
                    || !empty($existing['rail_originated_at']);
                if (($existing['status'] ?? '') !== 'draft' || $hasAllocations || $hasDownstreamActivity) {
                    throw new RuntimeException('Only unallocated, unposted, undispatched AP payment drafts can be updated by CSV');
                }
                scopedUpdate('ap_payments', (int) $existing['id'], [
                    'entity_id'          => !empty($row['entity_id']) ? (int) $row['entity_id'] : null,
                    'vendor_name'        => $row['vendor_name'],
                    'pay_date'           => $row['pay_date'],
                    'method'             => $row['method']     ?? 'ach',
                    'reference'          => $row['reference']  ?? null,
                    'external_id'        => $externalId ?? ($existing['external_id'] ?? null),
                    'source_system'      => $sourceSystemInput !== '' ? $sourceSystem : ($existing['source_system'] ?? 'manual'),
                    'amount'             => $amount,
                    'currency'           => $row['currency']   ?? 'USD',
                    'unallocated_amount' => $amount,
                    'notes'              => $row['notes']      ?? null,
                ]);
                $updated++;
                $pdo->commit();
                return (int) $existing['id'];
            }

            $id = scopedInsert('ap_payments', [
                'entity_id'          => !empty($row['entity_id']) ? (int) $row['entity_id'] : null,
                'vendor_name'        => $row['vendor_name'],
                'pay_date'           => $row['pay_date'],
                'method'             => $row['method']     ?? 'ach',
                'reference'          => $row['reference']  ?? null,
                'external_id'        => $externalId,
                'source_system'      => $sourceSystem,
                'amount'             => $amount,
                'currency'           => $row['currency']   ?? 'USD',
                'unallocated_amount' => $amount,
                'status'             => 'draft',
                'cleared_at'         => null,
                'sent_at'            => null,
                'notes'              => $row['notes']      ?? null,
                'created_by_user_id' => $user['id']        ?? null,
            ]);
            $created++;
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    $result['created_count'] = $created;
    $result['updated_count'] = $updated;
    $result['update_existing'] = $updateExisting;

    apAudit('ap.payment.csv_imported', [
        'imported' => $result['imported_count'],
        'created'  => $created,
        'updated'  => $updated,
        'skipped'  => $result['skipped_count'],
        'errors'   => count($result['errors']),
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|dry_run|commit|ai_suggest_map', 400);
