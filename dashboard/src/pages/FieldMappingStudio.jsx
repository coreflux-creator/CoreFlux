import React, { useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
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

/**
 * JobDiva enrichment buckets — the sync grafts these joined sub-records
 * onto the placement payload so a placement-level mapping can reach into
 * the candidate / job / customer / contact / start records without a
 * separate sync. The Studio surfaces these as visual groups in the
 * left pane so operators see "this is the Person section, this is the
 * Job section, this is the Assignment section" instead of a flat path
 * list.
 *
 * default-open groups bubble to the top; the rest expand on click.
 */
const PATH_GROUPS = [
  { key: '_jd_candidate', label: 'Person (candidate)',        icon: '👤', linked: 'person',             defaultOpen: true },
  { key: '_jd_job',       label: 'Job',                       icon: '💼', linked: 'self',               defaultOpen: true },
  { key: '_jd_customer',  label: 'End-client company',        icon: '🏢', linked: 'end_client_company', defaultOpen: true },
  { key: '_jd_contact',   label: 'Hiring contact',            icon: '☎️', linked: 'self',               defaultOpen: false },
  { key: '_jd_start',     label: 'Start / Assignment detail', icon: '📋', linked: 'self',               defaultOpen: true },
];

function groupPathsByNamespace(paths, entityType = 'placement') {
  const groups = new Map();
  // Friendly root-bucket label per entity_type so the UI doesn't say
  // "Placement fields" when the operator is actually mapping a Person
  // / Job / Customer / Contact / Assignment sub-record.
  const ROOT_LABELS = {
    placement:         { label: 'Placement fields (root record)',         icon: '📄' },
    person:            { label: 'Person fields (root of candidate record)', icon: '👤' },
    job:               { label: 'Job fields (root of job record)',        icon: '💼' },
    jobdiva_customer:  { label: 'End-client fields (root of customer record)', icon: '🏢' },
    contact:           { label: 'Contact fields (root of contact record)', icon: '☎️' },
    assignment:        { label: 'Assignment fields (root of start record)', icon: '📋' },
    company:           { label: 'Company fields (root record)',           icon: '🏢' },
    time_entry:        { label: 'Time entry fields (root record)',        icon: '⏱️' },
  };
  const rootMeta = ROOT_LABELS[entityType] || { label: 'Top-level fields', icon: '📄' };

  // Always-initialise known buckets so the UI is stable even when a
  // sub-record hasn't been indexed yet.
  for (const g of PATH_GROUPS) {
    groups.set(g.key, { meta: g, rows: [] });
  }
  groups.set('__root__', {
    meta: { key: '__root__', label: rootMeta.label, icon: rootMeta.icon, linked: 'self', defaultOpen: true },
    rows: [],
  });
  groups.set('__other__', {
    meta: { key: '__other__', label: 'Other / internal', icon: '…', linked: 'self', defaultOpen: false },
    rows: [],
  });

  for (const p of paths) {
    const top = (p.source_path || '').split('.')[0].split('[')[0];
    if (top.startsWith('_jd_') && groups.has(top)) {
      groups.get(top).rows.push(p);
    } else if (!top.startsWith('_jd_') && !top.startsWith('_')) {
      groups.get('__root__').rows.push(p);
    } else {
      groups.get('__other__').rows.push(p);
    }
  }
  // Preserve known-bucket order, drop empty buckets.
  const ordered = ['__root__', '_jd_candidate', '_jd_job', '_jd_customer', '_jd_contact', '_jd_start', '__other__'];
  return ordered
    .map(k => groups.get(k))
    .filter(g => g && g.rows.length > 0);
}

export default function FieldMappingStudio() {
  const location = useLocation();
  const queryParams = useMemo(() => new URLSearchParams(location.search), [location.search]);
  const [sources, setSources]         = useState([]);
  const [integration, setIntegration] = useState(queryParams.get('integration') || 'jobdiva');
  const [entityType, setEntityType]   = useState(queryParams.get('entity_type') || 'placement');

  const [paths, setPaths]             = useState([]);
  const [pathFilter, setPathFilter]   = useState('');
  const [selectedPath, setSelectedPath] = useState(null);
  const [openGroups, setOpenGroups]   = useState({});

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

  // -- Test-mapping panel state ----------------------------------------
  const [testOpen, setTestOpen]       = useState(false);
  const [testInput, setTestInput]     = useState('');
  const [testBusy, setTestBusy]       = useState(false);
  const [testResult, setTestResult]   = useState(null);

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

  // Group the filtered paths by joined-entity namespace so operators see
  // "Placement → Person → Job → End-client → Contact → Start" instead of
  // a 200-row flat list. When the operator types into the filter, groups
  // that have no surviving rows are dropped.
  const groupedPaths = useMemo(() => groupPathsByNamespace(filteredPaths, entityType), [filteredPaths, entityType]);

  // Default group-open state — only set once per integration/entity load
  // so collapsing remains sticky as the operator filters.
  useEffect(() => {
    const init = {};
    for (const g of PATH_GROUPS) init[g.key] = g.defaultOpen;
    init.__root__ = true;
    init.__other__ = false;
    setOpenGroups(init);
  }, [integration, entityType]);

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

  // Test the configured mappings against a sample payload — no writes.
  // Operator pastes a JobDiva (or other) record JSON and sees what each
  // configured rule would resolve to with the actual target identity.
  const handleTestRun = async () => {
    setError(null); setTestResult(null);
    let payload;
    try {
      payload = JSON.parse(testInput || '{}');
    } catch (e) {
      setError('Sample payload must be valid JSON: ' + (e.message || e));
      return;
    }
    setTestBusy(true);
    try {
      const r = await api.post('/api/admin/integrations/field_map_test.php', {
        integration, entity_type: entityType, payload,
      });
      setTestResult(r);
    } catch (e) { setError(e.message || 'Test failed'); }
    finally { setTestBusy(false); }
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
            style={{ minWidth: 200 }}
          >
            {(() => {
              // Data-driven: anything the indexer has actually seen for the
              // selected integration shows up first, with its path_count as
              // a tooltip so the operator picks the richest source. Static
              // fallbacks are appended so empty tenants can still pick the
              // entity types JobDiva / QBO / Zoho / Airtable produce.
              const seen = sources
                .filter(s => s.integration === integration)
                .map(s => ({ et: s.entity_type, count: Number(s.path_count) || 0 }));
              const fallback = {
                jobdiva:    ['placement', 'person', 'company', 'contact', 'jobdiva_customer', 'time_entry'],
                quickbooks: ['journal_entry', 'customer', 'vendor', 'invoice', 'bill', 'payment', 'gl_account', 'item'],
                zoho_books: ['journal_entry', 'customer', 'vendor', 'invoice', 'bill', 'payment', 'gl_account'],
                airtable:   ['record'],
              }[integration] || ['placement', 'person', 'company', 'contact'];
              const seenKeys = new Set(seen.map(s => s.et));
              const ordered = [
                ...seen,
                ...fallback.filter(et => !seenKeys.has(et)).map(et => ({ et, count: 0 })),
              ];
              return ordered.map(o => (
                <option key={o.et} value={o.et} title={o.count > 0 ? `${o.count} indexed paths` : 'not yet indexed'}>
                  {o.et}{o.count > 0 ? ` (${o.count})` : ''}
                </option>
              ));
            })()}
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
            <div data-testid="fms-paths-empty" style={emptyHint}>
              <p style={{ margin: 0 }}>
                No indexed paths yet for <code>{integration}/{entityType}</code>.
              </p>
              <p style={{ margin: '6px 0 0' }}>
                The indexer populates this list automatically the next time CoreFlux
                receives a payload from the integration. Trigger one now:
              </p>
              <p style={{ margin: '6px 0 0' }}>
                {integration === 'jobdiva' && (
                  <Link to="/admin/integrations/jobdiva"
                        data-testid="fms-paths-empty-jobdiva-link"
                        style={{ fontSize: 13 }}>→ Open JobDiva settings → "Sync now"</Link>
                )}
                {integration === 'quickbooks' && (
                  <Link to="/admin/integrations/qbo"
                        data-testid="fms-paths-empty-qbo-link"
                        style={{ fontSize: 13 }}>→ Open QBO settings → "Pull customers/vendors"</Link>
                )}
                {integration === 'zoho_books' && (
                  <Link to="/admin/integrations/zoho-books"
                        data-testid="fms-paths-empty-zoho-link"
                        style={{ fontSize: 13 }}>→ Open Zoho Books settings</Link>
                )}
                {integration === 'airtable' && (
                  <Link to="/admin/integrations/airtable"
                        data-testid="fms-paths-empty-airtable-link"
                        style={{ fontSize: 13 }}>→ Open Airtable settings</Link>
                )}
                {!['jobdiva', 'quickbooks', 'zoho_books', 'airtable'].includes(integration) && (
                  <Link to="/admin/integrations"
                        data-testid="fms-paths-empty-hub-link"
                        style={{ fontSize: 13 }}>→ Open Integrations Hub</Link>
                )}
              </p>
            </div>
          )}
          {!loading && filteredPaths.length > 0 && (
            <div data-testid="fms-paths-grouped" style={{ ...scrollList, padding: 0 }}>
              {/* Helpful preamble — adapts per entity type so operators
                  understand the source. Placement shows joined-entity
                  groups; the joined entity types themselves explain
                  that they're indexed FROM the placement sync. */}
              {integration === 'jobdiva' && entityType === 'placement' && (
                <div data-testid="fms-paths-explainer"
                     style={{ fontSize: 11, color: '#475569', background: '#f8fafc',
                              padding: '6px 10px', borderBottom: '1px solid #e2e8f0' }}>
                  Placement records are <strong>enriched</strong> server-side with the joined
                  Person, Job, End-client and Assignment detail. Pick any field from any group
                  below — set <em>linked_entity</em> in the save bar to route it to the right
                  CoreFlux row.
                </div>
              )}
              {integration === 'jobdiva' && ['person', 'job', 'jobdiva_customer', 'contact', 'assignment'].includes(entityType) && (
                <div data-testid="fms-paths-explainer-joined"
                     data-entity={entityType}
                     style={{ fontSize: 11, color: '#475569', background: '#fefce8',
                              padding: '6px 10px', borderBottom: '1px solid #fde68a' }}>
                  Source paths here come from the <strong>{entityType.replace('_', ' ')}</strong>{' '}
                  sub-record indexed during each Placement sync. Map them to any CoreFlux column
                  on the right — mappings stored under this entity_type are applied on every
                  placement pull using the joined sub-record as the source.
                </div>
              )}
              {groupedPaths.map(grp => {
                const isOpen = openGroups[grp.meta.key] !== undefined
                  ? openGroups[grp.meta.key]
                  : grp.meta.defaultOpen;
                return (
                  <div key={grp.meta.key}
                       data-testid={`fms-paths-group-${grp.meta.key}`}
                       data-open={isOpen ? 'yes' : 'no'}
                       style={{ borderBottom: '1px solid #e2e8f0' }}>
                    <button
                      type="button"
                      data-testid={`fms-paths-group-toggle-${grp.meta.key}`}
                      onClick={() => setOpenGroups(s => ({ ...s, [grp.meta.key]: !isOpen }))}
                      style={{
                        width: '100%', textAlign: 'left', padding: '8px 10px',
                        background: isOpen ? '#eff6ff' : '#f8fafc',
                        border: 0, borderBottom: '1px solid #e2e8f0',
                        cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 8,
                        fontSize: 12, fontWeight: 600, color: '#1e293b',
                      }}>
                      <span style={{ fontSize: 14 }}>{grp.meta.icon}</span>
                      <span style={{ flex: 1 }}>{grp.meta.label}</span>
                      <span style={{ fontSize: 10, color: '#64748b', fontWeight: 400 }}>
                        {grp.rows.length} {grp.rows.length === 1 ? 'field' : 'fields'}
                        {grp.meta.linked !== 'self' && ` · linked_entity=${grp.meta.linked}`}
                      </span>
                      <span style={{ fontSize: 10, color: '#64748b' }}>{isOpen ? '▾' : '▸'}</span>
                    </button>
                    {isOpen && (
                      <ul data-testid={`fms-paths-group-list-${grp.meta.key}`}
                          style={{ margin: 0, padding: 0, listStyle: 'none' }}>
                        {grp.rows.map(p => (
                          <li
                            key={p.source_path}
                            data-testid={`fms-path-${p.source_path}`}
                            onClick={() => {
                              setSelectedPath(p);
                              // Smart-default linked_entity from the group so
                              // operators don't have to remember "person fields
                              // need linked_entity=person".
                              if (grp.meta.linked && grp.meta.linked !== 'self') {
                                setLinkedEntity(grp.meta.linked);
                              }
                            }}
                            style={{
                              ...listItem,
                              ...(selectedPath?.source_path === p.source_path ? listItemActive : {}),
                              paddingLeft: 22,
                            }}
                          >
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                              <code style={{ fontSize: 12 }}>{p.source_path}</code>
                              <span style={{ fontSize: 10, color: '#64748b' }}>
                                {p.value_type} · ×{p.occurrence_count}
                              </span>
                            </div>
                            {p.sample_value && (
                              <div style={{ fontSize: 11, color: '#475569', marginTop: 2 }}>
                                sample: <em>{p.sample_value}</em>
                              </div>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                );
              })}
            </div>
          )}
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
      <div data-testid="fms-existing-pane" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 12, marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <div style={{ fontWeight: 600 }}>Existing mappings ({mappings.length})</div>
          <button
            type="button" className="btn"
            data-testid="fms-test-toggle"
            onClick={() => setTestOpen(o => !o)}
          >{testOpen ? 'Hide test panel' : 'Test mappings…'}</button>
        </div>
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

      {/* === Test panel === */}
      {testOpen && (
        <div data-testid="fms-test-pane" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 12 }}>
          <div style={{ marginBottom: 8, fontWeight: 600 }}>Test mappings against a sample payload</div>
          <p style={{ fontSize: 12, color: '#64748b', margin: '0 0 8px' }}>
            Paste a raw <code>{integration}</code> <code>{entityType}</code> JSON record (e.g. from
            the "View raw payload" affordance on any synced record). The configured mappings
            evaluate read-only — no DB writes. Includes <code>_jd_candidate</code>, <code>_jd_job</code>,
            <code>_jd_customer</code>, <code>_jd_contact</code> grafts the syncer adds during enrichment.
          </p>
          <textarea
            data-testid="fms-test-input"
            className="input"
            rows={8}
            placeholder='{"placementId": 27857851, "_jd_candidate": {"firstName": "Andrew"}, ...}'
            value={testInput}
            onChange={e => setTestInput(e.target.value)}
            style={{ width: '100%', fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 12 }}
          />
          <div style={{ marginTop: 8, display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button
              type="button" className="btn"
              onClick={() => { setTestInput(''); setTestResult(null); }}
              data-testid="fms-test-clear"
            >Clear</button>
            <button
              type="button" className="btn btn--primary"
              onClick={handleTestRun}
              disabled={testBusy || !testInput.trim()}
              data-testid="fms-test-run"
            >{testBusy ? 'Running…' : 'Run test'}</button>
          </div>
          {testResult && (
            <div data-testid="fms-test-results" style={{ marginTop: 12 }}>
              <div style={{ fontSize: 12, color: '#475569', marginBottom: 6 }}>
                <strong>{testResult.generalised?.totals?.matched ?? 0}</strong> of{' '}
                <strong>{testResult.generalised?.totals?.total ?? 0}</strong> mappings matched in this payload.
              </div>
              <table className="data-table" style={{ width: '100%', fontSize: 12 }}>
                <thead>
                  <tr>
                    <th>#</th><th>Source</th><th>Raw value</th><th>Transform</th><th>Resolved</th><th>Target</th><th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {(testResult.generalised?.results || []).map(r => (
                    <tr
                      key={r.mapping_id}
                      data-testid={`fms-test-row-${r.mapping_id}`}
                      data-matched={r.matched ? 'yes' : 'no'}
                      style={{ background: r.matched ? '#f0fdf4' : '#fef2f2' }}
                    >
                      <td><code>#{r.mapping_id}</code></td>
                      <td><code style={{ fontSize: 11 }}>{r.source_path}</code></td>
                      <td style={{ fontSize: 11, color: '#475569' }}>
                        {r.raw_value === null ? <em>—</em> : String(r.raw_value)}
                      </td>
                      <td style={{ fontSize: 11 }}>{r.transform}</td>
                      <td style={{ fontSize: 11, fontWeight: 600 }}>
                        {r.resolved_value === null ? <em>—</em> : String(r.resolved_value)}
                      </td>
                      <td><code style={{ fontSize: 11 }}>{r.target}</code></td>
                      <td style={{ fontSize: 11 }}>
                        {r.matched
                          ? <span style={{ color: '#15803d' }}>✓ would write</span>
                          : <span style={{ color: '#991b1b' }}>✗ no value</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
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
