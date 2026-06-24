import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const TYPES = ['text', 'number', 'date', 'boolean', 'select', 'multiselect'];

export default function CustomFields() {
  const path = '/api/v1/placements/custom-field-definitions';
  const { data, loading, error, reload } = useApi(path);
  const fields = data?.definitions ?? [];

  const [form, setForm] = useState({
    field_key: '',
    field_label: '',
    field_type: 'text',
    required: false,
    order_index: 0,
  });
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState(null);

  const add = async (e) => {
    e.preventDefault();
    setAdding(true);
    setAddError(null);
    try {
      await api.post(path, form);
      setForm({ field_key: '', field_label: '', field_type: 'text', required: false, order_index: 0 });
      reload();
    } catch (e) {
      setAddError(e);
    } finally {
      setAdding(false);
    }
  };

  const del = async (id) => {
    if (!confirm('Soft-delete this custom field? Existing values are preserved.')) return;
    await api.delete(`${path}?id=${id}`);
    reload();
  };

  return (
    <section data-testid="placements-custom-fields-page">
      <h2>Placement Custom Fields</h2>
      <p style={{ color: '#666' }}>Tenant-scoped fields shown on placement detail, exports, and governed reports.</p>

      <form onSubmit={add} style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem', flexWrap: 'wrap' }} data-testid="placements-custom-fields-add-form">
        <input
          className="input"
          placeholder="field_key (snake_case)"
          value={form.field_key}
          onChange={(e) => setForm({ ...form, field_key: e.target.value })}
          data-testid="placements-custom-fields-key"
          required
        />
        <input
          className="input"
          placeholder="Label"
          value={form.field_label}
          onChange={(e) => setForm({ ...form, field_label: e.target.value })}
          data-testid="placements-custom-fields-label"
          required
        />
        <select
          className="input"
          value={form.field_type}
          onChange={(e) => setForm({ ...form, field_type: e.target.value })}
          data-testid="placements-custom-fields-type"
        >
          {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <input
          className="input"
          type="number"
          value={form.order_index}
          onChange={(e) => setForm({ ...form, order_index: Number(e.target.value || 0) })}
          data-testid="placements-custom-fields-order"
          style={{ width: 96 }}
          aria-label="Order"
        />
        <label data-testid="placements-custom-fields-required-label" style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
          <input
            type="checkbox"
            checked={form.required}
            onChange={(e) => setForm({ ...form, required: e.target.checked })}
            data-testid="placements-custom-fields-required"
          /> required
        </label>
        <button className="btn btn--primary" data-testid="placements-custom-fields-add-btn" disabled={adding}>
          {adding ? 'Adding...' : 'Add field'}
        </button>
      </form>
      {addError && <p className="error" data-testid="placements-custom-fields-add-error">Error: {addError.message}</p>}

      {loading && <p data-testid="placements-custom-fields-loading">Loading...</p>}
      {error && <p className="error" data-testid="placements-custom-fields-error">Error: {error.message}</p>}
      <table className="data-table" data-testid="placements-custom-fields-table" style={{ width: '100%' }}>
        <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>Order</th><th></th></tr></thead>
        <tbody>
          {fields.length === 0 && <tr><td colSpan={6} className="empty" data-testid="placements-custom-fields-empty">No custom fields defined.</td></tr>}
          {fields.map((f) => (
            <tr key={f.id} data-testid={`placements-custom-fields-row-${f.id}`}>
              <td><code>{f.field_key}</code></td>
              <td>{f.field_label}</td>
              <td>{f.field_type}</td>
              <td>{f.required ? 'Yes' : '-'}</td>
              <td>{f.order_index ?? 0}</td>
              <td>
                <button className="btn btn--ghost" onClick={() => del(f.id)} data-testid={`placements-custom-fields-delete-${f.id}`}>
                  Delete
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
