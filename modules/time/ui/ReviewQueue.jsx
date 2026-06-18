import React, { useState, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import TokenIssueModal from './TokenIssueModal';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

/**
 * Review Queue — pending_review entries, grouped by source; inline approve/reject
 * plus multi-select + "Request client approval" tokenized-email flow.
 * Two-eye: approver must NOT be the creator (enforced server-side).
 */
export default function ReviewQueue() {
  const path = '/api/v1/time/entries?status=pending_review&per_page=500';
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];

  const [busy, setBusy] = useState(null);
  const [uiError, setUiError] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [issueFor, setIssueFor] = useState(null);
  const [toast, setToast] = useState(null);

  const approve = async (id) => {
    setBusy(id); setUiError(null);
    try { await api.post(`/api/v1/time/entries?action=approve&id=${id}`, {}); reload(); }
    catch (e) { setUiError(e); } finally { setBusy(null); }
  };
  const reject = async (id) => {
    const reason = prompt('Reject reason (required):');
    if (!reason) return;
    setBusy(id); setUiError(null);
    try { await api.post(`/api/v1/time/entries?action=reject&id=${id}`, { reason }); reload(); }
    catch (e) { setUiError(e); } finally { setBusy(null); }
  };

  const toggle = (id) => {
    setSelected(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const selectedRows = useMemo(() => rows.filter(r => selected.has(r.id)), [rows, selected]);
  const selectionShape = useMemo(() => {
    if (selectedRows.length === 0) return { ok: false, reason: null };
    const placement = selectedRows[0].placement_id;
    const period = selectedRows[0].period_id;
    const mixed = selectedRows.some(r => r.placement_id !== placement || r.period_id !== period);
    if (mixed) return { ok: false, reason: 'Selected entries span multiple placements or periods.' };
    return { ok: true };
  }, [selectedRows]);

  const openIssueModal = () => {
    if (!selectionShape.ok) return;
    setIssueFor(selectedRows);
  };

  const handleIssued = (res) => {
    setIssueFor(null);
    setSelected(new Set());
    if (res.email_status === 'sent') {
      setToast({ kind: 'ok', msg: `Approval email sent (token #${res.token_id}). Expires ${res.expires_at}.` });
    } else {
      setToast({ kind: 'warn', msg: `Token created but email failed: ${res.email_error || 'unknown'}. Configure RESEND_API_KEY, then revoke or reissue.` });
    }
    reload();
  };

  const bySource = rows.reduce((acc, r) => { (acc[r.source] = acc[r.source] || []).push(r); return acc; }, {});
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ status: 'pending_review', template_id: String(tplId) });
    return `/api/v1/time/csv-export?${params.toString()}`;
  };

  return (
    <section className="people-directory" data-testid="time-review-queue">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h2>Review Queue</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>Pending entries grouped by source. Two-eye control: you cannot approve your own entries.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          <a className="btn" href="/api/v1/time/csv-export?status=pending_review" data-testid="time-review-export-csv">Export CSV</a>
          <ExportTemplatePicker
            dataset="time_entries"
            buildHref={buildTemplateExportHref}
            label="Export via template"
            testid="time-entries-export-template"
          />
        </div>
      </div>

      {selected.size > 0 && (
        <div
          data-testid="time-review-selection-bar"
          style={{
            position: 'sticky', top: 0, zIndex: 5,
            background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)',
            borderRadius: 8, padding: '10px 14px', display: 'flex', alignItems: 'center',
            justifyContent: 'space-between', gap: 12, marginBottom: 'var(--cf-space-3)',
          }}
        >
          <div>
            <strong data-testid="time-review-selection-count">{selected.size}</strong> selected
            {!selectionShape.ok && selectionShape.reason && (
              <span style={{ marginLeft: 10, color: 'var(--cf-danger, #b91c1c)', fontSize: 13 }} data-testid="time-review-selection-invalid">
                {selectionShape.reason}
              </span>
            )}
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={() => setSelected(new Set())} data-testid="time-review-selection-clear">Clear</button>
            <button className="btn btn--primary" onClick={openIssueModal} disabled={!selectionShape.ok} data-testid="time-review-request-client-approval">
              Request client approval
            </button>
          </div>
        </div>
      )}

      {toast && (
        <div
          data-testid="time-review-toast"
          onClick={() => setToast(null)}
          style={{
            padding: 10, borderRadius: 8, marginBottom: 12, cursor: 'pointer', fontSize: 14,
            background: toast.kind === 'ok' ? '#ecfdf5' : '#fffbeb',
            color:      toast.kind === 'ok' ? '#047857' : '#92400e',
            border: `1px solid ${toast.kind === 'ok' ? '#a7f3d0' : '#fde68a'}`,
          }}
        >
          {toast.msg} <span style={{ float: 'right', opacity: 0.6 }}>dismiss</span>
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="time-review-error">Error: {error.message}</p>}
      {uiError && <p className="error" data-testid="time-review-ui-error">Error: {uiError.message}</p>}
      {!loading && rows.length === 0 && <p className="empty" data-testid="time-review-empty">Nothing pending review.</p>}

      {Object.entries(bySource).map(([source, group]) => (
        <div key={source} style={{ marginBottom: 'var(--cf-space-5)' }} data-testid={`time-review-source-${source}`}>
          <h3>{source} ({group.length})</h3>
          <table className="data-table" data-testid={`time-review-table-${source}`}>
            <thead><tr><th></th><th>Date</th><th>Person</th><th>Placement</th><th>Category</th><th>Hours</th><th>Description</th><th>Actions</th></tr></thead>
            <tbody>
              {group.map(r => (
                <tr key={r.id} data-testid={`time-review-row-${r.id}`}>
                  <td>
                    <input
                      type="checkbox"
                      checked={selected.has(r.id)}
                      onChange={() => toggle(r.id)}
                      data-testid={`time-review-select-${r.id}`}
                      aria-label={`Select entry ${r.id}`}
                    />
                  </td>
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

      {issueFor && (
        <TokenIssueModal
          entries={issueFor}
          onClose={() => setIssueFor(null)}
          onIssued={handleIssued}
        />
      )}
    </section>
  );
}
