import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';

const endpoint = '/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_stale_people';

export default function JobDivaPeopleLifecycleReview({ onClose, onSaved }) {
  const [review, setReview] = useState(null);
  const [choices, setChoices] = useState({});
  const [reason, setReason] = useState('');
  const [request, setRequest] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(null);
  const refresh = useCallback(async () => {
    setBusy(true); setError(''); setReview(null); setChoices({}); setReason(''); setRequest(null); setSaved(null);
    try { const result = await api.post(endpoint, { dry_run: true, limit: 5000 }); setReview(result.repair); }
    catch (e) { setError(e.message || 'Unable to load availability review.'); }
    finally { setBusy(false); }
  }, []);
  useEffect(() => { refresh(); }, [refresh]);
  const changed = (review?.rows || []).filter(row => choices[row.id] && choices[row.id] !== row.status);
  const save = async () => {
    const body = request || {
      dry_run: false, confirm: 'APPLY_REVIEWED_PEOPLE_AVAILABILITY', limit: review.limit,
      review_token: review.review_token, request_id: crypto.randomUUID(), reason: reason.trim(),
      decisions: Object.fromEntries(changed.map(row => [row.id, choices[row.id]])),
    };
    setRequest(body); setBusy(true); setError('');
    try { const result = await api.post(endpoint, body); setSaved(result.repair); onSaved?.(result.repair); }
    catch (e) { setError(e.message || 'Unable to confirm the save. Retry the same request or refresh the review.'); }
    finally { setBusy(false); }
  };
  return <section aria-label="Review People availability" data-testid="jobdiva-people-availability-review"
    style={{ border: '1px solid #cbd5e1', borderRadius: 10, background: '#fff', padding: 16, marginBottom: 20 }}>
    <h2>Review People availability</h2>
    <p>These people have placement history and no current assignment. Keep their current availability unless you choose a change.</p>
    <p>This updates directory availability. Employment, sign-in access, assignment history and payroll records remain unchanged.</p>
    {error && <p role="alert">{error}</p>}
    {saved ? <p role="status">Saved availability for {saved.updated} person(s){saved.replayed ? ' — original save confirmed' : ''}.</p> : <>
      {review && <div style={{ overflowX: 'auto' }}><table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Person</th><th>Current availability</th><th>Last placement end</th><th>Decision</th></tr></thead>
        <tbody>{review.rows.map(row => <tr key={row.id}>
          <td>{row.person_number} · {row.name}</td><td>{row.status}</td><td>{row.last_placement_end_date || 'Not recorded'}</td>
          <td><select aria-label={`Availability for ${row.person_number}`} value={choices[row.id] || ''} disabled={busy || !!request || !review.can_manage}
            onChange={e => setChoices(prev => ({ ...prev, [row.id]: e.target.value }))}>
            <option value="">Keep current</option><option value="bench">Bench</option>{review.can_inactivate && <option value="inactive">Inactive</option>}
          </select></td>
        </tr>)}</tbody>
      </table>{review.rows.length === 0 && <p>No people currently require this review.</p>}</div>}
      {review?.warnings?.length > 0 && <p role="status">{review.warnings.length} source link(s) need identity review before an availability decision.</p>}
      {review?.truncated && <p role="status">This review covers the first {review.checked} of {review.total} candidate links.</p>}
      {review && !review.can_manage && <p role="status">You have access to review availability. Saving requires People management and integration management access.</p>}
      {review?.rows?.length > 0 && <label style={{ display: 'block', marginTop: 12 }}>Reason for selected changes
        <textarea aria-label="Reason for availability changes" value={reason} maxLength={1000} disabled={busy || !!request || !review.can_manage}
          onChange={e => setReason(e.target.value)} style={{ display: 'block', width: '100%' }} />
      </label>}
    </>}
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 12 }}>
      {!saved && <button type="button" className="btn btn--primary" onClick={save}
        disabled={busy || !review?.can_manage || (!request && (!changed.length || reason.trim().length < 3 || changed.length > 500))}>
        {busy ? 'Working…' : request ? 'Retry original save' : `Save ${changed.length} selected change(s)`}
      </button>}
      <button type="button" className="btn btn--ghost" disabled={busy} onClick={refresh}>Refresh review</button>
      <button type="button" className="btn btn--ghost" disabled={busy} onClick={onClose}>Close</button>
    </div>
  </section>;
}
