import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Staffing Approvals Queue — list of submitted timesheets the user can
 * approve or reject. Phase 1 version: one-click approve, reject with reason.
 * Phase 2 will add bulk selection, email-only client approvers, audit drill-down.
 */
export default function StaffingApprovals({ session }) {
  const { data, loading, error, reload } = useApi('/modules/staffing/api/timesheets.php?action=list&status=submitted');
  const rows = data?.rows ?? [];
  const [busyId, setBusyId] = useState(null);
  const [rejecting, setRejecting] = useState(null);
  const [reason, setReason] = useState('');

  const act = async (action, row, extra = {}) => {
    setBusyId(row.id);
    try {
      await api.post(`/modules/staffing/api/timesheets.php?action=${action}`, {
        person_id: row.person_id, period_start: row.period_start, period_end: row.period_end,
        ...extra,
      });
      reload();
      setRejecting(null); setReason('');
    } catch (e) {
      alert(e.message);
    } finally {
      setBusyId(null);
    }
  };

  return (
    <section className="people-directory" data-testid="staffing-approvals">
      <header style={{ marginBottom: 'var(--cf-space-3)' }}>
        <h2>Approvals Queue</h2>
        <p style={{ color:'var(--cf-text-secondary)' }}>Submitted timesheets awaiting review.</p>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="staffing-approvals-error">Error: {error.message}</p>}

      {!loading && rows.length === 0 && (
        <p className="empty" data-testid="staffing-approvals-empty">No timesheets pending approval.</p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="staffing-approvals-table">
          <thead>
            <tr><th>Worker</th><th>Week</th><th>Hours</th><th>Submitted</th><th>Actions</th></tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} data-testid={`staffing-approval-row-${r.id}`}>
                <td>{r.first_name} {r.last_name}<br/><span style={{ fontSize:'0.8em', color:'var(--cf-text-muted)' }}>{r.email_primary}</span></td>
                <td>{r.period_start} → {r.period_end}</td>
                <td style={{ fontWeight:600 }}>{parseFloat(r.total_hours).toFixed(2)}</td>
                <td>{r.submitted_at}</td>
                <td>
                  {rejecting === r.id ? (
                    <div style={{ display:'flex', gap:4 }}>
                      <input
                        value={reason} onChange={e => setReason(e.target.value)}
                        placeholder="Reason for rejection" autoFocus
                        data-testid={`staffing-reject-reason-${r.id}`}
                        style={{ fontSize:'0.85em', padding:'4px 6px', flex: 1, border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 3 }}
                      />
                      <button className="btn" disabled={busyId === r.id || !reason.trim()}
                              onClick={() => act('reject', r, { reason })}
                              data-testid={`staffing-reject-confirm-${r.id}`}>Reject</button>
                      <button className="btn" onClick={() => { setRejecting(null); setReason(''); }}
                              data-testid={`staffing-reject-cancel-${r.id}`}>×</button>
                    </div>
                  ) : (
                    <div style={{ display:'flex', gap:6 }}>
                      <button className="btn btn--primary" disabled={busyId === r.id}
                              onClick={() => act('approve', r)}
                              data-testid={`staffing-approve-${r.id}`}>{busyId === r.id ? '…' : 'Approve'}</button>
                      <button className="btn" disabled={busyId === r.id}
                              onClick={() => setRejecting(r.id)}
                              data-testid={`staffing-reject-${r.id}`}>Reject</button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
