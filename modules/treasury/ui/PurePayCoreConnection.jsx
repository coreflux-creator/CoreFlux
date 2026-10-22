import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import PurePayrollInputReview from './PurePayrollInputReview';
import PurePeopleInputReview from './PurePeopleInputReview';
import PureAssignmentInputReview from './PureAssignmentInputReview';
import PurePeopleSetup from './PurePeopleSetup';

const endpoint = '/api/purepay_core.php?action=';
const capabilityNames = { 'read:coreflux': 'View records', 'write:coreflux': 'Link existing records', 'read:payroll': 'View employees and payroll', 'read:people_setup': 'View employee setup indicators', 'write:payroll_inputs': 'Send payroll inputs for review', 'write:payroll_authority': 'Reserve reviewed payroll sources', 'write:people_inputs': 'Send people for profile review', 'write:assignment_inputs': 'Send placements for commercial review' };
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
  const [providerId, setProviderId] = useState('');
  const [number, setNumber] = useState('');
  const [link, setLink] = useState(null);
  const [linkConfirmed, setLinkConfirmed] = useState(false);
  const [history, setHistory] = useState(null);
  const clearLink = () => { setLink(null); setProviderId(''); setNumber(''); setLinkConfirmed(false); };
  const run = async fn => {
    setBusy(true); setError('');
    try { await fn(); } catch (e) { setError(e.message || 'Could not complete this action.'); }
    finally { setBusy(false); }
  };
  const check = async () => {
    setReview(null); setConfirmed(false); setPage(null); setSeenCursors([]);
    clearLink(); setHistory(null);
    setReview(await api.post(`${endpoint}review`, {}));
  };
  const records = async cursor => {
    setPage(null); clearLink();
    const result = await api.post(`${endpoint}records`, { kind, cursor });
    if (cursor && result.next_cursor && seenCursors.includes(result.next_cursor)) throw new Error('The provider repeated a page. Check its records before continuing.');
    setPage(result); setSeenCursors(previous => cursor ? [...previous, cursor] : []);
  };
  const data = status.data || {};
  return <section style={card} data-testid="purepay-core-connection">
    <h3 style={{ marginTop: 0 }}>Core records and PurePayroll</h3>
    <p>Connect a dedicated Core key to review people, assignments, clients and vendors. Confirm the provider company before opening its records.</p>
    <p>Review an existing provider record alongside its Core number, then confirm the match. Record links preserve each app’s existing ID.</p>
    {status.loading && <p>Loading connection…</p>}
    {(error || status.error) && <p className="error" role="alert">{error || status.error.message}</p>}
    {data.organization && <p>Bound company: <strong>{data.organization.name}</strong> · {data.organization.id}</p>}
    {data.api_base && <p>Provider environment: {data.api_base}</p>}
    {data.connected && <p>Key ending {data.api_key_last4}. {data.reviewed_at ? 'Company previously reviewed; check current access below.' : 'Company confirmation required.'}</p>}
    <form onSubmit={e => { e.preventDefault(); run(async () => {
      setReview(null); setPage(null); setConfirmed(false); setSeenCursors([]); clearLink(); setHistory(null);
      await api.post(`${endpoint}connect`, { api_key: key }); setKey(''); await status.reload(); await check();
    }); }}>
      <label>Dedicated Core API key<input className="input" type="password" autoComplete="new-password" value={key} onChange={e => setKey(e.target.value)} /></label>
      <button className="btn" type="submit" disabled={busy || !key.trim()}>{data.connected ? 'Replace Core key' : 'Connect Core key'}</button>
    </form>
    {data.connected && <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
      <button className="btn" disabled={busy} onClick={() => run(check)}>Check company and access</button>
      <button className="btn" disabled={busy} onClick={() => run(async () => {
        await api.post(`${endpoint}disconnect`, {}); setReview(null); setPage(null); setConfirmed(false); clearLink(); setHistory(null); await status.reload();
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
        {review.people_setup_contract_version === '2026-10-22.1' && review.capabilities.includes('read:people_setup') && <PurePeopleSetup key={`setup-${review.review_hash}`} />}
        {review.payroll_input_contract_version === '2026-10-21.1' && review.capabilities.includes('read:payroll') && <PurePayrollInputReview key={review.review_hash} canSend={review.capabilities.includes('write:payroll_inputs')} />}
        {review.people_input_contract_version === '2026-10-22.1' && review.capabilities.includes('read:payroll') && <PurePeopleInputReview key={`people-${review.review_hash}`} canSend={review.capabilities.includes('write:people_inputs')} />}
        {review.assignment_input_contract_version === '2026-10-22.2' && <PureAssignmentInputReview key={`assignments-${review.review_hash}`} canSend={review.capabilities.includes('write:assignment_inputs')} />}
        <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
          <label>Provider records<select className="input" value={kind} disabled={busy} onChange={e => { setKind(e.target.value); setPage(null); setSeenCursors([]); clearLink(); setHistory(null); }}>
            <option value="placement">Assignments</option><option value="client">Clients</option><option value="vendor">Vendors</option>
            {review.capabilities.includes('read:payroll') && <option value="person">Employees</option>}
          </select></label>
          <button className="btn" disabled={busy} onClick={() => run(() => records(null))}>View records</button>
          {review.link_contract_version === '2026-10-21.1' && <button className="btn" disabled={busy} onClick={() => run(async () => {
            setHistory(null); setHistory(await api.post(`${endpoint}link_history`, { kind }));
          })}>Link history</button>}
        </div>
        {page && <>
          <p>{page.items.length} {page.items.length === 1 ? 'record' : 'records'} on this page. Provider IDs are retained separately from Core numbers.</p>
          <table className="data-table"><thead><tr><th>Provider ID</th><th>Name</th><th>Status</th><th>Core link</th></tr></thead><tbody>
            {page.items.map(item => <tr key={item.id}><td>{item.id}</td><td>{item.name || item.display_name || item.legal_name || item.item_name || '—'}</td><td>{item.status || '—'}</td><td>
              {review.capabilities.includes('write:coreflux') && review.link_contract_version === '2026-10-21.1' && <button className="btn" disabled={busy} onClick={() => { clearLink(); setProviderId(item.id); }}>Choose record</button>}
            </td></tr>)}
          </tbody></table>
          {page.next_cursor && <button className="btn" disabled={busy} onClick={() => run(() => records(page.next_cursor))}>Next page</button>}
        </>}
        {providerId && <div style={{ ...card, marginTop: 16 }}>
          <h4>Review a Core match</h4>
          <p>Selected provider ID: <strong>{providerId}</strong></p>
          <form onSubmit={e => { e.preventDefault(); run(async () => {
            setLink(null); setLinkConfirmed(false);
            setLink(await api.post(`${endpoint}link_preview`, { kind, number: number.trim(), provider_id: providerId }));
          }); }}>
            <label>Core number<input className="input" value={number} placeholder={{ person: 'P-123', placement: 'PL-123', client: 'CL-123', vendor: 'V-123' }[kind]} disabled={busy}
              onChange={e => { setNumber(e.target.value); setLink(null); setLinkConfirmed(false); }} /></label>
            <button className="btn" disabled={busy || !number.trim()}>Review match</button>
          </form>
          {link && <>
            <p>Provider company: <strong>{link.organization.name}</strong></p>
            <table className="data-table"><thead><tr><th>Core record</th><th>Provider record</th></tr></thead><tbody>
              <tr><td>{link.source.reference.number}</td><td>{link.provider_record.id}</td></tr>
              <tr><td>{link.source.record.name || '—'}</td><td>{link.provider_record.name || link.provider_record.item_name || link.provider_record.display_name || link.provider_record.legal_name || '—'}</td></tr>
              <tr><td>{link.source.record.email || '—'}</td><td>{link.provider_record.email || '—'}</td></tr>
              <tr><td>{link.source.record.status || '—'}</td><td>{link.provider_record.status || '—'}</td></tr>
            </tbody></table>
            <p>This links existing identities. Employee setup, assignment terms and payroll remain separate actions.</p>
            {link.delivery.state === 'delivered' ? <p role="status">Link confirmed and recorded. Provider receipt: {link.delivery.remote_receipt_id}</p>
              : ['sending', 'uncertain'].includes(link.delivery.state) ? <>
                <p role="status">The previous attempt needs a confirmed provider receipt. Checking it will not send the link again.</p>
                <button className="btn" disabled={busy} onClick={() => run(async () => {
                  const delivery = await api.post(`${endpoint}link_reconcile`, { delivery_id: Number(link.delivery.id), review_hash: link.delivery.review_hash });
                  setLink(previous => ({ ...previous, delivery })); setLinkConfirmed(false);
                })}>Check provider receipt</button>
              </> : ['preview', 'approved'].includes(link.delivery.state) ? <>
                {link.existing_provider_link && <p>The provider already has this match. Confirming records its receipt in Core.</p>}
                {!link.delivery_enabled && <p>Link review is available. Sending will be enabled after the provider connection passes acceptance.</p>}
                <label><input type="checkbox" checked={linkConfirmed} disabled={busy || !link.delivery_enabled} onChange={e => setLinkConfirmed(e.target.checked)} /> I reviewed both records and confirm this match.</label>
                <button className="btn btn--primary" disabled={busy || !link.delivery_enabled || !linkConfirmed} onClick={() => run(async () => {
                  const delivery = await api.post(`${endpoint}link_send`, { confirmed: true, delivery_id: Number(link.delivery.id), review_hash: link.delivery.review_hash });
                  setLink(previous => ({ ...previous, delivery })); setLinkConfirmed(false);
                })}>Confirm record link</button>
              </> : <p>This review is {link.delivery.state}. Review the current records again before linking.</p>}
          </>}
        </div>}
        {history && <div style={{ ...card, marginTop: 16 }}>
          <h4>Link history</h4>
          {!history.items.length && <p>No link reviews for this record type.</p>}
          <table className="data-table"><thead><tr><th>Core number</th><th>Provider ID</th><th>Status</th><th>Follow-up</th></tr></thead><tbody>
            {history.items.map(item => <tr key={item.delivery.id}><td>{item.reference.number}</td><td>{item.provider_id}</td><td>{item.delivery.state}</td><td>
              {['sending', 'uncertain'].includes(item.delivery.state) && <button className="btn" disabled={busy} onClick={() => run(async () => {
                const delivery = await api.post(`${endpoint}link_reconcile`, { delivery_id: Number(item.delivery.id), review_hash: item.delivery.review_hash });
                setHistory(previous => ({ ...previous, items: previous.items.map(row => row.delivery.id === delivery.id ? { ...row, delivery } : row) }));
                setLink(previous => previous?.delivery.id === delivery.id ? { ...previous, delivery } : previous);
              })}>Check provider receipt</button>}
            </td></tr>)}
          </tbody></table>
          {history.next_before && <button className="btn" disabled={busy} onClick={() => run(async () => {
            const result = await api.post(`${endpoint}link_history`, { kind, before: history.next_before }); setHistory(result);
          })}>Older reviews</button>}
        </div>}
      </>}
    </div>}
  </section>;
}
