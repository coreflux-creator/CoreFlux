<?php
/**
 * AP Phase A1 — Three-way match library.
 *
 * Computes match status for a bill against its referenced PO + receipts.
 * Returns a soft warnings/blockers structure that the UI surfaces.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

/**
 * Match a bill to its PO header + line totals.
 *
 *   $billId      → ap_bills.id
 *
 * Returns:
 *   [
 *     'matched'    => bool,
 *     'po'         => { id, po_number, total, status } | null,
 *     'po_total'   => float, 'receipt_total' => float, 'bill_total' => float,
 *     'tolerance_pct' => float,
 *     'warnings'   => [ string, ... ],
 *     'enforce'    => bool,   // tenant policy: hard-block on mismatch
 *   ]
 */
function apThreeWayMatch(int $tenantId, int $billId): array
{
    $pdo = getDB();
    $bill = $pdo->prepare('SELECT id, po_number, total FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1');
    $bill->execute(['t' => $tenantId, 'id' => $billId]);
    $bill = $bill->fetch(\PDO::FETCH_ASSOC);
    if (!$bill) return ['matched' => false, 'po' => null, 'warnings' => ['Bill not found'], 'enforce' => false];

    $cfg = $pdo->prepare('SELECT ap_three_way_match_enforce, ap_three_way_match_tolerance_pct FROM tenants WHERE id = :t');
    $cfg->execute(['t' => $tenantId]);
    $cfg = $cfg->fetch(\PDO::FETCH_ASSOC) ?: ['ap_three_way_match_enforce' => 0, 'ap_three_way_match_tolerance_pct' => 5.0];
    $enforce  = (bool) ((int) ($cfg['ap_three_way_match_enforce'] ?? 0));
    $tolPct   = (float) ($cfg['ap_three_way_match_tolerance_pct'] ?? 5.0);

    $billTotal = (float) ($bill['total'] ?? 0);
    $poNumber  = trim((string) ($bill['po_number'] ?? ''));

    if ($poNumber === '') {
        return [
            'matched' => false, 'po' => null,
            'po_total' => 0.0, 'receipt_total' => 0.0, 'bill_total' => $billTotal,
            'tolerance_pct' => $tolPct, 'warnings' => ['No PO referenced on bill'],
            'enforce' => $enforce,
        ];
    }

    $po = $pdo->prepare('SELECT id, po_number, total, status FROM ap_purchase_orders WHERE tenant_id = :t AND po_number = :pn LIMIT 1');
    $po->execute(['t' => $tenantId, 'pn' => $poNumber]);
    $po = $po->fetch(\PDO::FETCH_ASSOC);
    if (!$po) {
        return [
            'matched' => false, 'po' => null,
            'po_total' => 0.0, 'receipt_total' => 0.0, 'bill_total' => $billTotal,
            'tolerance_pct' => $tolPct,
            'warnings' => ["PO {$poNumber} referenced on bill but not found"],
            'enforce' => $enforce,
        ];
    }

    // Sum received quantities × line unit_price as the receipt-derived total.
    $receiptTotal = 0.0;
    $lines = $pdo->prepare(
        'SELECT pol.quantity_received, pol.unit_price
           FROM ap_purchase_order_lines pol
          WHERE pol.po_id = :po'
    );
    $lines->execute(['po' => (int) $po['id']]);
    foreach ($lines->fetchAll(\PDO::FETCH_ASSOC) as $ln) {
        $receiptTotal += (float) $ln['quantity_received'] * (float) $ln['unit_price'];
    }

    $poTotal = (float) $po['total'];
    $warnings = [];

    $diffPct = $poTotal > 0 ? abs(($billTotal - $poTotal) / $poTotal) * 100 : 0;
    if ($poTotal > 0 && $diffPct > $tolPct) {
        $warnings[] = sprintf('Bill total $%s differs from PO total $%s by %.1f%% (tolerance %.1f%%)',
            number_format($billTotal, 2), number_format($poTotal, 2), $diffPct, $tolPct);
    }
    if ($billTotal > $receiptTotal && $receiptTotal > 0) {
        $warnings[] = sprintf('Bill total $%s exceeds received total $%s',
            number_format($billTotal, 2), number_format($receiptTotal, 2));
    }
    if ($po['status'] === 'cancelled' || $po['status'] === 'closed') {
        $warnings[] = "PO is {$po['status']} — review before approving";
    }

    return [
        'matched' => empty($warnings),
        'po' => $po,
        'po_total' => $poTotal,
        'receipt_total' => $receiptTotal,
        'bill_total' => $billTotal,
        'tolerance_pct' => $tolPct,
        'warnings' => $warnings,
        'enforce' => $enforce,
    ];
}
