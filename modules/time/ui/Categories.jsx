import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

export default function Categories() {
  const path = '/api/v1/time/categories';
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];
  const [form, setForm] = useState({ code: '', label: '', parent_bucket: 'billable', is_overtime: false });
  const [error2, setError2] = useState(null);

  const add = async (e) => {
    e.preventDefault(); setError2(null);
    try { await api.post(path, form); setForm({ code: '', label: '', parent_bucket: 'billable', is_overtime: false }); reload(); }
    catch (e) { setError2(e); }
  };
  const del = async (id) => { if (!confirm('Deactivate?')) return; await api.delete(`${path}?id=${id}`); reload(); };

  return (
    <section className="people-directory" data-testid="time-categories">
      <h2>Tenant custom time categories</h2>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Standard categories (billable, OT, PTO, etc.) always exist. Tenant-specific additions here roll up to one of four buckets.</p>

      <form onSubmit={add} style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }} data-testid="time-categories-add-form">
        <input className="input" placeholder="code (snake_case)" value={form.code} onChange={e => setForm({ ...form, code: e.target.value })} data-testid="time-categories-code" required />
        <input className="input" placeholder="Label" value={form.label} onChange={e => setForm({ ...form, label: e.target.value })} data-testid="time-categories-label" required />
        <select className="input" value={form.parent_bucket} onChange={e => setForm({ ...form, parent_bucket: e.target.value })} data-testid="time-categories-bucket">
          {['billable','nonbillable','pto','unpaid'].map(b => <option key={b} value={b}>{b}</option>)}
        </select>
        <label data-testid="time-categories-ot-label" style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-1)' }}>
          <input type="checkbox" checked={form.is_overtime} onChange={e => setForm({ ...form, is_overtime: e.target.checked })} data-testid="time-categories-ot" /> OT
        </label>
        <button className="btn btn--primary" data-testid="time-categories-add-btn">Add</button>
      </form>
      {error2 && <p className="error" data-testid="time-categories-add-error">Error: {error2.message}</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      <table className="data-table" data-testid="time-categories-table">
        <thead><tr><th>Code</th><th>Label</th><th>Bucket</th><th>OT</th><th>Active</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="time-categories-empty">No custom categories.</td></tr>}
          {rows.map(c => (
            <tr key={c.id} data-testid={`time-category-row-${c.id}`}>
              <td><code>{c.code}</code></td><td>{c.label}</td><td>{c.parent_bucket}</td>
              <td>{c.is_overtime ? '✓' : '—'}</td><td>{c.active ? '✓' : '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => del(c.id)} data-testid={`time-category-deactivate-${c.id}`}>Deactivate</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
