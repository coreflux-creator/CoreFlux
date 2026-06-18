/**
 * GlDetailDrilldown — reusable slide-over modal that shows GL detail
 * for an account between (start, end). Click any row → opens the JE
 * detail page in a new tab.
 *
 * Use from any report:
 *   const [drill, setDrill] = useState(null);
 *   ...
 *   <span onClick={() => setDrill({ accountCode: row.code, start, end })}>
 *     {fmtMoney(row.amount)}
 *   </span>
 *   {drill && <GlDetailDrilldown {...drill} onClose={() => setDrill(null)} />}
 *
 * Reads from /api/v1/accounting/gl-detail (RBAC: accounting.coa.view).
 */
import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { fmtMoney } from '../lib/format';
import { X, ExternalLink } from 'lucide-react';

const GL_DETAIL_API = '/api/v1/accounting/gl-detail';

export default function GlDetailDrilldown({
  accountCode = null,
  accountId   = null,
  start,
  end,
  entityId    = null,
  label       = null,
  reportKey   = null,
  onClose,
}) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError(null);
    const qs = new URLSearchParams({ start, end });
    if (accountId)   qs.set('account_id',   String(accountId));
    if (accountCode) qs.set('account_code', accountCode);
    if (entityId)    qs.set('entity_id',    String(entityId));
    api.get(`${GL_DETAIL_API}?${qs.toString()}`)
      .then(r => { if (!cancelled) setData(r); })
      .catch(e => { if (!cancelled) setError(e.message || 'Failed to load GL detail'); })
      .finally(() => { if (!cancelled) setLoading(false); });

    // Fire-and-forget drill-through audit log. Failures are intentionally
    // silenced — drill logging must never block the drill itself.
    if (reportKey) {
      api.post('/api/admin/reports/log_drilldown.php', {
        report_key:   reportKey,
        account_code: accountCode || null,
        period_from:  start,
        period_to:    end,
        label,
      }).catch(() => {});
    }
    return () => { cancelled = true; };
  }, [accountId, accountCode, start, end, entityId, reportKey, label]);

  return (
    <div data-testid="gl-drilldown-modal"
         style={{ position: 'fixed', inset: 0, zIndex: 250,
                  background: 'rgba(15,23,42,0.45)',
                  display: 'flex', justifyContent: 'flex-end' }}
         onClick={onClose}>
      <div
        style={{ width: 'min(960px, 100%)', background: '#fff',
                 boxShadow: '-12px 0 40px rgba(15,23,42,0.25)',
                 display: 'flex', flexDirection: 'column' }}
        onClick={e => e.stopPropagation()}
      >
        <header style={{ padding: 14, borderBottom: '1px solid #e2e8f0',
                         display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.3 }}>
              GL detail · {start} → {end}
            </div>
            <h3 data-testid="gl-drilldown-title"
                style={{ margin: '2px 0 0', fontSize: 18 }}>
              {label || `Account ${accountCode || accountId}`}
              {data?.account && (
                <span style={{ marginLeft: 8, fontSize: 13, color: '#64748b', fontWeight: 400 }}>
                  <code>{data.account.code}</code> · {data.account.name}
                </span>
              )}
            </h3>
          </div>
          <button data-testid="gl-drilldown-close" onClick={onClose}
                  className="btn btn--ghost" style={{ fontSize: 13 }}>
            <X size={14} style={{ marginRight: 4, verticalAlign: 'middle' }} />Close
          </button>
        </header>

        {loading && <p data-testid="gl-drilldown-loading" style={{ padding: 20 }}>Loading…</p>}
        {error   && <p data-testid="gl-drilldown-error"   style={{ padding: 20, color: '#b91c1c' }}>{error}</p>}

        {data && (
          <>
            <div style={{ padding: '10px 14px', display: 'flex', gap: 14,
                          background: '#f8fafc', borderBottom: '1px solid #e2e8f0',
                          fontSize: 12, color: '#475569' }}>
              <span data-testid="gl-drilldown-opening">
                Opening: <strong>{fmtMoney(data.opening_balance)}</strong>
              </span>
              <span data-testid="gl-drilldown-total-debit">
                Debit: <strong>{fmtMoney(data.totals?.debit)}</strong>
              </span>
              <span data-testid="gl-drilldown-total-credit">
                Credit: <strong>{fmtMoney(data.totals?.credit)}</strong>
              </span>
              <span data-testid="gl-drilldown-net">
                Net: <strong>{fmtMoney(data.totals?.net)}</strong>
              </span>
              <span data-testid="gl-drilldown-ending"
                    style={{ marginLeft: 'auto', fontWeight: 600, color: '#0f172a' }}>
                Ending: {fmtMoney(data.totals?.ending_balance)}
              </span>
            </div>

            <div style={{ flex: 1, overflow: 'auto' }}>
              {(data.lines || []).length === 0 ? (
                <p data-testid="gl-drilldown-empty" style={{ padding: 20, color: '#64748b' }}>
                  No journal lines in this period.
                </p>
              ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                  <thead style={{ background: '#f8fafc', position: 'sticky', top: 0 }}>
                    <tr>
                      <th style={th}>Date</th>
                      <th style={th}>JE #</th>
                      <th style={th}>Memo</th>
                      <th style={th}>Source</th>
                      <th style={{ ...th, textAlign: 'right' }}>Debit</th>
                      <th style={{ ...th, textAlign: 'right' }}>Credit</th>
                      <th style={{ ...th, textAlign: 'right' }}>Running</th>
                      <th style={th}></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.lines.map((ln, i) => (
                      <tr key={`${ln.je_id}-${i}`}
                          data-testid={`gl-drilldown-row-${ln.je_id}`}
                          style={{ borderTop: '1px solid #f1f5f9' }}>
                        <td style={td}>{ln.posting_date}</td>
                        <td style={td}><code>{ln.je_number}</code></td>
                        <td style={{ ...td, maxWidth: 320, whiteSpace: 'nowrap',
                                     overflow: 'hidden', textOverflow: 'ellipsis' }}
                            title={ln.memo || ''}>
                          {ln.memo || <em style={{ color: '#94a3b8' }}>—</em>}
                        </td>
                        <td style={td}>
                          {ln.source_module ? (
                            <span style={{ fontSize: 11, color: '#64748b' }}>
                              {ln.source_module}{ln.source_ref_type ? `/${ln.source_ref_type}` : ''}
                            </span>
                          ) : <em style={{ color: '#cbd5e1' }}>—</em>}
                        </td>
                        <td style={{ ...td, textAlign: 'right', color: '#16a34a' }}>
                          {ln.debit ? fmtMoney(ln.debit) : ''}
                        </td>
                        <td style={{ ...td, textAlign: 'right', color: '#dc2626' }}>
                          {ln.credit ? fmtMoney(ln.credit) : ''}
                        </td>
                        <td style={{ ...td, textAlign: 'right', fontWeight: 600 }}>
                          {fmtMoney(ln.running)}
                        </td>
                        <td style={td}>
                          <a href={`/modules/accounting/journal-entries/${ln.je_id}`}
                             target="_blank" rel="noreferrer"
                             data-testid={`gl-drilldown-open-${ln.je_id}`}
                             title="Open JE"
                             style={{ color: '#0ea5e9' }}>
                            <ExternalLink size={12} />
                          </a>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

const th = {
  textAlign: 'left', padding: '8px 10px', fontSize: 11, fontWeight: 600,
  color: '#475569', borderBottom: '1px solid #e2e8f0',
};
const td = {
  padding: '8px 10px', verticalAlign: 'top', fontSize: 12, color: '#1e293b',
};
