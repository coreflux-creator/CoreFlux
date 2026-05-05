import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/**
 * Three-way match panel — surfaces match status when the bill references
 * a PO. Shows soft warnings if bill ≠ PO ≠ receipt totals (within tolerance).
 */
export default function ThreeWayMatchPanel({ billId }) {
  const { data, loading } = useApi(`/modules/ap/api/purchase_orders.php?action=match&bill_id=${billId}`);
  if (loading || !data) return null;
  const { matched, po, po_total, receipt_total, bill_total, tolerance_pct, warnings, enforce } = data;

  return (
    <div data-testid="ap-bill-three-way-match" style={{
      padding: 12, borderRadius: 8, marginBottom: 16,
      border: matched ? '1px solid #d1fae5' : '1px solid #fef3c7',
      background: matched ? '#f0fdf4' : '#fffbeb',
    }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
        <strong style={{ fontSize: 13 }}>
          {matched ? '✓ Three-way match passed' : '⚠ Three-way match — review needed'}
          {enforce && !matched && <span className="badge" style={{ marginLeft: 8, background: '#991b1b', color: '#fff' }}>BLOCKING</span>}
        </strong>
        {po ? <Link to={`/modules/ap/purchase-orders/${po.id}`} data-testid="ap-bill-three-way-po-link" style={{ fontSize: 12 }}>PO {po.po_number} →</Link> : null}
      </header>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 8, fontSize: 12 }}>
        <div><span className="muted">Bill total</span><br/><strong>{fmtMoney(bill_total)}</strong></div>
        <div><span className="muted">PO total</span><br/><strong>{fmtMoney(po_total)}</strong></div>
        <div><span className="muted">Received total</span><br/><strong>{fmtMoney(receipt_total)}</strong></div>
        <div><span className="muted">Tolerance</span><br/><strong>±{Number(tolerance_pct).toFixed(1)}%</strong></div>
      </div>
      {warnings && warnings.length > 0 && (
        <ul data-testid="ap-bill-three-way-warnings" style={{ margin: '8px 0 0', paddingLeft: 18, fontSize: 12, color: '#92400e' }}>
          {warnings.map((w, i) => <li key={i}>{w}</li>)}
        </ul>
      )}
    </div>
  );
}
