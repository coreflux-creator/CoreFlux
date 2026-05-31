import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <AccountingOutbox /> — operator view of the accounting outbox.
 * Lists every queued/processing/posted/failed/retrying/dead_letter
 * row for the tenant. Operators can:
 *   - filter by status
 *   - drill into a row to see the full payload + provider_result
 *   - retry a failed/dead_letter row (resets attempts on dead_letter)
 *   - cancel a queued/retrying/failed row
 *
 * Real value: shows the EXACT Jaz error message when a draft fails
 * (`contactResourceId required`, `lineItems[0] missing accountResourceId`,
 * …). Closes the feedback loop on the CoreFlux→Jaz mapper.
 *
 * Mounted at /admin/accounting/outbox.
 */
export default function AccountingOutbox() {
  const [rows, setRows] = useState([]);
  const [byStatus, setByStatus] = useState({});
  const [statusFilter, setStatusFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [detail, setDetail] = useState(null);
  const [actBusy, setActBusy] = useState({});

  const reload = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const qs = statusFilter ? `?status=${statusFilter}&limit=200` : '?limit=200';
      const r = await api.get(`/api/admin/accounting/outbox.php${qs}`);
      setRows(r.rows || []);
      setByStatus(r.by_status || {});
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  }, [statusFilter]);
  useEffect(() => { reload(); }, [reload]);

  const handleRetry = async (id) => {
    setActBusy(s => ({ ...s, [id]: true }));
    try {
      const r = await api.post('/api/admin/accounting/outbox.php?action=retry', { id });
      if (r.kicked_inline) {
        // Show updated row inline.
      }
      await reload();
    } catch (e) { setError(e.message || 'Retry failed'); }
    finally { setActBusy(s => ({ ...s, [id]: false })); }
  };

  const handleCancel = async (id) => {
    if (!confirm('Cancel this outbox row? It will move to dead_letter.')) return;
    setActBusy(s => ({ ...s, [id]: true }));
    try {
      await api.post('/api/admin/accounting/outbox.php?action=cancel', { id });
      await reload();
    } catch (e) { setError(e.message || 'Cancel failed'); }
    finally { setActBusy(s => ({ ...s, [id]: false })); }
  };

  const openDetail = async (id) => {
    try {
      const r = await api.get(`/api/admin/accounting/outbox.php?action=detail&id=${id}`);
      setDetail(r.command);
    } catch (e) { setError(e.message || 'Detail failed'); }
  };

  const STATUSES = ['queued', 'processing', 'posted', 'retrying', 'failed', 'dead_letter'];

  return (
    <section data-testid="accounting-outbox" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>Accounting Outbox</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Drafts queued for the accounting backend (Jaz). Errors here tell you
          exactly which fields the provider rejected.
        </p>
      </header>

      {error && <div className="error" data-testid="outbox-error">{error}</div>}

      <div style={{ marginBottom: 12, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <button
          className={`btn ${statusFilter === '' ? 'btn--primary' : 'btn--ghost'}`}
          onClick={() => setStatusFilter('')}
          data-testid="outbox-filter-all">
          All ({Object.values(byStatus).reduce((a, b) => a + b, 0)})
        </button>
        {STATUSES.map(s => (
          <button
            key={s}
            className={`btn ${statusFilter === s ? 'btn--primary' : 'btn--ghost'}`}
            onClick={() => setStatusFilter(s)}
            data-testid={`outbox-filter-${s}`}
            style={{ textTransform: 'capitalize' }}>
            {s.replace('_', ' ')} ({byStatus[s] || 0})
          </button>
        ))}
      </div>

      {loading ? (
        <p data-testid="outbox-loading">Loading…</p>
      ) : rows.length === 0 ? (
        <p data-testid="outbox-empty" style={{ color: '#64748b', fontSize: 13 }}>
          No outbox rows {statusFilter && `in "${statusFilter}"`} yet.
        </p>
      ) : (
        <table className="data-table" data-testid="outbox-table" style={{ width: '100%', fontSize: 13 }}>
          <thead>
            <tr style={{ color: '#64748b', textAlign: 'left' }}>
              <th style={{ padding: '6px 8px' }}>ID</th>
              <th style={{ padding: '6px 8px' }}>Type</th>
              <th style={{ padding: '6px 8px' }}>CoreFlux ref</th>
              <th style={{ padding: '6px 8px' }}>Provider</th>
              <th style={{ padding: '6px 8px' }}>Status</th>
              <th style={{ padding: '6px 8px' }}>Attempts</th>
              <th style={{ padding: '6px 8px' }}>Last error</th>
              <th style={{ padding: '6px 8px' }}>Updated</th>
              <th style={{ padding: '6px 8px' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} data-testid={`outbox-row-${r.id}`}>
                <td style={{ padding: '6px 8px' }}>
                  <button className="link" onClick={() => openDetail(r.id)}
                          data-testid={`outbox-detail-${r.id}`}
                          style={{ background: 'none', border: 0, color: '#2563eb', cursor: 'pointer' }}>
                    #{r.id}
                  </button>
                </td>
                <td style={{ padding: '6px 8px' }}><code>{r.command_type}</code></td>
                <td style={{ padding: '6px 8px' }}>
                  {r.object_type ? `${r.object_type}#${r.object_id || '?'}` : '—'}
                </td>
                <td style={{ padding: '6px 8px' }}>{r.provider}</td>
                <td style={{ padding: '6px 8px' }}>
                  <span style={statusBadge(r.status)}>{r.status.replace('_', ' ')}</span>
                </td>
                <td style={{ padding: '6px 8px' }}>{r.attempts}/{r.max_attempts}</td>
                <td style={{ padding: '6px 8px', maxWidth: 260,
                             overflow: 'hidden', textOverflow: 'ellipsis',
                             whiteSpace: 'nowrap', color: '#b91c1c' }}
                    title={r.error_message || ''}
                    data-testid={`outbox-error-${r.id}`}>
                  {r.error_code && <strong>[{r.error_code}] </strong>}
                  {r.error_message || '—'}
                </td>
                <td style={{ padding: '6px 8px', whiteSpace: 'nowrap', color: '#64748b', fontSize: 11 }}>
                  {r.updated_at || r.created_at}
                </td>
                <td style={{ padding: '6px 8px', whiteSpace: 'nowrap' }}>
                  {(r.status === 'failed' || r.status === 'retrying' || r.status === 'dead_letter') && (
                    <button className="btn btn--ghost" onClick={() => handleRetry(r.id)}
                            disabled={!!actBusy[r.id]}
                            data-testid={`outbox-retry-${r.id}`}
                            style={{ fontSize: 11 }}>
                      {actBusy[r.id] ? '…' : 'Retry'}
                    </button>
                  )}
                  {(r.status === 'queued' || r.status === 'retrying' || r.status === 'failed') && (
                    <button className="btn btn--ghost" onClick={() => handleCancel(r.id)}
                            disabled={!!actBusy[r.id]}
                            data-testid={`outbox-cancel-${r.id}`}
                            style={{ fontSize: 11, marginLeft: 4 }}>
                      Cancel
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {detail && (
        <div data-testid="outbox-detail-modal"
             style={modalOverlay}
             onClick={(e) => e.target === e.currentTarget && setDetail(null)}>
          <div style={modalCard}>
            <h3 style={{ margin: '0 0 12px' }}>Outbox command #{detail.id}</h3>
            <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 8px' }}>
              <code>{detail.command_type}</code> · {detail.provider} · status: <code>{detail.status}</code>
              {' · '}attempts: {detail.attempts}/{detail.max_attempts}
              {detail.idempotency_key && (<><br />idempotency: <code>{detail.idempotency_key}</code></>)}
            </p>
            <details open style={{ marginBottom: 12 }}>
              <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>command_payload</summary>
              <pre style={preStyle}>{JSON.stringify(detail.command_payload, null, 2)}</pre>
            </details>
            <details style={{ marginBottom: 12 }}>
              <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>provider_result</summary>
              <pre style={preStyle}>{JSON.stringify(detail.provider_result, null, 2)}</pre>
            </details>
            {detail.error_message && (
              <div style={{ padding: 8, background: '#fef2f2', color: '#b91c1c', fontSize: 12, borderRadius: 4 }}>
                <strong>[{detail.error_code}]</strong> {detail.error_message}
              </div>
            )}
            <div style={{ marginTop: 12, textAlign: 'right' }}>
              <button className="btn" onClick={() => setDetail(null)}
                      data-testid="outbox-detail-close">Close</button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}

function statusBadge(s) {
  const colors = {
    queued:      ['#eff6ff', '#1e40af'],
    processing:  ['#fef3c7', '#92400e'],
    posted:      ['#dcfce7', '#166534'],
    retrying:    ['#fef3c7', '#92400e'],
    failed:      ['#fee2e2', '#991b1b'],
    dead_letter: ['#fee2e2', '#7f1d1d'],
  }[s] || ['#f1f5f9', '#475569'];
  return {
    padding: '2px 8px', borderRadius: 4, fontSize: 11, fontWeight: 600,
    background: colors[0], color: colors[1], textTransform: 'capitalize',
  };
}
const modalOverlay = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)',
  display: 'flex', alignItems: 'center', justifyContent: 'center',
  zIndex: 50,
};
const modalCard = {
  background: '#fff', padding: 20, borderRadius: 8,
  width: '80%', maxWidth: 720, maxHeight: '85vh', overflowY: 'auto',
};
const preStyle = {
  background: '#f8fafc', padding: 8, borderRadius: 4,
  fontSize: 11, fontFamily: 'ui-monospace, monospace',
  maxHeight: 200, overflow: 'auto',
};
