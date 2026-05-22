import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * Tenant Integration Field Map admin — Slice 3 scaffolding.
 *
 * Configures per-tenant overrides for which external (source-side)
 * fields populate which CoreFlux internal columns, per (integration,
 * entity_type) pair. Replaces the hard-coded `jobdivaPluckField()`
 * candidate lists in the syncer once Slice 4 wires this registry in.
 *
 * Current state: lets a tenant_admin list / add / delete mappings.
 * The syncer does NOT yet read from this table — that's the next slice.
 * A banner makes this explicit so operators don't expect immediate
 * behaviour change.
 *
 * RBAC: integrations.field_map.manage (enforced server-side; this UI
 * is hidden from non-admins via AdminModule's nav gating elsewhere).
 */
const ENTITY_TYPES = ['placement', 'person', 'company', 'contact'];
const INTEGRATIONS = ['jobdiva', 'quickbooks', 'zoho_books', 'airtable', 'bullhorn'];

export default function IntegrationFieldMapAdmin() {
  const [integration, setIntegration] = useState('jobdiva');
  const [entityType, setEntityType]   = useState('placement');
  const [data, setData]               = useState(null);
  const [loading, setLoading]         = useState(false);
  const [error, setError]             = useState(null);
  const [form, setForm] = useState({ external_field: '', internal_field: '', transform: 'none', notes: '' });
  const [saving, setSaving] = useState(false);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const params = new URLSearchParams({ integration, entity_type: entityType });
      const r = await api(`/api/admin/integrations/field_map.php?${params}`);
      setData(r);
    } catch (e) {
      setError(e.message || 'Failed to load');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { reload(); /* eslint-disable-next-line */ }, [integration, entityType]);

  const handleSave = async (e) => {
    e.preventDefault();
    if (!form.external_field || !form.internal_field) return;
    setSaving(true); setError(null);
    try {
      await api('/api/admin/integrations/field_map.php', {
        method: 'POST',
        body: { integration, entity_type: entityType, ...form },
      });
      setForm({ external_field: '', internal_field: '', transform: 'none', notes: '' });
      await reload();
    } catch (e) {
      setError(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Remove this mapping? The syncer will revert to its built-in defaults.')) return;
    try {
      await api(`/api/admin/integrations/field_map.php?id=${id}`, { method: 'DELETE' });
      await reload();
    } catch (e) {
      setError(e.message || 'Delete failed');
    }
  };

  const allowedInternal = data?.allowed_internal_fields?.[entityType] || [];
  const transforms = data?.transforms || ['none'];
  const rows = data?.rows || [];

  return (
    <section data-testid="integration-field-map-admin" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: '1rem' }}>
        <h2 style={{ margin: 0 }}>Integration field map</h2>
        <p style={{ color: '#64748b', fontSize: 13, marginTop: 4 }}>
          Override the syncer's field choices for this tenant. Picks the external (source-side) field
          name and writes it into the chosen CoreFlux column.
        </p>
        <div
          data-testid="field-map-scaffolding-banner"
          style={{
            marginTop: '0.75rem',
            padding: '0.6rem 0.9rem',
            background: '#fef3c7', border: '1px solid #fde68a', borderRadius: 8,
            color: '#92400e', fontSize: 13,
          }}
        >
          <strong>Scaffolding mode.</strong>{' '}
          The mappings you save here are persisted but the syncer doesn't consult this registry yet.
          Wiring lands in the next slice — until then, the syncer uses its built-in field candidates.
        </div>
      </header>

      <div data-testid="field-map-scope" style={{ display: 'flex', gap: '0.75rem', marginBottom: '1rem' }}>
        <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
          Integration
          <select
            value={integration}
            onChange={(e) => setIntegration(e.target.value)}
            data-testid="field-map-integration-select"
            className="input"
          >
            {INTEGRATIONS.map(i => <option key={i} value={i}>{i}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
          Entity type
          <select
            value={entityType}
            onChange={(e) => setEntityType(e.target.value)}
            data-testid="field-map-entity-select"
            className="input"
          >
            {ENTITY_TYPES.map(e2 => <option key={e2} value={e2}>{e2}</option>)}
          </select>
        </label>
      </div>

      {error && <p className="error" data-testid="field-map-error">{error}</p>}
      {loading && <p data-testid="field-map-loading">Loading…</p>}

      {!loading && (
        <>
          <table className="data-table" data-testid="field-map-table" style={{ width: '100%', marginBottom: '1.5rem' }}>
            <thead>
              <tr style={{ fontSize: 11, color: '#64748b', textAlign: 'left' }}>
                <th style={{ padding: '6px 8px' }}>External field</th>
                <th style={{ padding: '6px 8px' }}>→ Internal field</th>
                <th style={{ padding: '6px 8px' }}>Transform</th>
                <th style={{ padding: '6px 8px' }}>Enabled</th>
                <th style={{ padding: '6px 8px' }}>Notes</th>
                <th style={{ padding: '6px 8px' }}></th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={6} data-testid="field-map-empty" style={{ padding: 12, color: '#64748b' }}>
                  No overrides configured — syncer uses built-in defaults.
                </td></tr>
              )}
              {rows.map(r => (
                <tr key={r.id} data-testid={`field-map-row-${r.id}`}>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace' }}>{r.external_field}</td>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace' }}>{r.internal_field}</td>
                  <td style={{ padding: '8px', fontSize: 12 }}>{r.transform}</td>
                  <td style={{ padding: '8px' }}>{r.enabled ? '✓' : '✗'}</td>
                  <td style={{ padding: '8px', fontSize: 12, color: '#64748b' }}>{r.notes || '—'}</td>
                  <td style={{ padding: '8px' }}>
                    <button
                      className="btn btn--ghost"
                      onClick={() => handleDelete(r.id)}
                      data-testid={`field-map-delete-${r.id}`}
                      style={{ fontSize: 12 }}
                    >Remove</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <form
            onSubmit={handleSave}
            data-testid="field-map-add-form"
            style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end', flexWrap: 'wrap' }}
          >
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
              External field
              <input
                type="text"
                value={form.external_field}
                onChange={(e) => setForm(f => ({ ...f, external_field: e.target.value }))}
                placeholder="e.g. jobTitle"
                data-testid="field-map-external-input"
                className="input"
              />
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
              Internal field
              <select
                value={form.internal_field}
                onChange={(e) => setForm(f => ({ ...f, internal_field: e.target.value }))}
                data-testid="field-map-internal-select"
                className="input"
              >
                <option value="">— select —</option>
                {allowedInternal.map(f => <option key={f} value={f}>{f}</option>)}
              </select>
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
              Transform
              <select
                value={form.transform}
                onChange={(e) => setForm(f => ({ ...f, transform: e.target.value }))}
                data-testid="field-map-transform-select"
                className="input"
              >
                {transforms.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569', flex: 1, minWidth: 180 }}>
              Notes (optional)
              <input
                type="text"
                value={form.notes}
                onChange={(e) => setForm(f => ({ ...f, notes: e.target.value }))}
                data-testid="field-map-notes-input"
                className="input"
              />
            </label>
            <button
              type="submit"
              className="btn btn--primary"
              disabled={saving || !form.external_field || !form.internal_field}
              data-testid="field-map-add-submit"
            >
              {saving ? 'Saving…' : '+ Add mapping'}
            </button>
          </form>
        </>
      )}
    </section>
  );
}
