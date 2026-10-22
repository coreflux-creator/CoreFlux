import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';

const endpoint = '/api/quanta.php?action=';
const labels = { full_name: 'Name', email: 'Email', phone: 'Phone' };
const stateLabel = state => ({ delivered: 'Confirmed by Quanta', uncertain: 'Receipt check needed', sending: 'Awaiting confirmation', failed: 'Rejected', preview: 'Ready for review', approved: 'Reviewed', conflict: 'Review changed', cancelled: 'Cancelled' })[state] || state;

export default function QuantaPublicationPanel({ people = [], workspaceId }) {
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState([]);
  const [preview, setPreview] = useState(null);
  const [history, setHistory] = useState(null);
  const [confirmed, setConfirmed] = useState({});
  const [needsCheck, setNeedsCheck] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const sequence = useRef(0);
  const controller = useRef(null);
  const mounted = useRef(false);
  const inFlight = useRef(false);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; sequence.current += 1; controller.current?.abort(); }; }, []);
  const choices = people.filter(p => `${p.person_number || ''} ${p.name} ${p.email_primary || ''}`.toLowerCase().includes(query.toLowerCase())).slice(0, 30);
  const sameWorkspace = () => /^[1-9][0-9]*$/.test(String(workspaceId || '')) && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId));

  const run = async (action, body, accept, failed) => {
    if (inFlight.current) return;
    if (!sameWorkspace()) { setPreview(null); setHistory(null); setError('The workspace changed. Refresh before reviewing people.'); return; }
    const revision = ++sequence.current;
    inFlight.current = true;
    controller.current?.abort(); controller.current = new AbortController();
    setBusy(true); setError('');
    const current = () => mounted.current && sequence.current === revision;
    try {
      const result = await api.post(`${endpoint}${action}`, body, { timeoutMs: 120000,
        headers: { 'X-CoreFlux-Tenant-Id': String(workspaceId) }, fetch: { signal: controller.current.signal } });
      if (current() && sameWorkspace()) accept(result);
      else if (current()) { setPreview(null); setHistory(null); setError('The workspace changed. Refresh before reviewing people.'); }
    } catch (e) {
      if (current() && sameWorkspace()) { failed?.(); setError(e.message || 'Could not complete the request. Check the delivery receipt before sending again.'); }
      else if (current()) { setPreview(null); setHistory(null); }
    } finally { if (current()) { inFlight.current = false; setBusy(false); } }
  };

  const choose = (id, checked) => {
    setSelected(prev => checked ? [...prev, id] : prev.filter(value => value !== id));
    setPreview(null); setHistory(null); setConfirmed({}); setNeedsCheck({}); setError('');
  };
  const prepare = () => {
    setPreview(null); setConfirmed({});
    return run('publication_preview', { person_ids: selected }, result => setPreview(result));
  };
  const updateDelivery = delivery => {
    setPreview(value => value && ({ ...value, rows: value.rows.map(row => Number(row.delivery?.id) === Number(delivery.id) ? { ...row, delivery } : row) }));
    setHistory(value => value && ({ ...value, rows: value.rows.map(row => Number(row.id) === Number(delivery.id) ? delivery : row) }));
    setConfirmed(value => ({ ...value, [delivery.id]: false }));
  };
  const send = row => {
    const delivery = row.delivery;
    if (!confirmed[delivery.id] || needsCheck[delivery.id]) return;
    setConfirmed(value => ({ ...value, [delivery.id]: false }));
    return run('publication_send', { delivery_id: Number(delivery.id), review_hash: delivery.review_hash, confirm_profile: true }, result => {
      updateDelivery(result); setNeedsCheck(value => ({ ...value, [delivery.id]: false }));
    }, () => setNeedsCheck(value => ({ ...value, [delivery.id]: true })));
  };
  const checkReceipt = delivery => run('publication_status', { delivery_id: Number(delivery.id), review_hash: delivery.review_hash }, result => {
    updateDelivery(result.delivery);
    setNeedsCheck(value => ({ ...value, [delivery.id]: ['sending', 'uncertain'].includes(result.delivery.state) }));
    if (result.message) setError(result.message);
  });
  const loadHistory = (personId, before = null) => run('publication_history', { person_id: Number(personId), before }, result => {
    setHistory(previous => ({ personId, rows: before && previous?.personId === personId
      ? [...previous.rows, ...result.rows.filter(row => !previous.rows.some(old => Number(old.id) === Number(row.id)))] : result.rows,
      next_before: result.next_before }));
  });

  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18 }}>Core people to Quanta</h2>
    <p>Prepare workers with their Core person number, or review name and phone changes. New workers remain disabled until onboarding is completed in Quanta. Existing provider IDs and access are preserved.</p>
    <label>Find people <input type="search" value={query} onChange={e => setQuery(e.target.value)} /></label>
    <div style={{ maxHeight: 240, overflow: 'auto', margin: '12px 0' }}>
      {choices.map(p => <label key={p.id} style={{ display: 'flex', gap: 8, padding: 5 }}>
        <input type="checkbox" checked={selected.includes(Number(p.id))} disabled={busy || !p.person_number || (!selected.includes(Number(p.id)) && selected.length >= 50)}
          onChange={e => choose(Number(p.id), e.target.checked)} />
        {p.person_number || 'Number unavailable'} · {p.name} · {p.email_primary || 'No email'}
      </label>)}
      {!choices.length && <p>No matching people.</p>}
    </div>
    <div style={{ display: 'flex', gap: 10 }}>
      <button type="button" className="btn btn-secondary" disabled={busy || !selected.length} onClick={prepare}>{busy ? 'Working…' : `Preview ${selected.length} selected`}</button>
      <button type="button" className="btn btn-secondary" disabled={busy || selected.length !== 1} onClick={() => loadHistory(selected[0])}>Recent deliveries</button>
    </div>
    {error && <p role="alert">{error}</p>}
    {preview && <>
      <p>Connected Quanta workspace: <strong>{preview.workspace?.name}</strong></p>
      <p role="status">{preview.hold_reason}</p>
      {preview.rows.map(row => {
        const delivery = row.delivery;
        const canSend = preview.delivery_enabled && delivery && ['preview', 'approved'].includes(delivery.state) && !needsCheck[delivery.id];
        return <article key={row.person_id} style={{ padding: '16px 0', borderTop: '1px solid var(--cf-border)' }}>
          <h3>{row.person_number} · {row.person_name}</h3><p>{row.message}</p>
          {row.proposed && <table className="table" style={{ width: '100%', maxWidth: 850, textAlign: 'left' }}>
            <thead><tr><th>Field</th><th>Currently in Quanta</th><th>Proposed</th></tr></thead>
            <tbody>{Object.entries(labels).map(([field, label]) => <tr key={field}><th>{label}</th><td>{row.current?.[field] ?? '—'}</td>
              <td>{field === 'phone' && row.proposed[field] === null ? (row.current ? 'Keep current phone' : 'Not supplied') : row.proposed[field]}</td></tr>)}</tbody>
          </table>}
          {delivery && <p role="status">Delivery #{delivery.id}: {stateLabel(delivery.state)}{delivery.remote_id && ` · Quanta worker ${delivery.remote_id}`}</p>}
          {needsCheck[delivery?.id] && <p>Check the receipt before trying another send.</p>}
          {canSend && <label style={{ display: 'block', margin: '10px 0' }}><input type="checkbox" disabled={busy} checked={!!confirmed[delivery.id]} onChange={e => setConfirmed(value => ({ ...value, [delivery.id]: e.target.checked }))} /> I reviewed this person and these contact fields.</label>}
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
            {canSend && <button type="button" className="btn btn-primary" disabled={busy || !confirmed[delivery.id]} onClick={() => send(row)}>Send reviewed person</button>}
            {delivery && <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => checkReceipt(delivery)}>Check receipt</button>}
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => loadHistory(row.person_id)}>Delivery history for {row.person_number}</button>
          </div>
        </article>;
      })}
    </>}
    {history && <div style={{ marginTop: 18 }}><h3>Recent deliveries · {people.find(p => Number(p.id) === history.personId)?.person_number || 'Selected person'}</h3>
      {!history.rows.length && <p>No worker-profile deliveries recorded.</p>}
      {history.rows.map(delivery => <div key={delivery.id} style={{ padding: '8px 0' }}>
        #{delivery.id} · {stateLabel(delivery.state)} · {delivery.created_at}
        <button type="button" className="btn btn-secondary btn-sm" disabled={busy} style={{ marginLeft: 12 }} onClick={() => checkReceipt(delivery)}>Check receipt #{delivery.id}</button>
      </div>)}
      {history.next_before && <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => loadHistory(history.personId, history.next_before)}>Earlier deliveries</button>}
    </div>}
  </section>;
}
