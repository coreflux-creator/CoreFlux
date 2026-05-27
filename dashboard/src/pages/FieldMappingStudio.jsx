import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';

/**
 * FieldMappingStudio — Phase 3 of the field-mapping rebuild.
 *
 * Left pane:  payload tree from /api/admin/integrations/payload_fields.php
 *             — every JSON path the indexer has seen for the selected
 *             (integration, entity_type), with sample values, ranked
 *             by occurrence_count.
 * Right pane: writable-targets dropdown from
 *             /api/admin/integrations/writable_targets.php — every
 *             column tenants can map external fields into, across
 *             every module + custom_field_values.
 * Bottom:     existing mappings table (read from the legacy field_map
 *             endpoint, deleted/disabled in-place).
 *
 * Save handler posts the new generalised shape (source_path +
 * target_module + target_table + target_column + linked_entity) to
 * the existing /api/admin/integrations/field_map.php upsert path —
 * Phase 2 taught it to accept the new fields.
 *
 * RBAC: tenant_admin.integrations (hidden in nav for non-admins).
 */

const LINKED_ENTITY_LABELS = {
  self:                   'self (the entity being upserted)',
  person:                 'person (linked talent)',
  end_client_company:     'end-client company',
  vendor_company:         'vendor company',
  placement_rates:        'placement_rates (sibling row)',
  placement_corp_details: 'placement_corp_details (sibling row)',
};

export default function FieldMappingStudio() {
  const [sources, setSources]         = useState([]);
  const [integration, setIntegration] = useState('jobdiva');
  const [entityType, setEntityType]   = useState('placement');

  const [paths, setPaths]             = useState([]);
  const [pathFilter, setPathFilter]   = useState('');
  const [selectedPath, setSelectedPath] = useState(null);

  const [targets, setTargets]         = useState([]);
  const [targetFilter, setTargetFilter] = useState('');
  const [selectedTarget, setSelectedTarget] = useState(null);

  const [linkedEntity, setLinkedEntity] = useState('self');
  const [transform, setTransform]       = useState('none');
  const [customFieldCode, setCustomFieldCode] = useState('');

  const [mappings, setMappings]       = useState([]);
  const [loading, setLoading]         = useState(false);
  const [saving, setSaving]           = useState(false);
  const [error, setError]             = useState(null);
  const [flash, setFlash]             = useState(null);

  // -- Load discovery + existing mappings ---------------------------------
  useEffect(() => {
    (async () => {
      try {
        const r = await api.get('/api/admin/integrations/payload_fields.php');
        setSources(r.sources || []);
      } catch (e) { setError(e.message); }
    })();
  }, []);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const [pathsRes, targetsRes, mapsRes] = await Promise.all([
        api.get(`/api/admin/integrations/payload_fields.php?integration=${integration}&entity_type=${entityType}`),
        api.get(`/api/admin/integrations/writable_targets.php`),
        api.get(`/api/admin/integrations/field_map.php?integration=${integration}&entity_type=${entityType}`),
      ]);
      setPaths(pathsRes.paths || []);
      setTargets(targetsRes.targets || []);
      setMappings(mapsRes.rows || mapsRes.mappings || []);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  };
  useEffect(() => { reload(); /* eslint-disable-next-line */ }, [integration, entityType]);

  // -- Derived ------------------------------------------------------------
  const filteredPaths = useMemo(() => {
    if (!pathFilter) return paths;
    const q = pathFilter.toLowerCase();
    return paths.filter(p => p.source_path.toLowerCase().includes(q)
                          || (p.sample_value || '').toLowerCase().includes(q));
  }, [paths, pathFilter]);

  const filteredTargets = useMemo(() => {
    if (!targetFilter) return targets;
    const q = targetFilter.toLowerCase();
    return targets.filter(t =>
      t.target_module.toLowerCase().includes(q)
      || t.target_table.toLowerCase().includes(q)
      || t.target_column.toLowerCase().includes(q)
      || (t.description || '').toLowerCase().includes(q));
  }, [targets, targetFilter]);

  // When a target is selected, pre-fill linked_entity from default.
  useEffect(() => {
    if (selectedTarget?.default_linked_entity) {
      setLinkedEntity(selectedTarget.default_linked_entity);
    }
  }, [selectedTarget]);

  const canSave = selectedPath && selectedTarget
                  && (selectedTarget.target_column !== '*' || customFieldCode);

  // -- Save / delete ------------------------------------------------------
  const handleSave = async () => {
    if (!canSave) return;
    setSaving(true); setError(null); setFlash(null);
    try {
      const body = {
        integration, entity_type: entityType,
        source_path:   selectedPath.source_path,
        target_module: selectedTarget.target_module,
        target_table:  selectedTarget.target_table,
        target_column: selectedTarget.target_column === '*' ? customFieldCode : selectedTarget.target_column,
        linked_entity: linkedEntity,
        transform,
        enabled: true,
      };
      await api.post('/api/admin/integrations/field_map.php', body);
      setFlash({ kind: 'success', msg: `Mapped ${body.source_path} → ${body.target_table}.${body.target_column} (${body.linked_entity})` });
      setSelectedPath(null); setSelectedTarget(null); setCustomFieldCode('');
      await reload();
    } catch (e) { setError(e.message || 'Save failed'); }
    finally { setSaving(false); }
  };

  const handleDelete = async (id, label) => {
    if (!window.confirm(`Remove mapping "${label}"? Syncer reverts to built-in defaults for that field.`)) return;
    setError(null); setFlash(null);
    try {
      await api.delete(`/api/admin/integrations/field_map.php?id=${id}`);
      setFlash({ kind: 'success', msg: `Mapping removed.` });
      await reload();
    } catch (e) { setError(e.message || 'Delete failed'); }
  };

  // -- Render -------------------------------------------------------------
  return (
    <section data-testid="field-mapping-studio" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 14 }}>
        <div>
          <h2 style={{ margin: 0 }}>Field Mapping Studio</h2>
          <p style={{ color: '#64748b', fontSize: 13, marginTop: 4, maxWidth: 720 }}>
            Pick any field from the integration's actual payload on the left, and any writable
            CoreFlux column on the right. Tenant mappings always win over built-in sync defaults.
            Cross-module routing via the <em>linked_entity</em> selector — a JobDiva customer
            payload can write to <code>companies.industry</code> on the end-client, for example.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <select
            data-testid="fms-integration"
            value={integration}
            onChange={e => { setIntegration(e.target.value); setSelectedPath(null); setSelectedTarget(null); }}
            className="input"
            style={{ minWidth: 160 }}
          >
            {Array.from(new Set([...(sources.map(s => s.integration)), 'jobdiva', 'quickbooks', 'zoho_books', 'airtable']))
              .filter(Boolean).map(i => <option key={i} value={i}>{i}</option>)}
          </select>
          <select
            data-testid="fms-entity-type"
            value={entityType}
            onChange={e => { setEntityType(e.target.value); setSelectedPath(null); setSelectedTarget(null); }}
            className="input"
            style={{ minWidth: 160 }}
          >
            {['placement', 'person', 'company', 'contact', 'gl_account', 'journal_entry', 'bill', 'invoice', 'payment'].map(et =>
              <option key={et} value={et}>{et}</option>)}
          </select>
        </div>
      </header>

      {flash && (
        <div data-testid="fms-flash"
             style={{ marginBottom: 10, padding: '6px 12px',
                      background: flash.kind === 'success' ? '#dcfce7' : '#fee2e2',
                      color: flash.kind === 'success' ? '#15803d' : '#991b1b',
                      border: '1px solid ' + (flash.kind === 'success' ? '#86efac' : '#fca5a5'),
                      borderRadius: 6, fontSize: 13 }}>{flash.msg}</div>
      )}
      {error && (
        <div data-testid="fms-error" className="error" style={{ marginBottom: 10 }}>{error}</div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
        {/* === LEFT PANE: payload paths === */}
        <div data-testid="fms-paths-pane" style={paneStyle}>
          <div style={paneHeader}>
            <strong>Source field</strong>
            <input
              data-testid="fms-paths-filter"
              type="text" className="input" placeholder="filter paths or values…"
              value={pathFilter} onChange={e => setPathFilter(e.target.value)}
              style={{ marginLeft: 8, flex: 1 }}
            />
          </div>
          {loading && <p>Loading paths…</p>}
          {!loading && filteredPaths.length === 0 && (
            <p data-testid="fms-paths-empty" style={emptyHint}>
              No indexed paths yet. Trigger a sync for <code>{integration}/{entityType}</code> and reload — the
              indexer populates this list automatically.
            </p>
          )}
          <ul data-testid="fms-paths-list" style={scrollList}>
            {filteredPaths.map(p => (
              <li
                key={p.source_path}
                data-testid={`fms-path-${p.source_path}`}
                onClick={() => setSelectedPath(p)}
                style={{ ...listItem, ...(selectedPath?.source_path === p.source_path ? listItemActive : {}) }}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                  <code style={{ fontSize: 12 }}>{p.source_path}</code>
                  <span style={{ fontSize: 10, color: '#64748b' }}>{p.value_type} · ×{p.occurrence_count}</span>
                </div>
                {p.sample_value && (
                  <div style={{ fontSize: 11, color: '#475569', marginTop: 2 }}>
                    sample: <em>{p.sample_value}</em>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </div>

        {/* === RIGHT PANE: writable targets === */}
        <div data-testid="fms-targets-pane" style={paneStyle}>
          <div style={paneHeader}>
            <strong>Target column</strong>
            <input
              data-testid="fms-targets-filter"
              type="text" className="input" placeholder="filter modules/tables/columns…"
              value={targetFilter} onChange={e => setTargetFilter(e.target.value)}
              style={{ marginLeft: 8, flex: 1 }}
            />
          </div>
          <ul data-testid="fms-targets-list" style={scrollList}>
            {filteredTargets.map(t => {
              const key = `${t.target_module}.${t.target_table}.${t.target_column}`;
              const active = selectedTarget && key === `${selectedTarget.target_module}.${selectedTarget.target_table}.${selectedTarget.target_column}`;
              return (
                <li
                  key={key}
                  data-testid={`fms-target-${key}`}
                  onClick={() => setSelectedTarget(t)}
                  style={{ ...listItem, ...(active ? listItemActive : {}) }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                    <code style={{ fontSize: 12 }}>{t.target_module}.{t.target_table}.{t.target_column}</code>
                    <span style={{ fontSize: 10, color: '#64748b' }}>{t.value_type}</span>
                  </div>
                  {t.description && (
                    <div style={{ fontSize: 11, color: '#475569', marginTop: 2 }}>{t.description}</div>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      </div>

      {/* === Save bar === */}
      <div data-testid="fms-save-bar" style={{
        background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8,
        padding: 12, marginBottom: 16, display: 'grid',
        gridTemplateColumns: '2fr 2fr 1fr 1fr auto', gap: 10, alignItems: 'center',
      }}>
        <div data-testid="fms-source-summary" style={{ fontSize: 12, color: selectedPath ? '#0f172a' : '#94a3b8' }}>
          <strong>FROM:</strong>{' '}
          {selectedPath
            ? <code>{selectedPath.source_path}</code>
            : <em>pick a source field</em>}
        </div>
        <div data-testid="fms-target-summary" style={{ fontSize: 12, color: selectedTarget ? '#0f172a' : '#94a3b8' }}>
          <strong>TO:</strong>{' '}
          {selectedTarget
            ? <code>
                {selectedTarget.target_table}.
                {selectedTarget.target_column === '*'
                  ? <input
                      data-testid="fms-custom-field-code"
                      placeholder="custom_field_code"
                      value={customFieldCode}
                      onChange={e => setCustomFieldCode(e.target.value)}
                      className="input" style={{ width: 140, marginLeft: 4 }}
                    />
                  : selectedTarget.target_column}
              </code>
            : <em>pick a target column</em>}
        </div>
        <label style={{ fontSize: 11, display: 'flex', flexDirection: 'column', gap: 2 }}>
          linked_entity
          <select
            data-testid="fms-linked-entity"
            className="input" value={linkedEntity}
            onChange={e => setLinkedEntity(e.target.value)}
          >
            {Object.entries(LINKED_ENTITY_LABELS).map(([k, lbl]) => (
              <option key={k} value={k}>{lbl}</option>
            ))}
          </select>
        </label>
        <label style={{ fontSize: 11, display: 'flex', flexDirection: 'column', gap: 2 }}>
          transform
          <select
            data-testid="fms-transform"
            className="input" value={transform} onChange={e => setTransform(e.target.value)}
          >
            <option value="none">none</option>
            <option value="date_normalise">date_normalise</option>
            <option value="lowercase">lowercase</option>
            <option value="cents_to_dollars">cents_to_dollars</option>
          </select>
        </label>
        <button
          type="button"
          data-testid="fms-save-btn"
          className="btn btn--primary"
          disabled={!canSave || saving}
          onClick={handleSave}
        >
          {saving ? 'Saving…' : 'Save mapping'}
        </button>
      </div>

      {/* === Existing mappings === */}
      <div data-testid="fms-existing-pane" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 12 }}>
        <div style={{ marginBottom: 8, fontWeight: 600 }}>Existing mappings ({mappings.length})</div>
        {mappings.length === 0
          ? <p data-testid="fms-existing-empty" style={emptyHint}>No mappings yet for this (integration, entity_type).</p>
          : (
            <table className="data-table" data-testid="fms-existing-table" style={{ width: '100%', fontSize: 12 }}>
              <thead>
                <tr>
                  <th>#</th><th>Source</th><th>Target</th><th>linked_entity</th>
                  <th>transform</th><th>enabled</th><th></th>
                </tr>
              </thead>
              <tbody>
                {mappings.map(m => {
                  const src    = m.source_path || m.external_field || '—';
                  const target = m.target_table
                    ? `${m.target_table}.${m.target_column}`
                    : (m.internal_field || '—');
                  return (
                    <tr key={m.id} data-testid={`fms-existing-${m.id}`}>
                      <td><code>#{m.id}</code></td>
                      <td><code style={{ fontSize: 11 }}>{src}</code></td>
                      <td><code style={{ fontSize: 11 }}>{target}</code></td>
                      <td>{m.linked_entity || 'self'}</td>
                      <td>{m.transform}</td>
                      <td>{m.enabled ? '✓' : '—'}</td>
                      <td>
                        <button
                          type="button" className="btn btn--ghost"
                          data-testid={`fms-existing-delete-${m.id}`}
                          onClick={() => handleDelete(m.id, `${src} → ${target}`)}
                        >Remove</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
      </div>
    </section>
  );
}

const paneStyle = {
  background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8,
  padding: 12, height: 460, display: 'flex', flexDirection: 'column',
};
const paneHeader = {
  display: 'flex', alignItems: 'center', marginBottom: 8,
};
const scrollList = {
  overflowY: 'auto', flex: 1, listStyle: 'none', padding: 0, margin: 0,
};
const listItem = {
  padding: '6px 8px', borderRadius: 4, cursor: 'pointer',
  borderBottom: '1px solid #f1f5f9',
};
const listItemActive = {
  background: '#dbeafe', borderBottom: '1px solid #93c5fd',
};
const emptyHint = {
  fontSize: 12, color: '#64748b', padding: '8px 0',
};
