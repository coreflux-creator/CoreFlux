import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

export default function AgingTable() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const { data, loading, error } = useApi(`/modules/billing/api/aging.php?as_of=${asOf}`);
  const rows = data?.rows ?? [];

  const totals = rows.reduce((acc, r) => ({
    cur: acc.cur + Number(r.bucket_current),
    b1:  acc.b1  + Number(r.bucket_1_30),
    b2:  acc.b2  + Number(r.bucket_31_60),
    b3:  acc.b3  + Number(r.bucket_61_90),
    b4:  acc.b4  + Number(r.bucket_91_plus),
    tot: acc.tot + Number(r.total_due),
  }), { cur: 0, b1: 0, b2: 0, b3: 0, b4: 0, tot: 0 });

  return (
    <section data-testid="billing-aging">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <h3 style={{ margin: 0 }}>AR aging</h3>
        <label style={{ fontSize: 13 }}>
          As of <input type="date" className="input" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="billing-aging-asof" style={{ marginLeft: 8 }} />
        </label>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-aging-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-aging-table">
        <thead>
          <tr>
            <th>Client</th>
            <th style={{textAlign:'right'}}>Current</th>
            <th style={{textAlign:'right'}}>1-30</th>
            <th style={{textAlign:'right'}}>31-60</th>
            <th style={{textAlign:'right'}}>61-90</th>
            <th style={{textAlign:'right'}}>91+</th>
            <th style={{textAlign:'right'}}>Total due</th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={7} className="empty" data-testid="billing-aging-empty">Nothing outstanding as of {asOf}.</td></tr>}
          {rows.map((r, i) => (
            <tr key={i} data-testid={`billing-aging-row-${i}`}>
              <td>{r.client_name}</td>
              <td style={{textAlign:'right'}}>{Number(r.bucket_current).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(r.bucket_1_30).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(r.bucket_31_60).toFixed(2)}</td>
              <td style={{textAlign:'right', color: Number(r.bucket_61_90) > 0 ? 'var(--cf-warning, #b45309)' : undefined}}>{Number(r.bucket_61_90).toFixed(2)}</td>
              <td style={{textAlign:'right', color: Number(r.bucket_91_plus) > 0 ? 'var(--cf-danger, #b91c1c)' : undefined, fontWeight: Number(r.bucket_91_plus) > 0 ? 600 : 400}}>{Number(r.bucket_91_plus).toFixed(2)}</td>
              <td style={{textAlign:'right', fontWeight: 600}}>{Number(r.total_due).toFixed(2)}</td>
            </tr>
          ))}
          {rows.length > 0 && (
            <tr style={{borderTop: '2px solid var(--cf-text, #111827)', fontWeight: 600}} data-testid="billing-aging-totals">
              <td>TOTAL</td>
              <td style={{textAlign:'right'}}>{totals.cur.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b1.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b2.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b3.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b4.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.tot.toFixed(2)}</td>
            </tr>
          )}
        </tbody>
      </table>
    </section>
  );
}
