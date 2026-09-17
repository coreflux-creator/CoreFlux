import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, CheckSquare2, CircleAlert, ExternalLink, Loader2, Mail, X } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';

function approvalBlocker(row) {
  if (Number(row.worker_is_reviewer) > 0 || Number(row.reviewer_entry_count) > 0) {
    return 'A different reviewer must approve time you entered or imported.';
  }
  const missingRates = Number(row.missing_rate_count || 0);
  if (missingRates > 0) {
    return `${missingRates} ${missingRates === 1 ? 'entry needs' : 'entries need'} an approved rate.`;
  }
  if (Number(row.pending_entry_count || 0) < 1) return 'No submitted entries are available.';
  return '';
}

function formatDateTime(value) {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return value;
  return parsed.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export default function StaffingApprovals() {
  const { data, loading, error, reload } = useApi('/modules/staffing/api/timesheets.php?action=list&status=submitted');
  const rows = useMemo(() => data?.rows ?? [], [data?.rows]);
  const rowIdsKey = rows.map(row => row.id).join(',');
  const eligibleRows = useMemo(() => rows.filter(row => !approvalBlocker(row)), [rows]);

  const [selected, setSelected] = useState(() => new Set());
  const [busyId, setBusyId] = useState(null);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [rejecting, setRejecting] = useState(null);
  const [reason, setReason] = useState('');
  const [bulkRejecting, setBulkRejecting] = useState(false);
  const [bulkReason, setBulkReason] = useState('');
  const [notice, setNotice] = useState(null);
  const [actionError, setActionError] = useState('');
  const [emailing, setEmailing] = useState(null);
  const [approverEmail, setApproverEmail] = useState('');
  const [approverName, setApproverName] = useState('');
  const [emailResult, setEmailResult] = useState(null);

  useEffect(() => {
    setSelected(new Set());
    setBulkRejecting(false);
    setBulkReason('');
  }, [rowIdsKey]);

  const allEligibleSelected = eligibleRows.length > 0 && eligibleRows.every(row => selected.has(Number(row.id)));
  const toggle = (row) => {
    if (approvalBlocker(row)) return;
    setSelected(current => {
      const next = new Set(current);
      const id = Number(row.id);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };
  const toggleAll = () => {
    setSelected(current => {
      const next = new Set(current);
      if (allEligibleSelected) eligibleRows.forEach(row => next.delete(Number(row.id)));
      else eligibleRows.forEach(row => next.add(Number(row.id)));
      return next;
    });
  };

  const act = async (action, row, extra = {}) => {
    setBusyId(row.id);
    setActionError('');
    setNotice(null);
    try {
      await api.post(`/modules/staffing/api/timesheets.php?action=${action}`, {
        person_id: row.person_id,
        period_start: row.period_start,
        period_end: row.period_end,
        ...extra,
      });
      setNotice({ kind: 'success', text: action === 'approve' ? 'Timesheet approved.' : 'Timesheet returned for correction.' });
      setRejecting(null);
      setReason('');
      reload();
    } catch (requestError) {
      setActionError(requestError?.message || String(requestError));
    } finally {
      setBusyId(null);
    }
  };

  const runBulk = async (action) => {
    const ids = Array.from(selected);
    if (ids.length === 0) return;
    setBulkBusy(true);
    setActionError('');
    setNotice(null);
    try {
      const response = await api.post(`/modules/staffing/api/timesheets.php?action=${action}`, {
        ids,
        ...(action === 'bulk_reject' ? { reason: bulkReason.trim() } : {}),
      });
      const count = Number(response?.approved ?? response?.rejected ?? ids.length);
      const postingWarnings = response?.posting_warnings?.length || 0;
      setNotice({
        kind: postingWarnings ? 'warning' : 'success',
        text: action === 'bulk_approve'
          ? `${count} ${count === 1 ? 'timesheet' : 'timesheets'} approved${postingWarnings ? `; ${postingWarnings} accounting post ${postingWarnings === 1 ? 'needs' : 'need'} review` : ''}.`
          : `${count} ${count === 1 ? 'timesheet' : 'timesheets'} returned for correction.`,
      });
      setSelected(new Set());
      setBulkRejecting(false);
      setBulkReason('');
      reload();
    } catch (requestError) {
      setActionError(requestError?.message || String(requestError));
    } finally {
      setBulkBusy(false);
    }
  };

  const sendApproverEmail = async (row) => {
    if (!approverEmail.trim()) return;
    setBusyId(row.id);
    setEmailResult(null);
    setActionError('');
    try {
      const response = await api.post('/modules/staffing/api/timesheet_email_approver.php', {
        timesheet_id: row.id,
        approver_email: approverEmail.trim(),
        approver_name: approverName.trim() || undefined,
      });
      setEmailResult({ row_id: row.id, ...response });
      setEmailing(null);
      setApproverEmail('');
      setApproverName('');
    } catch (requestError) {
      setEmailResult({ row_id: row.id, sent: false, error: requestError?.message || String(requestError) });
    } finally {
      setBusyId(null);
    }
  };

  return (
    <section className="people-directory" data-testid="staffing-approvals">
      <header style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 16, marginBottom: 'var(--cf-space-3)' }}>
        <div>
          <h2>Timesheet approvals</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>Submitted weeks awaiting review.</p>
        </div>
        {!loading && <strong style={{ color: 'var(--cf-accent, #0878f9)' }}>{rows.length} pending</strong>}
      </header>

      {(error || actionError) && (
        <div className="error" data-testid="staffing-approvals-error" style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <CircleAlert size={17} aria-hidden="true" />
          <span>{error ? error.message : actionError}</span>
        </div>
      )}

      {notice && (
        <div
          data-testid="staffing-approvals-result"
          style={{
            padding: '10px 12px',
            marginBottom: 12,
            borderRadius: 6,
            border: `1px solid ${notice.kind === 'warning' ? '#f6c453' : '#89d7c3'}`,
            background: notice.kind === 'warning' ? '#fff8e7' : '#edf9f5',
            color: notice.kind === 'warning' ? '#7c5608' : '#126b57',
          }}
        >
          {notice.text}
        </div>
      )}

      {selected.size > 0 && (
        <div
          data-testid="staffing-approvals-bulk-toolbar"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 10,
            flexWrap: 'wrap',
            padding: '10px 12px',
            marginBottom: 12,
            border: '1px solid #9dcbff',
            borderLeft: '3px solid var(--cf-accent, #0878f9)',
            borderRadius: 6,
            background: '#f3f8ff',
          }}
        >
          <CheckSquare2 size={18} color="var(--cf-accent, #0878f9)" aria-hidden="true" />
          <strong data-testid="staffing-approvals-selected-count">{selected.size} selected</strong>
          {!bulkRejecting ? (
            <>
              <button
                type="button"
                className="btn btn--primary"
                disabled={bulkBusy}
                onClick={() => runBulk('bulk_approve')}
                data-testid="staffing-approvals-bulk-approve"
              >
                {bulkBusy ? <Loader2 size={15} className="spin" aria-hidden="true" /> : <Check size={15} aria-hidden="true" />}
                {bulkBusy ? 'Approving…' : 'Approve selected'}
              </button>
              <button
                type="button"
                className="btn"
                disabled={bulkBusy}
                onClick={() => setBulkRejecting(true)}
                data-testid="staffing-approvals-bulk-reject"
              >
                Reject selected
              </button>
            </>
          ) : (
            <>
              <input
                className="input"
                value={bulkReason}
                maxLength={500}
                onChange={event => setBulkReason(event.target.value)}
                placeholder="Reason for correction"
                autoFocus
                data-testid="staffing-approvals-bulk-reject-reason"
                style={{ flex: '1 1 260px', minWidth: 220 }}
              />
              <button
                type="button"
                className="btn"
                disabled={bulkBusy || !bulkReason.trim()}
                onClick={() => runBulk('bulk_reject')}
                data-testid="staffing-approvals-bulk-reject-confirm"
              >
                {bulkBusy ? 'Rejecting…' : `Reject ${selected.size}`}
              </button>
              <button
                type="button"
                className="btn btn--ghost btn--icon"
                disabled={bulkBusy}
                onClick={() => { setBulkRejecting(false); setBulkReason(''); }}
                title="Cancel rejection"
                aria-label="Cancel rejection"
              >
                <X size={16} aria-hidden="true" />
              </button>
            </>
          )}
          <button
            type="button"
            className="btn btn--ghost btn--icon"
            disabled={bulkBusy}
            onClick={() => setSelected(new Set())}
            title="Clear selection"
            aria-label="Clear selection"
            data-testid="staffing-approvals-clear-selection"
            style={{ marginLeft: 'auto' }}
          >
            <X size={16} aria-hidden="true" />
          </button>
        </div>
      )}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty" data-testid="staffing-approvals-empty">No timesheets pending approval.</p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="staffing-approvals-table">
          <thead>
            <tr>
              <th style={{ width: 36 }}>
                <input
                  type="checkbox"
                  checked={allEligibleSelected}
                  onChange={toggleAll}
                  aria-label="Select all ready timesheets"
                  data-testid="staffing-approvals-select-all"
                />
              </th>
              <th>Worker</th>
              <th>Week</th>
              <th>Hours</th>
              <th>Entries</th>
              <th>Submitted</th>
              <th>Review</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(row => {
              const blocker = approvalBlocker(row);
              const rowBusy = busyId === row.id;
              return (
                <tr key={row.id} data-testid={`staffing-approval-row-${row.id}`}>
                  <td>
                    <input
                      type="checkbox"
                      checked={selected.has(Number(row.id))}
                      onChange={() => toggle(row)}
                      disabled={Boolean(blocker) || bulkBusy}
                      title={blocker || undefined}
                      aria-label={`Select ${row.first_name || ''} ${row.last_name || ''} timesheet`}
                      data-testid={`staffing-approval-select-${row.id}`}
                    />
                  </td>
                  <td>
                    <Link to={`../timesheets/${row.id}`} style={{ fontWeight: 600 }}>
                      {row.first_name} {row.last_name} <ExternalLink size={12} aria-hidden="true" />
                    </Link>
                    <br />
                    <span style={{ fontSize: 12, color: 'var(--cf-text-muted)' }}>{row.email_primary || 'No email'}</span>
                  </td>
                  <td>{row.period_start} – {row.period_end}</td>
                  <td style={{ fontWeight: 700 }}>{Number(row.total_hours || 0).toFixed(2)}</td>
                  <td>{Number(row.pending_entry_count || 0)}</td>
                  <td>{formatDateTime(row.submitted_at)}</td>
                  <td style={{ maxWidth: 260 }}>
                    {blocker ? (
                      <span style={{ color: '#9a5c00', fontSize: 12 }} data-testid={`staffing-approval-blocker-${row.id}`}>{blocker}</span>
                    ) : (
                      <span className="badge badge--active" style={{ color: '#087664', background: '#e8f8f3' }}>Ready</span>
                    )}
                  </td>
                  <td>
                    {rejecting === row.id ? (
                      <div style={{ display: 'flex', gap: 6, minWidth: 320 }}>
                        <input
                          className="input"
                          value={reason}
                          maxLength={500}
                          onChange={event => setReason(event.target.value)}
                          placeholder="Reason for correction"
                          autoFocus
                          data-testid={`staffing-reject-reason-${row.id}`}
                          style={{ flex: 1 }}
                        />
                        <button
                          type="button"
                          className="btn"
                          disabled={rowBusy || !reason.trim()}
                          onClick={() => act('reject', row, { reason })}
                          data-testid={`staffing-reject-confirm-${row.id}`}
                        >
                          Reject
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost btn--icon"
                          onClick={() => { setRejecting(null); setReason(''); }}
                          title="Cancel rejection"
                          aria-label="Cancel rejection"
                          data-testid={`staffing-reject-cancel-${row.id}`}
                        >
                          <X size={16} aria-hidden="true" />
                        </button>
                      </div>
                    ) : (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                          <button
                            type="button"
                            className="btn btn--primary"
                            disabled={rowBusy || bulkBusy || Boolean(blocker)}
                            title={blocker || undefined}
                            onClick={() => act('approve', row)}
                            data-testid={`staffing-approve-${row.id}`}
                          >
                            {rowBusy ? 'Approving…' : 'Approve'}
                          </button>
                          <button
                            type="button"
                            className="btn"
                            disabled={rowBusy || bulkBusy}
                            onClick={() => { setRejecting(row.id); setReason(''); }}
                            data-testid={`staffing-reject-${row.id}`}
                          >
                            Reject
                          </button>
                          <button
                            type="button"
                            className="btn btn--ghost"
                            disabled={rowBusy || bulkBusy}
                            onClick={() => { setEmailing(row.id); setApproverEmail(''); setApproverName(''); }}
                            title="Send a one-tap approval link to an external manager"
                            data-testid={`staffing-email-approver-${row.id}`}
                          >
                            <Mail size={14} aria-hidden="true" /> Email approver
                          </button>
                        </div>

                        {emailing === row.id && (
                          <div
                            style={{ display: 'flex', flexDirection: 'column', gap: 6, padding: 8, background: 'var(--cf-surface-subtle, #f7f9fc)', border: '1px solid var(--cf-border, #dfe6ef)', borderRadius: 6 }}
                            data-testid={`staffing-email-approver-form-${row.id}`}
                          >
                            <input
                              className="input"
                              value={approverEmail}
                              onChange={event => setApproverEmail(event.target.value)}
                              placeholder="approver@client.com"
                              type="email"
                              autoFocus
                              data-testid={`staffing-email-approver-email-${row.id}`}
                            />
                            <input
                              className="input"
                              value={approverName}
                              onChange={event => setApproverName(event.target.value)}
                              placeholder="Approver name (optional)"
                              data-testid={`staffing-email-approver-name-${row.id}`}
                            />
                            <div style={{ display: 'flex', gap: 6 }}>
                              <button
                                type="button"
                                className="btn btn--primary"
                                disabled={rowBusy || !approverEmail.trim()}
                                onClick={() => sendApproverEmail(row)}
                                data-testid={`staffing-email-approver-send-${row.id}`}
                              >
                                {rowBusy ? 'Sending…' : 'Send link'}
                              </button>
                              <button
                                type="button"
                                className="btn"
                                onClick={() => { setEmailing(null); setApproverEmail(''); setApproverName(''); }}
                                data-testid={`staffing-email-approver-cancel-${row.id}`}
                              >
                                Cancel
                              </button>
                            </div>
                          </div>
                        )}

                        {emailResult?.row_id === row.id && (
                          <div
                            style={{ fontSize: 12, padding: '6px 8px', borderRadius: 4, background: emailResult.sent ? '#e8f8f3' : '#fff1f1', color: emailResult.sent ? '#126b57' : '#8b2525' }}
                            data-testid={`staffing-email-approver-result-${row.id}`}
                          >
                            {emailResult.sent
                              ? `Link sent to ${emailResult.approver_email}, expires ${emailResult.expires_at}.`
                              : 'Mailer offline. '}
                            {!emailResult.sent && emailResult.approve_url && (
                              <a href={emailResult.approve_url} target="_blank" rel="noopener noreferrer" data-testid={`staffing-email-approver-fallback-${row.id}`}>
                                Open approval link
                              </a>
                            )}
                            {emailResult.error && !emailResult.sent && !emailResult.approve_url && <span>{emailResult.error}</span>}
                          </div>
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </section>
  );
}
