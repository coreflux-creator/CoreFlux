<?php
/**
 * Billing module — invoices CSV bulk import (multi-line).
 *
 *   GET  /api/billing/csv_import?action=template
 *   POST /api/billing/csv_import?action=dry_run
 *   POST /api/billing/csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * Same pattern as AP bills_csv_import: header + line items in one CSV,
 * rows grouped by `invoice_number`. First row of each group must carry
 * header fields (client_name, dates). Subsequent rows only need line_*.
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX).
 *
 * Imports invoices in DRAFT status. Approval + sending stays a deliberate
 * human action in the existing Invoice detail UI.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/accounting/csv_document_entity.php';
require_once __DIR__ . '/../lib/billing.php';

use Core\CsvImportService;

CsvImportService::registerSchema('billing_invoices', [
    'fields' => [
        // invoice_id wins over invoice_number for update-existing
        // matching — copy from the Invoices list UI (click-to-copy
        // <IdBadge prefix="INV" />). Optional; leave blank for new
        // invoices.
        'invoice_id'       => ['label' => 'Invoice ID',      'type' => 'integer'],
        'invoice_number'   => ['label' => 'Invoice #',       'required' => true],
        // external_id + source_system: correlate back to the system of
        // record (QBO, Zoho, etc.). When supplied, becomes the upsert
        // key so re-imports don't duplicate invoices.
        'external_id'      => ['label' => 'External ID (audit / integration)'],
        'source_system'    => ['label' => 'Source system',
                               'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'record_status'    => ['label' => 'Record status (read only)'],
        'amount_paid'      => ['label' => 'Amount paid (read only)', 'type' => 'number'],
        'client_name'      => ['label' => 'Client name'],
        'entity_code'      => ['label' => 'Entity code'],
        'issue_date'       => ['label' => 'Issue date',      'type' => 'date'],
        'due_date'         => ['label' => 'Due date',        'type' => 'date'],
        'period_start'     => ['label' => 'Period start',    'type' => 'date'],
        'period_end'       => ['label' => 'Period end',      'type' => 'date'],
        'currency'         => ['label' => 'Currency'],
        'po_number'        => ['label' => 'PO number'],
        'aggregation'      => ['label' => 'Aggregation',
                               'enum'  => ['per_placement','per_client']],
        'notes_external'   => ['label' => 'Notes (external)'],
        'line_id'          => ['label' => 'Line ID (read only)', 'type' => 'integer'],
        'line_no'          => ['label' => 'Line #',           'type' => 'number'],
        'line_description' => ['label' => 'Line description'],
        'line_quantity'    => ['label' => 'Line quantity',    'type' => 'number'],
        'line_unit'        => ['label' => 'Line unit'],
        'line_unit_price'  => ['label' => 'Line unit price',  'type' => 'number'],
        'line_subtotal'    => ['label' => 'Line subtotal',    'type' => 'number'],
        'line_tax_amount'  => ['label' => 'Line tax amount',  'type' => 'number'],
        'line_total'       => ['label' => 'Line total',       'type' => 'number'],
    ],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('billing_invoices');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('billing_invoices', $samples['billing_invoices'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('billing_invoices', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'billing.invoice.draft');
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

    $ins = CsvImportService::inspect('billing_invoices', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.billing_invoices',
            'entity_label'   => 'AR Invoices',
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
    rbac_legacy_require($user, 'billing.invoice.draft');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $review = accountingCsvReviewDocumentGroups(
        CsvImportService::dryRun('billing_invoices', $csv, $columnMap),
        accountingCsvDocumentEntities(getDB(), $tid),
        'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date']
    );
    api_ok($review['result']);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $review = accountingCsvReviewDocumentGroups(
        CsvImportService::dryRun('billing_invoices', $csv, $columnMap),
        accountingCsvDocumentEntities(getDB(), $tid),
        'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date']
    );
    $dry = $review['result'];
    if (!empty($dry['blocking_error']) || (!$skipInvalid && $dry['error_count'] > 0)) {
        api_ok([
            'imported_count' => 0, 'skipped_count' => $dry['groups'],
            'group_count' => $dry['groups'],
            'row_count' => count($dry['rows']), 'imported_row_count' => 0,
            'skipped_row_count' => count($dry['rows']),
            'errors' => $dry['errors'],
            'message' => $dry['blocking_error'] ?? 'Validation errors present; pass skip_invalid=1 to import valid documents only.',
        ]);
    }

    $groups = $review['groups'];

    $pdo = getDB();
    $imported = 0;
    $created  = 0;
    $updated  = 0;
    $importedRows = 0;
    $errors   = $dry['errors'];
    $ids      = [];

    foreach ($groups as $inv => $numberedRows) {
        if (count(array_intersect(array_keys($numberedRows), array_keys($dry['errors']))) > 0) continue;
        $rows = array_values($numberedRows);
        $header = $rows[0];
        $entity = $review['entities'][$inv];
        $externalId   = isset($header['external_id'])   && $header['external_id']   !== '' ? (string) $header['external_id']   : null;
        $sourceSystem = isset($header['source_system']) && $header['source_system'] !== '' ? (string) $header['source_system'] : 'manual';

        // Stable ID wins, then the external-system key, then invoice number.
        // Existing rows may only be replaced while they are unpaid, unposted
        // manual drafts. Posted accounting history is never CSV-mutable.
        $existing = null;
        $invoiceId = isset($header['invoice_id']) && $header['invoice_id'] !== '' ? (int) $header['invoice_id'] : 0;
        if ($invoiceId > 0) {
            $existing = scopedFind(
                'SELECT id, entity_id, status, journal_entry_id, amount_paid
                   FROM billing_invoices
                  WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $invoiceId]
            );
            if (!$existing) {
                $errors['__invoice_' . $inv] = ["Invoice ID {$invoiceId} was not found in this workspace"];
                continue;
            }
        } elseif ($externalId !== null) {
            $existing = scopedFind(
                'SELECT id, entity_id, status, journal_entry_id, amount_paid
                   FROM billing_invoices
                  WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e',
                ['s' => $sourceSystem, 'e' => $externalId]
            );
        }
        if (!$existing) {
            $existing = scopedFind(
                'SELECT id, entity_id, status, journal_entry_id, amount_paid
                   FROM billing_invoices
                  WHERE tenant_id = :tenant_id AND invoice_number = :n',
                ['n' => $inv]
            );
        }
        if ($existing) {
            if (!empty($existing['entity_id']) && (int) $existing['entity_id'] !== $entity['id']) {
                $errors['__invoice_' . $inv] = ['CSV cannot move an invoice to another legal entity'];
                continue;
            }
            if (!$updateExisting) {
                $errors['__invoice_' . $inv] = ['Invoice # ' . $inv . ' already exists; enable Update matching editable records to change its draft'];
                continue;
            }
            if (($existing['status'] ?? '') !== 'draft'
                || !empty($existing['journal_entry_id'])
                || abs((float) ($existing['amount_paid'] ?? 0)) >= 0.005) {
                $errors['__invoice_' . $inv] = ['Only unpaid, unposted draft invoices can be updated by CSV'];
                continue;
            }
            $sourceLines = $pdo->prepare(
                'SELECT COUNT(*) FROM billing_invoice_lines
                  WHERE invoice_id = :id AND source_type <> "manual"'
            );
            $sourceLines->execute(['id' => (int) $existing['id']]);
            if ((int) $sourceLines->fetchColumn() > 0) {
                $errors['__invoice_' . $inv] = ['Time-sourced invoice lines must be rebuilt from Time settlement, not overwritten by CSV'];
                continue;
            }
        }

        $subtotal = 0; $tax = 0; $total = 0;
        foreach ($rows as $r) {
            $amounts = accountingCsvDocumentLineAmounts($r);
            $subtotal += $amounts['subtotal'];
            $tax      += $amounts['tax'];
            $total    += $amounts['total'];
        }
        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);
        $total = round($total, 2);
        $wasUpdate = (bool) $existing;

        $pdo->beginTransaction();
        try {
            $headerPayload = [
                'invoice_number' => $inv,
                'external_id'    => $externalId,
                'source_system'  => $sourceSystem,
                'client_name'    => (string) $header['client_name'],
                'entity_id'      => $entity['id'],
                'currency'       => $entity['base_currency'],
                'issue_date'     => $header['issue_date'],
                'due_date'       => $header['due_date'],
                'period_start'   => $header['period_start'] ?? null,
                'period_end'     => $header['period_end']   ?? null,
                'subtotal'       => $subtotal,
                'tax_total'      => $tax,
                'total'          => $total,
                'amount_due'     => $total,
                'po_number'      => $header['po_number']    ?? null,
                'aggregation'    => $header['aggregation']  ?? 'per_client',
                'notes_external' => $header['notes_external'] ?? null,
            ];
            if ($existing) {
                $invId = (int) $existing['id'];
                scopedUpdate('billing_invoices', $invId, $headerPayload);
                $pdo->prepare('DELETE FROM billing_invoice_lines WHERE invoice_id = :id')->execute(['id' => $invId]);
            } else {
                $invId = scopedInsert('billing_invoices', $headerPayload + [
                    'status'             => 'draft',
                    'created_by_user_id' => $user['id'] ?? null,
                ]);
            }

            $lineNo = 0;
            foreach ($rows as $r) {
                $lineNo++;
                $amounts = accountingCsvDocumentLineAmounts($r);
                $pdo->prepare(
                    'INSERT INTO billing_invoice_lines
                       (invoice_id, line_no, source_type, description, quantity, unit, unit_price,
                        subtotal, tax_rate_pct, tax_amount, total)
                     VALUES
                       (:invoice_id, :line_no, :stype, :desc, :qty, :unit, :unit_price,
                        :subtotal, 0, :tax_amount, :total)'
                )->execute([
                    'invoice_id' => $invId,
                    'line_no'    => isset($r['line_no']) && (int) $r['line_no'] > 0 ? (int) $r['line_no'] : $lineNo,
                    'stype'      => 'manual',
                    'desc'       => (string) ($r['line_description'] ?? ''),
                    'qty'        => $amounts['quantity'],
                    'unit'       => (string) ($r['line_unit'] ?? 'hour'),
                    'unit_price' => $amounts['unitPrice'],
                    'subtotal'   => $amounts['subtotal'],
                    'tax_amount' => $amounts['tax'],
                    'total'      => $amounts['total'],
                ]);
            }
            $pdo->commit();
            $ids[$inv] = $invId;
            $imported++;
            $importedRows += count($rows);
            if ($wasUpdate) $updated++;
            else $created++;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $errors['__invoice_' . $inv] = ['persist failed: ' . $e->getMessage()];
        }
    }

    api_ok([
        'imported_count' => $imported,
        'created_count'  => $created,
        'updated_count'  => $updated,
        'skipped_count'  => count($groups) - $imported,
        'group_count'    => count($groups),
        'row_count'      => count($dry['rows']),
        'imported_row_count' => $importedRows,
        'skipped_row_count' => count($dry['rows']) - $importedRows,
        'errors'         => $errors,
        'ids'            => $ids,
        'update_existing'=> $updateExisting,
    ]);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
