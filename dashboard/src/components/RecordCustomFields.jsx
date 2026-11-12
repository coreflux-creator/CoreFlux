import React, { useState } from 'react';
import { api, getPinnedTenantId, useApi } from '../lib/api';

/** Remount drafts when the record, directory type or active company changes. */
export default function RecordCustomFields(props) {
  return <CustomFieldEditor key={`${getPinnedTenantId()}:${props.entityType}:${props.recordId}`} {...props} />;
}

function CustomFieldEditor({ entityType, recordId, definitionsUrl }) {
  const query = `entity_type=${encodeURIComponent(entityType)}`;
  const definitions = useApi(`/api/custom_field_definitions.php?${query}`);
  const valuesPath = `/api/custom_field_values.php?${query}&record_id=${encodeURIComponent(recordId)}`;
  const values = useApi(valuesPath);
  const layout = useApi(`/api/custom_field_layouts.php?${query}&surface=detail`);
  const [draft, setDraft] = useState({});
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState(null);
  const [saved, setSaved] = useState(false);
  const defs = (definitions.data?.definitions ?? []).filter(d => !d.sensitive_hidden && d.field_access?.visible !== false);
  const order = layout.data?.layout?.layout?.field_order ?? [];
  const ordered = [...defs].sort((a, b) => {
    const ai = order.indexOf(a.field_key), bi = order.indexOf(b.field_key);
    if (ai !== -1 || bi !== -1) return (ai === -1 ? 99999 : ai) - (bi === -1 ? 99999 : bi);
    return (a.order_index ?? 0) - (b.order_index ?? 0);
  });
  const current = Object.fromEntries((values.data?.values ?? []).map(v => [v.field_key, v.value]));
  const editable = d => values.data?.can_manage_values === true && d.field_access?.editable === true;

  if (definitions.loading || values.loading || layout.loading) return <p data-testid="tab-custom-loading">Loading custom fields…</p>;
  const readError = definitions.error || values.error;
  if (readError) return <p className="error" role="alert" data-testid="tab-custom-error">{readError.message}</p>;
  if (definitions.data?.entity_type !== entityType || values.data?.entity_type !== entityType
    || String(values.data?.record_id) !== String(recordId) || !definitions.data?.owner_tenant_id
    || definitions.data.owner_tenant_id !== values.data?.owner_tenant_id) {
    return <p className="error" role="alert" data-testid="tab-custom-error">The custom field directory changed. Reopen this record to continue.</p>;
  }

  const change = (def, value) => {
    if (saving || !editable(def)) return;
    setDraft(previous => ({ ...previous, [def.field_key]: value }));
    setSaved(false); setSaveError(null);
  };
  const save = async () => {
    if (saving || !Object.keys(draft).length) return;
    const payload = {}, expected = {};
    for (const [key, value] of Object.entries(draft)) {
      if (!defs.some(d => d.field_key === key && editable(d))) {
        setSaveError(new Error('Your access to a changed field has changed. Reload this record.')); return;
      }
      payload[key] = value === '' ? null : value;
      expected[key] = current[key] ?? null;
    }
    setSaving(true); setSaveError(null); setSaved(false);
    try {
      await api.post(valuesPath, { values: payload, expected_values: expected, expected_owner_tenant_id: values.data.owner_tenant_id });
      setDraft({});
      await values.reload();
      setSaved(true);
    } catch (error) { setSaveError(error); }
    finally { setSaving(false); }
  };
  const reload = async () => {
    if (Object.keys(draft).length && !window.confirm('Discard your unsaved custom field changes and load the saved values?')) return;
    setDraft({}); setSaveError(null); setSaved(false);
    await Promise.all([definitions.reload(), values.reload(), layout.reload()]);
  };

  return <section data-testid="tab-custom">
    <header style={{ display: 'flex', justifyContent: 'space-between', gap: 16, marginBottom: 12 }}>
      <div>
        <h3 style={{ margin: 0 }}>Custom fields</h3>
        {definitions.data?.can_manage_definitions === true && definitionsUrl && <p style={{ margin: '4px 0' }}><a href={definitionsUrl}>Manage custom fields</a></p>}
      </div>
      {values.data?.can_manage_values === true && <button className="btn btn--primary" data-testid="custom-values-save" disabled={saving || !Object.keys(draft).length} onClick={save}>{saving ? 'Saving…' : 'Save'}</button>}
    </header>
    {!defs.length && <p data-testid="custom-values-empty">No custom fields are available for this record.</p>}
    {values.data?.can_manage_values !== true && <p data-testid="custom-values-readonly">You can view these fields. Editing requires access from your administrator.</p>}
    {layout.error && <p>Saved field order is unavailable. Fields are shown in their default order.</p>}
    {saveError && <div role="alert" data-testid="custom-values-error"><p className="error">{saveError.message}</p><button className="btn" onClick={reload} disabled={saving}>Reload saved values</button></div>}
    {saved && !saving && <p role="status" data-testid="custom-values-saved">Saved.</p>}
    <fieldset disabled={saving} style={{ border: 0, padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 12 }}>
      {ordered.map(def => <label key={def.id} data-testid={`custom-values-field-${def.field_key}`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <span>{def.field_label}{Number(def.required) === 1 ? ' *' : ''}{Number(def.pii) === 1 ? ' 🔒' : ''}</span>
        <CustomFieldInput def={def} value={Object.hasOwn(draft, def.field_key) ? draft[def.field_key] : current[def.field_key] ?? null} disabled={saving || !editable(def)} onChange={value => change(def, value)} />
      </label>)}
    </fieldset>
  </section>;
}

export function CustomFieldInput({ def, value, onChange, disabled = false }) {
  const props = { className: 'input', 'data-testid': `custom-values-input-${def.field_key}`, disabled };
  if (def.field_type === 'boolean') return <select {...props} value={value == null || value === '' ? '' : String(value)} onChange={e => onChange(e.target.value === '' ? null : e.target.value === 'true')}>
    <option value="">— Unset —</option><option value="true">Yes</option><option value="false">No</option>
  </select>;
  if (def.field_type === 'select' || def.field_type === 'multiselect') {
    let options = [];
    try { options = Array.isArray(def.options) ? def.options : JSON.parse(def.options_json || '[]'); } catch { /* Invalid definitions remain subject to server validation. */ }
    const multiple = def.field_type === 'multiselect';
    return <select {...props} multiple={multiple} value={multiple ? (Array.isArray(value) ? value : []) : value ?? ''} onChange={e => onChange(multiple ? Array.from(e.target.selectedOptions, option => option.value) : e.target.value)}>
      {!multiple && <option value="">— Unset —</option>}
      {options.map(option => <option key={option} value={option}>{option}</option>)}
    </select>;
  }
  return <input {...props} type={def.field_type === 'number' ? 'number' : def.field_type === 'date' ? 'date' : 'text'} step={def.field_type === 'number' ? 'any' : undefined} value={value ?? ''} onChange={e => onChange(e.target.value)} />;
}
