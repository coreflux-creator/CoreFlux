import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Review Queue — pending_review entries, grouped by source; inline approve/reject/correct.
 * Two-eye: approver must NOT be the creator (enforced server-side).
 */
export default function ReviewQueue() {
  const path = '/modules/time/api/entries.php?status=pending_review&per_page=500';
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];

  const [busy, setBusy] = useState(null);
  const [uiError, setUiError] = useState(null);

  const approve = async (id) => {
    setBusy(id); setUiError(null);
    try { await api.post(`/modules/time/api/entries.php?action=approve&id=${id}`, {}); reload(); }
    catch (e) { setUiError(e); } finally { setBusy(null); }
  };
  const reject = async (id) => {
    const reason = prompt('Reject reason (required):');
    if (!reason) return;
    setBusy(id); setUiError(null);
    try { await api.post(`/modules/time/api/entries.php?action=reject&id=${id}`, { reason }); reload(); }
    catch (e) { setUiError(e); } finally { setBusy(null); }
  };

  const bySource = rows.reduce((acc, r) => { (acc[r.source] = acc[r.source] || []).push(r); return acc; }, {});

  return (
    <section className="people-directory" data-testid="time-review-queue">
      <h2>Review Queue</h2>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Pending entries grouped by source. Two-eye control: you cannot approve your own entries.</p>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="time-review-error">Error: {error.message}</p>}
      {uiError && <p className="error" data-testid="time-review-ui-error">Error: {uiError.message}</p>}
      {!loading && rows.length === 0 && <p className="empty" data-testid="time-review-empty">Nothing pending review.</p>}

      {Object.entries(bySource).map(([source, group]) => (
        <div key={source} style={{ marginBottom: 'var(--cf-space-5)' }} data-testid={`time-review-source-${source}`}>
          <h3>{source} ({group.length})</h3>
          <table className="data-table" data-testid={`time-review-table-${source}`}>
            <thead><tr><th>Date</th><th>Person</th><th>Placement</th><th>Category</th><th>Hours</th><th>Description</th><th>Actions</th></tr></thead>
            <tbody>
              {group.map(r => (
                <tr key={r.id} data-testid={`time-review-row-${r.id}`}>
                  <td>{r.work_date}</td>
                  <td>{r.first_name} {r.last_name}</td>
                  <td>{r.placement_title} <span style={{ color: 'var(--cf-text-secondary)' }}>· {r.end_client_name || '—'}</span></td>
                  <td>{r.category}</td>
                  <td>{parseFloat(r.hours).toFixed(2)}</td>
                  <td>{r.description || '—'}</td>
                  <td>
                    <button className="btn btn--primary" onClick={() => approve(r.id)} disabled={busy === r.id} data-testid={`time-review-approve-${r.id}`}>Approve</button>
                    {' '}
                    <button className="btn" onClick={() => reject(r.id)} disabled={busy === r.id} data-testid={`time-review-reject-${r.id}`}>Reject</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
    </section>
  );
}
