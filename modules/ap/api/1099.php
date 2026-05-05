<?php
/**
 * AP API — 1099-NEC ledger.
 *
 *   GET  /api/ap/1099?tax_year=YYYY        → current ledger rows
 *   POST /api/ap/1099?action=rebuild&tax_year=YYYY  → recomputes from cleared payments
 *
 * Phase A0 = ledger rollup only. Actual 1099-NEC PDF generation + IRS
 * e-file deferred to Phase A1/B.
 *
 * SPEC: /app/modules/ap/SPEC.md §5.5, §3.9.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.1099.view');
    $year = (int) ($_GET['tax_year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) api_error('invalid tax_year', 422);
    $rows = scopedQuery(
        'SELECT id, tax_year, vendor_name, vendor_type, tax_id_last4, total_paid, requires_1099_nec, computed_at, submitted_to_irs_at
         FROM ap_1099_ledger
         WHERE tenant_id = :tenant_id AND tax_year = :y
         ORDER BY total_paid DESC',
        ['y' => $year]
    );
    $totals = array_reduce($rows, function ($acc, $r) {
        $acc['vendors']++;
        $acc['total_paid'] += (float) $r['total_paid'];
        if ($r['requires_1099_nec']) $acc['requires_nec']++;
        return $acc;
    }, ['vendors' => 0, 'total_paid' => 0.0, 'requires_nec' => 0]);
    api_ok(['tax_year' => $year, 'rows' => $rows, 'totals' => $totals]);
}

if ($method === 'GET' && $action === 'readiness') {
    RBAC::requirePermission($user, 'ap.1099.view');
    $year = (int) ($_GET['tax_year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) api_error('invalid tax_year', 422);
    $pdo = getDB();

    // For each ledger row, check W-9 on file + valid TIN format.
    $rows = $pdo->prepare(
        'SELECT l.id, l.vendor_name, l.vendor_type, l.tax_id_last4,
                l.total_paid, l.requires_1099_nec,
                v.id AS vendor_id, v.tax_id_full_ct, v.tax_id_last4 AS vendor_tax_id_last4
           FROM ap_1099_ledger l
           LEFT JOIN ap_vendors_index v ON v.tenant_id = l.tenant_id AND v.vendor_name = l.vendor_name
          WHERE l.tenant_id = :t AND l.tax_year = :y
          ORDER BY l.total_paid DESC'
    );
    $rows->execute(['t' => $tid, 'y' => $year]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    // W-9 docs uploaded via vendor portal (Phase 2).
    $w9Stmt = $pdo->prepare(
        "SELECT vendor_id, MAX(uploaded_at) AS last_w9_at, COUNT(*) AS w9_count,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS w9_approved
           FROM ap_vendor_portal_documents
          WHERE tenant_id = :t AND document_type = 'w9'
          GROUP BY vendor_id"
    );
    try {
        $w9Stmt->execute(['t' => $tid]);
        $w9ByVendor = [];
        foreach ($w9Stmt->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $w9ByVendor[(int) $w['vendor_id']] = $w;
        }
    } catch (\Throwable $_) { $w9ByVendor = []; }

    $out = [];
    $blockerCount = 0; $readyCount = 0;
    foreach ($rows as $r) {
        $vid = (int) ($r['vendor_id'] ?? 0);
        $w9  = $w9ByVendor[$vid] ?? null;
        $hasW9        = $w9 && (int) $w9['w9_approved'] > 0;
        $tinPresent   = !empty($r['tax_id_full_ct']) || !empty($r['vendor_tax_id_last4']);
        $tinValid4    = !empty($r['vendor_tax_id_last4']) && preg_match('/^\d{4}$/', (string) $r['vendor_tax_id_last4']);

        $blockers = [];
        if (!$tinPresent)              $blockers[] = 'Missing TIN (EIN/SSN)';
        if ($tinPresent && !$tinValid4) $blockers[] = 'TIN last-4 not 4 digits';
        if (!$hasW9 && (int) $r['requires_1099_nec'] === 1) $blockers[] = 'No approved W-9 on file';

        $ready = empty($blockers);
        if ($ready) $readyCount++; else $blockerCount++;

        $out[] = [
            'ledger_id'        => (int) $r['id'],
            'vendor_id'        => $vid,
            'vendor_name'      => $r['vendor_name'],
            'vendor_type'      => $r['vendor_type'],
            'total_paid'       => (float) $r['total_paid'],
            'requires_1099_nec'=> (int) $r['requires_1099_nec'],
            'has_w9'           => (bool) $hasW9,
            'w9_count'         => $w9 ? (int) $w9['w9_count'] : 0,
            'tin_present'      => (bool) $tinPresent,
            'tin_last4'        => $r['vendor_tax_id_last4'] ?? $r['tax_id_last4'],
            'ready'            => $ready,
            'blockers'         => $blockers,
        ];
    }
    api_ok([
        'tax_year' => $year,
        'rows'     => $out,
        'summary'  => ['ready' => $readyCount, 'blocked' => $blockerCount, 'total' => count($out)],
    ]);
}

if ($method === 'POST' && $action === 'rebuild') {
    RBAC::requirePermission($user, 'ap.1099.generate');
    $year = (int) ($_GET['tax_year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) api_error('invalid tax_year', 422);
    $summary = apBuild1099Ledger($tid, $year);
    apAudit('ap.1099.ledger_built', ['tax_year' => $year, 'summary' => $summary], null);
    api_ok(['tax_year' => $year, 'summary' => $summary]);
}

if ($method === 'GET' && $action === 'print') {
    // Render a print-ready HTML 1099-NEC for browser → PDF. No server-side
    // PDF library needed; the browser's print dialog produces an archival
    // copy. Phase B can swap this for actual PDF generation + IRS e-file.
    RBAC::requirePermission($user, 'ap.1099.view');
    $year = (int) ($_GET['tax_year'] ?? date('Y'));
    $vendorIds = isset($_GET['vendor_ids']) ? array_filter(array_map('intval', explode(',', $_GET['vendor_ids']))) : [];

    $where = ['l.tenant_id = :t', 'l.tax_year = :y', 'l.requires_1099_nec = 1'];
    $params = ['t' => $tid, 'y' => $year];
    if ($vendorIds) {
        $place = implode(',', array_map(fn($i) => ':v' . $i, array_keys($vendorIds)));
        $where[] = 'l.id IN (' . $place . ')';
        foreach ($vendorIds as $i => $v) $params['v' . $i] = $v;
    }

    $rows = $GLOBALS['__pdo'] ?? getDB();
    $stmt = $rows->prepare(
        "SELECT l.*, v.tax_id_full, v.address_line1, v.address_line2,
                v.city, v.state, v.zip, t.legal_name AS payer_name,
                t.tax_id AS payer_tin, t.address_line1 AS payer_addr1,
                t.address_line2 AS payer_addr2, t.city AS payer_city,
                t.state AS payer_state, t.zip AS payer_zip
           FROM ap_1099_ledger l
           LEFT JOIN ap_vendors v ON v.id = l.vendor_id AND v.tenant_id = l.tenant_id
           LEFT JOIN tenants t ON t.id = l.tenant_id
          WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apAudit('ap.1099.print_rendered', ['tax_year' => $year, 'count' => count($forms)], null);

    // Plain HTML response (not JSON) — UI fetches and pops a new window
    // that auto-triggers print.
    header('Content-Type: text/html; charset=utf-8');
    echo render1099NecHtml($year, $forms);
    exit;
}

function render1099NecHtml(int $year, array $forms): string {
    $rows = '';
    foreach ($forms as $f) {
        $payerBlock = h(($f['payer_name'] ?? '')) . '<br>'
            . h(($f['payer_addr1'] ?? '')) . ' ' . h(($f['payer_addr2'] ?? '')) . '<br>'
            . h(($f['payer_city'] ?? '')) . ', ' . h(($f['payer_state'] ?? '')) . ' ' . h(($f['payer_zip'] ?? ''));
        $recipientBlock = h(($f['vendor_name'] ?? '')) . '<br>'
            . h(($f['address_line1'] ?? '')) . ' ' . h(($f['address_line2'] ?? '')) . '<br>'
            . h(($f['city'] ?? '')) . ', ' . h(($f['state'] ?? '')) . ' ' . h(($f['zip'] ?? ''));
        $tin    = $f['tax_id_full'] ?? ('***-**-' . ($f['tax_id_last4'] ?? '****'));
        $amount = number_format((float) $f['total_paid'], 2);
        $rows  .= <<<H
<div class="form-1099" data-vendor="{$f['vendor_id']}">
  <header>
    <h2>Form 1099-NEC — Tax Year {$year}</h2>
    <p class="muted">Nonemployee Compensation</p>
  </header>
  <table>
    <tr>
      <td class="label">PAYER's name, address, ZIP</td>
      <td class="label">RECIPIENT's name, address, ZIP</td>
    </tr>
    <tr>
      <td class="block">{$payerBlock}</td>
      <td class="block">{$recipientBlock}</td>
    </tr>
    <tr>
      <td class="label">PAYER's TIN: <strong>{$f['payer_tin']}</strong></td>
      <td class="label">RECIPIENT's TIN: <strong>{$tin}</strong></td>
    </tr>
    <tr>
      <td colspan="2" class="box1">
        <span class="label">Box 1. Nonemployee compensation</span>
        <span class="amount">\${$amount}</span>
      </td>
    </tr>
    <tr>
      <td colspan="2" class="muted small">
        OMB 1545-0116 · Generated by CoreFlux · Prior to filing,
        review accuracy and submit Copy A to the IRS via e-file or paper
        Form 1096 transmittal.
      </td>
    </tr>
  </table>
</div>
H;
    }
    $genDate = date('Y-m-d');
    $count   = count($forms);
    return <<<HTML
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<title>1099-NEC Forms — {$year}</title>
<style>
  @page { size: letter; margin: 0.5in; }
  body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11pt; color: #111; margin: 0; padding: 12px; }
  .form-1099 { page-break-after: always; border: 2px solid #000; padding: 16px; margin-bottom: 16px; }
  .form-1099:last-child { page-break-after: auto; }
  .form-1099 header h2 { margin: 0 0 4px; font-size: 14pt; }
  .form-1099 .muted { color: #555; font-size: 9pt; margin: 0 0 8px; }
  .form-1099 table { width: 100%; border-collapse: collapse; }
  .form-1099 td { border: 1px solid #888; padding: 6px 8px; vertical-align: top; }
  .form-1099 td.label { font-size: 8pt; color: #444; text-transform: uppercase; letter-spacing: 0.5px; }
  .form-1099 td.block { font-size: 11pt; min-height: 60px; line-height: 1.5; }
  .form-1099 td.box1 { padding: 12px; }
  .form-1099 td.box1 .label { float: left; }
  .form-1099 td.box1 .amount { float: right; font-size: 18pt; font-weight: 600; font-family: monospace; }
  .form-1099 td.box1::after { content: ''; clear: both; display: block; }
  .form-1099 td.small { font-size: 8pt; color: #777; }
  .toolbar { position: fixed; top: 0; left: 0; right: 0; padding: 8px 16px; background: #1f2937; color: #fff; }
  .toolbar button { padding: 4px 12px; border-radius: 4px; border: 1px solid #fff; background: transparent; color: #fff; cursor: pointer; margin-right: 8px; }
  @media print { .toolbar { display: none; } body { padding: 0; } }
</style>
</head><body>
<div class="toolbar">
  <button onclick="window.print()">Print / Save as PDF</button>
  <button onclick="window.close()">Close</button>
  <span style="float:right">CoreFlux 1099-NEC · Tax Year {$year} · Generated {$genDate} · {$count} form(s)</span>
</div>
<div style="margin-top: 50px"></div>
{$rows}
</body></html>
HTML;
}

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

api_error('Method not allowed', 405);
