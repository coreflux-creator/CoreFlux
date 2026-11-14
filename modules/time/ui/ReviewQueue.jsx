import React, { useState, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import TokenIssueModal from './TokenIssueModal';
import TimeDecisionReview from './TimeDecisionReview';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

/**
 * Review Queue — pending_review entries, grouped by source; inline approve/reject
 * plus multi-select + "Request client approval" tokenized-email flow.
 * Two-eye: approver must NOT be the creator (enforced server-side).
 */
export default function ReviewQueue() {
  const path = '/api/v1/time/entries?status=pending_review&per_page=500';
  const { data, loading, error, reload } = useApi(path);
  const rows = useMemo(() => data?.rows ?? [], [data?.rows]);

  const [decisionReview, setDecisionReview] = useState(null);
  const busy = !!decisionReview;
  const [uiError, setUiError] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [issueFor, setIssueFor] = useState(null);
  const [toast, setToast] = useState(null);

  const approve = id => setDecisionReview({ ids: [id], decision: 'approve' });
  const reject = id => setDecisionReview({ ids: [id], decision: 'reject' });
  const approveSelected = () => {
    const ids = [...selected].filter(id => rows.some(row => row.id === id));
    if (ids.length) setDecisionReview({ ids, decision: 'approve' });
  };
  const rejectSelected = () => {
    const ids = [...selected].filter(id => rows.some(row => row.id === id));
    if (ids.length) setDecisionReview({ ids, decision: 'reject' });
  };
  const decided = result => {
    setDecisionReview(null); setSelected(new Set()); setUiError(null);
    setToast({ kind: 'ok', msg: `${result.succeeded} entries ${result.decision === 'approve' ? 'approved' : 'returned for correction'}.` });
    reload();
  };

  const toggle = (id) => {
    setSelected(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };
  const allSelected = rows.length > 0 && rows.every(r => selected.has(r.id));
  const toggleAll = () => {
    setSelected(allSelected ? new Set() : new Set(rows.map(r => r.id)));
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
      setToast({ kind: 'ok', msg: `Approval email sent. The link expires ${res.expires_at}.` });
    } else {
      setToast({ kind: 'warn', msg: 'The approval request was created, but the email could not be sent. Check the email connection under Connections, then revoke or resend it.' });
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
          <h2>Review queue</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Review pending time grouped by source. Someone other than the submitter must approve it.
          </p>
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

      {rows.length > 0 && (
        <label style={{ display: 'inline-flex', alignItems: 'center', gap: 7, margin: '6px 0 12px', fontSize: 13 }}>
          <input
            type="checkbox"
            checked={allSelected}
            onChange={toggleAll}
            disabled={!!busy}
            data-testid="time-review-select-all"
          />
          Select all {rows.length} pending entries
        </label>
      )}

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
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
            <button className="btn btn--ghost" onClick={() => setSelected(new Set())} data-testid="time-review-selection-clear">Clear</button>
            <button className="btn btn--primary" onClick={approveSelected} disabled={!!busy} data-testid="time-review-approve-selected">
              {busy === 'bulk_approve' ? 'Approving…' : 'Approve selected'}
            </button>
            <button className="btn" onClick={rejectSelected} disabled={!!busy} data-testid="time-review-reject-selected">
              {busy === 'bulk_reject' ? 'Rejecting…' : 'Reject selected'}
            </button>
            <button className="btn" onClick={openIssueModal} disabled={!selectionShape.ok || !!busy} data-testid="time-review-request-client-approval">
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
                    <button className="btn btn--primary" onClick={() => approve(r.id)} disabled={!!busy} data-testid={`time-review-approve-${r.id}`}>Approve</button>
                    {' '}
                    <button className="btn" onClick={() => reject(r.id)} disabled={!!busy} data-testid={`time-review-reject-${r.id}`}>Reject</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}

      {decisionReview && <TimeDecisionReview kind="entries" {...decisionReview} onClose={() => setDecisionReview(null)} onDecided={decided} />}
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
