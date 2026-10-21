import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const endpoint = '/api/purepay_core.php?action=';
const capabilityNames = { 'read:coreflux': 'View records', 'write:coreflux': 'Link existing records', 'read:payroll': 'View employees and payroll' };
const card = { border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 16, marginBottom: 16 };

export default function PurePayCoreConnection() {
  const status = useApi(`${endpoint}status`);
  const [key, setKey] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [kind, setKind] = useState('placement');
  const [page, setPage] = useState(null);
  const [seenCursors, setSeenCursors] = useState([]);
  const run = async fn => {
    setBusy(true); setError('');
    try { await fn(); } catch (e) { setError(e.message || 'Could not complete this action.'); }
    finally { setBusy(false); }
  };
  const check = async () => {
    setReview(null); setConfirmed(false); setPage(null); setSeenCursors([]);
    setReview(await api.post(`${endpoint}review`, {}));
  };
  const records = async cursor => {
    setPage(null);
    const result = await api.post(`${endpoint}records`, { kind, cursor });
    if (cursor && result.next_cursor && seenCursors.includes(result.next_cursor)) throw new Error('The provider repeated a page. Check its records before continuing.');
    setPage(result); setSeenCursors(previous => cursor ? [...previous, cursor] : []);
  };
  const data = status.data || {};
  return <section style={card} data-testid="purepay-core-connection">
    <h3 style={{ marginTop: 0 }}>Core records and PurePayroll</h3>
    <p>Connect a dedicated Core key to review people, assignments, clients and vendors. Confirm the provider company before opening its records.</p>
    <p>You can review existing provider records here. Creating or updating records, linking Core numbers and running payroll are not available through this connection yet.</p>
    {status.loading && <p>Loading connection…</p>}
    {(error || status.error) && <p className="error" role="alert">{error || status.error.message}</p>}
    {data.organization && <p>Bound company: <strong>{data.organization.name}</strong> · {data.organization.id}</p>}
    {data.api_base && <p>Provider environment: {data.api_base}</p>}
    {data.connected && <p>Key ending {data.api_key_last4}. {data.reviewed_at ? 'Company previously reviewed; check current access below.' : 'Company confirmation required.'}</p>}
    <form onSubmit={e => { e.preventDefault(); run(async () => {
      setReview(null); setPage(null); setConfirmed(false); setSeenCursors([]);
      await api.post(`${endpoint}connect`, { api_key: key }); setKey(''); await status.reload(); await check();
    }); }}>
      <label>Dedicated Core API key<input className="input" type="password" autoComplete="new-password" value={key} onChange={e => setKey(e.target.value)} /></label>
      <button className="btn" type="submit" disabled={busy || !key.trim()}>{data.connected ? 'Replace Core key' : 'Connect Core key'}</button>
    </form>
    {data.connected && <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
      <button className="btn" disabled={busy} onClick={() => run(check)}>Check company and access</button>
      <button className="btn" disabled={busy} onClick={() => run(async () => {
        await api.post(`${endpoint}disconnect`, {}); setReview(null); setPage(null); setConfirmed(false); await status.reload();
      })}>Disconnect Core key</button>
    </div>}
    {review && <div style={{ marginTop: 16 }}>
      <p>Provider company: <strong>{review.organization.name}</strong> · {review.organization.id}</p>
      <p>Access: {review.capabilities.map(c => capabilityNames[c] || c).join(', ')}</p>
      {review.verified ? <p>Company and current access verified.</p> : <>
        <label><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} /> This is the provider company for the current Core company.</label>
        <button className="btn btn--primary" disabled={busy || !confirmed} onClick={() => run(async () => {
          await api.post(`${endpoint}confirm`, { confirmed: true, review_hash: review.review_hash, organization_id: review.organization.id }); await status.reload(); await check();
        })}>Confirm company</button>
      </>}
      {review.verified && <>
        <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
          <label>Provider records<select className="input" value={kind} disabled={busy} onChange={e => { setKind(e.target.value); setPage(null); setSeenCursors([]); }}>
            <option value="placement">Assignments</option><option value="client">Clients</option><option value="vendor">Vendors</option>
            {review.capabilities.includes('read:payroll') && <option value="person">Employees</option>}
          </select></label>
          <button className="btn" disabled={busy} onClick={() => run(() => records(null))}>View records</button>
        </div>
        {page && <>
          <p>{page.items.length} {page.items.length === 1 ? 'record' : 'records'} on this page. Provider IDs are retained separately from Core numbers.</p>
          <table className="data-table"><thead><tr><th>Provider ID</th><th>Name</th><th>Status</th></tr></thead><tbody>
            {page.items.map(item => <tr key={item.id}><td>{item.id}</td><td>{item.name || item.display_name || item.legal_name || item.item_name || '—'}</td><td>{item.status || '—'}</td></tr>)}
          </tbody></table>
          {page.next_cursor && <button className="btn" disabled={busy} onClick={() => run(() => records(page.next_cursor))}>Next page</button>}
        </>}
      </>}
    </div>}
  </section>;
}
