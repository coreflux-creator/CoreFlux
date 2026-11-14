import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const labels = { pending: 'Awaiting accounting', failed: 'Accounting needs attention', posted: 'Posted to ledger', cancelled: 'Withdrawn after a time correction' };
const sameWorkspace = id => !getPinnedTenantId() || String(getPinnedTenantId()) === String(id);

export default function AccountingHandoff({ timesheetId, workspaceId, refreshKey, allowActions = true }) {
  const [review, setReview] = useState(null), [error, setError] = useState('');
  const [loading, setLoading] = useState(true), [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false), [reload, setReload] = useState(0);
  const key = `${workspaceId}:${timesheetId}`;
  const activeKey = useRef(key); activeKey.current = key;
  const pending = useRef(false), mounted = useRef(false);
  const path = `/modules/time/api/accounting_handoffs.php?timesheet_id=${timesheetId}`;
  const matches = data => String(data?.workspace_id) === String(workspaceId)
    && String(data?.timesheet_id) === String(timesheetId) && sameWorkspace(workspaceId)
    && Object.hasOwn(labels, data?.status) && /^[a-f0-9]{64}$/.test(data?.review_revision || '');

  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    let active = true;
    setReview(null); setError(''); setConfirming(false); setLoading(true);
    if (!workspaceId || !sameWorkspace(workspaceId)) { setLoading(false); return undefined; }
    api.get(path).then(data => {
      if (!active) return;
      if (String(data?.workspace_id) !== String(workspaceId) || String(data?.timesheet_id) !== String(timesheetId)
        || !sameWorkspace(workspaceId) || !Object.hasOwn(labels, data?.status)
        || !/^[a-f0-9]{64}$/.test(data?.review_revision || '')) throw new Error('Accounting status does not match this timesheet and workspace. Refresh it.');
      setReview({ ...data, requestKey: key });
    }).catch(e => { if (active) setError(e.status === 404 ? 'No accounting handoff is recorded for this week yet.' : e.message); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [key, path, timesheetId, workspaceId, refreshKey, reload]);

  const ready = review?.requestKey === key && matches(review);
  const canRetry = ready && review.can_retry === true && allowActions && ['pending', 'failed'].includes(review.status);
  const current = requestKey => mounted.current && activeKey.current === requestKey && sameWorkspace(workspaceId);
  const post = async event => {
    event.preventDefault();
    if (!canRetry || !confirming || pending.current || !sameWorkspace(workspaceId)) return;
    pending.current = true; setBusy(true); setError('');
    const requestKey = key;
    try {
      const result = await api.post(path, { review_revision: review.review_revision });
      if (!current(requestKey)) return;
      if (!matches(result) || result.status !== 'posted' || String(result.id) !== String(review.id)) {
        throw new Error('The posting response could not be verified. Refresh accounting status before continuing.');
      }
      setReview({ ...result, requestKey }); setConfirming(false);
    } catch (e) {
      if (current(requestKey)) { setReview(null); setConfirming(false); setError(e.message || 'Refresh accounting status to check the outcome.'); }
    } finally { pending.current = false; if (mounted.current) setBusy(false); }
  };

  return <section className="card" data-testid="accounting-handoff" style={{ padding: 16, marginBottom: 16 }}>
    <h3>Accounting for this week</h3>
    {loading && <p>Loading accounting status…</p>}
    {error && <p role="alert">{error}</p>}
    {!sameWorkspace(workspaceId) && <p role="alert">Return to this timesheet’s original workspace to review accounting.</p>}
    {ready && <>
      <p><strong>{labels[review.status]}</strong> · {Number(review.attempts)} processing attempt(s)</p>
      {review.last_error && <p role="alert">{review.last_error}</p>}
      {review.completed_at && <p>Completed: {review.completed_at}</p>}
      {review.cancellation_reason && <p>Correction reason: {review.cancellation_reason}</p>}
      {canRetry && !confirming && <button type="button" className="btn btn--primary" disabled={busy} onClick={() => setConfirming(true)} data-testid="handoff-review-post">Review ledger posting</button>}
      {canRetry && confirming && <form onSubmit={post} data-testid="handoff-confirm-form">
        <p>Post the complete approved week TS-{timesheetId} to its configured accounting ledger using the retained approval rates. This records the week’s revenue and labor costs.</p>
        <button className="btn btn--primary" disabled={busy} data-testid="handoff-confirm">{busy ? 'Posting…' : 'Post approved week to ledger'}</button>{' '}
        <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => setConfirming(false)}>Cancel</button>
      </form>}
    </>}
    <button type="button" className="btn btn--ghost" disabled={busy || loading} onClick={() => setReload(value => value + 1)} data-testid="handoff-refresh">Refresh accounting status</button>
  </section>;
}
