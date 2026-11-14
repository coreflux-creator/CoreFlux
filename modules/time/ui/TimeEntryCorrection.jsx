import React, { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, useApi, getPinnedTenantId, bustApiCachePrefix } from '../../../dashboard/src/lib/api';

const CATEGORIES = [
  ['regular_billable', 'Regular · billable'], ['regular_nonbillable', 'Regular · nonbillable'],
  ['OT_billable', 'Overtime · billable'], ['OT_nonbillable', 'Overtime · nonbillable'],
  ['holiday', 'Holiday'], ['vacation', 'Vacation'], ['sick', 'Sick'], ['bereavement', 'Bereavement'],
  ['unpaid_leave', 'Unpaid leave'], ['custom', 'Custom'],
];
const sameWorkspace = workspace => !getPinnedTenantId() || String(workspace) === String(getPinnedTenantId());

export default function TimeEntryCorrection() {
  const { entryId } = useParams();
  const path = `/modules/time/api/entries.php?action=correction_review&id=${entryId}`;
  const { data, loading, error, reload } = useApi(path, [path]);
  const [form, setForm] = useState(null), [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState(null), [saved, setSaved] = useState(null);
  const submitting = useRef(false);
  const entry = data?.entry;
  useEffect(() => {
    setFailure(null); setSaved(null);
    setForm(entry && String(entry.id) === String(entryId) ? {
      work_date: entry.work_date, hours: String(entry.hours), category: entry.category,
      custom_category_id: entry.custom_category_id == null ? '' : String(entry.custom_category_id),
      description: entry.description || '', correction_reason: '', review_revision: data.revision,
    } : null);
  }, [entryId, data?.workspace_id, data?.revision, entry]);

  if (loading) return <p>Loading correction review…</p>;
  if (error) return <p role="alert">{error.message} <Link to={`/modules/time/entries/${entryId}/history`}>View time history</Link></p>;
  if (!entry || String(entry.id) !== String(entryId) || !sameWorkspace(data.workspace_id)) {
    return <p role="alert">Reload this entry in its original workspace.</p>;
  }
  const ready = form && form.review_revision === data.revision;
  const update = (key, value) => { setFailure(null); setForm(prior => ({ ...prior, [key]: value })); };
  const save = async event => {
    event.preventDefault();
    if (submitting.current || busy || saved || !ready || !sameWorkspace(data.workspace_id)) return;
    if (!form.correction_reason.trim()) { setFailure('Explain why this approved time needs a correction.'); return; }
    const hours = Number(form.hours);
    if (!Number.isFinite(hours) || hours <= 0 || hours > 24) { setFailure('Enter hours greater than zero and no more than 24.'); return; }
    submitting.current = true; setBusy(true); setFailure(null);
    try {
      const result = await api.post(`/modules/time/api/entries.php?action=correct&id=${entryId}`, {
        ...form, hours, custom_category_id: form.custom_category_id ? Number(form.custom_category_id) : null,
      });
      if (!sameWorkspace(data.workspace_id) || String(result.workspace_id) !== String(data.workspace_id)
        || String(result.superseded_entry_id) !== String(entryId) || !Number.isInteger(result.new_entry_id) || result.new_entry_id <= 0) {
        throw new Error('The workspace or response changed. Check time history before trying again.');
      }
      bustApiCachePrefix('/modules/time/api/entries.php');
      bustApiCachePrefix('/modules/staffing/api/timesheets.php');
      setSaved(result);
    } catch (e) { setFailure(e.message); }
    finally { submitting.current = false; setBusy(false); }
  };
  return <section className="people-directory" data-testid="time-entry-correction">
    <h2>Correct approved time</h2>
    <p>{[data.worker?.first_name, data.worker?.last_name].filter(Boolean).join(' ')} · {data.placement?.title}</p>
    <p>A correction retains the original approval and creates a draft replacement for separate approval.</p>
    <div data-testid="time-correction-original" style={{ background: 'var(--cf-surface, #f8fafc)', padding: 16, marginBottom: 16 }}>
      <strong>Original approved entry</strong>
      <p>{entry.work_date} · {Number(entry.hours).toFixed(2)} hours · {CATEGORIES.find(([key]) => key === entry.category)?.[1] || entry.category}</p>
      <p>Source: {String(entry.source || 'manual_entry').replaceAll('_', ' ')}{entry.source_ref_id ? ` · source record ${entry.source_ref_id}` : ''}</p>
      <p>Approved: {entry.approved_at || 'Not recorded'} · {String(entry.approved_via || 'Approval method not recorded').replaceAll('_', ' ')}</p>
      {entry.description && <p>{entry.description}</p>}
      <Link to={`/modules/time/entries/${entryId}/history`}>View original and replacement history</Link>
    </div>
    {failure && <p role="alert" data-testid="time-correction-error">{failure} <button type="button" className="btn" disabled={busy} onClick={reload}>Reload review</button></p>}
    {saved ? <div role="status" data-testid="time-correction-saved">
      <p>Draft replacement created. It needs submission and separate approval.</p>
      <Link to={`/modules/staffing/timesheets/${saved.timesheet_id}`}>Open replacement week</Link>{' · '}
      <Link to={`/modules/time/entries/${saved.new_entry_id}/history`}>View correction history</Link>
    </div> : ready && <form onSubmit={save} data-testid="time-correction-form">
      <fieldset disabled={busy} style={{ border: 0, padding: 0 }}>
        <legend>Draft replacement</legend>
        <label>Work date <input className="input" type="date" required value={form.work_date} onChange={e => update('work_date', e.target.value)} data-testid="time-correction-date" /></label>{' '}
        <label>Hours <input className="input" type="number" min="0.01" max="24" step="0.01" required value={form.hours} onChange={e => update('hours', e.target.value)} data-testid="time-correction-hours" /></label>{' '}
        <label>Category <select className="input" value={form.category} onChange={e => update('category', e.target.value)} data-testid="time-correction-category">
          {CATEGORIES.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select></label>
        <label style={{ display: 'block', marginTop: 12 }}>Custom category <select className="input" value={form.custom_category_id} onChange={e => update('custom_category_id', e.target.value)} data-testid="time-correction-custom">
          <option value="">None</option>
          {(data.custom_categories || []).map(category => <option key={category.id} value={category.id}>{category.label}</option>)}
          {form.custom_category_id && !(data.custom_categories || []).some(category => String(category.id) === form.custom_category_id) && <option value={form.custom_category_id}>Original category is unavailable — choose another or clear it</option>}
        </select></label>
        <label style={{ display: 'block', marginTop: 12 }}>Description <textarea className="input" value={form.description} onChange={e => update('description', e.target.value)} data-testid="time-correction-description" /></label>
        <label style={{ display: 'block', marginTop: 12 }}>Reason for correction <textarea className="input" required maxLength={500} value={form.correction_reason} onChange={e => update('correction_reason', e.target.value)} data-testid="time-correction-reason" /></label>
        <p>The worker and assignment stay linked to the original entry. Closed periods or time already used downstream require their review workflow first.</p>
        <button className="btn btn--primary" type="submit" disabled={busy || !form.correction_reason.trim()} data-testid="time-correction-submit">{busy ? 'Saving…' : 'Create draft replacement'}</button>{' '}
        <Link to={`/modules/time/entries/${entryId}/history`}>Cancel</Link>
      </fieldset>
    </form>}
  </section>;
}
