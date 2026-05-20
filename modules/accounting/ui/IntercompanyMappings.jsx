import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Intercompany mappings settings — one screen lists every (from_entity →
 * to_entity) directional mapping with its due-from / due-to account codes.
 *
 * Per-pair setup is required before split posts can resolve the IC offset
 * accounts. Ad-hoc override is allowed at post time via `ic_override`.
 */
export default function IntercompanyMappings() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/intercompany.php');
  const entitiesApi = useApi('/modules/accounting/api/entities.php?scope=hierarchy');
  const accountsApi = useApi('/modules/accounting/api/accounts.php');
  const [form, setForm] = useState({ from_entity_id: '', to_entity_id: '', due_from_account_code: '', due_to_account_code: '', notes: '' });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const entities = entitiesApi.data?.rows || entitiesApi.data?.entities || [];
  const accounts = accountsApi.data?.rows || accountsApi.data?.accounts || [];

  const save = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/modules/accounting/api/intercompany.php', {
        from_entity_id: Number(form.from_entity_id),
        to_entity_id: Number(form.to_entity_id),
        due_from_account_code: form.due_from_account_code,
        due_to_account_code: form.due_to_account_code,
        notes: form.notes || null,
      });
      setForm({ from_entity_id: '', to_entity_id: '', due_from_account_code: '', due_to_account_code: '', notes: '' });
      reload();
    } catch (e2) { setErr(e2.message); }
    finally { setBusy(false); }
  };

  const remove = async (id) => {
    if (!window.confirm('Deactivate this mapping?')) return;
    try { await api.delete(`/modules/accounting/api/intercompany.php?id=${id}`); reload(); }
    catch (e) { alert(e.message); }
  };

  return (
    <section data-testid="accounting-ic-mappings">
      <h2 style={{ margin: '0 0 8px' }}>Intercompany mappings</h2>
      <p style={{ fontSize: 13, color: '#666', maxWidth: 720, margin: '0 0 16px' }}>
        For each (from_entity → to_entity) pair, pre-configure the "Due from" asset (on the source entity's books) and the "Due to" liability (on the target entity's books). These accounts get auto-booked whenever a transaction is split across entities. Override ad-hoc at post time if needed.
      </p>

      <form onSubmit={save} style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr) auto', gap: 8, alignItems: 'end', padding: 12, background: '#f9fafb', borderRadius: 6, marginBottom: 16 }}>
        <label style={{ fontSize: 12 }}>From entity
          <select className="input" value={form.from_entity_id} onChange={e => setForm({ ...form, from_entity_id: e.target.value })} required data-testid="accounting-ic-from">
            <option value="">— select —</option>
            {entities.map(en => <option key={en.id} value={en.id}>{(en.legal_name || en.code) + (en.tenant_name ? `  ·  ${en.tenant_name}` : '')}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>To entity
          <select className="input" value={form.to_entity_id} onChange={e => setForm({ ...form, to_entity_id: e.target.value })} required data-testid="accounting-ic-to">
            <option value="">— select —</option>
            {entities.map(en => <option key={en.id} value={en.id}>{(en.legal_name || en.code) + (en.tenant_name ? `  ·  ${en.tenant_name}` : '')}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Due-from (asset on source)
          <select className="input" value={form.due_from_account_code} onChange={e => setForm({ ...form, due_from_account_code: e.target.value })} required data-testid="accounting-ic-due-from">
            <option value="">— select —</option>
            {accounts.filter(a => a.account_type === 'asset').map(a => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Due-to (liability on target)
          <select className="input" value={form.due_to_account_code} onChange={e => setForm({ ...form, due_to_account_code: e.target.value })} required data-testid="accounting-ic-due-to">
            <option value="">— select —</option>
            {accounts.filter(a => a.account_type === 'liability').map(a => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Notes
          <input className="input" value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} data-testid="accounting-ic-notes" />
        </label>
        <button type="submit" className="btn btn--primary" disabled={busy} data-testid="accounting-ic-save">{busy ? 'Saving…' : 'Save pair'}</button>
      </form>
      {err && <p className="error">{err}</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      <table className="data-table" data-testid="accounting-ic-table">
        <thead><tr><th>From</th><th>To</th><th>Due-from</th><th>Due-to</th><th>Notes</th><th>Status</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={7} style={{color:'#999'}} data-testid="accounting-ic-empty">No mappings yet — set up one pair per direction (A→B and B→A are separate).</td></tr>
          )}
          {(data?.rows || []).map(m => (
            <tr key={m.id} data-testid={`accounting-ic-row-${m.id}`}>
              <td>{m.from_entity_name || m.from_entity_id}</td>
              <td>{m.to_entity_name || m.to_entity_id}</td>
              <td><code>{m.due_from_account_code}</code></td>
              <td><code>{m.due_to_account_code}</code></td>
              <td>{m.notes}</td>
              <td><span className="badge">{Number(m.active) === 1 ? 'active' : 'inactive'}</span></td>
              <td>{Number(m.active) === 1 && <button className="btn btn--ghost" onClick={() => remove(m.id)} data-testid={`accounting-ic-remove-${m.id}`}>Deactivate</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
