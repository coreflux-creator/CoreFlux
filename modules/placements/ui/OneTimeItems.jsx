import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const destinations = { ar: 'Client invoice', ap: 'Vendor bill', payroll: 'Payroll earning', none: 'Margin-only cost' };
export default function OneTimeItems({ placementId, startDate, currency, items = [], parties = [], canManage = false, refresh }) {
  const emptyForm = () => ({ description: '', settlement_channel: 'ar', economic_party_id: '', direction: 'charge', amount: '', currency, apply_on: startDate || new Date().toISOString().slice(0, 10), taxable: false });
  const [form, setForm] = useState(emptyForm);
  const [pending, setPending] = useState(null);
  const [receipts, setReceipts] = useState({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const rows = Object.values({ ...receipts, ...Object.fromEntries(items.map(item => [item.id, item])) }).filter(item => item.status !== 'void' && receipts[item.id]?.status !== 'void');
  const recipients = parties.filter(party => party.settlement_channel === form.settlement_channel && Number(party.active ?? 1) === 1);
  const disabled = busy || pending !== null;
  const update = (name, value) => setForm(current => ({ ...current, [name]: value }));
  const save = async event => {
    event.preventDefault();
    if (!canManage || busy) return;
    const body = pending || { ...form, currency, amount: form.amount, economic_party_id: form.economic_party_id ? Number(form.economic_party_id) : null, request_id: crypto.randomUUID() };
    setPending(body); setBusy(true); setMessage('');
    try {
      const result = await api.post(`/modules/placements/api/economics.php?placement_id=${placementId}&action=item`, body);
      if (!result?.item || Number(result.item.placement_id) !== Number(placementId) || result.item.source_external_id !== `native-item:${body.request_id}`) throw new Error('The save result could not be confirmed.');
      setReceipts(current => ({ ...current, [result.item.id]: result.item }));
      setPending(null); setForm(emptyForm());
      setMessage(result.item.status === 'void' ? 'This item was already saved and later removed.' : result.changed ? 'One-time item added to the placement contract.' : 'The previous save is confirmed. No duplicate item was added.');
      // The receipt confirms the save even if a subsequent refresh is unavailable.
      Promise.resolve().then(() => refresh?.()).catch(() => {});
    } catch (error) {
      if (error.status === 422) setPending(null);
      setMessage(error.status === 422 ? error.message : `${error.message} Retry this save to confirm its result before entering another item.`);
    } finally { setBusy(false); }
  };
  const remove = async item => {
    if (!canManage || busy || pending || !window.confirm(`Remove "${item.description}"?`)) return;
    setBusy(true); setMessage('');
    try {
      const result = await api.delete(`/modules/placements/api/economics.php?action=item&id=${item.id}`);
      if (Number(result?.item?.id) !== Number(item.id) || result.item.status !== 'void') throw new Error('The removal could not be confirmed.');
      setReceipts(current => ({ ...current, [item.id]: result.item }));
      setMessage('One-time item removed.');
      Promise.resolve().then(() => refresh?.()).catch(() => {});
    } catch (error) { setMessage(`Remove failed: ${error.message}`); }
    finally { setBusy(false); }
  };
  return <section style={{ marginTop: 24 }} data-testid="economics-one-time-items">
    <h4 style={{ marginBottom: 4 }}>One-time items</h4>
    <table className="data-table">
      <thead><tr><th>Item</th><th>Destination</th><th>Recipient</th><th>Apply on</th><th>Amount</th><th>Status</th><th aria-label="Actions" /></tr></thead>
      <tbody>
        {rows.length === 0 && <tr><td colSpan={7} className="empty">No one-time items.</td></tr>}
        {rows.map(item => <tr key={item.id} data-testid={`economic-item-${item.id}`}>
          <td>{item.description}{Number(item.taxable) ? <div>Taxable</div> : null}</td>
          <td>{destinations[item.settlement_channel]}</td>
          <td>{item.recipient_name || parties.find(party => Number(party.id) === Number(item.economic_party_id))?.display_name || '-'}</td>
          <td>{item.apply_on}</td><td>{item.direction === 'credit' ? '-' : ''}{item.currency} {Number(item.amount).toFixed(2)}</td>
          <td>{item.settlement_status === 'applied' || item.obligation_id ? 'Applied' : item.settlement_channel === 'none' ? 'Margin only' : 'Pending'}</td>
          <td>{canManage && item.settlement_status !== 'applied' && !item.obligation_id && !Number(item.source_managed) && <button type="button" className="btn btn--sm" disabled={disabled} onClick={() => remove(item)}>Remove</button>}</td>
        </tr>)}
      </tbody>
    </table>
    {message && <p role="status">{message}</p>}
    {canManage && <form onSubmit={save} data-testid="one-time-item-form">
      <fieldset disabled={disabled} style={{ border: 0, padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(145px, 1fr))', gap: 8, marginTop: 12 }}>
        <label>Description<input className="input" aria-label="Item description" required maxLength={255} value={form.description} onChange={e => update('description', e.target.value)} /></label>
        <label>Destination<select className="input" aria-label="Item destination" value={form.settlement_channel} onChange={e => {
          const channel = e.target.value;
          const recipient = parties.find(party => party.settlement_channel === channel && Number(party.active ?? 1) === 1);
          setForm(current => ({ ...current, settlement_channel: channel, economic_party_id: recipient?.id || '', direction: ['ap','payroll'].includes(channel) ? 'charge' : current.direction, taxable: channel === 'ar' ? current.taxable : false }));
        }}>{Object.entries(destinations).map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        {form.settlement_channel !== 'none' && <label>Recipient<select className="input" aria-label="Item recipient" required value={form.economic_party_id} onChange={e => update('economic_party_id', e.target.value)}><option value="">Choose recipient</option>{recipients.map(party => <option key={party.id} value={party.id}>{party.display_name}</option>)}</select></label>}
        {!['ap','payroll'].includes(form.settlement_channel) && <label>Effect<select className="input" aria-label="Item effect" value={form.direction} onChange={e => update('direction', e.target.value)}><option value="charge">{form.settlement_channel === 'ar' ? 'Charge client' : 'Add cost'}</option><option value="credit">{form.settlement_channel === 'ar' ? 'Credit client' : 'Reduce cost'}</option></select></label>}
        <label>Amount<input className="input" aria-label="Item amount" type="number" min="0.01" max="9999999999.99" step="0.01" required value={form.amount} onChange={e => update('amount', e.target.value)} /></label>
        <label>Apply on<input className="input" aria-label="Item apply date" type="date" required value={form.apply_on} onChange={e => update('apply_on', e.target.value)} /></label>
        <label>Contract currency<input className="input" readOnly value={currency} /></label>
        {form.settlement_channel === 'ar' && <label><input type="checkbox" checked={form.taxable} onChange={e => update('taxable', e.target.checked)} /> Taxable</label>}
      </fieldset>
      <button className="btn btn--primary" disabled={busy || (!pending && form.settlement_channel !== 'none' && recipients.length === 0)}>{busy ? 'Saving...' : pending ? 'Retry same save' : 'Add one-time item'}</button>
    </form>}
    {canManage && form.settlement_channel !== 'none' && recipients.length === 0 && <p className="error">Add a {destinations[form.settlement_channel].toLowerCase()} participant before assigning this item.</p>}
  </section>;
}
