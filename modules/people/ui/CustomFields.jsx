import React, { useState } from 'react';
import { api, getPinnedTenantId, useApi } from '../../../dashboard/src/lib/api';

const TYPES = ['text','number','date','boolean','select','multiselect'];

export default function CustomFields({ entityType = 'people' }) {
  return <DefinitionsEditor key={`${getPinnedTenantId()}:${entityType}`} entityType={entityType} />;
}

function DefinitionsEditor({ entityType }) {
  const path = `/api/custom_field_definitions.php?entity_type=${encodeURIComponent(entityType)}`;
  const { data, loading, error, reload } = useApi(path);
  const fields = data?.fields ?? [];
  const canManage = data?.can_manage_definitions === true;
  const canManageSensitive = data?.can_manage_sensitive === true;

  const [form, setForm] = useState({ field_key: '', field_label: '', field_type: 'text', required: false, pii: false });
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState(null);
  const [optionsText, setOptionsText] = useState('');
  const [archiving, setArchiving] = useState(null);
  const [archiveError, setArchiveError] = useState(null);

  const add = async (e) => {
    e.preventDefault();
    if (!canManage || adding) return;
    setAdding(true); setAddError(null);
    try {
      const choices = ['select', 'multiselect'].includes(form.field_type);
      await api.post(path, { ...form, ...(choices ? { options: optionsText.split('\n').map(s => s.trim()).filter(Boolean) } : {}) });
      setForm({ field_key: '', field_label: '', field_type: 'text', required: false, pii: false });
      setOptionsText('');
      reload();
    } catch (e) { setAddError(e); }
    finally     { setAdding(false); }
  };

  const del = async (id) => {
    if (!canManage || archiving || !window.confirm('Archive this custom field? It will no longer appear on records. Existing values will be preserved.')) return;
    setArchiving(id); setArchiveError(null);
    try { await api.delete(`${path}&id=${id}`); await reload(); }
    catch (error) { setArchiveError(error); }
    finally { setArchiving(null); }
  };

  if (loading) return <p>Loading custom fields…</p>;
  if (error) return <p className="error" role="alert">{error.message}</p>;
  if (data?.entity_type !== entityType) return <p className="error" role="alert">The custom field directory changed. Reopen this page.</p>;

  return (
    <section data-testid="custom-fields-page">
      <h2>Custom fields — {entityType === 'companies' ? 'Clients and vendors' : entityType === 'placements' ? 'Placements' : 'People'}</h2>
      <p style={{ color: '#666' }}>These fields apply to records in this directory. Field keys stay fixed so linked imports keep their meaning.</p>

      {canManage && <form onSubmit={add} style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem', flexWrap: 'wrap' }} data-testid="custom-fields-add-form">
        <input className="input" aria-label="Field key" placeholder="Field key, e.g. project_code" value={form.field_key}
               onChange={e => setForm({ ...form, field_key: e.target.value })}
               data-testid="custom-fields-key" required />
        <input className="input" aria-label="Field label" placeholder="Label" value={form.field_label}
               onChange={e => setForm({ ...form, field_label: e.target.value })}
               data-testid="custom-fields-label" required />
        <select className="input" aria-label="Field type" value={form.field_type}
                onChange={e => setForm({ ...form, field_type: e.target.value })}
                data-testid="custom-fields-type">
          {TYPES.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
        {['select', 'multiselect'].includes(form.field_type) && (
          <label>Choices, one per line
            <textarea className="input" value={optionsText} onChange={e => setOptionsText(e.target.value)} required data-testid="custom-fields-options" />
          </label>
        )}
        <label data-testid="custom-fields-required-label" style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
          <input type="checkbox" checked={form.required}
                 onChange={e => setForm({ ...form, required: e.target.checked })}
                 data-testid="custom-fields-required" /> required
        </label>
        {canManageSensitive && <label data-testid="custom-fields-pii-label" style={{ display: 'flex', alignItems: 'center', gap: '0.25rem' }}>
          <input type="checkbox" checked={form.pii}
                 onChange={e => setForm({ ...form, pii: e.target.checked })}
                 data-testid="custom-fields-pii" /> Sensitive
        </label>}
        <button className="btn btn--primary" data-testid="custom-fields-add-btn" disabled={adding}>{adding ? '…' : 'Add field'}</button>
      </form>}
      {addError && <p className="error" data-testid="custom-fields-add-error">Error: {addError.message}</p>}
      {archiveError && <p className="error" role="alert">{archiveError.message}</p>}
      {!canManage && <p>You can view these definitions. Changes require access from your administrator.</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      <table className="data-table" data-testid="custom-fields-table" style={{ width: '100%' }}>
        <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>Sensitive</th><th></th></tr></thead>
        <tbody>
          {fields.length === 0 && <tr><td colSpan={6} className="empty" data-testid="custom-fields-empty">No custom fields defined.</td></tr>}
          {fields.map(f => (
            <tr key={f.id} data-testid={`custom-fields-row-${f.id}`}>
              <td><code>{f.field_key}</code></td>
              <td>{f.field_label}</td>
              <td>{f.field_type}</td>
              <td>{Number(f.required) === 1 ? '✓' : '—'}</td>
              <td>{Number(f.pii) === 1 ? '✓' : '—'}</td>
              <td>{canManage && (!Number(f.pii) || canManageSensitive) && <button className="btn btn--ghost" disabled={archiving !== null} onClick={() => del(f.id)} data-testid={`custom-fields-delete-${f.id}`}>{archiving === f.id ? 'Archiving…' : 'Archive'}</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
