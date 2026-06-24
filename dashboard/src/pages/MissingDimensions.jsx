import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { AlertTriangle, ArrowLeft } from 'lucide-react';

/**
 * Missing-dimension review page (Sprint 7f.4).
 * Lists posted JE lines whose account requires a dimension value the entry
 * is missing. Each row deep-links to the parent JE detail.
 */
const MISSING_DIMENSIONS_API = '/api/v1/accounting/missing-dimensions';

export default function MissingDimensions() {
  const { data, loading, error } = useApi(`${MISSING_DIMENSIONS_API}?days=90&limit=200`);

  if (loading) return <p data-testid="missing-dims-loading">Loading…</p>;
  if (error)   return <p className="error" data-testid="missing-dims-error">{error.message}</p>;

  const count = data?.count ?? 0;
  const rows  = data?.rows  ?? [];
  const byAccount = data?.by_account ?? [];

  return (
    <section data-testid="missing-dims-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header>
        <Link to="/modules/accounting/bookkeeping" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          <ArrowLeft size={14} style={{ verticalAlign: 'middle' }} /> Back to Bookkeeping Overview
        </Link>
        <h2 style={{ margin: '8px 0 0', display: 'flex', alignItems: 'center', gap: 8 }}>
          <AlertTriangle size={20} color="#d97706" /> Missing dimension values
        </h2>
        <p style={{ color: '#64748b', fontSize: 13, margin: '4px 0 0' }}>
          Posted journal lines in the last {data?.window_days ?? 90} days where the account requires a dimension that wasn't filled in. Open the JE to fix.
        </p>
      </header>

      {count === 0 && (
        <div data-testid="missing-dims-empty"
             style={{ padding: 16, background: '#ecfdf5', border: '1px solid #a7f3d0', borderRadius: 10, color: '#065f46', fontSize: 14 }}>
          {data?.note || "You're all caught up — no posted lines are missing required dimensions."}
        </div>
      )}

      {byAccount.length > 0 && (
        <div data-testid="missing-dims-by-account-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>By account ({byAccount.length})</h3>
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr><th>Account</th><th>Missing dimensions</th><th>Lines</th></tr>
            </thead>
            <tbody>
              {byAccount.map(a => (
                <tr key={a.account_id}
                    data-testid={`missing-dims-account-row-${a.account_id}`}>
                  <td><code>{a.account_code}</code> {a.account_name}</td>
                  <td>
                    {a.missing_dim_keys.map(k => (
                      <span key={k} className="badge"
                            style={{ marginRight: 4, padding: '2px 8px', background: '#fef3c7',
                                     color: '#92400e', borderRadius: 999, fontSize: 11 }}>{k}</span>
                    ))}
                  </td>
                  <td><strong>{a.missing_count}</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {rows.length > 0 && (
        <div data-testid="missing-dims-rows-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Lines ({count}{count >= (data?.limit ?? 200) ? `, showing ${rows.length}` : ''})</h3>
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr><th>Date</th><th>JE</th><th>Account</th><th>Description</th><th>Missing</th><th></th></tr>
            </thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.line_id} data-testid={`missing-dims-row-${r.line_id}`}>
                  <td style={{ fontSize: 12, color: '#64748b' }}>{r.posting_date}</td>
                  <td><code>{r.je_number}</code></td>
                  <td><code>{r.account_code}</code> {r.account_name}</td>
                  <td style={{ fontSize: 12 }}>{r.description}</td>
                  <td>
                    {r.missing_dim_keys.map(k => (
                      <span key={k} className="badge"
                            style={{ marginRight: 4, padding: '2px 8px', background: '#fef3c7',
                                     color: '#92400e', borderRadius: 999, fontSize: 11 }}>{k}</span>
                    ))}
                  </td>
                  <td>
                    <Link className="btn btn--ghost"
                          data-testid={`missing-dims-open-je-${r.je_id}`}
                          to={`/modules/accounting/journal-entries/${r.je_id}`}
                          style={{ fontSize: 12 }}>Open JE →</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
