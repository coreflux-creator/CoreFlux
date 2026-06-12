import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const TYPES = ['text','number','date','boolean','select','multiselect'];

export default function CustomFields() {
  const path = '/api/v1/people/custom-field-definitions';
  const { data, loading, error, reload } = useApi(path);
  const fields = data?.definitions ?? [];

  const [form, setForm] = useState({ field_key: '', field_label: '', field_type: 'text', required: false, pii: false });
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState(null);

  const add = async (e) => {
    e.preventDefault();
    setAdding(true); setAddError(null);
    try {
      await api.post(path, form);
      setForm({ field_key: '', field_label: '', field_type: 'text', required: false, pii: false });
      reload();
    } catch (e) { setAddError(e); }
    finally     { setAdding(false); }
  };

  const del = async (id) => {
    if (!confirm('Soft-delete this custom field? (Existing values are preserved.)')) return;
    await api.delete(`${path}?id=${id}`);
    reload();
  };

  return (
    <section data-testid="custom-fields-page">
      <h2>Custom Fields</h2>
      <p style={{ color: '#666' }}>Tenant-scoped custom fields shown on every person.</p>

      <form onSubmit={add} style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem', flexWrap: 'wrap' }} data-testid="custom-fields-add-form">
        <input className="input" placeholder="field_key (snake_case)" value={form.field_key}
               onChange={e => setForm({ ...form, field_key: e.target.value })}
               data-testid="custom-fields-key" required />
        <input className="input" placeholder="Label" value={form.field_label}
               onChange={e => setForm({ ...form, field_label: e.target.value })}
               data-testid="custom-fields-label" required />
        <select className="input" value={form.field_type}
                onChange={e => setForm({ ...form, field_type: e.target.value })}
                data-testid="custom-fields-type">
          {TYPES.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
        <label data-testid="custom-fields-required-label" style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
          <input type="checkbox" checked={form.required}
                 onChange={e => setForm({ ...form, required: e.target.checked })}
                 data-testid="custom-fields-required" /> required
        </label>
        <label data-testid="custom-fields-pii-label" style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
          <input type="checkbox" checked={form.pii}
                 onChange={e => setForm({ ...form, pii: e.target.checked })}
                 data-testid="custom-fields-pii" /> PII
        </label>
        <button className="btn btn--primary" data-testid="custom-fields-add-btn" disabled={adding}>{adding ? '…' : 'Add field'}</button>
      </form>
      {addError && <p className="error" data-testid="custom-fields-add-error">Error: {addError.message}</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      <table className="data-table" data-testid="custom-fields-table" style={{ width: '100%' }}>
        <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>PII</th><th></th></tr></thead>
        <tbody>
          {fields.length === 0 && <tr><td colSpan={6} className="empty" data-testid="custom-fields-empty">No custom fields defined.</td></tr>}
          {fields.map(f => (
            <tr key={f.id} data-testid={`custom-fields-row-${f.id}`}>
              <td><code>{f.field_key}</code></td>
              <td>{f.field_label}</td>
              <td>{f.field_type}</td>
              <td>{f.required ? '✓' : '—'}</td>
              <td>{f.pii ? '✓' : '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => del(f.id)} data-testid={`custom-fields-delete-${f.id}`}>Delete</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
