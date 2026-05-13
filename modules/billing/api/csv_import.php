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
require_once __DIR__ . '/../lib/billing.php';

use Core\CsvImportService;

CsvImportService::registerSchema('billing_invoices', [
    'fields' => [
        'invoice_number'   => ['label' => 'Invoice #',       'required' => true],
        'client_name'      => ['label' => 'Client name'],
        'issue_date'       => ['label' => 'Issue date',      'type' => 'date'],
        'due_date'         => ['label' => 'Due date',        'type' => 'date'],
        'period_start'     => ['label' => 'Period start',    'type' => 'date'],
        'period_end'       => ['label' => 'Period end',      'type' => 'date'],
        'currency'         => ['label' => 'Currency'],
        'po_number'        => ['label' => 'PO number'],
        'aggregation'      => ['label' => 'Aggregation',
                               'enum'  => ['per_placement','per_client']],
        'notes_external'   => ['label' => 'Notes (external)'],
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
    RBAC::requirePermission($user, 'billing.invoice.draft');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('billing_invoices');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    RBAC::requirePermission($user, 'billing.invoice.draft');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('billing_invoices', $samples['billing_invoices'] ?? []);
    exit;
}

if ($method === 'POST' && $action === 'dry_run') {
    RBAC::requirePermission($user, 'billing.invoice.draft');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('billing_invoices', $csv);

    $groups = [];
    foreach ($result['rows'] as $rn => $row) {
        $inv = (string) ($row['invoice_number'] ?? '');
        if ($inv === '') continue;
        $groups[$inv][] = ['rn' => $rn, 'row' => $row];
    }
    foreach ($groups as $inv => $g) {
        $first = $g[0]['row'];
        foreach (['client_name','issue_date','due_date'] as $req) {
            if (empty($first[$req])) {
                $rn = $g[0]['rn'];
                $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                $result['errors'][$rn][] = "{$req}: required on first row of invoice #{$inv}";
            }
        }
    }
    $result['error_count'] = count($result['errors']);
    $result['groups']      = count($groups);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    RBAC::requirePermission($user, 'billing.invoice.draft');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $skipInvalid = !empty($_GET['skip_invalid']);

    $dry = CsvImportService::dryRun('billing_invoices', $csv);
    if (!$skipInvalid && $dry['error_count'] > 0) {
        api_ok([
            'imported_count' => 0, 'skipped_count' => count($dry['rows']),
            'errors' => $dry['errors'],
            'message' => 'Validation errors present; pass skip_invalid=1 to import valid rows only.',
        ]);
    }

    $groups = [];
    foreach ($dry['rows'] as $rn => $row) {
        if ($skipInvalid && isset($dry['errors'][$rn])) continue;
        $inv = (string) ($row['invoice_number'] ?? '');
        if ($inv === '') continue;
        $groups[$inv][] = $row;
    }

    $pdo = getDB();
    $imported = 0;
    $errors   = $dry['errors'];
    $ids      = [];

    foreach ($groups as $inv => $rows) {
        $header = $rows[0];
        $existing = scopedFind('SELECT id FROM billing_invoices WHERE tenant_id = :tenant_id AND invoice_number = :n', ['n' => $inv]);
        if ($existing) {
            $errors['__invoice_' . $inv] = ['Invoice # ' . $inv . ' already exists; skipped'];
            continue;
        }

        $subtotal = 0; $tax = 0; $total = 0;
        foreach ($rows as $r) {
            $subtotal += (float) ($r['line_subtotal']  ?? 0);
            $tax      += (float) ($r['line_tax_amount']?? 0);
            $total    += (float) ($r['line_total']     ?? 0);
        }
        if ($total <= 0) $total = $subtotal + $tax;

        $pdo->beginTransaction();
        try {
            $invId = scopedInsert('billing_invoices', [
                'invoice_number'     => $inv,
                'client_name'        => (string) $header['client_name'],
                'currency'           => $header['currency']  ?? 'USD',
                'issue_date'         => $header['issue_date'],
                'due_date'           => $header['due_date'],
                'period_start'       => $header['period_start'] ?? null,
                'period_end'         => $header['period_end']   ?? null,
                'subtotal'           => $subtotal,
                'tax_total'          => $tax,
                'total'              => $total,
                'amount_due'         => $total,
                'status'             => 'draft',
                'po_number'          => $header['po_number']    ?? null,
                'aggregation'        => $header['aggregation']  ?? 'per_client',
                'notes_external'     => $header['notes_external']?? null,
                'created_by_user_id' => $user['id'] ?? null,
            ]);

            $lineNo = 0;
            foreach ($rows as $r) {
                $lineNo++;
                $sub = (float) ($r['line_subtotal'] ?? (((float) ($r['line_quantity'] ?? 0)) * ((float) ($r['line_unit_price'] ?? 0))));
                $taxAmt = (float) ($r['line_tax_amount'] ?? 0);
                $lineTotal = (float) ($r['line_total'] ?? ($sub + $taxAmt));
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
                    'qty'        => (float) ($r['line_quantity'] ?? 1),
                    'unit'       => (string) ($r['line_unit'] ?? 'hour'),
                    'unit_price' => (float) ($r['line_unit_price'] ?? 0),
                    'subtotal'   => $sub,
                    'tax_amount' => $taxAmt,
                    'total'      => $lineTotal,
                ]);
            }
            $pdo->commit();
            $ids[$inv] = $invId;
            $imported++;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $errors['__invoice_' . $inv] = ['persist failed: ' . $e->getMessage()];
        }
    }

    api_ok([
        'imported_count' => $imported,
        'skipped_count'  => count($groups) - $imported,
        'group_count'    => count($groups),
        'errors'         => $errors,
        'ids'            => $ids,
    ]);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
