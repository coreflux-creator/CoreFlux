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
  const [emailing, setEmailing] = useState(null);   // row id currently showing the email form
  const [approverEmail, setApproverEmail] = useState('');
  const [approverName, setApproverName]   = useState('');
  const [emailResult, setEmailResult]     = useState(null); // {row_id, sent, approve_url, error}

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

  const sendApproverEmail = async (row) => {
    if (!approverEmail.trim()) return;
    setBusyId(row.id);
    setEmailResult(null);
    try {
      const res = await api.post('/modules/staffing/api/timesheet_email_approver.php', {
        timesheet_id:   row.id,
        approver_email: approverEmail.trim(),
        approver_name:  approverName.trim() || undefined,
      });
      setEmailResult({ row_id: row.id, ...res });
      setEmailing(null); setApproverEmail(''); setApproverName('');
    } catch (e) {
      setEmailResult({ row_id: row.id, sent: false, error: e.message });
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
                    <div style={{ display:'flex', flexDirection:'column', gap:6 }}>
                      <div style={{ display:'flex', gap:6 }}>
                        <button className="btn btn--primary" disabled={busyId === r.id}
                                onClick={() => act('approve', r)}
                                data-testid={`staffing-approve-${r.id}`}>{busyId === r.id ? '…' : 'Approve'}</button>
                        <button className="btn" disabled={busyId === r.id}
                                onClick={() => setRejecting(r.id)}
                                data-testid={`staffing-reject-${r.id}`}>Reject</button>
                        <button className="btn" disabled={busyId === r.id}
                                onClick={() => { setEmailing(r.id); setApproverEmail(''); setApproverName(''); }}
                                title="Send a one-tap approval link to an external manager"
                                data-testid={`staffing-email-approver-${r.id}`}>Email approver</button>
                      </div>
                      {emailing === r.id && (
                        <div style={{ display:'flex', flexDirection:'column', gap:4, padding:'6px', background:'var(--cf-surface-subtle, #f9fafb)', borderRadius:4 }}
                             data-testid={`staffing-email-approver-form-${r.id}`}>
                          <input
                            value={approverEmail} onChange={e => setApproverEmail(e.target.value)}
                            placeholder="approver@client.com" type="email" autoFocus
                            data-testid={`staffing-email-approver-email-${r.id}`}
                            style={{ fontSize:'0.85em', padding:'4px 6px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 3 }}
                          />
                          <input
                            value={approverName} onChange={e => setApproverName(e.target.value)}
                            placeholder="Approver name (optional)"
                            data-testid={`staffing-email-approver-name-${r.id}`}
                            style={{ fontSize:'0.85em', padding:'4px 6px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 3 }}
                          />
                          <div style={{ display:'flex', gap:4 }}>
                            <button className="btn btn--primary" disabled={busyId === r.id || !approverEmail.trim()}
                                    onClick={() => sendApproverEmail(r)}
                                    data-testid={`staffing-email-approver-send-${r.id}`}>{busyId === r.id ? 'Sending…' : 'Send link'}</button>
                            <button className="btn"
                                    onClick={() => { setEmailing(null); setApproverEmail(''); setApproverName(''); }}
                                    data-testid={`staffing-email-approver-cancel-${r.id}`}>Cancel</button>
                          </div>
                        </div>
                      )}
                      {emailResult?.row_id === r.id && (
                        <div style={{ fontSize:'0.8em', padding:'4px 6px', borderRadius:3, background: emailResult.sent ? '#dcfce7' : '#fee2e2', color: emailResult.sent ? '#166534' : '#7f1d1d' }}
                             data-testid={`staffing-email-approver-result-${r.id}`}>
                          {emailResult.sent
                            ? `Link sent to ${emailResult.approver_email}, expires ${emailResult.expires_at}.`
                            : `Mailer offline — share this link manually: `}
                          {!emailResult.sent && emailResult.approve_url && (
                            <a href={emailResult.approve_url} target="_blank" rel="noopener noreferrer"
                               data-testid={`staffing-email-approver-fallback-${r.id}`}>Approve link</a>
                          )}
                          {emailResult.error && !emailResult.sent && !emailResult.approve_url && (
                            <span> {emailResult.error}</span>
                          )}
                        </div>
                      )}
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
