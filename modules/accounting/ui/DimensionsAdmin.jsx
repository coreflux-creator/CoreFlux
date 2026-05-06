import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * DimensionsAdmin — tenant-configurable accounting dimensions
 * (Sprint 2 / B2). The platform ships zero hardcoded dimensions; each
 * tenant declares what they care about (placement / shift / fund / class /
 * worker_class …) and per-account requirement rules.
 *
 * Endpoints:
 *   GET    /modules/accounting/api/dimensions.php
 *   POST   /modules/accounting/api/dimensions.php          (upsert)
 *   DELETE /modules/accounting/api/dimensions.php?id=N    (deactivate)
 *   GET    /modules/accounting/api/dimensions.php?action=values&id=N
 *   POST   /modules/accounting/api/dimensions.php?action=add_value
 */
export default function DimensionsAdmin() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/dimensions.php');
  const dims = data?.dimensions ?? [];
  const [createOpen, setCreateOpen] = useState(false);
  const [activeDim, setActiveDim]   = useState(null);
  const [busy, setBusy] = useState(false);
  const [actErr, setActErr] = useState(null);

  const [draft, setDraft] = useState({
    dim_key: '', label: '', data_type: 'text',
    required_default: false, sort_order: 0,
  });

  const submitCreate = async (e) => {
    e?.preventDefault?.();
    setBusy(true); setActErr(null);
    try {
      await api.post('/modules/accounting/api/dimensions.php', draft);
      setCreateOpen(false);
      setDraft({ dim_key: '', label: '', data_type: 'text', required_default: false, sort_order: 0 });
      await reload();
    } catch (e) { setActErr(e); } finally { setBusy(false); }
  };

  const deactivate = async (id) => {
    if (!confirm('Deactivate this dimension? Existing JE history is preserved; new postings will skip it.')) return;
    setBusy(true); setActErr(null);
    try {
      await api.delete(`/modules/accounting/api/dimensions.php?id=${id}`);
      await reload();
    } catch (e) { setActErr(e); } finally { setBusy(false); }
  };

  return (
    <section data-testid="accounting-dimensions">
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Dimensions</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
            Tenant-configurable analytical tags posted alongside every JE line. Define keys (e.g. <code>placement</code>, <code>fund</code>, <code>class</code>) and which accounts must / cannot carry them.
          </p>
        </div>
        <button className="btn btn--primary" data-testid="dimensions-new" onClick={() => setCreateOpen(true)}>+ New dimension</button>
      </header>

      {loading && <p>Loading…</p>}
      {error   && <p className="error" data-testid="dimensions-error">Error: {error.message}</p>}
      {actErr  && <p className="error" data-testid="dimensions-action-error">Action failed: {actErr.message}</p>}

      <table className="data-table" style={{ width: '100%' }}>
        <thead>
          <tr><th>Key</th><th>Label</th><th>Type</th><th>Required default</th><th>Sort</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          {dims.length === 0 && !loading && (
            <tr><td colSpan={7} className="empty" data-testid="dimensions-empty">
              No dimensions yet. Click "New dimension" to register your first analytical tag.
            </td></tr>
          )}
          {dims.map(d => (
            <tr key={d.id} data-testid={`dimensions-row-${d.id}`}>
              <td style={{ fontFamily: 'monospace' }}>{d.dim_key}</td>
              <td>{d.label}</td>
              <td>{d.data_type}{d.reference_table ? ` → ${d.reference_table}` : ''}</td>
              <td>{d.required_default ? <span className="badge">required</span> : 'optional'}</td>
              <td>{d.sort_order}</td>
              <td>{d.active ? <span className="badge badge--ok">active</span> : <span className="badge">inactive</span>}</td>
              <td style={{ display: 'flex', gap: 6 }}>
                {d.data_type === 'enum' && (
                  <button className="btn btn--ghost" style={{ fontSize: 12 }}
                          data-testid={`dimensions-values-${d.id}`}
                          onClick={() => setActiveDim(d)}>Values</button>
                )}
                {!!d.active && (
                  <button className="btn btn--ghost" style={{ fontSize: 12, color: '#dc2626' }}
                          data-testid={`dimensions-deactivate-${d.id}`}
                          disabled={busy}
                          onClick={() => deactivate(d.id)}>Deactivate</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {createOpen && (
        <div data-testid="dimensions-create-modal" style={modalBackdrop}>
          <div style={modalCard}>
            <h3 style={{ marginTop: 0 }}>New dimension</h3>
            <form onSubmit={submitCreate} style={{ display: 'grid', gap: 10 }}>
              <label>Key (lowercase a-z, 0-9, _)
                <input className="input" data-testid="dimensions-create-key" value={draft.dim_key} required
                       onChange={e => setDraft({ ...draft, dim_key: e.target.value })} />
              </label>
              <label>Label
                <input className="input" data-testid="dimensions-create-label" value={draft.label} required
                       onChange={e => setDraft({ ...draft, label: e.target.value })} />
              </label>
              <label>Type
                <select className="input" data-testid="dimensions-create-type" value={draft.data_type}
                        onChange={e => setDraft({ ...draft, data_type: e.target.value })}>
                  <option value="text">text</option>
                  <option value="enum">enum (whitelist values below after create)</option>
                  <option value="number">number</option>
                  <option value="reference">reference (external table id)</option>
                </select>
              </label>
              <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <input type="checkbox" data-testid="dimensions-create-required"
                       checked={draft.required_default}
                       onChange={e => setDraft({ ...draft, required_default: e.target.checked })} />
                Required by default on every account
              </label>
              <label>Sort order
                <input className="input" type="number" value={draft.sort_order}
                       onChange={e => setDraft({ ...draft, sort_order: Number(e.target.value) })} />
              </label>
              <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
                <button type="submit" className="btn btn--primary"
                        data-testid="dimensions-create-submit"
                        disabled={busy || !draft.dim_key.trim() || !draft.label.trim()}>
                  {busy ? 'Saving…' : 'Create'}
                </button>
                <button type="button" className="btn btn--ghost" onClick={() => setCreateOpen(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {activeDim && (
        <DimensionValuesPanel dim={activeDim} onClose={() => setActiveDim(null)} />
      )}
    </section>
  );
}

function DimensionValuesPanel({ dim, onClose }) {
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/dimensions.php?action=values&id=${dim.id}`);
  const values = data?.values ?? [];
  const [draft, setDraft] = useState({ value_code: '', value_label: '' });
  const [busy, setBusy] = useState(false);
  const [err2, setErr2] = useState(null);

  const add = async (e) => {
    e?.preventDefault?.();
    setBusy(true); setErr2(null);
    try {
      await api.post('/modules/accounting/api/dimensions.php?action=add_value', {
        dimension_id: dim.id,
        value_code:   draft.value_code,
        value_label:  draft.value_label,
      });
      setDraft({ value_code: '', value_label: '' });
      await reload();
    } catch (e) { setErr2(e); } finally { setBusy(false); }
  };

  return (
    <div data-testid="dimensions-values-panel" style={modalBackdrop}>
      <div style={modalCard}>
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h3 style={{ margin: 0 }}>Whitelist values for <code>{dim.dim_key}</code></h3>
          <button className="btn btn--ghost" onClick={onClose}>Close</button>
        </header>

        <form onSubmit={add} style={{ display: 'flex', gap: 8, marginTop: 12 }}>
          <input className="input" data-testid="dimensions-values-code" placeholder="code (e.g. NY01)"
                 value={draft.value_code} required
                 onChange={e => setDraft({ ...draft, value_code: e.target.value })} />
          <input className="input" data-testid="dimensions-values-label" placeholder="label" style={{ flex: 1 }}
                 value={draft.value_label} required
                 onChange={e => setDraft({ ...draft, value_label: e.target.value })} />
          <button type="submit" className="btn btn--primary"
                  data-testid="dimensions-values-add"
                  disabled={busy || !draft.value_code.trim() || !draft.value_label.trim()}>
            {busy ? '…' : 'Add'}
          </button>
        </form>

        {err2 && <p className="error">Error: {err2.message}</p>}
        {loading && <p>Loading…</p>}
        {error   && <p className="error">Error: {error.message}</p>}

        <table className="data-table" style={{ width: '100%', marginTop: 12 }}>
          <thead><tr><th>Code</th><th>Label</th><th>Status</th></tr></thead>
          <tbody>
            {values.length === 0 && !loading && (
              <tr><td colSpan={3} className="empty">No values yet — add the first one above.</td></tr>
            )}
            {values.map(v => (
              <tr key={v.id} data-testid={`dimensions-value-row-${v.id}`}>
                <td style={{ fontFamily: 'monospace' }}>{v.value_code}</td>
                <td>{v.value_label}</td>
                <td>{v.active ? 'active' : 'inactive'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

const modalBackdrop = {
  position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)',
  display: 'flex', alignItems: 'center', justifyContent: 'center',
  zIndex: 1000, padding: 16,
};
const modalCard = {
  background: '#fff', borderRadius: 12, padding: 24,
  width: 'min(560px, 100%)', maxHeight: '90vh', overflow: 'auto',
  boxShadow: '0 25px 50px -12px rgba(0,0,0,0.25)',
};
