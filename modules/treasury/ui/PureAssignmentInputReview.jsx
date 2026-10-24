import React, { useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const endpoint = '/api/purepay_core.php?action=assignment_input_';
const names = { fee_fraction: 'Fee percentage', fee_amount: 'Fee amount', fee_basis: 'Fee basis',
  bill_adder_fraction: 'Bill adder', bill_discount_fraction: 'Bill discount', margin_fraction: 'Modeled margin',
  c2c_overhead_override: 'C2C overhead override', workers_comp_override: 'Workers compensation override',
  pwp_enabled: 'Source paid-when-paid setting', pwp_overridden: 'Paid-when-paid overridden',
  source_ref: 'Source reference', source_user_id: 'Core user', economic_party_id: 'Core economic party',
  staffing_job_id: 'Core job', accounting_entity_id: 'Core legal entity', jobdiva_job_id: 'JobDiva job ID',
  external_id: 'Source ID', source_external_id: 'Source item ID', id: 'Core ID' };
const label = key => names[key] || key.replaceAll('_', ' ');
const display = value => value === null ? 'Not specified' : typeof value === 'boolean' ? value ? 'Yes' : 'No' : String(value);
const percentKeys = new Set(['fee_fraction', 'bill_adder_fraction', 'bill_discount_fraction', 'margin_fraction',
  'employer_load_override', 'workers_comp_override', 'benefits_load_override', 'c2c_overhead_override']);
const percentage = value => {
  if (value === null) return 'Not specified';
  const negative = value.startsWith('-'); const [whole, fraction = ''] = (negative ? value.slice(1) : value).split('.');
  const tail = fraction.padEnd(2, '0'); const rest = tail.slice(2).replace(/0+$/, '');
  return `${negative ? '-' : ''}${(whole + tail.slice(0, 2)).replace(/^0+(?=\d)/, '')}${rest ? `.${rest}` : ''}%`;
};
const bases = { none: 'No fee', pay_rate: 'Placement pay rate', portal_fee_pct: 'Portal fee · percentage of billing',
  portal_fee_flat: 'Fixed portal fee', per_hour: 'Per hour', per_invoice: 'Per invoice', one_time: 'One time',
  pct_bill: 'Percentage of billing', pct_margin: 'Percentage of margin', net_margin: 'Percentage of net margin',
  gross_margin: 'Percentage of gross margin', bill_rate: 'Percentage of bill rate', flat: 'Fixed amount' };
const box = { border: '1px solid var(--cf-border, #ddd)', borderRadius: 6, padding: 12, margin: '12px 0' };
function Values({ values }) {
  return <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 10 }}>
    {Object.entries(values).map(([key, value]) => <div key={key}><dt style={{ textTransform: 'capitalize', fontSize: 12, opacity: .7 }}>{label(key)}</dt><dd style={{ margin: 0, overflowWrap: 'anywhere' }}>{percentKeys.has(key) ? percentage(value) : key === 'fee_basis' ? bases[value] || label(value) : display(value)}</dd></div>)}
  </dl>;
}
function Parties({ parties }) {
  return <div>{parties.map(party => <details key={party.id} style={box}>
    <summary>{party.display_name} · {label(party.role)} · {label(party.settlement_channel)} · {Object.values(party.roots).filter(Boolean).map(ref => ref.number).join(' · ')}</summary>
    <Values values={Object.fromEntries(Object.entries(party).filter(([key]) => !['roots', 'cycle', 'client_identity_basis'].includes(key)))} />
    {party.cycle && <Values values={party.cycle} />}
    {party.client_identity_basis === 'current_placement' && <p>Client number is mapped from the current placement. The original approval retained the company identity, not its client number.</p>}
  </details>)}</div>;
}

export function AssignmentCommercialPreview({ packet: p }) {
  return <div data-testid="assignment-commercial-preview">
    <p><strong>{p.title}</strong> · {p.placement.number} · {p.person.number} · {p.person_name} · {p.employment_type.toUpperCase()}</p>
    <p>Client {p.client.number}{p.primary_vendor && ` · Payee vendor ${p.primary_vendor.number}`} · {p.start_date} → {p.end_date || 'Open-ended'} · {label(p.source_status)}</p>
    <p>A zero override is an explicit waiver; an unspecified override may inherit a Core default. Approved terms and current editable settings are shown separately.</p>
    {p.terms.map(term => <details key={term.start_date} style={box}>
      <summary>Approved terms: {term.start_date} → {term.end_date || 'Open-ended'} · {term.currency}</summary>
      <p>Pay per {term.pay_rate_unit}; billing per {term.bill_rate_unit}.</p>
      <Values values={Object.fromEntries(['pay', 'bill'].flatMap(kind => ['regular', 'overtime', 'double_time'].map(category => [`${kind}_${category}`, term[`${kind}_rates`][category]])))} />
      <p>Rate #{term.economics.rate_snapshot_id} approved {term.economics.approved_at} (Core source time).</p>
      <Values values={{ approved_rate_from: term.economics.rate_effective_from, approved_rate_to: term.economics.rate_effective_to,
        overtime_multiplier: term.economics.overtime_multiplier, double_time_multiplier: term.economics.double_time_multiplier }} />
      <h5>Approved adjustments and overrides</h5><Values values={term.economics.adjustments} />
      <h5>Resolved overhead at approval</h5><Values values={Object.fromEntries(Object.entries(term.economics.resolved_costs).map(([name, cost]) => [name, `${percentage(cost.fraction)} · ${label(cost.source)}`]))} />
      <h5>Approved economic parties</h5><Parties parties={term.economics.parties} />
      <p>Historic approvals did not retain cycle anchors, settlement offsets or override flags. Their absence here is not a default.</p>
      <h5>Core model as saved</h5><Values values={term.economics.modeled_totals} />
    </details>)}
    <details style={box}><summary>Current work details and editable commercial settings</summary>
      <p>These settings do not replace the approved terms for each period.</p>
      <Values values={{ ...p.work_context, end_client: p.work_context.end_client?.number || null }} />
      <Values values={{ billing_cycle: p.current_commercial_context.billing_cycle_id, vendor_payment_cycle: p.current_commercial_context.ap_cycle_id,
        payroll_cycle: p.current_commercial_context.payroll_cycle_id }} />
      <h5>Current economic parties</h5><Parties parties={p.current_commercial_context.parties} />
      <h5>Current cycles</h5>{p.current_commercial_context.cycles.map(cycle => <div style={box} key={cycle.id}><Values values={cycle} /></div>)}
      <h5>One-time items</h5><p>Configuration only; these are not proof of posting, payment or payroll inclusion.</p>
      {p.current_commercial_context.one_time_items.map(item => <div style={box} key={item.id}><Values values={item} /></div>)}
    </details>
  </div>;
}

export default function PureAssignmentInputReview({ canSend = false }) {
  const [number, setNumber] = useState('');
  const [review, setReview] = useState(null);
  const [history, setHistory] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [attempted, setAttempted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  const select = value => { setReview(value); setConfirmed(false); setAttempted(false); };
  async function run(action, body, apply) {
    const current = ++generation.current;
    setBusy(true); setError('');
    try { const result = await api.post(endpoint + action, body); if (current === generation.current) apply(result); }
    catch (e) { if (current === generation.current) setError(e.message || 'Placement review could not be completed.'); }
    finally { if (current === generation.current) setBusy(false); }
  }
  const preview = (previous = null) => run('preview', { placement_number: number.trim(), previous_revision: previous }, result => select(result));
  const loadHistory = before => run('history', { placement_number: number.trim(), before }, setHistory);
  const send = () => {
    if (!review || !confirmed || !canSend || busy || attempted || !review.delivery_enabled) return;
    setAttempted(true);
    run('send', { delivery_id: Number(review.delivery.id), review_hash: review.delivery.review_hash, confirmed }, delivery => {
      setReview(value => ({ ...value, delivery })); setConfirmed(false);
    });
  };
  const status = () => run('status', { delivery_id: Number(review.delivery.id), review_hash: review.delivery.review_hash }, result => {
    setReview(value => ({ ...value, ...result })); setConfirmed(false);
  });
  const receipt = review?.provider_review;
  return <section style={{ borderTop: '1px solid var(--cf-border, #ddd)', marginTop: 20, paddingTop: 16 }} aria-label="Placement commercial review">
    <h4>Send a placement and its commercial terms</h4>
    <p>Review the complete placement before sending it to PurePay. Native assignment creation, activation, billing and payroll remain separate steps.</p>
    <form onSubmit={event => { event.preventDefault(); select(null); preview(); }} style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'end' }}>
      <label>Placement number<input className="input" value={number} placeholder="PL-123" disabled={busy} onChange={event => { ++generation.current; setNumber(event.target.value); select(null); setHistory(null); }} /></label>
      <button className="btn" disabled={busy || !canSend || !number.trim()}>Review placement</button>
      <button className="btn" type="button" disabled={busy || !number.trim()} onClick={() => loadHistory(null)}>Placement review history</button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}
    {history && <div>{history.items.map(item => <button className="btn" type="button" disabled={busy} key={item.delivery.id} onClick={() => select(item)}>
      {item.packet.placement.number} · {item.delivery.created_at} · {item.delivery.state}
    </button>)}{history.next_before && <button className="btn" type="button" disabled={busy} onClick={() => loadHistory(history.next_before)}>Next page</button>}</div>}
    {review && <div>
      <p>Destination: <strong>{review.organization.name}</strong> · {review.delivery.state}</p>
      <AssignmentCommercialPreview packet={review.packet} />
      {!review.delivery_enabled && <p>Placement delivery is not enabled in this environment.</p>}
      {['preview', 'approved'].includes(review.delivery.state) && !attempted && <>
        <label><input type="checkbox" disabled={busy} checked={confirmed} onChange={event => setConfirmed(event.target.checked)} /> I reviewed the placement, all commercial periods, current settings and destination company.</label>
        <button className="btn" type="button" disabled={busy || !confirmed || !canSend || !review.delivery_enabled} onClick={send}>Send for placement review</button>
      </>}
      {(attempted || ['sending', 'uncertain', 'delivered'].includes(review.delivery.state)) && <button className="btn" type="button" disabled={busy} onClick={status}>Check provider receipt and assignment</button>}
      {receipt && <p role="status">{receipt.state === 'created' ? `Assignment confirmed: ${receipt.assignment_id} · ${receipt.assignment_status}. Review its Core record link before sending payroll time.`
        : receipt.state === 'superseded' ? 'This is a historical snapshot; a later revision is accepted.'
          : receipt.state === 'needs_mapping' ? 'Received. Link the missing people, client or vendor records in PurePay before creating the assignment.'
            : receipt.state === 'conflict' ? 'The existing assignment or identity needs reconciliation in PurePay.'
              : 'Received. Open Placements from Core in PurePay to review and apply the snapshot.'}</p>}
      {receipt?.accepted_revision && ['created', 'superseded'].includes(receipt.state) && <button className="btn" type="button" disabled={busy || !canSend}
        onClick={() => preview(receipt.accepted_revision)}>Review current Core changes as a revision</button>}
    </div>}
  </section>;
}
