<?php
/**
 * Public customer-portal invoice view.
 *   GET /billing/invoice.php?t=<token>
 *
 * Unauthenticated — the token in the URL is the credential. Print-friendly
 * HTML; customer can Cmd/Ctrl+P to save as PDF.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../modules/billing/lib/billing.php';

$raw = (string) ($_GET['t'] ?? '');
$tok = billingTokenFindByRaw($raw);
$inv = $lines = $tenantRow = null;

if ($tok) {
    $now = date('Y-m-d H:i:s');
    if ($tok['expires_at'] && $tok['expires_at'] < $now) {
        $tok = null; // expired — present same "not found" page to avoid disclosure
    }
}

if ($tok) {
    $pdo = getDB();
    $iStmt = $pdo->prepare('SELECT * FROM billing_invoices WHERE id = :id AND tenant_id = :t');
    $iStmt->execute(['id' => $tok['invoice_id'], 't' => $tok['tenant_id']]);
    $inv = $iStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($inv) {
        $lStmt = $pdo->prepare('SELECT * FROM billing_invoice_lines WHERE invoice_id = :id ORDER BY line_no');
        $lStmt->execute(['id' => $inv['id']]);
        $lines = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        $tStmt = $pdo->prepare('SELECT name, billing_payment_instructions, mail_from_name_override FROM tenants WHERE id = :id');
        $tStmt->execute(['id' => $tok['tenant_id']]);
        $tenantRow = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Bump view counter (best-effort)
        $pdo->prepare('UPDATE billing_invoice_tokens SET last_viewed_at = NOW(), view_count = view_count + 1 WHERE id = :id')
            ->execute(['id' => $tok['id']]);
    } else {
        $tok = null;
    }
}

function bv_esc($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function bv_money($n, $cur = 'USD'): string {
    return number_format((float) $n, 2) . ' ' . bv_esc($cur);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $inv ? 'Invoice ' . bv_esc($inv['invoice_number']) : 'Invoice not found' ?></title>
<style>
  :root { --fg:#111; --muted:#6b7280; --border:#e5e7eb; --bg:#f7f8fa; --brand:#1f2937; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--fg); background: var(--bg); }
  .wrap { max-width: 820px; margin: 32px auto; padding: 0 20px; }
  .invoice { background: #fff; padding: 40px; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; gap: 24px; }
  .header h1 { margin: 0 0 4px; font-size: 28px; }
  .header .meta { text-align: right; font-size: 14px; color: var(--muted); }
  .header .meta strong { color: var(--fg); }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; padding-top: 24px; border-top: 2px solid var(--brand); }
  .party h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin: 0 0 8px; }
  .party p { margin: 0; font-size: 14px; line-height: 1.6; }
  table.lines { width: 100%; border-collapse: collapse; margin-bottom: 32px; font-size: 14px; }
  table.lines th, table.lines td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
  table.lines th { background: var(--bg); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 600; }
  table.lines td.right, table.lines th.right { text-align: right; }
  .totals { margin-left: auto; max-width: 280px; }
  .totals dl { display: grid; grid-template-columns: 1fr auto; gap: 8px 24px; margin: 0; font-size: 14px; }
  .totals .total { border-top: 2px solid var(--brand); padding-top: 12px; margin-top: 8px; font-size: 18px; font-weight: 700; }
  .notes { padding: 24px; background: var(--bg); border-radius: 8px; margin-top: 32px; font-size: 13px; color: var(--muted); white-space: pre-wrap; }
  .actions { text-align: center; padding: 12px; }
  .actions button { background: var(--brand); color: #fff; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
  .status-banner { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
  .status-banner.paid { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
  .status-banner.partial { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
  .status-banner.void { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
  .empty { padding: 48px; text-align: center; }
  @media print {
    body { background: #fff; }
    .wrap { margin: 0; padding: 0; max-width: 100%; }
    .invoice { border: none; box-shadow: none; padding: 0; }
    .actions { display: none; }
  }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$inv): ?>
  <div class="invoice empty" data-testid="billing-invoice-not-found">
    <h2>Invoice not found</h2>
    <p style="color: var(--muted)">This link is invalid or has expired. Please contact the sender for a new link.</p>
  </div>
<?php else: ?>
  <div class="actions">
    <button onclick="window.print()" data-testid="billing-invoice-print">Print / Save as PDF</button>
  </div>
  <div class="invoice" data-testid="billing-invoice-view">
    <?php if ($inv['status'] === 'paid'): ?>
      <div class="status-banner paid" data-testid="billing-invoice-status-paid"><strong>PAID</strong> — thank you.</div>
    <?php elseif ($inv['status'] === 'partially_paid'): ?>
      <div class="status-banner partial" data-testid="billing-invoice-status-partial"><strong>PARTIALLY PAID</strong> — remaining due: <?= bv_money($inv['amount_due'], $inv['currency']) ?>.</div>
    <?php elseif ($inv['status'] === 'void'): ?>
      <div class="status-banner void" data-testid="billing-invoice-status-void"><strong>VOID</strong> — this invoice has been cancelled.</div>
    <?php endif; ?>

    <div class="header">
      <div>
        <h1>Invoice</h1>
        <div style="color: var(--muted); font-size: 14px"><?= bv_esc($tenantRow['mail_from_name_override'] ?? $tenantRow['name'] ?? '') ?></div>
      </div>
      <div class="meta">
        <div><strong data-testid="billing-invoice-number"><?= bv_esc($inv['invoice_number']) ?></strong></div>
        <div>Issued: <?= bv_esc($inv['issue_date']) ?></div>
        <div>Due: <?= bv_esc($inv['due_date']) ?></div>
        <?php if (!empty($inv['po_number'])): ?>
          <div>PO #: <?= bv_esc($inv['po_number']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="parties">
      <div class="party">
        <h3>From</h3>
        <p><strong><?= bv_esc($tenantRow['name'] ?? 'Your supplier') ?></strong></p>
      </div>
      <div class="party">
        <h3>Bill to</h3>
        <p>
          <strong data-testid="billing-invoice-bill-to"><?= bv_esc($inv['client_name']) ?></strong><br>
          <?php
            $billTo = $inv['bill_to_json'] ? json_decode($inv['bill_to_json'], true) : null;
            if (is_array($billTo)) {
                foreach (['street','city','state','postal_code','country'] as $f) {
                    if (!empty($billTo[$f])) echo bv_esc($billTo[$f]) . '<br>';
                }
            }
          ?>
        </p>
      </div>
    </div>

    <table class="lines" data-testid="billing-invoice-lines">
      <thead>
        <tr>
          <th>Description</th>
          <th class="right">Qty</th>
          <th>Unit</th>
          <th class="right">Unit price</th>
          <th class="right">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($lines ?? []) as $l): ?>
        <tr data-testid="billing-invoice-line-<?= bv_esc($l['line_no']) ?>">
          <td><?= bv_esc($l['description']) ?></td>
          <td class="right"><?= number_format((float) $l['quantity'], 2) ?></td>
          <td><?= bv_esc($l['unit']) ?></td>
          <td class="right"><?= number_format((float) $l['unit_price'], 2) ?></td>
          <td class="right"><?= number_format((float) $l['subtotal'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <dl>
        <dt>Subtotal</dt><dd data-testid="billing-invoice-subtotal"><?= bv_money($inv['subtotal'], $inv['currency']) ?></dd>
        <?php if ((float) $inv['tax_total'] > 0): ?>
          <dt>Tax</dt><dd><?= bv_money($inv['tax_total'], $inv['currency']) ?></dd>
        <?php endif; ?>
        <dt class="total">Total</dt><dd class="total" data-testid="billing-invoice-total"><?= bv_money($inv['total'], $inv['currency']) ?></dd>
        <?php if ((float) $inv['amount_paid'] > 0): ?>
          <dt>Amount paid</dt><dd>-<?= bv_money($inv['amount_paid'], $inv['currency']) ?></dd>
          <dt class="total">Balance due</dt><dd class="total"><?= bv_money($inv['amount_due'], $inv['currency']) ?></dd>
        <?php endif; ?>
      </dl>
    </div>

    <?php if (!empty($inv['notes_external'])): ?>
      <div class="notes" data-testid="billing-invoice-notes"><?= bv_esc($inv['notes_external']) ?></div>
    <?php endif; ?>
    <?php if (!empty($tenantRow['billing_payment_instructions'])): ?>
      <div class="notes" data-testid="billing-invoice-payment-instructions">
        <strong>Payment instructions:</strong><br>
        <?= nl2br(bv_esc($tenantRow['billing_payment_instructions'])) ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>
