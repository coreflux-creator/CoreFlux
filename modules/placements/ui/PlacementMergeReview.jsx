import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import PlacementPicker from './PlacementPicker';

const endpoint = '/modules/placements/api/merge.php';
const labels = { title: 'Title', notes: 'Notes', worksite_city: 'Worksite city', worksite_state: 'Worksite state', worksite_country: 'Country', remote_policy: 'Remote work', branch: 'Branch / business unit', service_line: 'Service line', workers_comp_class: 'Workers’ compensation class', department: 'Department', cost_center: 'Cost center' };
const show = value => value === null || value === '' ? 'Not recorded' : String(value).replaceAll('_', ' ');
const card = { padding: 16, border: '1px solid #cbd5e1', borderRadius: 10, background: '#fff', marginBottom: 16 };

export default function PlacementMergeReview({ onClose, onSaved }) {
  const [candidates, setCandidates] = useState(null), [pair, setPair] = useState({ survivor: null, retired: null });
  const [review, setReview] = useState(null), [choices, setChoices] = useState({}), [primary, setPrimary] = useState('');
  const [reason, setReason] = useState(''), [confirmed, setConfirmed] = useState(false), [request, setRequest] = useState(null);
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [saved, setSaved] = useState(null);
  const generation = useRef(0);
  useEffect(() => {
    const pending = generation;
    let current = true;
    api.get(`${endpoint}?action=candidates`).then(result => { if (current) setCandidates(result); })
      .catch(e => { if (current) setError(e.message || 'Unable to load duplicate candidates.'); });
    return () => { current = false; pending.current++; };
  }, []);
  const select = (side, row) => { generation.current++; setPair(p => ({ ...p, [side]: row })); setReview(null); setChoices({}); setPrimary(''); setConfirmed(false); setRequest(null); setSaved(null); setError(''); };
  const preview = async () => {
    const expected = ++generation.current;
    setBusy(true); setError(''); setReview(null); setChoices({}); setPrimary(''); setConfirmed(false); setRequest(null); setSaved(null);
    try {
      const result = await api.post(`${endpoint}?action=preview`, { survivor_id: Number(pair.survivor.id), retired_id: Number(pair.retired.id) });
      if (generation.current === expected) setReview(result);
    } catch (e) { if (generation.current === expected) setError(e.message || 'Unable to review these placements.'); }
    finally { if (generation.current === expected) setBusy(false); }
  };
  const ready = review && Object.keys(review.fields).every(key => choices[key]) && (!review.source_options.length || primary) && confirmed && reason.trim().length >= 3;
  const save = async () => {
    const body = request || { survivor_id: review.survivor_id, retired_id: review.retired_id, fields: choices,
      primary_jobdiva_mapping_id: primary ? Number(primary) : null, configuration_placement_id: review.survivor_id,
      history_policy: 'retain_original_rows', confirm: 'MERGE_REVIEWED_PLACEMENTS', review_token: review.review_token,
      request_id: crypto.randomUUID(), reason: reason.trim() };
    setRequest(body); setBusy(true); setError('');
    try { const result = await api.post(`${endpoint}?action=apply`, body); setSaved(result); onSaved?.(result); }
    catch (e) { setError(e.message || 'The result could not be confirmed. Retry this same merge to recover its receipt.'); }
    finally { setBusy(false); }
  };
  return <section data-testid="placement-merge-review" aria-label="Review duplicate placements">
    <h1>Review duplicate placements</h1>
    <p>Choose the placement that will remain current, then review each field and its future JobDiva source.</p>
    {error && <p role="alert" style={{ color: '#b91c1c' }}>{error}</p>}
    {saved ? <div role="status" style={card}>
      <h2>{saved.retained_number} now points to {saved.survivor_number}</h2>
      <p>{saved.replayed ? 'The original merge is confirmed. ' : ''}Original records and provider links were retained.</p>
      <Link to={`/modules/placements/${saved.survivor_id}/overview`}>Open current placement</Link>{' · '}
      <Link to={`/modules/placements/${saved.retired_id}/history`}>Open original history</Link>
    </div> : <>
      <fieldset disabled={busy || !!request} style={card}>
        <legend>1. Choose the two records</legend>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))', gap: 16 }}>
          <div><label>Keep as current</label><PlacementPicker value={pair.survivor?.id || ''} onChange={row => select('survivor', row)} placeholder="Find the placement to keep" testId="merge-survivor" /></div>
          <div><label>Consolidate into the current record</label><PlacementPicker value={pair.retired?.id || ''} onChange={row => select('retired', row)} placeholder="Find the duplicate" testId="merge-retired" /></div>
        </div>
        <button type="button" className="btn btn--primary" style={{ marginTop: 12 }} onClick={preview}
          disabled={busy || !!request || !pair.survivor || !pair.retired || Number(pair.survivor.id) === Number(pair.retired.id)}>Compare selected placements</button>
        {candidates && <details style={{ marginTop: 16 }}><summary>Suggested groups ({candidates.total_groups})</summary>
          {!candidates.candidates.length && <p>No duplicate groups were identified. You can still choose two placements above.</p>}
          {candidates.candidates.map((group, index) => <div key={index} style={{ borderTop: '1px solid #e2e8f0', paddingTop: 12, marginTop: 12 }}>
            {group.person_review_required && <p>These records refer to different people. Resolve the person identities before consolidating placements.</p>}
            {group.rows.map(row => <div key={row.id} style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <Link to={`/modules/placements/${row.id}/overview`}>{row.placement_number || 'Number unavailable'} · {row.title}</Link>
              <span>{row.person_name} · {row.start_date} · {show(row.status)}</span>
              <button type="button" className="btn btn--ghost" disabled={busy || !!request || group.person_review_required} onClick={() => select('survivor', row)}>Keep as current</button>
              <button type="button" className="btn btn--ghost" disabled={busy || !!request || group.person_review_required} onClick={() => select('retired', row)}>Choose as duplicate</button>
            </div>)}
          </div>)}
          {candidates.truncated && <p>Showing the first {candidates.groups_checked} groups. Select another pair using the search fields above.</p>}
        </details>}
      </fieldset>
      {review && <>
        <div style={card}><h2>2. Current setup and retained history</h2>
          <p><strong>{review.survivor_number}</strong> keeps its status, dates, client relationships, schedules and commercial setup. <strong>{review.retired_number}</strong> becomes a historical placement.</p>
          <p>Choose the other record as current if you want to keep its setup. Existing time, approvals, documents, rates, custom fields and financial records stay with their original placement numbers.</p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 16 }}>
            {['survivor', 'retired'].map(side => <div key={side}><h3>{review[`${side}_number`]} {side === 'survivor' ? '(current after merge)' : '(history after merge)'}</h3>
              <p>{show(review.current_configuration.status[side])} · {show(review.current_configuration.start_date[side])} → {show(review.current_configuration.end_date[side])}</p>
              {(review.rates?.[side] || []).map(rate => <p key={rate.id}>{rate.effective_from} · Bill {rate.currency} {rate.bill_rate}/{rate.bill_rate_unit} · Pay {rate.currency} {rate.pay_rate}/{rate.pay_rate_unit} · {rate.approved_at ? 'Approved' : 'Draft'}</p>)}
              <Link to={`/modules/placements/${review[`${side}_id`]}/economics`}>View complete setup</Link>
            </div>)}
          </div>
          <p>{review.provider_pairs_retained} provider link(s) and {review.custom_values_retained} custom value(s) will be retained.</p>
        </div>
        <fieldset disabled={busy || !!request} style={card}><legend>3. Choose the descriptive fields</legend>
          <p>Each selection becomes a Core-owned value. Future source updates will respect your choice.</p>
          <div style={{ overflowX: 'auto' }}><table className="data-table" style={{ width: '100%' }}><thead><tr><th>Field</th><th>{review.survivor_number}</th><th>{review.retired_number}</th><th>Keep value from</th></tr></thead>
            <tbody>{Object.entries(review.fields).map(([field, values]) => <tr key={field}><th>{labels[field] || field}</th><td style={{ whiteSpace: 'pre-wrap', maxWidth: 300 }}>{show(values.survivor)}</td><td style={{ whiteSpace: 'pre-wrap', maxWidth: 300 }}>{show(values.retired)}</td>
              <td><select aria-label={`Keep ${labels[field] || field} from`} value={choices[field] || ''} disabled={busy || !!request} onChange={e => setChoices(c => ({ ...c, [field]: e.target.value }))}>
                <option value="">Choose a record</option><option value="survivor">{review.survivor_number}</option><option value="retired">{review.retired_number}</option>
              </select></td></tr>)}</tbody></table></div>
        </fieldset>
        <fieldset disabled={busy || !!request} style={card}><legend>4. Choose the source for future updates</legend>
          {review.source_options.length ? <><p>All existing provider links stay in place. Choose the JobDiva assignment that may update the current placement.</p>
            <select aria-label="Future JobDiva source" value={primary} disabled={busy || !!request} onChange={e => setPrimary(e.target.value)}>
              <option value="">Choose a JobDiva assignment</option>{review.source_options.map(option => <option key={option.mapping_id} value={option.mapping_id} disabled={!option.eligible}>
                {option.physical_placement_number} · JobDiva {option.external_id}{!option.eligible ? ' — source review required' : ''}
              </option>)}
            </select></> : <p>Neither placement has a JobDiva source link.</p>}
        </fieldset>
        <fieldset disabled={busy || !!request} style={card}><legend>5. Confirm the reviewed merge</legend>
          <label style={{ display: 'block' }}>Reason for consolidation<textarea aria-label="Reason for placement merge" value={reason} maxLength={1000} disabled={busy || !!request} onChange={e => setReason(e.target.value)} style={{ display: 'block', width: '100%' }} /></label>
          <label><input type="checkbox" checked={confirmed} disabled={busy || !!request} onChange={e => setConfirmed(e.target.checked)} /> Keep {review.survivor_number} as current, archive {review.retired_number}, and retain original history and provider links.</label>
        </fieldset>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}><button type="button" className="btn btn--primary" onClick={save} disabled={busy || (!request && !ready)}>{busy ? 'Working…' : request ? 'Retry original merge' : 'Confirm reviewed merge'}</button>
          <button type="button" className="btn btn--ghost" disabled={busy} onClick={preview}>Refresh review</button></div>
      </>}
    </>}
    {onClose ? <button type="button" className="btn btn--ghost" style={{ marginTop: 16 }} disabled={busy} onClick={onClose}>Close</button> : <p><Link to="/modules/placements/list">Back to placements</Link></p>}
  </section>;
}
