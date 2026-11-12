import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const labels = {
  position: 'Position', party_name: 'Company', party_role: 'Role', company_id: 'Company ID',
  portal_fee_pct: 'Portal fee', portal_fee_flat: 'Portal flat amount', payment_terms_override: 'Payment terms',
  pwp_enabled: 'Paid when paid', is_payable: 'Payable', submittal_id: 'Submittal number', vms_job_id: 'VMS job number',
  referrer_type: 'Recipient type', referrer_vendor_name: 'Company', referrer_company_id: 'Company ID',
  referrer_person_id: 'Person ID', referrer_user_id: 'User ID', fee_pct: 'Fee percentage', fee_flat: 'Fee amount',
  fee_basis: 'Calculation', duration_months: 'Duration in months', start_date: 'Start', end_date: 'End', notes: 'Notes',
  role: 'Role', user_id: 'User ID', split_pct: 'Split', basis: 'Calculation', flat_amount: 'Flat amount',
  effective_from: 'Effective from', effective_to: 'Effective to',
  payment_terms: 'Payment terms', operating_cycle_id: 'Schedule reference',
  currency: 'Currency', payment_frequency: 'Payment frequency', due_month: 'Due month',
};
const settings = { payment_terms_overridden: 'payment terms', pwp_overridden: 'paid when paid', cycle_overridden: 'schedule' };
function display(field, value) {
  if (value === null || value === undefined || value === '') return '—';
  if (field.endsWith('_pct')) return `${(Number(value) * 100).toFixed(2)}%`;
  if (['pwp_enabled', 'is_payable'].includes(field)) return Number(value) ? 'Yes' : 'No';
  return String(value);
}
function recipient(row, references) {
  return references?.[row.identity]?.name || references?.[row.identity?.replace(/^vendor:/, 'company:')]?.name
    || row.values.party_name || row.values.referrer_vendor_name || row.identity || 'Unresolved recipient';
}
function Values({ current, proposed }) {
  const fields = [...new Set([...Object.keys(current || {}), ...Object.keys(proposed || {})])];
  return <table className="data-table"><thead><tr><th>Field</th><th>Current</th><th>JobDiva proposal</th></tr></thead>
    <tbody>{fields.map(field => <tr key={field}><th scope="row">{labels[field] || field}</th>
      <td>{display(field, current?.[field])}</td><td>{display(field, proposed?.[field])}</td></tr>)}</tbody></table>;
}

export default function SourceRuleReview({ placementId, kind, disabled = false, onApplied }) {
  const [review, setReview] = useState(null), [matches, setMatches] = useState({});
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [notice, setNotice] = useState('');
  const [pending, setPending] = useState(null);
  const generation = useRef(0);
  const endpoint = `/modules/placements/api/source_rules.php?placement_id=${placementId}&kind=${kind}`;
  useEffect(() => {
    setReview(null); setMatches({}); setPending(null); setBusy(false); setError(''); setNotice('');
    return () => { generation.current += 1; };
  }, [placementId, kind]);
  const load = async () => {
    const request = ++generation.current;
    setBusy(true); setReview(null); setMatches({}); setPending(null); setError(''); setNotice('');
    try { const next = await api.get(endpoint); if (request === generation.current) setReview(next); }
    catch (e) { if (request === generation.current) setError(e.message); }
    finally { if (request === generation.current) setBusy(false); }
  };
  const retained = new Set(Object.values(matches).filter(value => value !== '' && Number(value) > 0).map(Number));
  const retiring = (review?.current || []).filter(row => !retained.has(row.id));
  const protectedRemoval = retiring.some(row => row.has_linked_records || row.retained_participant_settings?.length);
  const complete = review?.proposed.every(row => matches[row.key] !== undefined && matches[row.key] !== '');
  const allowed = review?.can_apply === true && review?.selectable === true && complete && !protectedRemoval;
  const apply = async () => {
    if (!allowed || busy || disabled) return;
    if (!pending && !window.confirm(`Replace ${review.label.toLowerCase()} with the reviewed JobDiva rules?\n\n${review.proposed.length} source rule(s).\nRetire current rules: ${retiring.map(row => `#${row.id}`).join(', ') || 'none'}.\n\nChosen existing rule IDs and linked records will be retained. Future JobDiva refreshes will control this rule set. Approved rates and financial history stay unchanged.`)) return;
    const body = pending || {
      review_token: review.review_token, request_id: crypto.randomUUID(), confirm: 'REPLACE_REVIEWED_SOURCE_RULES',
      matches: Object.fromEntries(Object.entries(matches).map(([key, value]) => [key, Number(value)])),
    };
    const request = ++generation.current;
    setPending(body); setBusy(true); setError(''); setNotice('');
    try {
      const result = await api.post(endpoint, body);
      if (request !== generation.current) return;
      setReview(null); setMatches({}); setPending(null);
      setNotice(result.currently_follows_source === false
        ? 'This replacement was already saved. A later Core edit has taken ownership again. Refresh the Contract tab to review the current rules.'
        : 'Reviewed rules saved. This rule set now follows JobDiva. Approved rates and financial history are unchanged.');
      try { await onApplied?.(); }
      catch { if (request === generation.current) setNotice('Reviewed rules saved. Refresh the Contract tab to see the updated rules.'); }
    } catch (e) {
      if (request === generation.current) setError(`${e.message} Retry the same request to check its result, or refresh the review before changing your choices.`);
    } finally { if (request === generation.current) setBusy(false); }
  };
  return <section data-testid={`source-rule-review-${kind}`} style={{ margin: '12px 0' }}>
    <button type="button" className="btn btn--sm" disabled={busy || disabled} onClick={load}>{review ? 'Refresh source review' : 'Review JobDiva replacement'}</button>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p role="status">{notice}</p>}
    {review && <div style={{ padding: 16, marginTop: 12, border: '1px solid #cbd5e1', borderRadius: 8 }}>
      <h4>{review.label}: review source replacement</h4>
      <p>JobDiva assignment {review.source_start_id}. Source saved {review.source_updated_at || 'at an unavailable time'}.</p>
      <p>Match each proposal to a current rule for the same recipient, or create a new rule. Unmatched current rules will be retired. The source does not provide stable IDs for these individual rules.</p>
      {review.can_apply !== true && <p>Saving this replacement requires permission to edit this rule set.</p>}
      {(review.issues || []).map((issue, i) => <p key={i} role="note">{issue}</p>)}
      {!review.proposed.length && <p role="note">The saved source contains no rules in this set. Applying this review will retire every current rule and allow future source refreshes to manage this set.</p>}
      {review.proposed.map(proposal => {
        const chosen = review.current.find(row => row.id === Number(matches[proposal.key]));
        return <div key={proposal.key} style={{ marginTop: 20 }}>
          <strong>{recipient(proposal, review.references)}</strong>
          <label style={{ display: 'block', margin: '8px 0' }}>Keep existing rule or create new
            <select className="input" aria-label={`Match ${proposal.key}`} value={matches[proposal.key] ?? ''}
              disabled={busy || disabled || !!pending || review.can_apply !== true || !review.selectable}
              onChange={e => setMatches(current => ({ ...current, [proposal.key]: e.target.value }))}>
              <option value="">Choose a rule</option><option value="0">Create a new rule</option>
              {review.current.filter(row => row.identity && row.identity === proposal.identity).map(row =>
                <option key={row.id} value={String(row.id)} disabled={retained.has(row.id) && chosen?.id !== row.id}>Keep #{row.id} — {recipient(row, review.references)}</option>)}
            </select>
          </label>
          {chosen?.has_linked_records && <p>Linked documents, credentials, portal and plan references stay with rule #{chosen.id}.</p>}
          {!!chosen?.retained_participant_settings?.length && <p>Separately reviewed participant settings stay in Core: {chosen.retained_participant_settings.map(key => settings[key] || key).join(', ')}.</p>}
          {Object.entries(chosen?.retained_participant_values || {}).map(([field, value]) => <p key={field}>Contract participant retains {labels[field] || field}: <strong>{display(field, value)}</strong>.</p>)}
          <Values current={chosen?.values} proposed={proposal.values} />
        </div>;
      })}
      {!!retiring.length && <div style={{ marginTop: 20 }}><h4>Current rules without a match</h4>
        {retiring.map(row => <details key={row.id}><summary>#{row.id} — {recipient(row, review.references)} — {row.has_linked_records || row.retained_participant_settings?.length ? 'must be preserved or its links reviewed first' : 'will retire'}</summary>
          <Values current={row.values} /></details>)}
      </div>}
      {protectedRemoval && <p role="note">Some unmatched rules have linked records or separately reviewed settings. Match them to the same recipient, or resolve those links before applying.</p>}
      {review.can_apply === true && <button type="button" className="btn btn--primary" style={{ marginTop: 16 }} disabled={!allowed || busy || disabled} onClick={apply}>
        {pending ? 'Retry same replacement' : 'Apply reviewed rules and return to JobDiva'}
      </button>}
    </div>}
  </section>;
}
