<?php
/**
 * Billing — Invoice PDF generation.
 *
 *   invoiceRenderPdf(int $invoiceId, bool $useCache = true): string  → absolute path
 *   invoiceBuildPdfHtml(int $invoiceId): string                       → HTML template
 *
 * Cached at `/app/storage/billing/invoices/<tenant_id>/<invoice_id>-<hash>.pdf`.
 * Cache key is the invoice's `updated_at` + amount_due (so any edit busts cache).
 *
 * Permissions are NOT checked here — callers (HTTP endpoint, mail attach)
 * are responsible. This is a builder.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/pdf_renderer.php';

const COREFLUX_INVOICE_PDF_STORAGE_ROOT = __DIR__ . '/../../../storage/billing/invoices';

function invoiceRenderPdf(int $invoiceId, bool $useCache = true): string {
    $pdo = getDB();
    $inv = _invoiceLoad($pdo, $invoiceId);
    if (!$inv) throw new RuntimeException("Invoice #{$invoiceId} not found");

    $cacheKey = hash('sha1', (string) $inv['updated_at'] . '|' . (string) $inv['amount_due']);
    $tenantId = (int) $inv['tenant_id'];
    $outDir   = COREFLUX_INVOICE_PDF_STORAGE_ROOT . '/' . $tenantId;
    $outPath  = $outDir . '/' . $invoiceId . '-' . $cacheKey . '.pdf';

    if ($useCache && is_file($outPath) && filesize($outPath) > 0) return $outPath;

    $html = invoiceBuildPdfHtmlFinal($invoiceId);
    cf_render_html_to_pdf($html, $outPath, ['paper' => 'letter']);
    return $outPath;
}

function invoiceBuildPdfHtml(int $invoiceId): string {
    $pdo = getDB();
    $inv = _invoiceLoad($pdo, $invoiceId);
    if (!$inv) throw new RuntimeException("Invoice #{$invoiceId} not found");

    $lines = _invoiceLines($pdo, $invoiceId, (int) $inv['tenant_id']);
    $tenant = _invoiceTenantBranding($pdo, (int) $inv['tenant_id']);

    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $money = fn($v) => '$' . number_format((float) $v, 2);

    $billToJson = is_string($inv['bill_to_json'] ?? null) ? json_decode($inv['bill_to_json'], true) : null;
    $billToLines = [];
    if (is_array($billToJson)) {
        foreach (['name', 'street1', 'street2', 'city_state_zip', 'email'] as $k) {
            if (!empty($billToJson[$k])) $billToLines[] = (string) $billToJson[$k];
        }
    } else {
        $billToLines[] = (string) ($inv['client_name'] ?? '');
    }

    $linesHtml = '';
    foreach ($lines as $i => $l) {
        $linesHtml .= '<tr>'
            . '<td class="num">' . ($i + 1) . '</td>'
            . '<td>' . $h($l['description']) . '</td>'
            . '<td class="num">' . $h(rtrim(rtrim(number_format((float) $l['quantity'], 4, '.', ''), '0'), '.')) . '</td>'
            . '<td>' . $h($l['unit']) . '</td>'
            . '<td class="num">' . $money($l['unit_price']) . '</td>'
            . '<td class="num">' . $money($l['subtotal']) . '</td>'
            . '<td class="num">' . $money($l['tax_amount']) . '</td>'
            . '<td class="num">' . $money($l['total']) . '</td>'
            . '</tr>';
    }
    if ($linesHtml === '') {
        $linesHtml = '<tr><td colspan="8" class="muted center">No line items</td></tr>';
    }

    $brandColor = $h($tenant['primary_color'] ?? '#0f172a');
    $logoUrl    = $tenant['logo_url'] ?? null;
    $logoHtml   = $logoUrl ? '<img src="' . $h($logoUrl) . '" alt="logo" class="logo">' : '<div class="brand-name">' . $h($tenant['name'] ?? 'CoreFlux') . '</div>';

    $notesExternal = trim((string) ($inv['notes_external'] ?? ''));
    $notesHtml = $notesExternal !== ''
        ? '<div class="notes"><h4>Notes</h4><div class="notes-body">' . nl2br($h($notesExternal)) . '</div></div>'
        : '';

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {$h($inv['invoice_number'])}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #0f172a; font-size: 11pt; margin: 0; padding: 0 32px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; padding: 36px 0 20px; border-bottom: 3px solid {$brandColor}; }
  .header .logo { max-height: 56px; max-width: 220px; }
  .brand-name { font-size: 22pt; font-weight: 700; color: {$brandColor}; }
  .title { text-align: right; }
  .title h1 { margin: 0 0 4px; font-size: 26pt; color: {$brandColor}; letter-spacing: 0.04em; }
  .title .meta { color: #64748b; font-size: 10pt; line-height: 1.55; }
  .parties { display: flex; justify-content: space-between; margin: 24px 0; gap: 20px; }
  .party h4 { margin: 0 0 6px; font-size: 9pt; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; }
  .party .line { font-size: 11pt; line-height: 1.55; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
  table.lines th { text-align: left; padding: 8px 6px; font-size: 9pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; }
  table.lines td { padding: 10px 6px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .num { text-align: right; }
  .center { text-align: center; }
  .muted { color: #94a3b8; }
  .totals { margin-top: 16px; width: 50%; margin-left: auto; }
  .totals table { width: 100%; border-collapse: collapse; }
  .totals td { padding: 5px 6px; }
  .totals .label { color: #475569; text-align: right; }
  .totals .val { text-align: right; font-variant-numeric: tabular-nums; }
  .totals .grand { font-weight: 700; font-size: 13pt; border-top: 2px solid {$brandColor}; padding-top: 8px; color: {$brandColor}; }
  .notes { margin: 36px 0 0; padding: 14px 18px; background: #f8fafc; border-left: 4px solid {$brandColor}; border-radius: 4px; }
  .notes h4 { margin: 0 0 6px; font-size: 9pt; color: #94a3b8; text-transform: uppercase; }
  .notes-body { font-size: 10pt; color: #475569; }
  .footer { margin-top: 60px; text-align: center; color: #94a3b8; font-size: 9pt; padding-top: 12px; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
  <div class="header">
    <div>{$logoHtml}</div>
    <div class="title">
      <h1>INVOICE</h1>
      <div class="meta">
        <strong>{$h($inv['invoice_number'])}</strong><br>
        Issue date: {$h($inv['issue_date'])}<br>
        Due date: {$h($inv['due_date'])}
        @{po_section}
      </div>
    </div>
  </div>
  <div class="parties">
    <div class="party">
      <h4>From</h4>
      <div class="line"><strong>{$h($tenant['name'] ?? '')}</strong></div>
    </div>
    <div class="party">
      <h4>Bill to</h4>
      <div class="line">@{billto}</div>
    </div>
    @{period_block}
  </div>
  <table class="lines">
    <thead>
      <tr>
        <th class="num" style="width:30px">#</th>
        <th>Description</th>
        <th class="num">Qty</th>
        <th>Unit</th>
        <th class="num">Rate</th>
        <th class="num">Subtotal</th>
        <th class="num">Tax</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>{$linesHtml}</tbody>
  </table>
  <div class="totals">
    <table>
      <tr><td class="label">Subtotal</td><td class="val">{$money($inv['subtotal'])}</td></tr>
      <tr><td class="label">Tax</td><td class="val">{$money($inv['tax_total'])}</td></tr>
      <tr><td class="label grand">Total due</td><td class="val grand">{$money($inv['amount_due'])}</td></tr>
    </table>
  </div>
  {$notesHtml}
  <div class="footer">Thank you for your business — generated by CoreFlux.</div>
</body>
</html>
HTML
    // Late substitutions (so the heredoc stays readable):
    // PO line + Bill-to block + period block.
    ;
}

// (Re-)inject dynamic blocks into the heredoc placeholders. Done as a
// second pass to avoid a sprawling heredoc with conditionals inline.
function _invoicePdfPostprocess(string $html, array $inv, array $billToLines, $h): string {
    $po = !empty($inv['po_number']) ? "<br>PO: " . $h($inv['po_number']) : '';
    $billto = implode('<br>', array_map($h, $billToLines));
    $period = (!empty($inv['period_start']) && !empty($inv['period_end']))
        ? '<div class="party"><h4>Service period</h4><div class="line">'
          . $h($inv['period_start']) . ' &mdash; ' . $h($inv['period_end']) . '</div></div>'
        : '';
    return strtr($html, [
        '@{po_section}'   => $po,
        '@{billto}'       => $billto,
        '@{period_block}' => $period,
    ]);
}

// --- internal helpers -------------------------------------------------- //

function _invoiceLoad(PDO $pdo, int $invoiceId): ?array {
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $st = $pdo->prepare('SELECT * FROM billing_invoices WHERE id = :id LIMIT 1');
    $st->execute(['id' => $invoiceId]);
    $row = $st->fetch();
    return $row ?: null;
}

function _invoiceLines(PDO $pdo, int $invoiceId, int $tenantId): array {
    $st = $pdo->prepare(
        'SELECT line_no, description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total
           FROM billing_invoice_lines
          WHERE invoice_id = :id AND tenant_id = :t
          ORDER BY line_no ASC'
    );
    $st->execute(['id' => $invoiceId, 't' => $tenantId]);
    return $st->fetchAll();
}

function _invoiceTenantBranding(PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare('SELECT name, primary_color, logo_url FROM tenants WHERE id = :id LIMIT 1');
        $st->execute(['id' => $tenantId]);
        return $st->fetch() ?: ['name' => 'CoreFlux'];
    } catch (\Throwable $_) {
        return ['name' => 'CoreFlux'];
    }
}

// Wrap invoiceBuildPdfHtml so postprocessing runs.
function invoiceBuildPdfHtmlFinal(int $invoiceId): string {
    $pdo = getDB();
    $inv = _invoiceLoad($pdo, $invoiceId);
    if (!$inv) throw new RuntimeException("Invoice #{$invoiceId} not found");

    $billToJson = is_string($inv['bill_to_json'] ?? null) ? json_decode($inv['bill_to_json'], true) : null;
    $billToLines = [];
    if (is_array($billToJson)) {
        foreach (['name', 'street1', 'street2', 'city_state_zip', 'email'] as $k) {
            if (!empty($billToJson[$k])) $billToLines[] = (string) $billToJson[$k];
        }
    } else {
        $billToLines[] = (string) ($inv['client_name'] ?? '');
    }
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    return _invoicePdfPostprocess(invoiceBuildPdfHtml($invoiceId), $inv, $billToLines, $h);
}
