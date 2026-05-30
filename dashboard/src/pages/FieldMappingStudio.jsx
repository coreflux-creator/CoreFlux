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

  // -- Re-index existing JobDiva placements -----------------------------
  const [reindexBusy, setReindexBusy] = useState(false);
  const [reindexResult, setReindexResult] = useState(null);
  const [reindexedThisSession, setReindexedThisSession] = useState(false);

  // -- Auto-map suggestions panel state ---------------------------------
  const [suggestOpen, setSuggestOpen] = useState(false);
  const [suggestBusy, setSuggestBusy] = useState(false);
  const [suggestList, setSuggestList] = useState([]);
  const [suggestSelected, setSuggestSelected] = useState({}); // {index:true}
  const [suggestApplying, setSuggestApplying] = useState(false);
  const [suggestError, setSuggestError] = useState(null);

  // -- CSV upload (joined-entity fallback) ------------------------------
  const [csvOpen, setCsvOpen]       = useState(false);
  const [csvFile, setCsvFile]       = useState(null);
  const [csvEntity, setCsvEntity]   = useState('');
  const [csvBusy, setCsvBusy]       = useState(false);
  const [csvResult, setCsvResult]   = useState(null);
  const [csvError, setCsvError]     = useState(null);

  // -- Source Payload Inspector (read-only browse view across entities) --
  const [inspectorOpen, setInspectorOpen] = useState(false);
  const [inspectorEntity, setInspectorEntity] = useState(entityType);
  const [inspectorPaths, setInspectorPaths] = useState([]);
  const [inspectorBusy, setInspectorBusy] = useState(false);
  const [inspectorFilter, setInspectorFilter] = useState('');
  // Global-search mode: when ON, filter searches across every entity
  // bucket (paths_by_entity from the all-buckets API endpoint).
  const [inspectorGlobal, setInspectorGlobal] = useState(false);
  const [inspectorGlobalMap, setInspectorGlobalMap] = useState({}); // { entity_type: [paths…] }

  // -- "What JobDiva actually returned" raw-payload diagnostic ----------
  const [rawOpen, setRawOpen]       = useState(false);
  const [rawBusy, setRawBusy]       = useState(false);
  const [rawData, setRawData]       = useState(null);
  const [rawError, setRawError]     = useState(null);
  const [rawShowJson, setRawShowJson] = useState(false);

  // -- JobDiva endpoint probe diagnostic (🔎 Diagnose JobDiva) ----------
  const [diagOpen, setDiagOpen]       = useState(false);
  const [diagBusy, setDiagBusy]       = useState(false);
  const [diagResults, setDiagResults] = useState(null);
  const [diagError, setDiagError]     = useState(null);
  const [diagExpanded, setDiagExpanded] = useState({});

  const reloadSources = async () => {
    try {
      const r = await api.get('/api/admin/integrations/payload_fields.php');
      setSources(r.sources || []);
      return r.sources || [];
    } catch (e) { setError(e.message); return []; }
  };

  const handleReindex = async (silent = false) => {
    if (reindexBusy) return null;
    setReindexBusy(true);
    if (!silent) setError(null);
    try {
      const r = await api.post('/api/admin/integrations/reindex_jobdiva_subpayloads.php', {});
      setReindexResult(r);
      setReindexedThisSession(true);
      // Refresh sources + current pane after the re-index so the
      // operator immediately sees the new entity types.
      await reloadSources();
      await reload();
      if (!silent) {
        const tot = Object.values(r.sub_records_indexed || {}).reduce((a, b) => a + (Number(b) || 0), 0);
        setFlash({
          kind: 'success',
          msg: `Indexed joined sub-records from ${r.placements_walked || 0} placement(s) — ${tot} sub-records routed to Person/Job/Customer/Contact/Assignment.`,
        });
      }
      return r;
    } catch (e) {
      if (!silent) setError(e.message || 'Re-index failed');
      return null;
    } finally {
      setReindexBusy(false);
    }
  };

  // -- Auto-map suggestions -----------------------------------------------
  const loadSuggestions = async () => {
    setSuggestBusy(true); setSuggestError(null); setSuggestList([]); setSuggestSelected({});
    try {
      const r = await api.post('/api/admin/integrations/suggest_mappings.php', {
        integration, entity_type: entityType,
      });
      const rows = Array.isArray(r.suggestions) ? r.suggestions : [];
      setSuggestList(rows);
      // Default-select all high-confidence rows (≥0.85). Operators can
      // toggle individual rows or use Select all / none.
      const sel = {};
      rows.forEach((s, i) => { if ((s.confidence ?? 0) >= 0.85) sel[i] = true; });
      setSuggestSelected(sel);
    } catch (e) {
      setSuggestError(e.message || 'Failed to load suggestions');
    } finally {
      setSuggestBusy(false);
    }
  };

  const openSuggest = async () => {
    setSuggestOpen(true);
    if (suggestList.length === 0 && !suggestBusy) await loadSuggestions();
  };

  const applySuggestions = async () => {
    const picks = suggestList.filter((_, i) => suggestSelected[i]);
    if (picks.length === 0) {
      setSuggestError('Pick at least one suggestion to apply.');
      return;
    }
    setSuggestApplying(true); setSuggestError(null);
    let ok = 0, failed = 0;
    for (const s of picks) {
      try {
        await api.post('/api/admin/integrations/field_map.php', {
          integration,
          entity_type: entityType,
          source_path:   s.source_path,
          target_module: s.target_module,
          target_table:  s.target_table,
          target_column: s.target_column,
          linked_entity: s.linked_entity,
          transform:     s.transform,
          enabled: true,
        });
        ok++;
      } catch {
        failed++;
      }
    }
    setSuggestApplying(false);
    setFlash({
      kind: failed === 0 ? 'success' : 'error',
      msg: `Applied ${ok} of ${picks.length} suggested mapping${picks.length === 1 ? '' : 's'}`
            + (failed > 0 ? ` — ${failed} failed.` : '.'),
    });
    // Refresh mapping list + close the panel.
    await reload();
    if (failed === 0) {
      setSuggestOpen(false);
      setSuggestList([]);
      setSuggestSelected({});
    } else {
      // Keep failed rows visible so operator can retry or remove.
      setSuggestList(picks.filter((_, i) => i >= ok));
      setSuggestSelected({});
    }
  };

  // -- CSV upload handlers ------------------------------------------------
  const openCsv = () => {
    setCsvOpen(true);
    setCsvFile(null);
    setCsvResult(null);
    setCsvError(null);
    // Pre-fill the entity dropdown with the one the operator is viewing
    // so the most common path (open studio on `job`, upload jobs.csv)
    // is zero-config.
    setCsvEntity(entityType);
  };

  const submitCsv = async () => {
    if (!csvFile) { setCsvError('Pick a CSV file first.'); return; }
    if (!csvEntity) { setCsvError('Pick an entity type.'); return; }
    setCsvBusy(true); setCsvError(null); setCsvResult(null);
    try {
      const form = new FormData();
      form.append('integration', integration);
      form.append('entity_type', csvEntity);
      form.append('file', csvFile);
      // Use raw fetch to send multipart — api.post serialises JSON.
      const base = (window.__CF_API_BASE__ || '');
      const res = await fetch(`${base}/api/admin/integrations/upload_csv.php`, {
        method: 'POST', credentials: 'include', body: form,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || json.message || `Upload failed (${res.status})`);
      }
      setCsvResult(json);
      // Refresh sources so the new entity type / paths appear in the
      // dropdown + left pane immediately.
      await reloadSources();
      // Auto-switch to the entity type the operator just populated so
      // they can see the result without another click.
      if (csvEntity !== entityType) setEntityType(csvEntity);
      await reload();
    } catch (e) {
      setCsvError(e.message || 'Upload failed');
    } finally {
      setCsvBusy(false);
    }
  };

  // -- Source Payload Inspector handlers ---------------------------------
  const openInspector = async () => {
    setInspectorOpen(true);
    setInspectorEntity(entityType);
    setInspectorFilter('');
    setInspectorGlobal(false);
    await loadInspectorPaths(entityType);
  };

  const loadInspectorPaths = async (et) => {
    setInspectorBusy(true);
    try {
      const r = await api.get(`/api/admin/integrations/payload_fields.php?integration=${integration}&entity_type=${et}&limit=2000`);
      setInspectorPaths(r.paths || []);
    } catch (e) {
      setInspectorPaths([]);
    } finally {
      setInspectorBusy(false);
    }
  };

  // Load every bucket's paths in one round-trip for global-search mode.
  const loadInspectorPathsGlobal = async () => {
    setInspectorBusy(true);
    try {
      const r = await api.get(`/api/admin/integrations/payload_fields.php?integration=${integration}&entity_type=*&limit=2000`);
      setInspectorGlobalMap(r.paths_by_entity || {});
    } catch (e) {
      setInspectorGlobalMap({});
    } finally {
      setInspectorBusy(false);
    }
  };

  const toggleInspectorGlobal = async () => {
    const next = !inspectorGlobal;
    setInspectorGlobal(next);
    if (next && Object.keys(inspectorGlobalMap).length === 0) {
      await loadInspectorPathsGlobal();
    }
  };

  const switchInspectorEntity = async (et) => {
    setInspectorEntity(et);
    setInspectorFilter('');
    // Don't reload if global mode is on — the entity tabs are just
    // visual focus while the global map is already loaded.
    if (!inspectorGlobal) await loadInspectorPaths(et);
  };

  // Apply an inspected path to the mapping form (closes inspector, pre-selects).
  const useInspectedPath = (path) => {
    if (inspectorEntity !== entityType) {
      // Switch the main pane to the entity the user is browsing, then
      // select the path after `reload()` repopulates `paths`. Setting
      // selectedPath BEFORE reload is OK — handleSave reads the path
      // string, not an identity.
      setEntityType(inspectorEntity);
    }
    setSelectedPath(path);
    setInspectorOpen(false);
  };

  // -- Raw JobDiva payload diagnostic -------------------------------------
  // Pulls the most-recent placement's stored payload + _jd_* enrichment
  // buckets so the operator can SEE what JobDiva actually returned. This
  // is the only way to tell "JobDiva's /searchStart only returned status
  // for our tenant" from "our extractor isn't surfacing what JobDiva sent".
  const openRawPayload = async () => {
    setRawOpen(true);
    setRawBusy(true);
    setRawError(null);
    setRawData(null);
    setRawShowJson(false);
    try {
      const r = await api.get('/api/admin/integrations/jobdiva_raw_payload.php?internal_entity_type=placement');
      if (r && r.ok === false) {
        setRawError(r.message || r.reason || 'no record');
      } else {
        setRawData(r);
      }
    } catch (e) {
      setRawError(e.message || 'Failed to load raw payload');
    } finally {
      setRawBusy(false);
    }
  };

  // -- Load discovery + existing mappings ---------------------------------
  useEffect(() => {
    reloadSources();
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, []);

  // Auto re-index for JobDiva tenants whose picker is stuck on placement-
  // only: if we've loaded sources and the only jobdiva entity is
  // 'placement' with paths, fire ONE silent re-index so the operator
  // immediately sees Person/Job/Customer/Contact/Assignment populated
  // without having to know about a button. Guarded so we never loop.
  useEffect(() => {
    if (integration !== 'jobdiva') return;
    if (reindexedThisSession || reindexBusy) return;
    if (!sources || sources.length === 0) return;
    const jdSources = sources.filter(s => s.integration === 'jobdiva');
    if (jdSources.length === 0) return;
    const hasPlacement = jdSources.some(s => s.entity_type === 'placement' && Number(s.path_count) > 0);
    const hasJoined    = jdSources.some(s =>
      ['person', 'job', 'jobdiva_customer', 'contact', 'assignment'].includes(s.entity_type)
      && Number(s.path_count) > 0
    );
    if (hasPlacement && !hasJoined) {
      handleReindex(/*silent=*/true);
    }
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [sources, integration]);

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
          <button
            type="button"
            data-testid="fms-automap-btn"
            onClick={openSuggest}
            className="btn btn--primary"
            title="Propose mappings automatically based on field-name matching."
            style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
            ✨ Auto-map
          </button>
          <button
            type="button"
            data-testid="fms-inspect-btn"
            onClick={openInspector}
            className="btn btn--ghost"
            title="Browse all indexed source fields across every entity bucket (read-only)."
            style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
            📋 Inspect sources
          </button>
          {integration === 'jobdiva' && (
            <button
              type="button"
              data-testid="fms-jobdiva-mirror-btn"
              onClick={async () => {
                if (!confirm('Mirror every JobDiva Job, Candidate, and Customer referenced by your synced placements into CoreFlux?\n\nUses /apiv2/bi/JobsDetail, /CandidatesDetail, /CompaniesDetail (the by-ID endpoints from the official Swagger spec) so we don\'t rely on date-range guesswork. Will take 10-60 seconds depending on volume.')) return;
                setFlash({ kind: 'info', text: 'Mirror sync running…' });
                try {
                  const r = await api.post('/api/admin/integrations/jobdiva_mirror_by_placements.php', {});
                  setFlash({
                    kind: 'ok',
                    text: `Mirror done · scanned ${r.placements_scanned} placements → ` +
                          `jobs ×${r.jobs_processed}/${r.unique_job_ids}, ` +
                          `candidates ×${r.candidates_processed}/${r.unique_candidate_ids}, ` +
                          `contacts ×${r.customers_processed}/${r.unique_customer_ids}, ` +
                          `assignments ×${r.assignments_processed}/${r.unique_start_ids}`,
                  });
                  await reload();
                } catch (e) {
                  setFlash({ kind: 'err', text: 'Mirror sync failed: ' + (e.message || e) });
                }
              }}
              className="btn btn--primary"
              title="Pull full Job, Candidate, and Customer records for every ID referenced by your placements via the /JobsDetail, /CandidatesDetail, /CompaniesDetail by-ID endpoints."
              style={{ whiteSpace: 'nowrap', fontSize: 13, background: '#0c4a6e', color: '#fff' }}>
              🪞 Mirror Jobs + Candidates
            </button>
          )}
          {integration === 'jobdiva' && (
            <button
              type="button"
              data-testid="fms-jobdiva-diagnose-btn"
              onClick={async () => {
                setDiagOpen(true);
                setDiagBusy(true);
                setDiagError(null);
                setDiagResults(null);
                try {
                  const r = await api.post('/api/admin/integrations/jobdiva_probe.php', {});
                  setDiagResults(r);
                } catch (e) {
                  setDiagError(e.message || 'Probe failed');
                } finally {
                  setDiagBusy(false);
                }
              }}
              className="btn btn--ghost"
              title="Probe 6-8 JobDiva endpoints and show the raw HTTP status + response size + body preview for each. Reveals exactly which endpoints your JobDiva auth scope grants."
              style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
              🔎 Diagnose JobDiva
            </button>
          )}
          {integration === 'jobdiva' && (
            <button
              type="button"
              data-testid="fms-raw-payload-btn"
              onClick={openRawPayload}
              className="btn btn--ghost"
              title="See exactly what JobDiva returned for the most-recent placement — every _jd_* enrichment bucket with field counts. Use this when an entity bucket has surprisingly few mappable paths."
              style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
              🔬 Raw payload
            </button>
          )}
          <button
            type="button"
            data-testid="fms-csv-upload-btn"
            onClick={openCsv}
            className="btn btn--ghost"
            title="Drop a CSV export from your integration to index every column as a mappable path."
            style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
            📄 Upload CSV
          </button>
        </div>
      </header>

      {/* Entity tab strip — clearer + more visible than the entity-type
          dropdown above. Lists every (integration, entity_type) tuple
          we've actually seen, with its indexed path count. Operator
          asked: "clearer entity tabs in the Field Mapping Studio
          (Person / Job / Customer / Contact / Assignment / Placement)
          with a count of fields available in each". The dropdown
          stays in place for power users + small screens. */}
      {(() => {
        const seen = sources
          .filter(s => s.integration === integration)
          .map(s => ({ et: s.entity_type, count: Number(s.path_count) || 0 }));
        const fallback = {
          jobdiva:    ['placement', 'person', 'job', 'jobdiva_customer', 'contact', 'assignment', 'jobdiva_job', 'jobdiva_candidate', 'jobdiva_contact', 'jobdiva_assignment'],
          quickbooks: ['journal_entry', 'customer', 'vendor', 'invoice', 'bill', 'payment', 'gl_account', 'item'],
          zoho_books: ['journal_entry', 'customer', 'vendor', 'invoice', 'bill', 'payment', 'gl_account'],
          airtable:   ['record'],
        }[integration] || [];
        const seenKeys = new Set(seen.map(s => s.et));
        const ordered = [
          ...seen,
          ...fallback.filter(et => !seenKeys.has(et)).map(et => ({ et, count: 0 })),
        ];
        if (ordered.length === 0) return null;
        const LABELS = {
          placement:        'Placement',
          person:           'Person',
          job:              'Job',
          jobdiva_customer: 'Customer',
          customer:         'Customer',
          contact:          'Contact',
          assignment:       'Assignment',
          company:          'Company',
          jobdiva_job:        '🪞 JobDiva Job (full mirror)',
          jobdiva_candidate:  '🪞 JobDiva Candidate (full mirror)',
          jobdiva_contact:    '🪞 JobDiva Contact (full mirror)',
          jobdiva_assignment: '🪞 JobDiva Assignment (full mirror)',
          journal_entry:    'Journal Entry',
          vendor:           'Vendor',
          invoice:          'Invoice',
          bill:             'Bill',
          payment:          'Payment',
          gl_account:       'GL Account',
          item:             'Item',
          time_entry:       'Time Entry',
          record:           'Record',
        };
        return (
          <div data-testid="fms-entity-tabs"
               role="tablist"
               style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 14,
                        paddingBottom: 10, borderBottom: '1px solid #e2e8f0' }}>
            {ordered.map(o => {
              const active = o.et === entityType;
              return (
                <button
                  key={o.et}
                  type="button"
                  role="tab"
                  aria-selected={active}
                  data-testid={`fms-entity-tab-${o.et}`}
                  data-active={active ? 'true' : 'false'}
                  onClick={() => { setEntityType(o.et); setSelectedPath(null); setSelectedTarget(null); }}
                  title={o.count > 0 ? `${o.count} indexed paths` : 'not yet indexed'}
                  style={{
                    padding: '6px 12px',
                    borderRadius: 999,
                    border: '1px solid ' + (active ? '#2563eb' : '#cbd5e1'),
                    background: active ? '#2563eb' : '#fff',
                    color: active ? '#fff' : (o.count > 0 ? '#0f172a' : '#94a3b8'),
                    cursor: 'pointer',
                    fontSize: 12,
                    fontWeight: active ? 600 : 500,
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    whiteSpace: 'nowrap',
                  }}>
                  <span>{LABELS[o.et] || o.et}</span>
                  <span
                    data-testid={`fms-entity-tab-${o.et}-count`}
                    style={{
                      fontSize: 11,
                      padding: '1px 7px',
                      borderRadius: 999,
                      background: active ? 'rgba(255,255,255,0.22)' : (o.count > 0 ? '#e0f2fe' : '#f1f5f9'),
                      color: active ? '#fff' : (o.count > 0 ? '#0369a1' : '#94a3b8'),
                    }}>
                    {o.count}
                  </span>
                </button>
              );
            })}
          </div>
        );
      })()}

      {/* JobDiva re-index banner — surfaces when the only jobdiva source
          is `placement`. Lets the operator extract joined Person/Job/
          Customer/Contact/Assignment sub-records out of every existing
          placement payload WITHOUT triggering a fresh JobDiva HTTP sync. */}
      {integration === 'jobdiva' && (() => {
        const jdSources = sources.filter(s => s.integration === 'jobdiva');
        const placementSrc = jdSources.find(s => s.entity_type === 'placement');
        const joinedSrc = jdSources.filter(s =>
          ['person', 'job', 'jobdiva_customer', 'contact', 'assignment'].includes(s.entity_type)
          && Number(s.path_count) > 0
        );
        if (!placementSrc || Number(placementSrc.path_count) === 0) return null;
        const placementCount = Number(placementSrc.path_count) || 0;
        const joinedCount = joinedSrc.length;
        return (
          <div data-testid="fms-jobdiva-reindex-banner"
               data-joined-source-count={joinedCount}
               style={{ marginBottom: 14, padding: 12,
                        background: joinedCount > 0
                          ? 'linear-gradient(135deg,#ecfdf5,#f0fdf4)'
                          : 'linear-gradient(135deg,#fef3c7,#fef9c3)',
                        border: '1px solid ' + (joinedCount > 0 ? '#86efac' : '#fde68a'),
                        borderRadius: 8,
                        display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
            <div style={{ flex: 1, fontSize: 12 }}>
              <strong style={{ color: '#0f172a' }}>
                {joinedCount > 0
                  ? `JobDiva joined entities indexed (${joinedCount}/5).`
                  : `Joined-entity fields not yet indexed.`}
              </strong>
              <div style={{ marginTop: 4, color: '#475569' }}>
                {joinedCount > 0
                  ? <>You have <strong>{placementCount}</strong> placement paths indexed plus joined sub-records. <strong>Re-index again</strong> any time to pull the latest full Job / Candidate / Customer records from JobDiva via <code>/apiv2/jobdiva/search*</code> and refresh the indexed paths.</>
                  : <>You have <strong>{placementCount}</strong> placement paths indexed but none of the joined Person / Job / End-client / Contact / Assignment fields are in the picker yet. Click below to extract them from your existing placement payloads <em>and</em> fetch full joined records from JobDiva — no fresh sync needed.</>}
              </div>
              {reindexResult && (
                <div data-testid="fms-jobdiva-reindex-result"
                     style={{ marginTop: 6, fontSize: 11, color: '#0f172a' }}>
                  Last run: walked <strong>{reindexResult.placements_walked}</strong> placements →
                  {' '}{Object.entries(reindexResult.sub_records_indexed || {})
                          .filter(([, n]) => Number(n) > 0)
                          .map(([k, n]) => `${k} ×${n}`)
                          .join(', ') || 'no joined sub-records found'}
                  {Number(reindexResult.enrichment_ran_for) > 0 && (
                    <span data-testid="fms-jobdiva-reindex-enrichment"
                          style={{ marginLeft: 6, color: '#0369a1' }}>
                      · fetched full joined records for <strong>{reindexResult.enrichment_ran_for}</strong> placement(s) from JobDiva
                    </span>
                  )}
                  {Array.isArray(reindexResult.enrichment_errors) && reindexResult.enrichment_errors.length > 0 && (
                    <div data-testid="fms-jobdiva-reindex-enrichment-error"
                         style={{ marginTop: 4, color: '#b91c1c' }}>
                      Enrichment had errors — your JobDiva account may not have access to the
                      <code> /apiv2/jobdiva/search* </code>endpoints. Flat-prefix fields
                      (<code>jobRefNo</code>, <code>candidateRefNo</code>, etc.) are still indexed.
                    </div>
                  )}
                  {reindexResult.endpoint_diagnostics && Object.keys(reindexResult.endpoint_diagnostics).length > 0 && (
                    <details data-testid="fms-jobdiva-endpoint-diagnostics"
                             style={{ marginTop: 8 }}>
                      <summary style={{ cursor: 'pointer', fontSize: 11, fontWeight: 600, color: '#0f172a' }}>
                        Per-endpoint diagnostics ({Object.keys(reindexResult.endpoint_diagnostics).length} endpoints) — click to expand
                      </summary>
                      <table style={{ marginTop: 6, width: '100%', borderCollapse: 'collapse', fontSize: 11 }}>
                        <thead>
                          <tr style={{ background: '#f8fafc' }}>
                            <th style={{ ...thStyle, fontSize: 10 }}>Kind → Endpoint</th>
                            <th style={{ ...thStyle, fontSize: 10, textAlign: 'right' }}>IDs seen</th>
                            <th style={{ ...thStyle, fontSize: 10, textAlign: 'right' }}>Attempted</th>
                            <th style={{ ...thStyle, fontSize: 10, textAlign: 'right' }}>OK</th>
                            <th style={{ ...thStyle, fontSize: 10, textAlign: 'right' }}>Empty</th>
                            <th style={{ ...thStyle, fontSize: 10, textAlign: 'right' }}>Failed</th>
                            <th style={{ ...thStyle, fontSize: 10 }}>Sample error</th>
                          </tr>
                        </thead>
                        <tbody>
                          {Object.entries(reindexResult.endpoint_diagnostics).map(([kind, d]) => (
                            <tr key={kind}
                                data-testid={`fms-jobdiva-diag-${kind}`}
                                data-broken={d.broken_endpoint ? 'yes' : 'no'}
                                style={{ borderTop: '1px solid #e2e8f0',
                                         background: d.broken_endpoint ? '#fef2f2'
                                                   : d.succeeded > 0    ? '#f0fdf4'
                                                   : 'transparent' }}>
                              <td style={{ ...tdStyle, fontSize: 11 }}>
                                <code>{kind}</code>{' '}
                                <span style={{ color: '#94a3b8' }}>{d.endpoint}</span>
                                {d.broken_endpoint && <span style={{ marginLeft: 4, color: '#b91c1c' }}> broken</span>}
                              </td>
                              <td style={{ ...tdStyle, fontSize: 11, textAlign: 'right' }}>{d.ids_seen ?? 0}</td>
                              <td style={{ ...tdStyle, fontSize: 11, textAlign: 'right' }}>{d.attempted ?? 0}</td>
                              <td style={{ ...tdStyle, fontSize: 11, textAlign: 'right', color: '#16a34a' }}>{d.succeeded ?? 0}</td>
                              <td style={{ ...tdStyle, fontSize: 11, textAlign: 'right', color: '#94a3b8' }}>{d.empty_response ?? 0}</td>
                              <td style={{ ...tdStyle, fontSize: 11, textAlign: 'right', color: '#dc2626' }}>{d.failed ?? 0}</td>
                              <td style={{ ...tdStyle, fontSize: 10, color: '#64748b', maxWidth: 280,
                                           whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}
                                  title={d.sample_error || ''}>
                                {d.sample_error || <em style={{ color: '#cbd5e1' }}>—</em>}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </details>
                  )}
                </div>
              )}
            </div>
            <button
              type="button"
              data-testid="fms-jobdiva-reindex-btn"
              onClick={() => handleReindex(false)}
              disabled={reindexBusy}
              className="btn btn--primary"
              style={{ whiteSpace: 'nowrap', fontSize: 13 }}>
              {reindexBusy ? 'Re-indexing…' : (joinedCount > 0 ? 'Re-index again' : 'Re-index now')}
            </button>
          </div>
        );
      })()}

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

      {/* Auto-map suggestion modal — opens from the ✨ Auto-map button.
          Rule-based proposals from /api/admin/integrations/suggest_mappings.php.
          Operator can toggle individual suggestions, Select-all / none, and
          apply the batch via the existing /field_map.php save endpoint. */}
      {suggestOpen && (
        <div data-testid="fms-suggest-modal"
             style={{ position: 'fixed', inset: 0, zIndex: 200,
                      background: 'rgba(15,23,42,0.45)',
                      display: 'flex', alignItems: 'flex-start', justifyContent: 'center',
                      padding: '40px 20px', overflow: 'auto' }}>
          <div style={{ background: '#fff', borderRadius: 12, width: '100%', maxWidth: 1080,
                        padding: 18, boxShadow: '0 12px 40px rgba(15,23,42,0.25)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18 }}>
                  ✨ Auto-mapping suggestions for <code>{integration}/{entityType}</code>
                </h3>
                <p style={{ margin: '4px 0 0', fontSize: 12, color: '#475569', maxWidth: 740 }}>
                  Rule-based proposals based on normalised field names + a CoreFlux synonym
                  dictionary (e.g. <code>firstName</code> → <code>first_name</code>,{' '}
                  <code>zipCode</code> → <code>postal_code</code>). Each row is independently
                  reviewable. High-confidence rows (≥ 0.85) are pre-selected — toggle anything
                  you don't want before applying.
                </p>
              </div>
              <button data-testid="fms-suggest-close" onClick={() => setSuggestOpen(false)}
                      className="btn btn--ghost" style={{ fontSize: 13 }}>Close</button>
            </div>

            <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
              <button data-testid="fms-suggest-reload" onClick={loadSuggestions}
                      disabled={suggestBusy}
                      className="btn btn--ghost" style={{ fontSize: 12 }}>
                {suggestBusy ? 'Loading…' : 'Reload suggestions'}
              </button>
              <button data-testid="fms-suggest-select-all"
                      onClick={() => {
                        const all = {}; suggestList.forEach((_, i) => { all[i] = true; });
                        setSuggestSelected(all);
                      }}
                      disabled={suggestBusy || suggestList.length === 0}
                      className="btn btn--ghost" style={{ fontSize: 12 }}>Select all</button>
              <button data-testid="fms-suggest-select-none"
                      onClick={() => setSuggestSelected({})}
                      disabled={suggestBusy || suggestList.length === 0}
                      className="btn btn--ghost" style={{ fontSize: 12 }}>Select none</button>
              <div style={{ flex: 1 }} />
              <span data-testid="fms-suggest-count"
                    style={{ fontSize: 12, color: '#475569' }}>
                {Object.values(suggestSelected).filter(Boolean).length} of {suggestList.length} selected
              </span>
              <button data-testid="fms-suggest-apply"
                      onClick={applySuggestions}
                      disabled={suggestApplying || suggestBusy
                                || Object.values(suggestSelected).filter(Boolean).length === 0}
                      className="btn btn--primary" style={{ fontSize: 13 }}>
                {suggestApplying ? 'Applying…' : 'Apply selected'}
              </button>
            </div>

            {suggestError && (
              <div data-testid="fms-suggest-error"
                   style={{ marginTop: 10, color: '#b91c1c', fontSize: 13 }}>{suggestError}</div>
            )}

            <div data-testid="fms-suggest-list"
                 style={{ marginTop: 12, maxHeight: 480, overflow: 'auto',
                          border: '1px solid #e2e8f0', borderRadius: 8 }}>
              {suggestList.length === 0 && !suggestBusy && (
                <p data-testid="fms-suggest-empty"
                   style={{ padding: 16, color: '#64748b', fontSize: 13 }}>
                  No new suggestions for <code>{integration}/{entityType}</code>. Either nothing's
                  been indexed yet, or every recognised field already has a mapping.
                </p>
              )}
              {suggestList.length > 0 && (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                  <thead style={{ background: '#f8fafc', position: 'sticky', top: 0 }}>
                    <tr>
                      <th style={thStyle}></th>
                      <th style={thStyle}>Source path</th>
                      <th style={thStyle}>Sample</th>
                      <th style={thStyle}>→ Target column</th>
                      <th style={thStyle}>Linked entity</th>
                      <th style={thStyle}>Transform</th>
                      <th style={thStyle}>Confidence</th>
                      <th style={thStyle}>Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    {suggestList.map((s, i) => {
                      const conf = Number(s.confidence) || 0;
                      const confColor = conf >= 0.9 ? '#16a34a'
                                       : conf >= 0.8 ? '#0ea5e9'
                                       : conf >= 0.5 ? '#d97706'
                                       : '#dc2626';
                      return (
                        <tr key={`${s.source_path}-${i}`}
                            data-testid={`fms-suggest-row-${i}`}
                            data-source-path={s.source_path}
                            style={{ borderTop: '1px solid #e2e8f0' }}>
                          <td style={tdStyle}>
                            <input type="checkbox"
                                   data-testid={`fms-suggest-check-${i}`}
                                   checked={!!suggestSelected[i]}
                                   onChange={e => setSuggestSelected(s2 => ({ ...s2, [i]: e.target.checked }))} />
                          </td>
                          <td style={tdStyle}><code>{s.source_path}</code></td>
                          <td style={{ ...tdStyle, color: '#475569', maxWidth: 200,
                                       whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}
                              title={s.sample_value || ''}>
                            {s.sample_value || <em style={{ color: '#94a3b8' }}>—</em>}
                          </td>
                          <td style={tdStyle}>
                            {(() => {
                              // Inline-edit dropdown: any writable target can
                              // be picked, scoped to the same target_module
                              // first so the operator sees the most relevant
                              // columns at the top.
                              const currentKey = `${s.target_module}|${s.target_table}|${s.target_column}`;
                              const allOpts = (targets || []).filter(t =>
                                t.target_column && t.target_column !== '*'
                              );
                              const sameModule = allOpts.filter(t => t.target_module === s.target_module);
                              const otherModule = allOpts.filter(t => t.target_module !== s.target_module);
                              const opts = [...sameModule, ...otherModule];
                              // Ensure the current selection is present even
                              // if it isn't in the writable_targets list yet.
                              const hasCurrent = opts.some(t =>
                                `${t.target_module}|${t.target_table}|${t.target_column}` === currentKey);
                              if (!hasCurrent) {
                                opts.unshift({
                                  target_module: s.target_module,
                                  target_table:  s.target_table,
                                  target_column: s.target_column,
                                });
                              }
                              return (
                                <select
                                  data-testid={`fms-suggest-target-${i}`}
                                  data-current={currentKey}
                                  value={currentKey}
                                  onChange={e => {
                                    const [m, tbl, col] = e.target.value.split('|');
                                    setSuggestList(list => list.map((row, idx) =>
                                      idx === i
                                        ? { ...row, target_module: m, target_table: tbl, target_column: col, _edited: true }
                                        : row
                                    ));
                                  }}
                                  className="input"
                                  style={{ minWidth: 280, fontSize: 12, padding: '4px 6px' }}
                                >
                                  {opts.map(t => {
                                    const key = `${t.target_module}|${t.target_table}|${t.target_column}`;
                                    return (
                                      <option key={key} value={key}>
                                        {t.target_module}.{t.target_table}.{t.target_column}
                                      </option>
                                    );
                                  })}
                                </select>
                              );
                            })()}
                            {s._edited && (
                              <span data-testid={`fms-suggest-edited-${i}`}
                                    style={{ marginLeft: 6, fontSize: 10, color: '#0ea5e9' }}>
                                edited
                              </span>
                            )}
                          </td>
                          <td style={tdStyle}>
                            <select
                              data-testid={`fms-suggest-linked-${i}`}
                              value={s.linked_entity}
                              onChange={e => {
                                const v = e.target.value;
                                setSuggestList(list => list.map((row, idx) =>
                                  idx === i ? { ...row, linked_entity: v, _edited: true } : row
                                ));
                              }}
                              className="input"
                              style={{ fontSize: 12, padding: '4px 6px' }}
                            >
                              {Object.entries(LINKED_ENTITY_LABELS).map(([k, label]) =>
                                <option key={k} value={k}>{k}</option>
                              )}
                            </select>
                          </td>
                          <td style={tdStyle}>
                            <select
                              data-testid={`fms-suggest-transform-${i}`}
                              value={s.transform || 'none'}
                              onChange={e => {
                                const v = e.target.value;
                                setSuggestList(list => list.map((row, idx) =>
                                  idx === i ? { ...row, transform: v, _edited: true } : row
                                ));
                              }}
                              className="input"
                              style={{ fontSize: 12, padding: '4px 6px' }}
                            >
                              {['none', 'lowercase', 'uppercase', 'trim', 'date_normalise', 'json_decode'].map(t =>
                                <option key={t} value={t}>{t}</option>
                              )}
                            </select>
                          </td>
                          <td style={{ ...tdStyle, color: confColor, fontWeight: 600 }}>
                            {(conf * 100).toFixed(0)}%
                          </td>
                          <td style={{ ...tdStyle, color: '#64748b' }}>{s.reason}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}

      {/* CSV upload modal — fallback path for integrations whose REST
          enrichment endpoints aren't reachable on the operator's
          tenant. The operator drops a CSV export, every column
          becomes a first-class indexed path under their chosen
          entity_type, and the Auto-map suggester picks it up. */}
      {csvOpen && (
        <div data-testid="fms-csv-modal"
             style={{ position: 'fixed', inset: 0, zIndex: 200,
                      background: 'rgba(15,23,42,0.45)',
                      display: 'flex', alignItems: 'flex-start', justifyContent: 'center',
                      padding: '60px 20px', overflow: 'auto' }}>
          <div style={{ background: '#fff', borderRadius: 12, width: '100%', maxWidth: 640,
                        padding: 18, boxShadow: '0 12px 40px rgba(15,23,42,0.25)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18 }}>📄 Upload a CSV export</h3>
                <p style={{ margin: '4px 0 0', fontSize: 12, color: '#475569', maxWidth: 540 }}>
                  Drop any CSV export (JobDiva Job list, Candidate list, Customer list, Airtable
                  view, QBO report, anything). The header row becomes the field names, every column
                  becomes a mappable path under the chosen entity type — exactly as if the data
                  had come from a live API sync. Use this when an integration's REST endpoints
                  aren't reachable on your tenant.
                </p>
              </div>
              <button data-testid="fms-csv-close"
                      onClick={() => setCsvOpen(false)}
                      className="btn btn--ghost" style={{ fontSize: 13 }}>Close</button>
            </div>

            <div style={{ marginTop: 14, display: 'grid', gap: 10 }}>
              <label style={{ fontSize: 12, color: '#475569' }}>
                Integration
                <input data-testid="fms-csv-integration"
                       value={integration} disabled
                       className="input"
                       style={{ display: 'block', marginTop: 4, fontSize: 13, background: '#f8fafc' }} />
              </label>
              <label style={{ fontSize: 12, color: '#475569' }}>
                Entity type
                <input data-testid="fms-csv-entity"
                       value={csvEntity}
                       onChange={e => setCsvEntity(e.target.value.replace(/[^a-z0-9_]/g, '').toLowerCase())}
                       placeholder="e.g. job, person, jobdiva_customer"
                       className="input"
                       style={{ display: 'block', marginTop: 4, fontSize: 13 }} />
                <span style={{ fontSize: 11, color: '#94a3b8' }}>
                  lowercase letters / digits / underscores only — this is the key the picker
                  groups paths under (will be created if it doesn't exist yet)
                </span>
              </label>
              <label style={{ fontSize: 12, color: '#475569' }}>
                CSV file
                <input data-testid="fms-csv-file"
                       type="file" accept=".csv,text/csv,text/plain"
                       onChange={e => setCsvFile(e.target.files?.[0] || null)}
                       style={{ display: 'block', marginTop: 4, fontSize: 13 }} />
                <span style={{ fontSize: 11, color: '#94a3b8' }}>
                  max 25 MB · UTF-8 BOM accepted · header row required
                </span>
              </label>
            </div>

            {csvError && (
              <div data-testid="fms-csv-error"
                   style={{ marginTop: 10, color: '#b91c1c', fontSize: 13 }}>{csvError}</div>
            )}

            {csvResult && (
              <div data-testid="fms-csv-result"
                   style={{ marginTop: 12, padding: 10, background: '#ecfdf5',
                            border: '1px solid #86efac', borderRadius: 8, fontSize: 12 }}>
                <strong>Done.</strong> Indexed{' '}
                <strong>{csvResult.rows_indexed}</strong> of {csvResult.rows_seen} row(s){' '}
                · {csvResult.field_count} column(s) became mappable paths{' '}
                under <code>{csvResult.integration}/{csvResult.entity_type}</code>.{' '}
                {csvResult.rows_skipped > 0 && <em>({csvResult.rows_skipped} skipped)</em>}
                {Array.isArray(csvResult.sample_headers) && csvResult.sample_headers.length > 0 && (
                  <div style={{ marginTop: 6, color: '#475569' }}>
                    Sample headers: {csvResult.sample_headers.map((h, i) =>
                      <code key={i} style={{ marginRight: 6 }}>{h}</code>
                    )}
                  </div>
                )}
                {Array.isArray(csvResult.errors) && csvResult.errors.length > 0 && (
                  <details style={{ marginTop: 6 }}>
                    <summary style={{ cursor: 'pointer', color: '#b91c1c' }}>
                      {csvResult.errors.length} row error(s) — click to see
                    </summary>
                    <ul style={{ margin: '4px 0 0 16px', padding: 0 }}>
                      {csvResult.errors.map((er, i) => <li key={i} style={{ fontSize: 11 }}>{er}</li>)}
                    </ul>
                  </details>
                )}
              </div>
            )}

            <div style={{ marginTop: 14, display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
              <button data-testid="fms-csv-submit"
                      onClick={submitCsv}
                      disabled={csvBusy || !csvFile || !csvEntity}
                      className="btn btn--primary" style={{ fontSize: 13 }}>
                {csvBusy ? 'Indexing…' : 'Index CSV'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* === SOURCE PAYLOAD INSPECTOR (read-only) ============================
          Browse every indexed source field across every entity bucket
          for the current integration. Helps operators see what fields
          are actually flowing in from JobDiva BEFORE picking what to
          map. Selecting a row pre-fills the mapping form on close. */}
      {inspectorOpen && (() => {
        const seen = sources
          .filter(s => s.integration === integration)
          .map(s => ({ et: s.entity_type, count: Number(s.path_count) || 0 }))
          .sort((a, b) => b.count - a.count);
        const LABELS = {
          placement: 'Placement', person: 'Person', job: 'Job',
          jobdiva_customer: 'Customer', customer: 'Customer',
          contact: 'Contact', assignment: 'Assignment', company: 'Company',
          journal_entry: 'Journal Entry', vendor: 'Vendor', invoice: 'Invoice',
          bill: 'Bill', payment: 'Payment', gl_account: 'GL Account',
          item: 'Item', time_entry: 'Time Entry', record: 'Record',
        };
        return (
          <div data-testid="fms-inspector-overlay"
               role="dialog" aria-modal="true"
               style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.5)',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        padding: 24, zIndex: 100 }}
               onClick={(e) => { if (e.target === e.currentTarget) setInspectorOpen(false); }}>
            <div data-testid="fms-inspector-modal"
                 style={{ background: '#fff', borderRadius: 12, padding: 20,
                          width: 'min(960px, 96vw)', maxHeight: '88vh',
                          display: 'flex', flexDirection: 'column', gap: 12,
                          boxShadow: '0 10px 40px rgba(0,0,0,0.18)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <div>
                  <h3 style={{ margin: 0, fontSize: 18 }}>Source Payload Inspector</h3>
                  <p style={{ color: '#64748b', fontSize: 12, margin: '4px 0 0' }}>
                    Read-only browse of every indexed field across every entity bucket for{' '}
                    <code style={{ background: '#f1f5f9', padding: '1px 6px', borderRadius: 4 }}>{integration}</code>.{' '}
                    Click a row to use it in the main mapping form.
                  </p>
                </div>
                <button data-testid="fms-inspector-close"
                        onClick={() => setInspectorOpen(false)}
                        className="btn btn--ghost"
                        style={{ fontSize: 12, padding: '4px 10px' }}>✕</button>
              </div>

              {/* Inspector entity tabs */}
              <div data-testid="fms-inspector-tabs"
                   style={{ display: 'flex', flexWrap: 'wrap', gap: 6,
                            paddingBottom: 8, borderBottom: '1px solid #e2e8f0' }}>
                {seen.length === 0 && (
                  <span style={{ fontSize: 12, color: '#94a3b8' }}>
                    No indexed payloads yet for <code>{integration}</code>. Trigger a sync first.
                  </span>
                )}
                {seen.map(o => {
                  const active = o.et === inspectorEntity;
                  return (
                    <button
                      key={o.et}
                      type="button"
                      data-testid={`fms-inspector-tab-${o.et}`}
                      data-active={active ? 'true' : 'false'}
                      onClick={() => switchInspectorEntity(o.et)}
                      style={{
                        padding: '5px 11px', borderRadius: 999,
                        border: '1px solid ' + (active ? '#2563eb' : '#cbd5e1'),
                        background: active ? '#2563eb' : '#fff',
                        color: active ? '#fff' : '#0f172a',
                        cursor: 'pointer', fontSize: 12,
                        display: 'inline-flex', alignItems: 'center', gap: 6,
                      }}>
                      <span>{LABELS[o.et] || o.et}</span>
                      <span style={{
                        fontSize: 11, padding: '1px 7px', borderRadius: 999,
                        background: active ? 'rgba(255,255,255,0.22)' : '#e0f2fe',
                        color: active ? '#fff' : '#0369a1',
                      }}>{o.count}</span>
                    </button>
                  );
                })}
              </div>

              <input
                data-testid="fms-inspector-filter"
                type="text" className="input"
                placeholder={inspectorGlobal ? "search EVERY entity bucket… (e.g. 'pay rate')" : "filter source paths or sample values…"}
                value={inspectorFilter}
                onChange={e => setInspectorFilter(e.target.value)}
                style={{ fontSize: 13 }}
              />

              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <label data-testid="fms-inspector-global-toggle-label"
                       style={{ display: 'inline-flex', alignItems: 'center', gap: 6,
                                fontSize: 12, color: '#475569', cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    data-testid="fms-inspector-global-toggle"
                    checked={inspectorGlobal}
                    onChange={toggleInspectorGlobal}
                  />
                  🌐 Search across <strong>every</strong> entity bucket
                </label>
                {inspectorGlobal && (
                  <span style={{ fontSize: 11, color: '#0369a1' }}>
                    Showing matches from {Object.keys(inspectorGlobalMap).length} bucket(s)
                  </span>
                )}
              </div>

              <div data-testid="fms-inspector-list"
                   style={{ flex: 1, overflowY: 'auto', border: '1px solid #e2e8f0', borderRadius: 6 }}>
                {inspectorBusy && (
                  <div style={{ padding: 20, color: '#64748b', fontSize: 13 }}>Loading paths…</div>
                )}

                {/* Global mode — flatten paths_by_entity, filter, show entity column */}
                {!inspectorBusy && inspectorGlobal && (() => {
                  const q = inspectorFilter.toLowerCase();
                  const rows = [];
                  Object.entries(inspectorGlobalMap).forEach(([et, list]) => {
                    (list || []).forEach(p => {
                      if (!q
                          || (p.source_path || '').toLowerCase().includes(q)
                          || String(p.sample_value || '').toLowerCase().includes(q)) {
                        rows.push({ ...p, _et: et });
                      }
                    });
                  });
                  if (rows.length === 0) {
                    return (
                      <div data-testid="fms-inspector-empty"
                           style={{ padding: 20, color: '#64748b', fontSize: 13 }}>
                        No paths match this filter across any bucket.
                      </div>
                    );
                  }
                  return (
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                      <thead style={{ position: 'sticky', top: 0, background: '#f8fafc', zIndex: 1 }}>
                        <tr>
                          <th style={{ ...thStyle, width: '15%' }}>Bucket</th>
                          <th style={{ ...thStyle, width: '32%' }}>Source path</th>
                          <th style={{ ...thStyle, width: '10%' }}>Type</th>
                          <th style={{ ...thStyle, width: '35%' }}>Sample value</th>
                          <th style={{ ...thStyle, width: '8%', textAlign: 'right' }}>Seen</th>
                        </tr>
                      </thead>
                      <tbody>
                        {rows.slice(0, 500).map((p, i) => (
                          <tr key={`${p._et}-${p.source_path}-${i}`}
                              data-testid={`fms-inspector-global-row-${i}`}
                              data-entity={p._et}
                              onClick={() => {
                                setInspectorEntity(p._et);
                                useInspectedPath(p);
                              }}
                              style={{ borderTop: '1px solid #f1f5f9', cursor: 'pointer' }}
                              onMouseEnter={e => e.currentTarget.style.background = '#f8fafc'}
                              onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                            <td style={{ ...tdStyle }}>
                              <span style={{
                                fontSize: 10, padding: '1px 6px', borderRadius: 999,
                                background: '#e0f2fe', color: '#0369a1',
                              }}>{p._et}</span>
                            </td>
                            <td style={{ ...tdStyle, fontFamily: 'ui-monospace, monospace', fontSize: 11 }}>{p.source_path}</td>
                            <td style={{ ...tdStyle, color: '#64748b' }}>{p.value_type || '—'}</td>
                            <td style={{ ...tdStyle, color: '#475569',
                                         whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 0 }}
                                title={String(p.sample_value ?? '')}>
                              {p.sample_value === null || p.sample_value === undefined || p.sample_value === ''
                                ? <em style={{ color: '#94a3b8' }}>empty</em>
                                : String(p.sample_value)}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', color: '#94a3b8' }}>{p.occurrence_count ?? 0}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  );
                })()}

                {/* Per-bucket mode — original behavior */}
                {!inspectorBusy && !inspectorGlobal && (() => {
                  const q = inspectorFilter.toLowerCase();
                  const visible = !q ? inspectorPaths : inspectorPaths.filter(p =>
                    (p.source_path || '').toLowerCase().includes(q)
                    || String(p.sample_value || '').toLowerCase().includes(q)
                  );
                  if (visible.length === 0) {
                    return (
                      <div data-testid="fms-inspector-empty"
                           style={{ padding: 20, color: '#64748b', fontSize: 13 }}>
                        No paths {inspectorFilter ? 'match this filter' : `indexed yet for ${inspectorEntity}`}.
                      </div>
                    );
                  }
                  return (
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                      <thead style={{ position: 'sticky', top: 0, background: '#f8fafc', zIndex: 1 }}>
                        <tr>
                          <th style={{ ...thStyle, width: '38%' }}>Source path</th>
                          <th style={{ ...thStyle, width: '12%' }}>Type</th>
                          <th style={{ ...thStyle, width: '40%' }}>Sample value</th>
                          <th style={{ ...thStyle, width: '10%', textAlign: 'right' }}>Seen</th>
                        </tr>
                      </thead>
                      <tbody>
                        {visible.map((p, i) => (
                          <tr key={i}
                              data-testid={`fms-inspector-row-${i}`}
                              onClick={() => useInspectedPath(p)}
                              style={{ borderTop: '1px solid #f1f5f9', cursor: 'pointer' }}
                              onMouseEnter={e => e.currentTarget.style.background = '#f8fafc'}
                              onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                            <td style={{ ...tdStyle, fontFamily: 'ui-monospace, monospace', fontSize: 11 }}>{p.source_path}</td>
                            <td style={{ ...tdStyle, color: '#64748b' }}>{p.value_type || '—'}</td>
                            <td style={{ ...tdStyle, color: '#475569',
                                         whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 0 }}
                                title={String(p.sample_value ?? '')}>
                              {p.sample_value === null || p.sample_value === undefined || p.sample_value === ''
                                ? <em style={{ color: '#94a3b8' }}>empty</em>
                                : String(p.sample_value)}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', color: '#94a3b8' }}>{p.occurrence_count ?? 0}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  );
                })()}
              </div>
              <div style={{ fontSize: 11, color: '#64748b', textAlign: 'right' }}>
                {inspectorGlobal
                  ? `${Object.values(inspectorGlobalMap).reduce((sum, l) => sum + (l?.length || 0), 0)} total paths across ${Object.keys(inspectorGlobalMap).length} bucket(s) — click a row to use it`
                  : `${inspectorPaths.length} path${inspectorPaths.length === 1 ? '' : 's'} ${inspectorPaths.length > 0 ? '— click a row to use it' : ''}`}
              </div>
            </div>
          </div>
        );
      })()}
      {/* === RAW JOBDIVA PAYLOAD DIAGNOSTIC ==================================
          Shows what JobDiva actually returned for the most-recent
          placement. Critical when an entity bucket has surprisingly
          few mappable paths — proves whether the gap is on the
          JobDiva side (account permissions / sparse response) or
          ours (extractor missing fields). */}
      {rawOpen && (
        <div data-testid="fms-raw-overlay"
             role="dialog" aria-modal="true"
             style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.5)',
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      padding: 24, zIndex: 101 }}
             onClick={(e) => { if (e.target === e.currentTarget) setRawOpen(false); }}>
          <div data-testid="fms-raw-modal"
               style={{ background: '#fff', borderRadius: 12, padding: 20,
                        width: 'min(880px, 96vw)', maxHeight: '88vh',
                        display: 'flex', flexDirection: 'column', gap: 12,
                        boxShadow: '0 10px 40px rgba(0,0,0,0.18)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18 }}>What JobDiva actually returned</h3>
                <p style={{ color: '#64748b', fontSize: 12, margin: '4px 0 0', maxWidth: 620 }}>
                  Raw <code>payload_snapshot</code> from the most-recent placement sync — including every{' '}
                  <code>_jd_*</code> enrichment bucket. If a bucket shows few keys here, that's what
                  JobDiva sent us. The fix is on the JobDiva side (account field permissions / endpoint
                  scope), not in CoreFlux.
                </p>
              </div>
              <button data-testid="fms-raw-close"
                      onClick={() => setRawOpen(false)}
                      className="btn btn--ghost"
                      style={{ fontSize: 12, padding: '4px 10px' }}>✕</button>
            </div>

            {rawBusy && <div style={{ padding: 20, color: '#64748b', fontSize: 13 }}>Loading raw payload…</div>}
            {rawError && (
              <div data-testid="fms-raw-error"
                   style={{ padding: 12, background: '#fef2f2', color: '#991b1b',
                            border: '1px solid #fca5a5', borderRadius: 6, fontSize: 12 }}>
                {rawError}
              </div>
            )}

            {!rawBusy && rawData && (
              <div data-testid="fms-raw-content"
                   style={{ flex: 1, overflowY: 'auto', display: 'flex',
                            flexDirection: 'column', gap: 12 }}>
                <div style={{ fontSize: 12, color: '#475569' }}>
                  Showing placement <strong data-testid="fms-raw-extid">{rawData.external_id}</strong>{' '}
                  · last updated <code style={{ fontSize: 11 }}>{rawData.updated_at}</code>
                </div>

                <div data-testid="fms-raw-stats"
                     style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 12 }}>
                  <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>
                    Per-bucket breakdown
                  </div>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                    <thead>
                      <tr style={{ background: '#f8fafc' }}>
                        <th style={{ ...thStyle, fontSize: 11 }}>Bucket</th>
                        <th style={{ ...thStyle, fontSize: 11, textAlign: 'right' }}>Present?</th>
                        <th style={{ ...thStyle, fontSize: 11, textAlign: 'right' }}>Field count</th>
                        <th style={{ ...thStyle, fontSize: 11 }}>Top keys</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr style={{ borderTop: '1px solid #f1f5f9' }}>
                        <td style={{ ...tdStyle }}><strong>Placement (top-level scalars)</strong></td>
                        <td style={{ ...tdStyle, textAlign: 'right' }}>✓</td>
                        <td style={{ ...tdStyle, textAlign: 'right', fontWeight: 600 }}
                            data-testid="fms-raw-top-count">
                          {rawData.stats?.top_level_scalar_field_count ?? '—'}
                        </td>
                        <td style={{ ...tdStyle, color: '#475569', fontSize: 11 }}>
                          {(rawData.stats?.top_level_scalar_keys || []).slice(0, 8).join(', ')}
                          {(rawData.stats?.top_level_scalar_keys || []).length > 8 && ' …'}
                        </td>
                      </tr>
                      {Object.entries(rawData.stats?.buckets || {}).map(([bucket, info]) => {
                        const lowFields = info.present && info.field_count <= 2;
                        return (
                          <tr key={bucket}
                              data-testid={`fms-raw-bucket-${bucket}`}
                              data-low-fields={lowFields ? 'yes' : 'no'}
                              style={{ borderTop: '1px solid #f1f5f9',
                                       background: !info.present ? '#fef2f2'
                                                 : lowFields    ? '#fef9c3'
                                                 : 'transparent' }}>
                            <td style={{ ...tdStyle }}>
                              <code style={{ fontSize: 11 }}>{bucket}</code>
                              {!info.present && <span style={{ marginLeft: 6, color: '#b91c1c', fontSize: 11 }}>not returned by JobDiva enrichment</span>}
                              {lowFields && <span style={{ marginLeft: 6, color: '#a16207', fontSize: 11 }}>sparse — JobDiva returned ≤2 fields</span>}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right' }}>{info.present ? '✓' : '✗'}</td>
                            <td style={{ ...tdStyle, textAlign: 'right', fontWeight: lowFields ? 700 : 500,
                                         color: lowFields ? '#a16207' : '#0f172a' }}>
                              {info.field_count}
                            </td>
                            <td style={{ ...tdStyle, color: '#475569', fontSize: 11 }}>
                              {(info.keys || []).slice(0, 8).join(', ')}
                              {(info.keys || []).length > 8 && ' …'}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                  <div style={{ marginTop: 10, fontSize: 11, color: '#64748b' }}>
                    Red rows = JobDiva's <code>/searchJob</code>, <code>/searchCandidate</code>, <code>/searchCustomer</code>,
                    {' '}<code>/searchContact</code> or <code>/searchStart</code> endpoint didn't return data for this tenant.
                    That's a JobDiva account-permission issue on those endpoints — but read the
                    <strong> "What we extracted from flat fields" </strong>panel below: CoreFlux ALSO walks the placement's
                    own flat top-level scalars to fan-out joined-entity data, so you're not necessarily stuck.
                  </div>
                </div>

                {/* What our flat extractor produced — the actual source of
                    mappable fields when JobDiva enrichment is absent. */}
                {rawData.stats?.extracted_into_buckets && (
                  <div data-testid="fms-raw-extracted"
                       style={{ border: '1px solid #bae6fd', borderRadius: 8, padding: 12, background: '#f0f9ff' }}>
                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8, color: '#0c4a6e' }}>
                      What CoreFlux extracted from flat top-level keys
                    </div>
                    <div style={{ fontSize: 11, color: '#0c4a6e', marginBottom: 8 }}>
                      JobDiva V2 BI carries joined-entity fields as flat keys (e.g.
                      <code style={{ margin: '0 4px' }}>candidate id</code>,
                      <code style={{ margin: '0 4px' }}>start pay rate</code>). CoreFlux strips the
                      prefix and routes them into the right bucket — even when JobDiva's enrichment
                      endpoints return nothing.
                    </div>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                      <thead>
                        <tr style={{ background: '#e0f2fe' }}>
                          <th style={{ ...thStyle, fontSize: 11 }}>Bucket</th>
                          <th style={{ ...thStyle, fontSize: 11, textAlign: 'right' }}>Extracted</th>
                          <th style={{ ...thStyle, fontSize: 11 }}>Keys</th>
                        </tr>
                      </thead>
                      <tbody>
                        {Object.entries(rawData.stats.extracted_into_buckets).map(([bucket, info]) => (
                          <tr key={bucket}
                              data-testid={`fms-raw-extracted-${bucket}`}
                              style={{ borderTop: '1px solid #bae6fd' }}>
                            <td style={{ ...tdStyle }}>
                              <code style={{ fontSize: 11 }}>{bucket}</code>
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', fontWeight: 600,
                                         color: info.field_count === 0 ? '#94a3b8' : '#0369a1' }}>
                              {info.field_count}
                            </td>
                            <td style={{ ...tdStyle, color: '#0c4a6e', fontSize: 11 }}>
                              {(info.keys || []).slice(0, 10).join(', ')}
                              {(info.keys || []).length > 10 && ` …(+${info.keys.length - 10})`}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                <div>
                  <button
                    type="button"
                    data-testid="fms-raw-toggle-json"
                    onClick={() => setRawShowJson(s => !s)}
                    className="btn btn--ghost"
                    style={{ fontSize: 12 }}>
                    {rawShowJson ? 'Hide full JSON' : 'Show full JSON'}
                  </button>
                </div>
                {rawShowJson && (
                  <pre data-testid="fms-raw-json"
                       style={{ background: '#0f172a', color: '#e2e8f0', padding: 14,
                                borderRadius: 8, fontSize: 11, overflow: 'auto',
                                maxHeight: 360, margin: 0 }}>
                    {JSON.stringify(rawData.payload, null, 2)}
                  </pre>
                )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* === 🔎 JOBDIVA ENDPOINT PROBE ========================================
          Runs the /api/admin/integrations/jobdiva_probe.php battery and
          shows the raw HTTP status + item count + body preview for every
          endpoint. Lets the operator see which endpoints JobDiva's
          per-tenant auth scope actually grants — without us guessing. */}
      {diagOpen && (
        <div data-testid="fms-diag-overlay"
             role="dialog" aria-modal="true"
             style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.5)',
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      padding: 24, zIndex: 102 }}
             onClick={(e) => { if (e.target === e.currentTarget) setDiagOpen(false); }}>
          <div data-testid="fms-diag-modal"
               style={{ background: '#fff', borderRadius: 12, padding: 20,
                        width: 'min(1040px, 96vw)', maxHeight: '90vh',
                        display: 'flex', flexDirection: 'column', gap: 12,
                        boxShadow: '0 10px 40px rgba(0,0,0,0.18)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18 }}>🔎 JobDiva endpoint diagnostic</h3>
                <p style={{ color: '#64748b', fontSize: 12, margin: '4px 0 0', maxWidth: 720 }}>
                  Probes 6-8 JobDiva V2 BI endpoints with sample params and shows the raw HTTP
                  response. Look for: <code>status=200</code> with <code>item_count &gt; 0</code> = endpoint live.
                  <code> status=403</code> or <code>item_count = 0</code> with a body like
                  <code style={{ margin: '0 4px' }}>{'"No records found"'}</code> = your JobDiva API user
                  lacks scope on that endpoint (talk to your JobDiva admin).
                </p>
              </div>
              <button data-testid="fms-diag-close"
                      onClick={() => setDiagOpen(false)}
                      className="btn btn--ghost"
                      style={{ fontSize: 12, padding: '4px 10px' }}>✕</button>
            </div>

            {diagBusy && <div style={{ padding: 20, color: '#64748b', fontSize: 13 }}>Probing JobDiva endpoints…</div>}
            {diagError && (
              <div data-testid="fms-diag-error"
                   style={{ padding: 12, background: '#fef2f2', color: '#991b1b',
                            border: '1px solid #fca5a5', borderRadius: 6, fontSize: 12 }}>
                {diagError}
              </div>
            )}

            {!diagBusy && diagResults && (
              <div data-testid="fms-diag-content"
                   style={{ flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8 }}>
                <div style={{ fontSize: 12, color: '#475569' }}>
                  Date range probed: <code>{diagResults.from_date}</code> → <code>{diagResults.to_date}</code>
                  · <strong>{(diagResults.probes || []).length}</strong> endpoints
                </div>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                  <thead>
                    <tr style={{ background: '#f8fafc' }}>
                      <th style={{ ...thStyle, width: '38%' }}>Endpoint</th>
                      <th style={{ ...thStyle, width: '10%', textAlign: 'right' }}>Status</th>
                      <th style={{ ...thStyle, width: '10%', textAlign: 'right' }}>Items</th>
                      <th style={{ ...thStyle, width: '11%', textAlign: 'right' }}>Body size</th>
                      <th style={{ ...thStyle, width: '11%', textAlign: 'right' }}>Latency</th>
                      <th style={{ ...thStyle, width: '20%' }}>Outcome</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(diagResults.probes || []).map((p, i) => {
                      const ok = p.status === 200 && (p.item_count ?? 0) > 0;
                      const empty = p.status === 200 && (p.item_count ?? 0) === 0 && !p.error;
                      const err = !!p.error || (p.status && p.status >= 400);
                      const bg = ok ? '#f0fdf4' : err ? '#fef2f2' : empty ? '#fef9c3' : 'transparent';
                      const outcome = ok ? '✓ live & populated' : err ? `✗ error${p.status ? ` (${p.status})` : ''}` : empty ? '⚠️ live but empty' : '—';
                      const isExpanded = !!diagExpanded[i];
                      return (
                        <>
                          <tr key={i}
                              data-testid={`fms-diag-row-${i}`}
                              data-outcome={ok ? 'ok' : err ? 'error' : 'empty'}
                              onClick={() => setDiagExpanded(d => ({ ...d, [i]: !d[i] }))}
                              style={{ borderTop: '1px solid #f1f5f9', background: bg, cursor: 'pointer' }}>
                            <td style={{ ...tdStyle, fontFamily: 'ui-monospace, monospace', fontSize: 11 }}>
                              <span style={{ marginRight: 4 }}>{isExpanded ? '▼' : '▶'}</span>
                              {p.name || p.path}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', fontWeight: 600,
                                         color: ok ? '#15803d' : err ? '#b91c1c' : empty ? '#a16207' : '#64748b' }}>
                              {p.status ?? '—'}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', fontWeight: 600 }}
                                data-testid={`fms-diag-row-${i}-count`}>
                              {p.item_count ?? '—'}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', color: '#64748b' }}>
                              {p.body_size ? `${p.body_size.toLocaleString()} B` : '—'}
                            </td>
                            <td style={{ ...tdStyle, textAlign: 'right', color: '#64748b' }}>
                              {p.latency_ms ? `${p.latency_ms} ms` : '—'}
                            </td>
                            <td style={{ ...tdStyle, fontSize: 11,
                                         color: ok ? '#15803d' : err ? '#b91c1c' : empty ? '#a16207' : '#64748b' }}>
                              {outcome}
                            </td>
                          </tr>
                          {isExpanded && (
                            <tr key={`${i}-detail`} style={{ background: '#fafafa' }}>
                              <td colSpan={6} style={{ padding: 12, borderTop: '1px solid #f1f5f9' }}>
                                <div style={{ fontSize: 11, color: '#475569', marginBottom: 6 }}>
                                  <strong>GET</strong>{' '}
                                  <code style={{ fontFamily: 'ui-monospace, monospace' }}>
                                    {p.path}{p.query && Object.keys(p.query).length > 0 ? '?' + Object.entries(p.query).map(([k,v]) => `${k}=${v}`).join('&') : ''}
                                  </code>
                                  {p.li_uuid && <span style={{ marginLeft: 10, color: '#64748b' }}>li-uuid: <code>{p.li_uuid}</code></span>}
                                </div>
                                {p.note && (
                                  <div style={{ fontSize: 11, color: '#0c4a6e', marginBottom: 6,
                                                padding: 6, background: '#f0f9ff', borderRadius: 4 }}>
                                    ℹ️ {p.note}
                                  </div>
                                )}
                                {p.error && (
                                  <pre data-testid={`fms-diag-row-${i}-error`}
                                       style={{ background: '#7f1d1d', color: '#fecaca',
                                                padding: 10, borderRadius: 4, fontSize: 11,
                                                margin: 0, whiteSpace: 'pre-wrap', overflow: 'auto', maxHeight: 200 }}>
                                    {p.error}
                                  </pre>
                                )}
                                {p.body_preview && (
                                  <pre data-testid={`fms-diag-row-${i}-body`}
                                       style={{ background: '#0f172a', color: '#e2e8f0',
                                                padding: 10, borderRadius: 4, fontSize: 11,
                                                margin: 0, whiteSpace: 'pre-wrap', overflow: 'auto', maxHeight: 260 }}>
                                    {p.body_preview}
                                    {p.body_size > 800 && <span style={{ color: '#64748b' }}>{`\n…(${p.body_size - 800} more bytes truncated)`}</span>}
                                  </pre>
                                )}
                              </td>
                            </tr>
                          )}
                        </>
                      );
                    })}
                  </tbody>
                </table>
                <div style={{ fontSize: 11, color: '#64748b' }}>
                  Click any row to expand the request URL + raw response body.
                </div>
              </div>
            )}
          </div>
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
const thStyle = {
  textAlign: 'left', padding: '8px 10px', fontSize: 11, fontWeight: 600,
  color: '#475569', borderBottom: '1px solid #e2e8f0',
};
const tdStyle = {
  padding: '8px 10px', verticalAlign: 'top', fontSize: 12, color: '#1e293b',
};

