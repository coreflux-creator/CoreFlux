import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const label = value => value.replaceAll('_', ' ').replace(/^./, c => c.toUpperCase());
const display = value => value === null || value === '' ? 'Blank' : String(value);
const provider = source => source.system === 'quickbooks_online' ? 'QuickBooks' : 'JobDiva';

export default function ClientSourceReview({ client, onClose, onSaved }) {
  const [review, setReview] = useState(null);
  const [sourceKey, setSourceKey] = useState('');
  const [fields, setFields] = useState([]);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const refresh = useCallback(async () => {
    setBusy(true); setError(''); setFields([]); setConfirmed(false); setReview(null);
    try {
      const result = await api.get(`/modules/staffing/api/clients.php?action=source_review&id=${client.id}`);
      setReview(result.review); setSourceKey(Object.keys(result.review.observations)[0] || '');
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  }, [client.id]);
  useEffect(() => { refresh(); }, [refresh]);
  const apply = async () => {
    if (!confirmed || !fields.length || !review) return;
    setBusy(true); setError('');
    try {
      const result = await api.post(`/modules/staffing/api/clients.php?action=source_accept&id=${client.id}`, {
        source_key: sourceKey, fields, review_token: review.review_token, confirm_source: true,
      });
      setReview(null); setFields([]); setConfirmed(false);
      setMessage(result.replayed ? 'This selection was already applied. Later changes were preserved.' : 'Selected values applied. Core keeps control of these fields.');
      onSaved?.();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  const observation = review?.observations[sourceKey];
  return <div role="dialog" aria-modal="true" aria-label="Review incoming client values" style={{ position: 'fixed', inset: 0, zIndex: 60, background: '#0006', display: 'flex', justifyContent: 'flex-end' }}>
    <section style={{ background: '#fff', width: 760, maxWidth: '96vw', height: '100%', overflow: 'auto', padding: 24 }}>
      <h2>Incoming values · {client.name}</h2>
      <p>Choose values to apply once. Later source updates will keep these fields protected in Core.</p>
      <button type="button" disabled={busy} onClick={refresh}>Refresh review</button>{' '}
      <button type="button" disabled={busy} onClick={onClose}>Close review</button>
      {busy && <p role="status">Loading…</p>}
      {error && <p role="alert">{error}</p>}
      {message && <p role="status">{message}</p>}
      {review && !Object.keys(review.observations).length && <p>No incoming client values are available in this workspace.</p>}
      {observation && <>
        <label>Source <select aria-label="Client source" value={sourceKey} disabled={busy} onChange={e => { setSourceKey(e.target.value); setFields([]); setConfirmed(false); }}>
          {Object.entries(review.observations).map(([key, value]) => <option key={key} value={key}>{provider(value.source)} · {value.source.external_id}</option>)}
        </select></label>
        <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Use</th><th>Field</th><th>Core</th><th>Incoming</th></tr></thead><tbody>
          {Object.entries(observation.proposed).map(([field, value]) => <tr key={field}>
            <td><input type="checkbox" disabled={busy} checked={fields.includes(field)} aria-label={`Use incoming ${label(field)}`} onChange={e => { setFields(e.target.checked ? [...fields, field] : fields.filter(f => f !== field)); setConfirmed(false); }} /></td>
            <th>{label(field)}</th><td>{display(review.current[field])}</td><td>{display(value)}</td>
          </tr>)}
        </tbody></table></div>
        <label><input type="checkbox" aria-label="Confirm selected client values" checked={confirmed} disabled={busy || !fields.length} onChange={e => setConfirmed(e.target.checked)} /> Apply these values once and keep them controlled by Core.</label>
        <p><button type="button" disabled={busy || !confirmed || !fields.length} onClick={apply}>Apply selected values</button></p>
      </>}
    </section>
  </div>;
}
