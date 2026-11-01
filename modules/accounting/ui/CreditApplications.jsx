import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const ENDPOINT = '/modules/accounting/api/credit_applications.php';
const today = () => new Date().toLocaleDateString('en-CA');
const money = value => Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CreditApplications() {
  const { data: entities, error: entityError } = useApi('/modules/accounting/api/entities.php');
  const [form, setForm] = useState({ entity_id: '', side: 'ar', credit_id: '', document_id: '', application_date: today(), amount: '' });
  const [rows, setRows] = useState(null);
  const [preview, setPreview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [correction, setCorrection] = useState(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const reset = () => { setPreview(null); setConfirmed(false); setError(''); setMessage(''); };
  const change = (key, value) => {
    reset();
    setForm(old => ({ ...old, [key]: value }));
    if (key === 'entity_id' || key === 'side') {
      setRows(null); setCorrection(null);
      setForm(old => ({ ...old, credit_id: '', document_id: '' }));
    }
  };
  const refresh = async () => {
    const result = await api.get(`${ENDPOINT}?entity_id=${Number(form.entity_id)}&side=${form.side}`);
    setRows(result);
  };
  const run = async fn => {
    setBusy(true); setError(''); setMessage('');
    try { await fn(); } catch (err) { setError(err.message); setPreview(null); setConfirmed(false); }
    finally { setBusy(false); }
  };
  const credits = (rows?.documents || []).filter(row => row.opening_cutover_id && Number(row.amount_due) < 0);
  const targets = (rows?.documents || []).filter(row => Number(row.amount_due) > 0);
  return (
    <section className="opening-balances credit-applications">
      <h2>Apply opening credits</h2>
      <p>Use a carried-forward customer or vendor credit against an unpaid invoice or bill.
        Applications change the two open balances. Cash, income, expenses, and the ledger total stay the same.</p>
      <p><Link to="../opening-balances">Opening balances</Link></p>
      {(error || entityError) && <div className="error-banner" role="alert">{error || entityError}</div>}
      {message && <p role="status">{message}</p>}
      <fieldset disabled={busy}>
        <legend>Choose open items</legend>
        <label>Legal entity
          <select value={form.entity_id} onChange={e => change('entity_id', e.target.value)}>
            <option value="">Choose an entity</option>
            {(entities?.rows || []).filter(row => Number(row.active) === 1).map(row =>
              <option key={row.id} value={row.id}>{row.legal_name} ({row.code})</option>)}
          </select>
        </label>
        <label>Credit type
          <select value={form.side} onChange={e => change('side', e.target.value)}>
            <option value="ar">Customer credit</option><option value="ap">Vendor credit</option>
          </select>
        </label>
        <button type="button" disabled={!form.entity_id} onClick={() => run(async () => { reset(); await refresh(); })}>Load open items</button>
        {rows && <>
          <label>Credit
            <select value={form.credit_id} onChange={e => change('credit_id', e.target.value)}>
              <option value="">Choose a credit</option>
              {credits.map(row => <option key={row.id} value={row.id}>{row.party} · {row.number} · {row.currency} {money(-Number(row.amount_due))}</option>)}
            </select>
          </label>
          <label>{form.side === 'ar' ? 'Invoice' : 'Bill'}
            <select value={form.document_id} onChange={e => change('document_id', e.target.value)}>
              <option value="">Choose an open item</option>
              {targets.map(row => <option key={row.id} value={row.id}>{row.party} · {row.number} · {row.currency} {money(row.amount_due)}</option>)}
            </select>
          </label>
          <label>Application date<input type="date" value={form.application_date} max={today()} onChange={e => change('application_date', e.target.value)} /></label>
          <label>Amount<input type="number" min="0.01" step="0.01" value={form.amount} onChange={e => change('amount', e.target.value)} /></label>
          <button type="button" disabled={!form.credit_id || !form.document_id || !form.amount}
            onClick={() => run(async () => { setConfirmed(false); setPreview(await api.post(`${ENDPOINT}?action=preview`, form)); })}>Review application</button>
          {!credits.length && <p>No unapplied opening credits in this entity.</p>}
        </>}
      </fieldset>
      {preview && <fieldset disabled={busy}>
        <legend>Review application</legend>
        <p>{preview.party} · {preview.application_date} · {preview.currency} {money(preview.amount)}</p>
        <p>Credit {preview.credit_number}: {money(preview.credit_before)} available → {money(preview.credit_after)}.</p>
        <p>{form.side === 'ar' ? 'Invoice' : 'Bill'} {preview.document_number}: {money(preview.document_before)} due → {money(preview.document_after)}.</p>
        <label><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />I reviewed the party, documents, date, and amount.</label>
        <button type="button" disabled={!confirmed} onClick={() => run(async () => {
          const result = await api.post(`${ENDPOINT}?action=commit`, { ...form, preview_token: preview.preview_token });
          setPreview(null); setConfirmed(false); await refresh();
          setMessage(`Application ${result.id} ${result.idempotent_replay ? 'already recorded' : 'recorded'}.`);
        })}>Apply credit</button>
      </fieldset>}
      {rows && <><h3>Application history</h3>
        <table><thead><tr><th>Reference</th><th>Date</th><th>Credit / document</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>{rows.applications.map(row => <tr key={row.id}>
            <td>{row.id}</td><td>{row.application_date}</td><td>{row.party}: {row.credit_number} / {row.document_number}</td><td>{row.currency} {money(row.amount)}</td>
            <td>{row.reversal_date ? `Reversed ${row.reversal_date}: ${row.reversal_reason}` : 'Applied'}</td>
            <td>{!row.reversal_date && <button type="button" disabled={busy} onClick={() => {
              reset(); setCorrection({ id: row.id, amount: row.amount, reversal_date: today(), reason: '', confirmed: false });
            }}>Correct application</button>}</td>
          </tr>)}</tbody></table>
        {!rows.applications.length && <p>No credit applications recorded.</p>}
      </>}
      {correction && <fieldset disabled={busy}><legend>Reverse application {correction.id}</legend>
        <p>This restores {money(correction.amount)} to the credit and the unpaid document. The dated history remains available.</p>
        <label>Correction date<input type="date" max={today()} value={correction.reversal_date}
          onChange={e => setCorrection({ ...correction, reversal_date: e.target.value, confirmed: false })} /></label>
        <label>Reason<input value={correction.reason} maxLength={500}
          onChange={e => setCorrection({ ...correction, reason: e.target.value, confirmed: false })} /></label>
        <label><input type="checkbox" checked={correction.confirmed}
          onChange={e => setCorrection({ ...correction, confirmed: e.target.checked })} />I reviewed this correction.</label>
        <button type="button" disabled={!correction.confirmed || !correction.reason.trim()} onClick={() => run(async () => {
          await api.post(`${ENDPOINT}?action=reverse`, { ...correction, side: form.side });
          setCorrection(null); await refresh(); setMessage('Credit application reversed.');
        })}>Reverse application</button>
        <button type="button" onClick={() => setCorrection(null)}>Cancel</button>
      </fieldset>}
    </section>
  );
}
