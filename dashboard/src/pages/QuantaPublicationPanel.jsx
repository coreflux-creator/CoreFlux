import React, { useState } from 'react';
import { api } from '../lib/api';

export default function QuantaPublicationPanel({ people = [] }) {
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState([]);
  const [preview, setPreview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const choices = people.filter(p => `${p.person_number || ''} ${p.name} ${p.email_primary || ''}`.toLowerCase().includes(query.toLowerCase())).slice(0, 30);
  const prepare = async () => {
    setBusy(true); setError(''); setPreview(null);
    try { setPreview(await api.post('/api/quanta.php?action=publication_preview', { person_ids: selected }, { timeoutMs: 120000 })); }
    catch (e) { setError(e.message || 'Could not prepare people'); }
    finally { setBusy(false); }
  };
  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18 }}>Core people to Quanta</h2>
    <p>Review the name, email and phone that Core can supply. Existing Quanta and PurePayroll identifiers stay attached to their records.</p>
    <label>Find people <input type="search" value={query} onChange={e => setQuery(e.target.value)} /></label>
    <div style={{ maxHeight: 240, overflow: 'auto', margin: '12px 0' }}>
      {choices.map(p => <label key={p.id} style={{ display: 'flex', gap: 8, padding: 5 }}>
        <input type="checkbox" checked={selected.includes(Number(p.id))} disabled={busy || (!selected.includes(Number(p.id)) && selected.length >= 50)}
          onChange={e => { setSelected(prev => e.target.checked ? [...prev, Number(p.id)] : prev.filter(id => id !== Number(p.id))); setPreview(null); }} />
        {p.person_number || `P-${p.id}`} · {p.name} · {p.email_primary || 'No email'}
      </label>)}
      {!choices.length && <p>No matching people.</p>}
    </div>
    <button type="button" className="btn btn-secondary" disabled={busy || !selected.length} onClick={prepare}>
      {busy ? 'Preparing…' : `Preview ${selected.length} selected`}
    </button>
    {error && <p role="alert">{error}</p>}
    {preview && <>
      <p role="status">{preview.hold_reason}</p>
      <div style={{ overflowX: 'auto' }}><table className="table" style={{ width: '100%', textAlign: 'left' }}>
        <thead><tr><th>Person</th><th>Proposed change</th><th>Review</th></tr></thead>
        <tbody>{preview.rows.map(row => <tr key={row.person_id}>
          <td>{row.person_number} · {row.person_name || ''}</td>
          <td>{Object.entries(row.payload || {}).map(([name, value]) => <div key={name}>{({ full_name: 'Name', email: 'Email', phone: 'Phone', active: 'Active' })[name] || name}: {String(value)}</div>)}</td>
          <td>{row.message}{row.delivery && <div>Saved preview #{row.delivery.id} · {row.delivery.state}</div>}</td>
        </tr>)}</tbody>
      </table></div>
    </>}
  </section>;
}
