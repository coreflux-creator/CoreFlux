import React, { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Timesheet Detail — Batch 2 (2026-02).
 *
 * Read-only drill-in for a single timesheet. Replaces the rigid "edit
 * the current week only" experience with a proper detail view that can
 * be reached from the list, the placement detail page, or the
 * approvals queue.
 *
 * Operator actions vary by status:
 *   - draft     → "Edit this week" link routes to TimesheetWeek
 *                 (the existing inline grid).
 *   - submitted → Approve / Reject controls (requires
 *                 timesheets.approve permission server-side).
 *   - approved  → read-only.
 *   - rejected  → read-only (worker is expected to re-open in
 *                 TimesheetWeek and re-submit).
 *
 * URL: /modules/staffing/timesheets/:id
 *      /modules/staffing/timesheets/:id?placement_id=N  (placement view)
 */
export default function TimesheetDetail({ session }) {
  const { id } = useParams();
  const nav = useNavigate();
  const placementId = new URLSearchParams(window.location.search).get('placement_id');
  const apiPath = placementId
    ? `/modules/staffing/api/timesheets.php?action=detail_for_placement&id=${id}&placement_id=${placementId}`
    : `/modules/staffing/api/timesheets.php?action=detail&id=${id}`;
  const { data, loading, error, reload } = useApi(apiPath, [apiPath]);
  const ts      = data?.timesheet;
  const entries = data?.entries ?? [];

  const [busy, setBusy] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [actionError, setActionError] = useState(null);

  if (loading) return <p data-testid="timesheet-detail-loading">Loading timesheet…</p>;
  if (error)   return <p className="error" data-testid="timesheet-detail-error">Error: {error.message}</p>;
  if (!ts)     return <p data-testid="timesheet-detail-empty">Timesheet not found.</p>;

  const act = async (action, extra = {}) => {
    setBusy(true); setActionError(null);
    try {
      await api.post(`/modules/staffing/api/timesheets.php?action=${action}`, {
        person_id: ts.person_id,
        period_start: ts.period_start,
        period_end: ts.period_end,
        ...extra,
      });
      reload();
      setRejecting(false); setReason('');
    } catch (e) { setActionError(e.message); }
    finally { setBusy(false); }
  };

  // Aggregate by placement → row[ placement_id ] = { title, hours }
  const byPlacement = {};
  entries.forEach(e => {
    const k = e.placement_id;
    if (!byPlacement[k]) byPlacement[k] = { title: e.placement_title || `Placement #${k}`, client: e.client_name, hours: 0 };
    byPlacement[k].hours += Number(e.hours || 0);
  });

  const isDraft     = ts.status === 'draft';
  const isSubmitted = ts.status === 'submitted';

  return (
    <section data-testid="timesheet-detail-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <button type="button" className="btn btn--ghost" onClick={() => nav('..')}
                  data-testid="timesheet-detail-back">← Timesheets</button>
          <h2 style={{ margin: '8px 0 4px' }} data-testid="timesheet-detail-title">
            Timesheet #{ts.id}
            {placementId && <span style={{ fontSize: 14, color: '#666', marginLeft: 8 }}>
              · placement {placementId} only ({Number(data.placement_hours || 0).toFixed(2)}h)
            </span>}
          </h2>
          <p style={{ color: '#666', fontSize: 13, margin: 0 }} data-testid="timesheet-detail-meta">
            <strong>{ts.first_name || ''} {ts.last_name || ''}</strong>
            {ts.email_primary && <> · {ts.email_primary}</>}
            {' · '}{ts.period_start} → {ts.period_end}
            {' · '}<StatusBadge status={ts.status} />
            {' · '}{Number(ts.total_hours || 0).toFixed(2)}h total
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {isDraft && (
            <Link to="../week" className="btn btn--primary"
                  data-testid="timesheet-detail-edit">
              Edit this week
            </Link>
          )}
          {isSubmitted && !rejecting && (
            <>
              <button type="button" className="btn btn--primary" disabled={busy}
                      onClick={() => act('approve')}
                      data-testid="timesheet-detail-approve">
                {busy ? '…' : 'Approve'}
              </button>
              <button type="button" className="btn btn--ghost" disabled={busy}
                      onClick={() => setRejecting(true)}
                      data-testid="timesheet-detail-reject-open">
                Reject
              </button>
            </>
          )}
        </div>
      </header>

      {rejecting && (
        <div style={{ background: '#fff7ed', border: '1px solid #fdba74', padding: 12, borderRadius: 6, marginBottom: 16 }}
             data-testid="timesheet-detail-reject-form">
          <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>
            Rejection reason (required)
            <input className="input" style={{ width: '100%', marginTop: 4 }}
                   value={reason} onChange={e => setReason(e.target.value)}
                   data-testid="timesheet-detail-reject-reason" />
          </label>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn btn--danger" disabled={busy || !reason.trim()}
                    onClick={() => act('reject', { reason })}
                    data-testid="timesheet-detail-reject-confirm">
              Confirm reject
            </button>
            <button type="button" className="btn btn--ghost" disabled={busy}
                    onClick={() => { setRejecting(false); setReason(''); }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {actionError && <p className="error" data-testid="timesheet-detail-action-error">{actionError}</p>}
      {ts.rejection_reason && (
        <p style={{ background: '#fee2e2', padding: 8, borderRadius: 4, fontSize: 13 }}
           data-testid="timesheet-detail-rejection-reason">
          <strong>Rejection reason:</strong> {ts.rejection_reason}
        </p>
      )}

      {/* Per-placement summary */}
      {!placementId && Object.keys(byPlacement).length > 1 && (
        <>
          <h3 style={{ marginTop: 16, fontSize: 14, color: '#475569' }}>By placement</h3>
          <table className="data-table" data-testid="timesheet-detail-by-placement">
            <thead><tr><th>Placement</th><th>Client</th><th>Hours</th></tr></thead>
            <tbody>
              {Object.entries(byPlacement).map(([k, v]) => (
                <tr key={k} data-testid={`timesheet-detail-by-placement-row-${k}`}>
                  <td>
                    <Link to={`/modules/staffing/placements/${k}`}>{v.title}</Link>
                  </td>
                  <td style={{ fontSize: 12, color: '#666' }}>{v.client}</td>
                  <td style={{ fontWeight: 600 }}>{v.hours.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      <h3 style={{ marginTop: 16, fontSize: 14, color: '#475569' }}>Entries</h3>
      <table className="data-table" data-testid="timesheet-detail-entries">
        <thead>
          <tr>
            <th>Date</th>
            <th>Placement</th>
            <th>Hour type</th>
            <th>Hours</th>
            <th>Billable</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          {entries.length === 0 && (
            <tr><td colSpan={6} style={{ color: '#999' }} data-testid="timesheet-detail-entries-empty">No entries.</td></tr>
          )}
          {entries.map(e => (
            <tr key={e.id} data-testid={`timesheet-detail-entry-${e.id}`}>
              <td style={{ fontSize: 12 }}>{e.work_date}</td>
              <td style={{ fontSize: 12 }}>
                <Link to={`/modules/staffing/placements/${e.placement_id}`}>{e.placement_title || `Placement #${e.placement_id}`}</Link>
                {e.client_name && <span style={{ display: 'block', color: '#666', fontSize: 11 }}>{e.client_name}</span>}
              </td>
              <td style={{ fontSize: 12 }}>{e.hour_type}</td>
              <td style={{ fontWeight: 600 }}>{Number(e.hours || 0).toFixed(2)}</td>
              <td style={{ fontSize: 12 }}>{Number(e.billable) === 1 ? 'Yes' : 'No'}</td>
              <td style={{ fontSize: 12, color: '#666' }}>{e.description}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function StatusBadge({ status }) {
  const colors = {
    draft:         { bg: '#e0e7ff', fg: '#3730a3' },
    submitted:     { bg: '#fef3c7', fg: '#92400e' },
    approved:      { bg: '#dcfce7', fg: '#166534' },
    rejected:      { bg: '#fee2e2', fg: '#991b1b' },
    locked:        { bg: '#e5e7eb', fg: '#374151' },
    payroll_ready: { bg: '#cffafe', fg: '#155e75' },
    billing_ready: { bg: '#fae8ff', fg: '#86198f' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 999,
      background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600,
    }} data-testid={`timesheet-status-${status}`}>{status}</span>
  );
}
