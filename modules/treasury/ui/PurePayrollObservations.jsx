import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const endpoint = '/api/purepay_core.php?action=payroll_observation_';
const labels = { gross: 'Gross pay', bonus: 'Bonus', reimbursements: 'Reimbursements', federal_wh: 'Federal withholding', social_security: 'Employee Social Security', medicare: 'Employee Medicare', state_wh: 'State withholding', local_wh: 'Local withholding', sdi_pfl: 'State insurance and leave', other_deductions: 'Other deductions', garnishments: 'Garnishments', net: 'Net pay', employer_fica: 'Employer Social Security and Medicare', employer_futa: 'Federal unemployment', employer_suta: 'State unemployment', employer_match: 'Employer retirement match', funding_required: 'Funding required' };
const comparisonLabels = { matched: 'Totals agree', different: 'Difference', no_lines: 'No payroll lines', missing_line_amounts: 'Incomplete line amounts', missing_provider_total: 'Run total unavailable' };
const personLabels = { unlinked: 'No Core link', ambiguous: 'Conflicting Core links', requires_review: 'Core link needs review' };
const statusLabel = value => value === 'paid' ? 'Recorded paid' : value || 'Status unavailable';
const amount = (value, reason) => value == null ? `Unknown${reason ? ` (${reason})` : ''}` : value;
const card = { padding: 16, marginTop: 20, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 };
const sourceLabels = { id: 'Reference ID', entry_id: 'Entry ID', source: 'Source system', person_id: 'Person ID', source_workspace_id: 'Source workspace', revision: 'Revision', core_input_id: 'Core input reference', date: 'Work date', hours: 'Regular hours', ot_hours: 'Overtime hours', instance_id: 'Core installation', time_tenant_id: 'Core time workspace', time_entry_id: 'Core time entry', system: 'Original system', workspace_id: 'Original workspace', component: 'Time component' };
function SourceReference({ entry, index }) {
  const fields = Object.entries(entry).flatMap(([key, value]) => value !== null && typeof value === 'object'
    ? Object.entries(value).map(([field, item]) => ({ key: `${key}.${field}`, label: `${key === 'core' ? 'Core' : 'Original source'} · ${sourceLabels[field] || field}`, value: item }))
    : [{ key, label: sourceLabels[key] || key, value }]);
  return <div style={{ marginTop: 8 }}><strong>Recorded reference {index + 1}</strong>{fields.length === 0 ? <p>No usable reference fields.</p> : <dl>{fields.map(field => <React.Fragment key={field.key}><dt>{field.label}</dt><dd style={{ overflowWrap: 'anywhere' }}>{field.value ?? 'Unknown (invalid)'}</dd></React.Fragment>)}</dl>}</div>;
}

export function PurePayrollObservationView({ capture, savedAt }) {
  const data = capture.observation;
  const people = new Map(capture.comparison.people.map(person => [person.employee_id, person]));
  return <section aria-label="Payroll observation details">
    {savedAt && <p role="status">Saved capture from {savedAt}. This shows the records reviewed then; refresh the run for current figures and links.</p>}
    <p><strong>{statusLabel(data.status)}</strong> · Pay date {data.pay_date || 'unknown'} · {data.currency || 'Currency unknown'}</p>
    <p>Period {data.period_start || 'unknown'} to {data.period_end || 'unknown'} · Provider run {data.run_id}</p>
    <p>{data.execution_hold === true ? 'Provider execution hold is set.' : data.execution_hold === false ? 'Provider reports no execution hold.' : 'Provider execution hold is unknown.'}</p>
    <p>These are stored provider ledger figures. Payment delivery, tax remittance and matching against Core time and rates have not been verified. Saving a capture records this review in Core; it does not post accounting entries.</p>
    <h5>Run totals compared with payroll lines</h5>
    <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Amount</th><th>Stored run total</th><th>Sum of complete lines</th><th>Difference (run − lines)</th><th>Review</th></tr></thead><tbody>
      {Object.entries(capture.comparison.totals).map(([field, row]) => <tr key={field}><td>{labels[field] || field}</td><td>{amount(row.provider_total, data.unavailable_totals[field])}</td><td>{amount(row.line_total)}</td><td>{amount(row.difference)}</td><td>{comparisonLabels[row.status]}{row.missing_lines ? ` (${row.missing_lines} incomplete)` : ''}</td></tr>)}
    </tbody></table></div>
    <h5>Employees and source details</h5>
    <p>Core person numbers appear only for completed identity links. Payroll source references remain as recorded by the provider.</p>
    {data.lines.length === 0 && <p>No payroll lines were recorded.</p>}
    {data.lines.map(line => {
      const person = people.get(line.employee_id);
      return <details key={line.line_index} style={{ marginTop: 8 }}><summary>Line {line.line_index + 1} · {line.employee_id || 'Employee unknown'} · {person?.person?.number || personLabels[person?.link_status] || 'No Core link'} · Gross {amount(line.amounts.gross, line.unavailable_amounts.gross)}</summary>
        <p>Regular hours {amount(line.hours, line.unavailable_fields.hours)} · Overtime hours {amount(line.ot_hours, line.unavailable_fields.ot_hours)}</p>
        <dl>{Object.entries(line.amounts).map(([field, value]) => <React.Fragment key={field}><dt>{labels[field] || field}</dt><dd>{amount(value, line.unavailable_amounts[field])}</dd></React.Fragment>)}</dl>
        <p>Source references: {line.source_time_lineage.status.replaceAll('_', ' ')}{line.source_time_lineage.unavailable_entries.length ? ` · ${line.source_time_lineage.unavailable_entries.length} incomplete or invalid references` : ''}</p>
        {line.source_time_lineage.entries.map((entry, index) => <SourceReference key={index} entry={entry} index={index} />)}
      </details>;
    })}
    <p>Provider observed {data.observed_at}. The provider rechecked these records; this is not a simultaneous accounting snapshot.</p>
  </section>;
}

export default function PurePayrollObservations({ tenantId, connectionRevision }) {
  const [page, setPage] = useState(null);
  const [limit, setLimit] = useState(25);
  const [selected, setSelected] = useState(null);
  const [history, setHistory] = useState(null);
  const [runId, setRunId] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const generation = useRef(0);
  const request = useRef(null);
  const pending = useRef(false);
  const seenCursors = useRef(new Set());
  const reset = () => { setPage(null); setSelected(null); setHistory(null); setRunId(''); setConfirmed(false); setMessage(''); seenCursors.current.clear(); };
  useEffect(() => {
    const lifecycle = generation; const active = request; const working = pending;
    generation.current++; request.current?.abort(); pending.current = false;
    setPage(null); setSelected(null); setHistory(null); setRunId(''); setConfirmed(false); setBusy(false); setError(''); setMessage(''); seenCursors.current.clear();
    return () => { lifecycle.current++; active.current?.abort(); working.current = false; };
  }, [tenantId, connectionRevision]);
  const currentTenant = () => !!tenantId && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const run = async (action, body, accept) => {
    if (pending.current) return;
    if (!currentTenant()) { reset(); setError('Workspace changed. Check the company and access again.'); return; }
    const sequence = ++generation.current;
    request.current?.abort(); const controller = new AbortController(); request.current = controller;
    pending.current = true; setBusy(true); setError(''); setMessage(''); setConfirmed(false);
    try {
      const result = await api.post(endpoint + action, body, { timeoutMs: 60000,
        headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: controller.signal } });
      if (sequence !== generation.current) return;
      if (!currentTenant()) { reset(); throw new Error('Workspace changed. Check the company and access again.'); }
      const scoped = action === 'save' ? result.saved : result;
      if (String(scoped.tenant_id) !== String(tenantId)) { reset(); throw new Error('The response belongs to another workspace. Check the company again.'); }
      accept(result);
    } catch (err) {
      if (sequence !== generation.current) return;
      // A save rechecks the provider and Core links. Do not retain a stale confirmation after failure.
      if (action === 'save' || action === 'read') setSelected(null);
      setError(err.message || 'Could not review provider payroll.');
    } finally { if (sequence === generation.current) { pending.current = false; setBusy(false); } }
  };
  const list = cursor => {
    setPage(null); setSelected(null); setHistory(null); setRunId('');
    if (!cursor) seenCursors.current.clear();
    return run('page', { limit, cursor }, result => {
      if (cursor) seenCursors.current.add(cursor);
      if (result.next_cursor && seenCursors.current.has(result.next_cursor)) throw new Error('The provider repeated a payroll page. Refresh the list before continuing.');
      setPage(result);
    });
  };
  const read = id => {
    setSelected(null); setHistory(null); setRunId(id);
    return run('read', { run_id: id }, result => setSelected({ capture: result, savedAt: null }));
  };
  const showHistory = before => run('history', { run_id: runId, before }, result => { setSelected(null); setHistory(result); });
  return <section aria-label="Provider-operated payroll" style={card}>
    <h4>Payroll operated in PurePayroll</h4>
    <p>Review existing runs while PurePayroll remains the operational payroll system. Core can retain the provider figures and show reviewed person links for comparison.</p>
    <label>Runs per page<select className="input" value={limit} disabled={busy} onChange={e => { setLimit(Number(e.target.value)); reset(); }}>
      {[1, 5, 10, 25].map(size => <option value={size} key={size}>{size}</option>)}
    </select></label>{' '}
    <button className="btn" disabled={busy || !tenantId} onClick={() => list(null)}>Find provider payroll</button>
    <p>For a large payroll, choose fewer runs per page. An oversized response is refused in full.</p>
    {error && <p role="alert" className="error">{error}</p>}
    {message && <p role="status">{message}</p>}
    {page && <>
      <p>{page.items.length} runs on this page · {page.organization.name}</p>
      {page.items.length > 0 && <table className="data-table"><thead><tr><th>Provider run</th><th>Period</th><th>Status</th><th>Review</th></tr></thead><tbody>{page.items.map(item => <tr key={item.run_id}>
        <td>{item.run_id}</td><td>{item.period_start || 'Unknown'} to {item.period_end || 'unknown'}</td><td>{statusLabel(item.status)}</td><td><button className="btn" disabled={busy} onClick={() => read(item.run_id)}>Review {item.run_id}</button></td>
      </tr>)}</tbody></table>}
      {page.next_cursor && <button className="btn" disabled={busy} onClick={() => list(page.next_cursor)}>Next payroll page</button>}
    </>}
    {runId && <p><button className="btn" disabled={busy} onClick={() => read(runId)}>Refresh selected payroll</button>{' '}<button className="btn" disabled={busy} onClick={() => showHistory(null)}>View saved captures</button></p>}
    {history && <div>
      <p>{history.items.length} saved captures on this page for {history.run_id}.</p>
      {history.items.map(item => <button className="btn" key={item.id} disabled={busy} onClick={() => { setSelected({ capture: item.saved, savedAt: item.saved_at }); setConfirmed(false); }}>Capture {item.id} · {item.saved_at}</button>)}
      {history.next_before && <button className="btn" disabled={busy} onClick={() => showHistory(history.next_before)}>Older captures</button>}
    </div>}
    {selected && <>
      <PurePayrollObservationView capture={selected.capture} savedAt={selected.savedAt} />
      {!selected.savedAt && <>
        <label><input type="checkbox" disabled={busy} checked={confirmed} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these figures, differences and Core person links.</label>{' '}
        <button className="btn btn--primary" disabled={busy || !confirmed} onClick={() => run('save', { run_id: selected.capture.observation.run_id, review_hash: selected.capture.review_hash, confirmed: true }, result => {
          setSelected(null); setMessage(result.replayed ? 'This provider and Core version was already captured. Open saved captures to review it.' : 'Review captured in Core. Open saved captures to review it.');
        })}>Save reviewed capture</button>
      </>}
    </>}
  </section>;
}
