import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney } from '../../../dashboard/src/lib/format';

export default function AgingTable() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const { data, loading, error } = useApi(`/modules/ap/api/aging.php?as_of=${asOf}`);
  const rows = data?.rows ?? [];

  const totals = rows.reduce((acc, r) => {
    acc.current   += Number(r.bucket_current || 0);
    acc.d1_30     += Number(r.bucket_1_30 || 0);
    acc.d31_60    += Number(r.bucket_31_60 || 0);
    acc.d61_90    += Number(r.bucket_61_90 || 0);
    acc.d91_plus  += Number(r.bucket_91_plus || 0);
    acc.total_due += Number(r.total_due || 0);
    return acc;
  }, { current: 0, d1_30: 0, d31_60: 0, d61_90: 0, d91_plus: 0, total_due: 0 });

  return (
    <section className="report-page aging-report" data-testid="ap-aging-table">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3 style={{ margin: 0 }}>Accounts payable aging</h3>
          <p className="report-page__meta">Posted vendor balances as of {asOf}</p>
        </div>
        <label style={{ fontSize: 13 }}>As of <input className="input" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="ap-aging-asof" style={{ marginLeft: 8 }} /></label>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      {!loading && !error && (
        <div className="aging-summary" data-testid="ap-aging-summary">
          <AgingSummary label="Total payables" value={totals.total_due} tone="blue" />
          <AgingSummary label="Current" value={totals.current} tone="teal" />
          <AgingSummary label="Past due" value={totals.d1_30 + totals.d31_60 + totals.d61_90 + totals.d91_plus} tone="amber" />
          <AgingSummary label="Over 90 days" value={totals.d91_plus} tone="red" />
        </div>
      )}

      <div className="data-table-wrap aging-table-wrap">
      <table className="data-table" data-testid="ap-aging-data">
        <thead><tr><th>Vendor</th><th style={{textAlign:'right'}}>Current</th><th style={{textAlign:'right'}}>1-30</th><th style={{textAlign:'right'}}>31-60</th><th style={{textAlign:'right'}}>61-90</th><th style={{textAlign:'right'}}>91+</th><th style={{textAlign:'right'}}>Total due</th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={7} className="empty" data-testid="ap-aging-empty">No open bills.</td></tr>}
          {rows.map((r, i) => (
            <tr key={i} data-testid={`ap-aging-row-${i}`}>
              <td>{r.vendor_name}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(r.bucket_current)}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(r.bucket_1_30)}</td>
              <td style={{ textAlign: 'right', color: Number(r.bucket_31_60) > 0 ? '#b7791f' : undefined }}>{fmtMoney(r.bucket_31_60)}</td>
              <td style={{ textAlign: 'right', color: Number(r.bucket_61_90) > 0 ? '#dc2626' : undefined }}>{fmtMoney(r.bucket_61_90)}</td>
              <td style={{ textAlign: 'right', color: Number(r.bucket_91_plus) > 0 ? '#b91c1c' : undefined, fontWeight: Number(r.bucket_91_plus) > 0 ? 700 : 400 }}>{fmtMoney(r.bucket_91_plus)}</td>
              <td style={{ textAlign: 'right', fontWeight: 600 }}>{fmtMoney(r.total_due)}</td>
            </tr>
          ))}
          {rows.length > 0 && (
            <tr data-testid="ap-aging-totals" style={{ background: 'var(--cf-surface-alt, #f9fafb)', fontWeight: 700 }}>
              <td>Total</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(totals.current)}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(totals.d1_30)}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(totals.d31_60)}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(totals.d61_90)}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(totals.d91_plus)}</td>
              <td style={{ textAlign: 'right' }}>{fmtMoney(totals.total_due)}</td>
            </tr>
          )}
        </tbody>
      </table>
      </div>
    </section>
  );
}

function AgingSummary({ label, value, tone }) {
  return (
    <div className={`aging-summary__item aging-summary__item--${tone}`}>
      <span>{label}</span>
      <strong>{fmtMoney(value)}</strong>
    </div>
  );
}
