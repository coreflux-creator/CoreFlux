import React, { useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const labels = { core_preparing: 'Preparing', draft: 'Draft', void: 'Void', core_archived: 'Archived', approved: 'Approved', awaiting_funds: 'Awaiting funds', paying: 'Processing', submitted: 'Submitted', paid: 'Recorded paid' };
const taxLabels = { federal_wh: 'Federal withholding', social_security: 'Employee Social Security', medicare: 'Employee Medicare', state_wh: 'State withholding', local_wh: 'Local withholding', sdi_pfl: 'Employee state insurance and leave contributions', other_deductions: 'Other employee deductions', garnishments: 'Garnishments', employer_fica: 'Employer Social Security and Medicare', employer_futa: 'Federal unemployment', employer_suta: 'State unemployment and employer contributions', employer_match: 'Employer retirement match' };
const cell = { whiteSpace: 'normal', overflowWrap: 'anywhere', verticalAlign: 'top' };
const table = { width: '100%', minWidth: 560, tableLayout: 'fixed' };
const personNumbers = line => [...new Set(line.components.map(({ input_component: c }) => c.person.number))].join(', ');
const reference = (label, value) => <details style={{ marginTop: 4, ...cell }}><summary>{label}</summary><small>{value}</small></details>;
export function PurePayrollResultView({ result }) {
  return <div style={{ marginTop: 12 }} aria-label="Provider payroll result">
    <p><strong>{labels[result.status] || result.status}</strong> · Pay date {result.pay_date} · {result.calculation_mode === 'manual' ? 'Manually entered payroll figures' : 'Provider-calculated payroll figures'}{result.archived ? ' · Archived' : ''}</p>
    {result.execution_hold && <p role="status">This pilot run is held. Approval, funding and payment are unavailable.</p>}
    <p>Observed {result.observed_at}. These are provider ledger figures; employee payments and tax remittances are not confirmed. Viewing or saving this result does not post to Core’s ledger or release reserved hours.</p>
    <div style={{ overflowX: 'auto' }}><table className="data-table" style={table}><colgroup><col style={{ width: '16%' }} /><col style={{ width: '18%' }} /><col style={{ width: '26%' }} /><col style={{ width: '24%' }} /><col style={{ width: '16%' }} /></colgroup><thead><tr><th style={cell}>Person</th><th style={cell}>Placement</th><th style={cell}>Source time</th><th style={cell}>Hours × approved rate</th><th style={cell}>Gross earnings</th></tr></thead><tbody>
      {result.lines.flatMap(line => line.components.map(({ input_component: c, gross }) => <tr key={`${line.employee_id}:${c.source.time_entry_id}`}><td style={cell}><strong>{c.person.number}</strong>{reference('Employee ID', line.employee_id)}</td><td style={cell}><strong>{c.placement.number}</strong>{reference('Assignment ID', c.assignment_id)}</td><td style={cell}>{c.source.work_date}<br />{c.source.category}<br />Core time {c.source.time_entry_id}{c.source.source_system === 'quanta' && reference('Quanta reference', `${c.source.source_entry_id} · ${c.source.source_component}`)}</td><td style={cell}>{c.source.hours} × {c.rate.pay_rate} × {c.rate.multiplier}</td><td style={cell}><strong>USD {gross}</strong></td></tr>))}
    </tbody></table></div>
    <p>Taxes, deductions, bonuses and reimbursements remain attributed to the employee. They have not been spread across placements.</p>
    <div style={{ overflowX: 'auto' }}><table className="data-table" style={table}><thead><tr><th style={cell}>Person</th><th style={cell}>Source earnings</th><th style={cell}>Bonus</th><th style={cell}>Gross</th><th style={cell}>Reimbursement</th><th style={cell}>Net pay</th><th style={cell}>Funding required</th></tr></thead><tbody>
      {result.lines.map(line => <tr key={line.employee_id}><td style={cell}>{personNumbers(line)}{reference('Employee ID', line.employee_id)}</td>{['source_gross', 'bonus', 'gross', 'reimbursements', 'net', 'funding_required'].map(k => <td style={cell} key={k}>{line.amounts[k]}</td>)}</tr>)}
    </tbody></table></div>
    <details><summary>Tax and deduction detail</summary>{result.lines.map(line => <div key={line.employee_id}><p>{personNumbers(line)} (USD)</p>{reference('Employee ID', line.employee_id)}<dl>{Object.entries(taxLabels).map(([k, label]) => <React.Fragment key={k}><dt>{label}{k === 'garnishments' ? ' (included in other deductions)' : ''}</dt><dd>{line.amounts[k]}</dd></React.Fragment>)}</dl></div>)}</details>
    {reference('Run and observation references', `Run ${result.run_id} · Observation ${result.result_hash}`)}
  </div>;
}

export default function PurePayrollResults({ delivery }) {
  const [history, setHistory] = useState(null);
  const [selected, setSelected] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const pending = useRef(false);
  const context = { delivery_id: Number(delivery.id), review_hash: delivery.review_hash };
  const perform = async fn => { if (pending.current) return; pending.current = true; setBusy(true); setError(''); try { await fn(); } catch (e) { setError(e.message || 'Could not read payroll results.'); } finally { pending.current = false; setBusy(false); } };
  const historyPage = before => api.post('/api/purepay_core.php?action=payroll_result_history', { ...context, before });
  return <section aria-label="PurePayroll results" style={{ marginTop: 16 }}>
    <h4>Payroll results in Core</h4>
    <button className="btn" type="button" disabled={busy} onClick={() => perform(async () => { setHistory(await historyPage(null)); setSelected(null); })}>View result history</button>
    {error && <p role="alert" className="error">{error}</p>}
    {history && <>
      {!history.authorities.length && <p>No source reservation has been created for these inputs.</p>}
      {history.authorities.map((a, i) => <button className="btn" type="button" key={a.id} disabled={busy} onClick={() => perform(async () => { const saved = await api.post('/api/purepay_core.php?action=payroll_result_refresh', { ...context, authority_id: a.id }); setSelected(saved.result); setHistory(await historyPage(null)); })}>Check provider result {i + 1} · {a.state === 'held' ? 'Source reserved' : 'Source released'}</button>)}
      {history.more_authorities && <p>More reservations exist. Reconcile their history before treating this list as complete.</p>}
      <p>{history.items.length} saved observations on this page. Each records what the provider reported at that time.</p>
      {history.items.map(item => <button className="btn" type="button" disabled={busy} key={item.id} onClick={() => setSelected(item.result)}>Observation {item.id} · {labels[item.result.status]} · {item.result.observed_at}</button>)}
      {history.next_before && <button className="btn" type="button" disabled={busy} onClick={() => perform(async () => { setHistory(await historyPage(history.next_before)); setSelected(null); })}>Older observations</button>}
    </>}
    {selected && <PurePayrollResultView result={selected} />}
  </section>;
}
