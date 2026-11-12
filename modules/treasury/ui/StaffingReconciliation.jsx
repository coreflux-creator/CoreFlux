import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const show = value => value == null ? 'Unknown' : String(value);
const periods = { exact: 'Same work period', within: 'Within the selected period', contains: 'Covers a longer period', overlap: 'Partly overlaps', outside: 'Outside the selected period', unknown: 'Service dates unavailable' };
const label = value => value.replaceAll('_', ' ');
function Facts({ value }) {
  return <dl>{Object.entries(value).map(([key, item]) => <React.Fragment key={key}><dt>{label(key)}</dt><dd>{Array.isArray(item)
    ? item.length ? item.map((entry, index) => <Facts key={index} value={typeof entry === 'object' && entry !== null ? entry : { value: entry }} />) : 'None recorded'
    : typeof item === 'object' && item !== null ? <Facts value={item} /> : typeof item === 'boolean' ? (item ? 'Yes' : 'No') : show(item)}</dd></React.Fragment>)}</dl>;
}
function Difference({ comparison }) {
  return <p>Numeric difference: {show(comparison.numeric_difference)}. Currency: {comparison.currency_comparison}.
    {' '}Difference in the same currency: {show(comparison.monetary_difference)}. Allocation to these time entries has not been established.</p>;
}

export function StaffingReconciliationView({ report }) {
  const [page, setPage] = useState(0);
  useEffect(() => setPage(0), [report]);
  const entries = report.time.entries, start = page * 50;
  return <section aria-label="Placement financial comparison">
    <h4>{report.placement.number} · {report.person.number} · {report.person_name}</h4>
    <p>Work period {report.period.start} to {report.period.end}. Read {report.read_at}.</p>
    <p>This combines current Core time with saved provider evidence. It covers reviewed placement associations on this page; unlinked documents are not included. Provider records have not been refreshed by this comparison.</p>
    <h5>Approved Core time</h5>
    <p>{show(report.time.approved_hours)} approved hours · {show(report.time.payable_hours)} payable · {show(report.time.billable_hours)} billable.</p>
    <p>Frozen-rate pay: {show(report.time.pay_amount)}. Frozen-rate bill amount: {show(report.time.bill_amount)}. Currency: {show(report.time.currency)}.</p>
    <p>These amounts cover approved hourly time before tax and one-time items. A Core approval does not prove an original client approval.</p>
    {!entries.length && <p>No Core time entries in this placement and period.</p>}
    {!!entries.length && <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Date</th><th>Core entry</th><th>Hours</th><th>Review</th><th>Source</th><th>Rate snapshot</th><th>Needs attention</th></tr></thead>
      <tbody>{entries.slice(start, start + 50).map(entry => <tr key={entry.id}><td>{entry.date}</td><td>{entry.id}</td><td>{show(entry.hours)}</td><td>{entry.approved ? 'Approved' : label(entry.status)}</td><td>{show(entry.source)}</td><td>{show(entry.rate_snapshot_id)}</td><td>{entry.issues.length ? entry.issues.map(label).join(', ') : '—'}</td></tr>)}</tbody></table></div>}
    {entries.length > 50 && <p><button className="btn" disabled={!page} onClick={() => setPage(p => p - 1)}>Previous time</button>{' '}<button className="btn" disabled={start + 50 >= entries.length} onClick={() => setPage(p => p + 1)}>Next time</button></p>}
    <h5>Reviewed invoice and bill lines</h5>
    {!report.financial_lines.length && <p>No reviewed placement associations on this page. This does not establish that the provider has no documents.</p>}
    {report.financial_lines.map(line => <details key={line.association_id}>
      <summary>{line.kind === 'invoice' ? 'Invoice' : 'Bill'} {line.document_id} · {line.description || line.item_name || line.line_id} · {periods[line.period_relation]}</summary>
      <p>Source line {line.line_id}. Association {line.association_id}, reviewed {line.reviewed_at}. Capture {line.capture_id}, observed {line.captured_at}.</p>
      {line.newer_capture_available && <p role="status">A newer document capture is available. This association still refers to the earlier reviewed evidence.</p>}
      <p>Source service dates: {show(line.service_start)} to {show(line.service_end)}.</p>
      <table className="data-table"><thead><tr><th>Figure</th><th>Provider line</th><th>Approved Core time for the source period</th></tr></thead><tbody>
        <tr><td>Hours</td><td>{show(line.hours)}</td><td>{show(line.core_approved_hours)}</td></tr>
        <tr><td>Amount</td><td>{show(line.amount)}</td><td>{show(line.core_amount)}</td></tr>
        <tr><td>Currency</td><td>{show(line.currency)}</td><td>{show(line.core_currency)}</td></tr>
      </tbody></table>
      <Difference comparison={line.comparison} />
      <details><summary>Document payments, fees, returns and allocations</summary>
        <p>These records belong to the whole document. They are not automatically allocated to this placement line. Stored paid statuses do not prove bank delivery. Amounts labelled cents retain that unit.</p>
        <Facts value={Object.fromEntries(Object.entries(line.document_payment_evidence).filter(([key]) => key !== 'scope'))} />
      </details>
    </details>)}
    <h5>Selected payroll evidence</h5>
    {report.payroll.status === 'not_selected' && <p>No payroll run selected.</p>}
    {report.payroll.status === 'no_saved_capture' && <p>No saved capture for this run. Review and save it under “Payroll operated in PurePayroll” first.</p>}
    {report.payroll.status === 'no_reviewed_person_link' && <p>The saved payroll has no reviewed link to this Core person.</p>}
    {report.payroll.period_relation && <p>{periods[report.payroll.period_relation]}: {show(report.payroll.period_start)} to {show(report.payroll.period_end)}. Pay date {show(report.payroll.pay_date)}. Recorded status {show(report.payroll.provider_status)}. Currency {show(report.payroll.currency)}.</p>}
    {!!report.payroll.other_placement_entries && <p>This person also has time on another placement in the selected period. The payroll amount has not been allocated between placements.</p>}
    {report.payroll.lines.map(line => <details key={line.line_index}><summary>Payroll employee {line.employee_id} · gross {show(line.amounts.gross)}</summary>
      <p>Recorded regular hours {show(line.hours)}, overtime {show(line.ot_hours)}. Core frozen-rate pay for a comparable period: {show(line.core_pay_amount)}.</p>
      <Difference comparison={line.comparison} /><Facts value={line.amounts} />
      <details><summary>Original time references</summary><Facts value={line.source_time_lineage} /></details>
    </details>)}
  </section>;
}

export default function StaffingReconciliation({ tenantId, connectionVersion }) {
  const [number, setNumber] = useState(''), [start, setStart] = useState(''), [end, setEnd] = useState(''), [runId, setRunId] = useState('');
  const [report, setReport] = useState(null), [error, setError] = useState(''), [busy, setBusy] = useState(false);
  const generation = useRef(0), active = useRef(null), pending = useRef(false);
  useEffect(() => {
    generation.current++; active.current?.abort(); pending.current = false;
    setNumber(''); setStart(''); setEnd(''); setRunId(''); setReport(null); setError(''); setBusy(false);
    return () => { generation.current++; active.current?.abort(); pending.current = false; };
  }, [tenantId, connectionVersion]);
  const current = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const change = setter => event => { setReport(null); setError(''); setter(event.target.value); };
  const read = async (before = null) => {
    if (pending.current) return;
    if (!current()) { setReport(null); setError('Workspace changed. Refresh the company connection.'); return; }
    const sequence = ++generation.current, controller = new AbortController(); active.current?.abort(); active.current = controller;
    pending.current = true; setBusy(true); setReport(null); setError('');
    try {
      const value = await api.post('/api/staffing_reconciliation.php', { placement_number: number.trim().toUpperCase(), start, end, payroll_run_id: runId.trim() || null, before },
        { timeoutMs: 60000, headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!current() || String(value.tenant_id) !== String(tenantId)) throw new Error('Workspace changed. Refresh the company connection.');
      setReport(value);
    } catch (e) { if (sequence === generation.current) setError(e.message || 'Could not read the comparison.'); }
    finally { if (sequence === generation.current) { pending.current = false; setBusy(false); } }
  };
  const valid = /^PL-[1-9][0-9]*$/.test(number.trim().toUpperCase()) && /^\d{4}-\d{2}-\d{2}$/.test(start) && /^\d{4}-\d{2}-\d{2}$/.test(end) && start <= end && (!runId.trim() || /^[A-Za-z0-9_-]{1,128}$/.test(runId.trim()));
  return <section aria-label="Compare placement and period" style={{ padding: 16, border: '1px solid var(--cf-border)', borderRadius: 8, marginBottom: 16 }}>
    <h3>Compare placement and work period</h3>
    <label>Placement number<input className="input" value={number} disabled={busy} placeholder="PL-…" onChange={change(setNumber)} /></label>
    <label>Work period start<input className="input" type="date" value={start} disabled={busy} onChange={change(setStart)} /></label>
    <label>Work period end<input className="input" type="date" value={end} disabled={busy} onChange={change(setEnd)} /></label>
    <label>Saved PurePayroll run ID (optional)<input className="input" value={runId} disabled={busy} onChange={change(setRunId)} /></label>
    <p><button className="btn" disabled={busy || !valid} onClick={() => read()}>Compare saved evidence</button></p>
    {error && <p className="error" role="alert">{error}</p>}
    {current() && report && <><StaffingReconciliationView report={report} />{report.next_before && <button className="btn" disabled={busy} onClick={() => read(report.next_before)}>Older placement associations</button>}</>}
  </section>;
}
