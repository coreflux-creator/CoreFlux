<?php
/**
 * AP module — bills CSV bulk import (multi-line).
 *
 *   GET  /api/ap/bills_csv_import?action=template
 *   POST /api/ap/bills_csv_import?action=dry_run
 *   POST /api/ap/bills_csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * Header + line items live in the SAME CSV. Rows that share the same
 * `bill_number` are grouped into one bill with N lines. Header fields
 * (vendor_name, dates, etc.) are read from the FIRST row of each group;
 * subsequent rows of the same bill only need line_* columns. Empty
 * header cells on continuation rows are fine.
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX).
 *
 * Encrypted PII, payment routing, GL account assignment are intentionally
 * NOT in scope for the bulk-import — those need the secure detail UI.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/ap.php';

use Core\CsvImportService;

CsvImportService::registerSchema('ap_bills', [
    'fields' => [
        // Header fields (read from first row of each bill_number group)
        'bill_number'      => ['label' => 'Bill #',         'required' => true],
        'vendor_name'      => ['label' => 'Vendor name'],
        'vendor_type'      => ['label' => 'Vendor type',
                               'enum'  => ['1099_individual','c2c_corp','w9_business','utility','other']],
        'bill_date'        => ['label' => 'Bill date',      'type' => 'date'],
        'due_date'         => ['label' => 'Due date',       'type' => 'date'],
        'received_at'      => ['label' => 'Received at',    'type' => 'date'],
        'period_start'     => ['label' => 'Period start',   'type' => 'date'],
        'period_end'       => ['label' => 'Period end',     'type' => 'date'],
        'currency'         => ['label' => 'Currency'],
        'po_number'        => ['label' => 'PO number'],
        'notes_internal'   => ['label' => 'Notes (internal)'],
        // Line fields (one row per line)
        'line_no'          => ['label' => 'Line #',           'type' => 'number'],
        'line_description' => ['label' => 'Line description'],
        'line_quantity'    => ['label' => 'Line quantity',    'type' => 'number'],
        'line_unit'        => ['label' => 'Line unit'],
        'line_unit_price'  => ['label' => 'Line unit price',  'type' => 'number'],
        'line_subtotal'    => ['label' => 'Line subtotal',    'type' => 'number'],
        'line_tax_amount'  => ['label' => 'Line tax amount',  'type' => 'number'],
        'line_total'       => ['label' => 'Line total',       'type' => 'number'],
    ],
    // bill_number is NOT unique across rows (multi-line). Don't add to unique_within_batch.
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    RBAC::requirePermission($user, 'ap.bill.create');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bills_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('ap_bills');
    exit;
}

if ($method === 'POST' && $action === 'dry_run') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('ap_bills', $csv);

    // Group rows by bill_number, enforce that the FIRST row of each
    // group has the required header fields.
    $groups = [];
    foreach ($result['rows'] as $rn => $row) {
        $bn = (string) ($row['bill_number'] ?? '');
        if ($bn === '') continue;
        $groups[$bn][] = ['rn' => $rn, 'row' => $row];
    }
    foreach ($groups as $bn => $g) {
        $first = $g[0]['row'];
        foreach (['vendor_name','bill_date','due_date'] as $req) {
            if (empty($first[$req])) {
                $rn = $g[0]['rn'];
                $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                $result['errors'][$rn][] = "{$req}: required on first row of bill #{$bn}";
            }
        }
    }
    $result['error_count'] = count($result['errors']);
    $result['groups']      = count($groups);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $skipInvalid = !empty($_GET['skip_invalid']);

    $dry = CsvImportService::dryRun('ap_bills', $csv);
    if (!$skipInvalid && $dry['error_count'] > 0) {
        api_ok([
            'imported_count' => 0, 'skipped_count' => count($dry['rows']),
            'errors' => $dry['errors'],
            'message' => 'Validation errors present; pass skip_invalid=1 to import valid rows only.',
        ]);
    }

    // Group rows by bill_number
    $groups = [];
    foreach ($dry['rows'] as $rn => $row) {
        if ($skipInvalid && isset($dry['errors'][$rn])) continue;
        $bn = (string) ($row['bill_number'] ?? '');
        if ($bn === '') continue;
        $groups[$bn][] = $row;
    }

    $pdo = getDB();
    $imported = 0;
    $errors   = $dry['errors'];
    $ids      = [];

    foreach ($groups as $bn => $rows) {
        $header = $rows[0];
        // Skip if bill_number already exists in tenant (idempotent)
        $existing = scopedFind('SELECT id FROM ap_bills WHERE tenant_id = :tenant_id AND bill_number = :n', ['n' => $bn]);
        if ($existing) {
            $errors['__bill_' . $bn] = ['Bill # ' . $bn . ' already exists; skipped'];
            continue;
        }

        $subtotal = 0; $tax = 0; $total = 0;
        foreach ($rows as $r) {
            $subtotal += (float) ($r['line_subtotal']  ?? 0);
            $tax      += (float) ($r['line_tax_amount']?? 0);
            $total    += (float) ($r['line_total']     ?? 0);
        }
        if ($total <= 0) {
            // Compute total as subtotal + tax if line_total missing
            $total = $subtotal + $tax;
        }

        $pdo->beginTransaction();
        try {
            $billId = scopedInsert('ap_bills', [
                'bill_number'        => $bn,
                'internal_ref'       => apNextInternalRef($tid),
                'vendor_name'        => (string) $header['vendor_name'],
                'vendor_type'        => (string) ($header['vendor_type'] ?? 'other'),
                'received_at'        => $header['received_at'] ?? $header['bill_date'],
                'bill_date'          => $header['bill_date'],
                'due_date'           => $header['due_date'],
                'period_start'       => $header['period_start'] ?? null,
                'period_end'         => $header['period_end']   ?? null,
                'currency'           => $header['currency']     ?? 'USD',
                'subtotal'           => $subtotal,
                'tax_total'          => $tax,
                'total'              => $total,
                'amount_due'         => $total,
                'status'             => 'pending_approval',
                'source'             => 'manual',
                'po_number'          => $header['po_number']      ?? null,
                'notes_internal'     => $header['notes_internal'] ?? null,
                'created_by_user_id' => $user['id'] ?? null,
            ]);

            $lineNo = 0;
            foreach ($rows as $r) {
                $lineNo++;
                $sub = (float) ($r['line_subtotal'] ?? (((float) ($r['line_quantity'] ?? 0)) * ((float) ($r['line_unit_price'] ?? 0))));
                $taxAmt = (float) ($r['line_tax_amount'] ?? 0);
                $lineTotal = (float) ($r['line_total'] ?? ($sub + $taxAmt));
                $pdo->prepare(
                    'INSERT INTO ap_bill_lines
                       (bill_id, line_no, source_type, description, quantity, unit, unit_price,
                        subtotal, tax_rate_pct, tax_amount, total)
                     VALUES
                       (:bill_id, :line_no, :stype, :desc, :qty, :unit, :unit_price,
                        :subtotal, 0, :tax_amount, :total)'
                )->execute([
                    'bill_id'    => $billId,
                    'line_no'    => isset($r['line_no']) && (int) $r['line_no'] > 0 ? (int) $r['line_no'] : $lineNo,
                    'stype'      => 'manual',
                    'desc'       => (string) ($r['line_description'] ?? ''),
                    'qty'        => (float) ($r['line_quantity'] ?? 1),
                    'unit'       => (string) ($r['line_unit'] ?? 'hour'),
                    'unit_price' => (float) ($r['line_unit_price'] ?? 0),
                    'subtotal'   => $sub,
                    'tax_amount' => $taxAmt,
                    'total'      => $lineTotal,
                ]);
            }
            $pdo->commit();
            $ids[$bn] = $billId;
            $imported++;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $errors['__bill_' . $bn] = ['persist failed: ' . $e->getMessage()];
        }
    }

    apAudit('ap.bill.csv_imported', ['imported' => $imported, 'groups' => count($groups), 'errors' => count($errors)]);
    api_ok([
        'imported_count' => $imported,
        'skipped_count'  => count($groups) - $imported,
        'group_count'    => count($groups),
        'errors'         => $errors,
        'ids'            => $ids,
    ]);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
