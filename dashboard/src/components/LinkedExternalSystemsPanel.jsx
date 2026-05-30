import React, { useState } from 'react';
import { api, useApi } from '../lib/api';
import { Link2, AlertCircle, CheckCircle2, Clock, ExternalLink, ChevronRight, ChevronDown, Sparkles, X, Pencil, Trash2, Plus, Save } from 'lucide-react';

/**
 * Linked External Systems panel — full per-record mapping list with an
 * expandable per-source detail row that surfaces:
 *   - Curated identifier fields from payload_snapshot (Job #, Candidate
 *     ID, Job Title, …) — picked per (source_system, entity_type) from
 *     SOURCE_ID_FIELDS so operators don't have to dig through raw JSON
 *     for routine information.
 *   - Collapsible raw payload viewer for full inspection.
 *
 * Reads /api/integrations/mappings.php?action=list_for_internal which now
 * returns payload_snapshot alongside the mapping metadata.
 *
 * Sibling to <ConnectedSourcesBadge /> (the inline-pill variant used in
 * lists). This panel goes on the entity detail page.
 */
const SOURCE_LABEL = {
  jobdiva: 'JobDiva',
  bullhorn: 'Bullhorn',
  greenhouse: 'Greenhouse',
  quickbooks: 'QuickBooks',
  zoho_books: 'Zoho Books',
  airtable: 'Airtable',
};

// Per (source, entity_type) curated lookup. Candidate keys are matched
// case- and separator-insensitively against the raw payload (mirrors
// the backend jobdivaPluckField normalisation).
const SOURCE_ID_FIELDS = {
  jobdiva: {
    placement: [
      ['Start ID',        ['startId', 'id']],
      ['JobDiva Job #',   ['jobNumber', 'job_number', 'jobId', 'job_id', 'jobNum']],
      ['Job Title',       ['jobTitle', 'job_title', 'title', 'positionTitle']],
      ['Candidate ID',    ['candidateId', 'candidate_id', 'employeeId', 'employee_id']],
      ['Candidate Name',  ['candidateName', 'candidate_name']],
      ['Company',         ['companyName', 'company_name', 'endClientName']],
      ['Start Date',      ['startDate', 'start_date']],
      ['End Date',        ['endDate',   'end_date']],
      ['Bill Rate',       ['billRate',  'bill_rate']],
      ['Pay Rate',        ['payRate',   'pay_rate']],
    ],
    person: [
      ['Candidate ID',   ['id', 'candidateId', 'candidate_id', 'employeeId']],
      ['Email',          ['email', 'candidateEmail']],
      ['Phone',          ['phone', 'candidatePhone', 'phone 1']],
    ],
    company: [
      ['Company ID',     ['id', 'companyId', 'company_id']],
      ['Company Name',   ['name', 'companyName', 'company_name']],
    ],
    contact: [
      ['Contact ID',     ['id', 'contactId', 'contact_id']],
      ['Company ID',     ['company id', 'companyId', 'company_id']],
      ['Email',          ['email']],
    ],
  },
  // Airtable Slice-3 — surface the natural-key field operators most
  // often pick as the linkage match column, plus the Airtable rec id.
  airtable: {
    placement: [
      ['Airtable Rec',    ['_airtable_id', 'id', 'rec_id']],
      ['Placement ID',    ['external_id', 'placement_id', 'placementId']],
      ['Title',           ['title', 'job_title', 'Job Title']],
      ['Client',          ['client_name', 'end_client_name', 'Client']],
      ['Created',         ['_airtable_created_time']],
    ],
    company: [
      ['Airtable Rec',    ['_airtable_id', 'id', 'rec_id']],
      ['Name',            ['name', 'company_name', 'Name']],
      ['Domain',          ['domain', 'website', 'Domain']],
      ['DUNS',            ['duns', 'DUNS']],
    ],
    customer: [
      ['Airtable Rec',    ['_airtable_id', 'id', 'rec_id']],
      ['Name',            ['name', 'customer_name', 'Name']],
    ],
    vendor: [
      ['Airtable Rec',    ['_airtable_id', 'id', 'rec_id']],
      ['Vendor Name',     ['vendor_name', 'name', 'Vendor', 'Name']],
      ['Tax ID',          ['tax_id', 'ein', 'Tax ID']],
    ],
    contact: [
      ['Airtable Rec',    ['_airtable_id', 'id', 'rec_id']],
      ['Email',           ['email_primary', 'email', 'Email']],
      ['Phone',           ['phone', 'Phone']],
    ],
  },
};

const STATUS_PALETTE = {
  ok:                { bg: '#ecfdf5', fg: '#065f46', border: '#a7f3d0', icon: CheckCircle2, label: 'In sync' },
  stale:             { bg: '#fef3c7', fg: '#92400e', border: '#fde68a', icon: Clock,         label: 'Stale' },
  error:             { bg: '#fef2f2', fg: '#991b1b', border: '#fecaca', icon: AlertCircle,   label: 'Error' },
  deleted_in_source: { bg: '#f1f5f9', fg: '#475569', border: '#cbd5e1', icon: AlertCircle,   label: 'Deleted in source' },
  // Airtable Slice-2 — unmatched/ambiguous when the resolver couldn't
  // link to a real CoreFlux row. Operator should reconcile via the
  // Airtable Settings → Reconciliation tab.
  unmatched:         { bg: '#fff7ed', fg: '#9a3412', border: '#fed7aa', icon: AlertCircle,   label: 'Needs linkage' },
  ambiguous:         { bg: '#fdf2f8', fg: '#9d174d', border: '#fbcfe8', icon: AlertCircle,   label: 'Ambiguous match' },
};

const DIRECTION_LABEL = {
  pull:    'Pull only',
  push:    'Push only',
  two_way: 'Two-way',
  off:     'Disabled',
};

// Case- and separator-insensitive payload lookup (JS mirror of
// jobdivaPluckField). Returns '' when no candidate key resolves to a
// non-empty scalar.
function pluckPayloadValue(payload, candidates) {
  if (!payload || typeof payload !== 'object') return '';
  const norm = {};
  for (const k of Object.keys(payload)) {
    const v = payload[k];
    if (v === null || v === undefined) continue;
    if (typeof v !== 'string' && typeof v !== 'number') continue;
    const nk = String(k).toLowerCase().replace(/[^a-z0-9]/g, '');
    if (!(nk in norm)) norm[nk] = v;
  }
  for (const cand of candidates) {
    const nk = String(cand).toLowerCase().replace(/[^a-z0-9]/g, '');
    if (nk in norm) {
      const s = String(norm[nk]).trim();
      if (s !== '') return s;
    }
  }
  return '';
}

function SuggestMappingModal({ open, onClose, mapping, entityType }) {
  // Modal that POSTs the mapping's raw payload to
  // /api/admin/integrations/field_map_suggest.php and lets the operator
  // tick which proposed (external_field → internal_field) rows to apply.
  // One-click "Apply selected" walks the upsert endpoint per row.
  const [suggestions, setSuggestions] = useState([]);
  const [shadowed, setShadowed]       = useState([]);
  const [loading, setLoading]         = useState(false);
  const [error, setError]             = useState(null);
  const [selected, setSelected]       = useState({});
  const [applying, setApplying]       = useState(false);
  const [applied, setApplied]         = useState(0);

  React.useEffect(() => {
    if (!open) return;
    setLoading(true); setError(null); setApplied(0);
    api.post('/api/admin/integrations/field_map_suggest.php', {
      integration: mapping.source_system,
      entity_type: entityType,
      payload:     mapping.payload_snapshot || {},
    }).then(r => {
      setSuggestions(r.suggestions || []);
      setShadowed(r.shadowed || []);
      // Pre-select all high-confidence (≥ 0.9) suggestions so the
      // operator can one-click "Apply selected" without ticking each.
      const preset = {};
      (r.suggestions || []).forEach((s, i) => { if (s.confidence >= 0.9) preset[i] = true; });
      setSelected(preset);
    })
    .catch(e => setError(e.message || 'Suggest failed'))
    .finally(() => setLoading(false));
  }, [open, mapping.source_system, mapping.payload_snapshot, entityType]);

  if (!open) return null;

  const handleApply = async () => {
    setApplying(true); setError(null);
    let ok = 0;
    for (let i = 0; i < suggestions.length; i++) {
      if (!selected[i]) continue;
      const s = suggestions[i];
      try {
        await api.post('/api/admin/integrations/field_map.php', {
          integration:    mapping.source_system,
          entity_type:    entityType,
          external_field: s.external_field,
          internal_field: s.internal_field,
          transform:      s.transform || 'none',
          notes:          `Suggested ${new Date().toISOString().slice(0,10)}: ${s.reason}`,
        });
        ok++;
      } catch (e) {
        setError(`Failed on ${s.internal_field}: ${e.message || e}`);
        break;
      }
    }
    setApplied(ok);
    setApplying(false);
  };

  return (
    <div
      data-testid={`suggest-mapping-modal-${mapping.source_system}`}
      onClick={onClose}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 9999, padding: '2rem',
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          background: '#fff', borderRadius: 12, padding: 0,
          maxWidth: 720, width: '100%', maxHeight: '80vh',
          display: 'flex', flexDirection: 'column', overflow: 'hidden',
        }}
      >
        <header style={{
          padding: '14px 20px', borderBottom: '1px solid #e2e8f0',
          display: 'flex', alignItems: 'center', gap: 8,
        }}>
          <Sparkles size={16} color="#7c3aed" />
          <strong style={{ flex: 1 }}>Suggested mappings — {mapping.source_system} / {entityType}</strong>
          <button onClick={onClose} data-testid="suggest-mapping-modal-close"
                  style={{ background: 'transparent', border: 'none', cursor: 'pointer', color: '#64748b' }}>
            <X size={18} />
          </button>
        </header>

        <div style={{ padding: 20, overflowY: 'auto', flex: 1 }}>
          {loading && <p data-testid="suggest-mapping-loading">Scanning payload…</p>}
          {error && <p className="error" data-testid="suggest-mapping-error" style={{ color: '#991b1b' }}>{error}</p>}
          {applied > 0 && (
            <div data-testid="suggest-mapping-applied"
                 style={{ marginBottom: 12, padding: '0.5rem 0.75rem',
                          background: '#ecfdf5', border: '1px solid #a7f3d0', borderRadius: 8,
                          color: '#065f46', fontSize: 13 }}>
              ✓ Applied {applied} mapping{applied === 1 ? '' : 's'}. Trigger Sync now to use them.
            </div>
          )}

          {!loading && suggestions.length === 0 && !error && (
            <p data-testid="suggest-mapping-empty" style={{ color: '#64748b' }}>
              No new suggestions — every recognisable field is already mapped or doesn't match an internal column.
            </p>
          )}

          {!loading && suggestions.length > 0 && (
            <table data-testid="suggest-mapping-table" style={{ width: '100%', fontSize: 13 }}>
              <thead>
                <tr style={{ color: '#64748b', textAlign: 'left' }}>
                  <th style={{ padding: '6px 8px', width: 24 }}>
                    <input
                      type="checkbox"
                      data-testid="suggest-mapping-toggle-all"
                      checked={suggestions.every((_, i) => selected[i])}
                      onChange={(e) => {
                        const next = {};
                        if (e.target.checked) suggestions.forEach((_, i) => { next[i] = true; });
                        setSelected(next);
                      }}
                    />
                  </th>
                  <th style={{ padding: '6px 8px' }}>External field</th>
                  <th style={{ padding: '6px 8px' }}>→ Internal field</th>
                  <th style={{ padding: '6px 8px' }}>Transform</th>
                  <th style={{ padding: '6px 8px' }}>Confidence</th>
                  <th style={{ padding: '6px 8px' }}>Sample</th>
                </tr>
              </thead>
              <tbody>
                {suggestions.map((s, i) => (
                  <tr key={i} data-testid={`suggest-mapping-row-${i}`}>
                    <td style={{ padding: '6px 8px' }}>
                      <input
                        type="checkbox"
                        checked={!!selected[i]}
                        onChange={(e) => setSelected(prev => ({ ...prev, [i]: e.target.checked }))}
                        data-testid={`suggest-mapping-check-${s.internal_field}`}
                      />
                    </td>
                    <td style={{ padding: '6px 8px', fontFamily: 'ui-monospace, monospace' }}>{s.external_field}</td>
                    <td style={{ padding: '6px 8px', fontFamily: 'ui-monospace, monospace' }}>{s.internal_field}</td>
                    <td style={{ padding: '6px 8px', fontSize: 12 }}>{s.transform}</td>
                    <td style={{ padding: '6px 8px' }}>
                      <span style={{
                        padding: '2px 6px', borderRadius: 999, fontSize: 11, fontWeight: 600,
                        background: s.confidence >= 0.9 ? '#ecfdf5' : (s.confidence >= 0.7 ? '#fef3c7' : '#f1f5f9'),
                        color:      s.confidence >= 0.9 ? '#065f46' : (s.confidence >= 0.7 ? '#92400e' : '#475569'),
                      }}>
                        {Math.round(s.confidence * 100)}%
                      </span>
                      <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{s.reason}</div>
                    </td>
                    <td style={{ padding: '6px 8px', fontSize: 11, color: '#475569', fontFamily: 'ui-monospace, monospace',
                                 maxWidth: 140, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {s.sample_value ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {!loading && shadowed.length > 0 && (
            <details data-testid="suggest-mapping-shadowed" style={{ marginTop: 16, color: '#64748b', fontSize: 12 }}>
              <summary style={{ cursor: 'pointer' }}>
                {shadowed.length} suggestion{shadowed.length === 1 ? '' : 's'} already configured (click to view)
              </summary>
              <ul style={{ marginTop: 6 }}>
                {shadowed.map((s, i) => (
                  <li key={i}>
                    <code>{s.external_field}</code> → <code>{s.internal_field}</code> ({s.reason})
                  </li>
                ))}
              </ul>
            </details>
          )}
        </div>

        <footer style={{
          padding: '12px 20px', borderTop: '1px solid #e2e8f0',
          display: 'flex', justifyContent: 'flex-end', gap: 8,
        }}>
          <button onClick={onClose} className="btn btn--ghost" data-testid="suggest-mapping-cancel">Cancel</button>
          <button
            onClick={handleApply}
            className="btn btn--primary"
            disabled={applying || suggestions.length === 0 || !suggestions.some((_, i) => selected[i])}
            data-testid="suggest-mapping-apply"
          >
            {applying ? 'Applying…' : `Apply ${suggestions.filter((_, i) => selected[i]).length} selected`}
          </button>
        </footer>
      </div>
    </div>
  );
}

function FieldMapEditor({ integration, entityType, payload }) {
  // Editable list of CURRENT (external_field → internal_field) mappings
  // for this (integration, entity_type). Replaces the AI-only "Suggest"
  // flow with direct CRUD so operators can fix sync drift on demand —
  // e.g. when JobDiva returns `name` and we expected `jobTitle`, the
  // operator can map `name → title` and re-trigger sync.
  //
  // Data sources:
  //   GET  /api/admin/integrations/field_map.php?integration=&entity_type=
  //   POST /api/admin/integrations/field_map.php  (upsert by internal_field)
  //   DEL  /api/admin/integrations/field_map.php?id=
  const url = `/api/admin/integrations/field_map.php?integration=${encodeURIComponent(integration)}&entity_type=${encodeURIComponent(entityType)}`;
  const { data, loading, error, reload } = useApi(url);
  const [editing, setEditing] = useState(null);          // mapping id being edited inline
  const [draft, setDraft]     = useState({ external_field: '', transform: 'none' });
  const [adding, setAdding]   = useState(false);
  const [newRow, setNewRow]   = useState({ internal_field: '', external_field: '', transform: 'none' });
  const [busy, setBusy]       = useState(false);
  const [opError, setOpError] = useState(null);

  const rows       = data?.rows || [];
  const allowed    = data?.allowed_internal_fields?.[entityType] || [];
  const transforms = data?.transforms || ['none'];
  const migrationPending = !!data?.migration_pending;
  // Heuristic — internal fields whose name ends with `_date` or starts
  // with `date_` are the legitimate targets for `date_normalise`. Used
  // to flag the "footgun" config of applying `date_normalise` to a
  // text column like `end_client_name` (observed in prod 2026-02:
  // `customer name → end_client_name` with date_normalise was nuking
  // the value to NULL because "Public Storage" isn't a date).
  const isDateField = (f) => /(^date_|_date$|_at$|_from$|_to$)/i.test(f);
  const [migrating, setMigrating] = useState(false);
  const [migrateMsg, setMigrateMsg] = useState(null);
  const [flushing, setFlushing] = useState(false);
  const [flushMsg, setFlushMsg] = useState(null);

  const flushOpcacheAndRetry = async () => {
    // Recovery path for the "backend deployed but FPM workers still
    // serving stale bytecode" failure mode. Hitting a brand-new endpoint
    // forces FPM to compile fresh code, then opcache_reset() clears the
    // rest of the pool on subsequent requests.
    setFlushing(true); setFlushMsg(null);
    try {
      const r = await api.post('/api/admin/opcache_flush.php');
      setFlushMsg(r.available
        ? (r.reset ? 'OPcache flushed. Retrying…' : 'OPcache flush failed — try once more or restart FPM.')
        : 'OPcache not loaded on this server. Retrying anyway…');
      reload && reload();
    } catch (e) {
      setFlushMsg('Flush failed: HTTP ' + (e.status ?? '?') + ' — ' + (e.message || e));
    } finally { setFlushing(false); }
  };

  const runMigration = async () => {
    setMigrating(true); setMigrateMsg(null);
    try {
      const r = await api.post('/api/admin/migrate.php');
      const errs = (r.status?.errors || []).length;
      const applied = (r.status?.applied_files || []).length;
      setMigrateMsg(errs === 0
        ? `Applied ${applied} migration(s). Reloading…`
        : `Applied ${applied} with ${errs} error(s) — check Sync History drawer for details.`);
      reload && reload();
    } catch (e) {
      setMigrateMsg('Failed: ' + (e.message || e));
    } finally { setMigrating(false); }
  };

  // Payload-key suggestions for autocomplete. We surface top-level
  // scalar keys; nested objects are findable via "View raw payload"
  // below — kept off the autocomplete to avoid overwhelming the dropdown.
  const payloadKeys = React.useMemo(() => {
    if (!payload || typeof payload !== 'object') return [];
    return Object.keys(payload).filter(k => {
      const v = payload[k];
      return v === null || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';
    }).sort();
  }, [payload]);

  // Internal fields not yet mapped — drives the "Add mapping" dropdown.
  const mappedInternal = new Set(rows.map(r => r.internal_field));
  const unmappedInternal = allowed.filter(f => !mappedInternal.has(f));

  const startEdit = (r) => {
    setEditing(r.id);
    setDraft({ external_field: r.external_field || '', transform: r.transform || 'none' });
    setOpError(null);
  };

  const saveEdit = async (r) => {
    setBusy(true); setOpError(null);
    try {
      await api.post('/api/admin/integrations/field_map.php', {
        integration, entity_type: entityType,
        internal_field: r.internal_field,
        external_field: draft.external_field.trim(),
        transform:      draft.transform,
        enabled:        true,
      });
      setEditing(null);
      reload && reload();
    } catch (e) {
      setOpError(e.message || 'Save failed');
    } finally { setBusy(false); }
  };

  const remove = async (r) => {
    if (!window.confirm(`Remove mapping ${r.external_field} → ${r.internal_field}?`)) return;
    setBusy(true); setOpError(null);
    try {
      await api.delete(`/api/admin/integrations/field_map.php?id=${r.id}`);
      reload && reload();
    } catch (e) {
      setOpError(e.message || 'Delete failed');
    } finally { setBusy(false); }
  };

  const saveNew = async () => {
    if (!newRow.internal_field || !newRow.external_field.trim()) {
      setOpError('Pick an internal field and enter the external field name'); return;
    }
    setBusy(true); setOpError(null);
    try {
      await api.post('/api/admin/integrations/field_map.php', {
        integration, entity_type: entityType,
        internal_field: newRow.internal_field,
        external_field: newRow.external_field.trim(),
        transform:      newRow.transform,
        enabled:        true,
      });
      setNewRow({ internal_field: '', external_field: '', transform: 'none' });
      setAdding(false);
      reload && reload();
    } catch (e) {
      setOpError(e.message || 'Save failed');
    } finally { setBusy(false); }
  };

  const cellStyle  = { padding: '6px 8px', fontSize: 12, verticalAlign: 'middle' };
  const inputStyle = {
    width: '100%', padding: '3px 6px', fontSize: 12,
    fontFamily: 'ui-monospace, SFMono-Regular, monospace',
    border: '1px solid #cbd5e1', borderRadius: 4,
  };
  const btnIcon    = {
    background: 'transparent', border: 'none', cursor: 'pointer',
    padding: 2, color: '#475569', display: 'inline-flex', alignItems: 'center',
  };

  return (
    <div
      data-testid={`field-map-editor-${integration}-${entityType}`}
      style={{
        marginBottom: 12, padding: 10,
        background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}>
        <Pencil size={12} color="#475569" />
        <strong style={{ fontSize: 12 }}>Current field mappings</strong>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 11, color: '#64748b' }}>
          {rows.length} active · {unmappedInternal.length} unmapped
        </span>
      </div>
      <p data-testid="field-map-scope-hint"
         style={{ fontSize: 11, color: '#64748b', margin: '0 0 6px',
                  background: '#f1f5f9', border: '1px solid #e2e8f0',
                  padding: '4px 8px', borderRadius: 4 }}>
        These mappings are <strong>tenant-wide</strong> — they apply to every {integration} {entityType} record, not just this one.
        This record&apos;s raw payload (below) is shown here only so you can copy real field names into the mapping.
        {entityType === 'placement' && (
          <> Mapping <code>bill_rate</code> / <code>pay_rate</code> / <code>currency</code> writes to the <code>placement_rates</code> table; everything else writes to <code>placements</code>.</>
        )}
      </p>

      {loading && <p data-testid="field-map-loading" style={{ fontSize: 11, color: '#64748b', margin: 0 }}>Loading mappings…</p>}
      {migrationPending && (
        <div data-testid="field-map-migration-pending"
             style={{ fontSize: 12, background: '#fef3c7', border: '1px solid #fde68a',
                      padding: '8px 12px', borderRadius: 6, color: '#92400e',
                      marginBottom: 8 }}>
          <strong>Migration pending.</strong>{' '}
          The <code>tenant_integration_field_map</code> table hasn&apos;t been created on this environment yet
          (migration 068). Click below to apply pending migrations — idempotent, safe to retry.
          <div style={{ marginTop: 6 }}>
            <button
              onClick={runMigration}
              disabled={migrating}
              data-testid="field-map-run-migration"
              style={{
                background: '#92400e', color: '#fff', border: 'none', cursor: 'pointer',
                fontSize: 11, padding: '4px 10px', borderRadius: 4, fontWeight: 600,
              }}
            >
              {migrating ? 'Running…' : 'Run pending migrations'}
            </button>
            {migrateMsg && (
              <span data-testid="field-map-migration-msg"
                    style={{ marginLeft: 10, fontSize: 11 }}>{migrateMsg}</span>
            )}
          </div>
        </div>
      )}
      {error && (
        <div data-testid="field-map-error"
             style={{ fontSize: 11, background: '#fef2f2', border: '1px solid #fecaca',
                      padding: '6px 10px', borderRadius: 6, color: '#991b1b', marginBottom: 6 }}>
          <strong>Couldn&apos;t load mappings.</strong>
          {' '}HTTP {error.status ?? '?'} — {error.message || 'Unknown error'}
          {error.data?.required && (
            <div style={{ marginTop: 4 }}>
              Required permission: <code>{error.data.required}</code>
              {error.data.required_module && (
                <> (module <code>{error.data.required_module}</code> + <code>{error.data.required_action}</code> level)</>
              )}
            </div>
          )}
          {error.data?.raw && (
            <details style={{ marginTop: 4 }}>
              <summary style={{ cursor: 'pointer' }}>Raw response</summary>
              <pre style={{ marginTop: 4, fontSize: 10, whiteSpace: 'pre-wrap',
                            wordBreak: 'break-word', maxHeight: 200, overflow: 'auto' }}>{error.data.raw}</pre>
            </details>
          )}
          {/* Recovery affordance — when a 500 returns an empty body
              (text/html, Content-Length: 0), the most common cause on
              this stack is stale PHP-FPM opcache holding pre-deploy
              bytecode. One click flushes the cache + retries the GET. */}
          {error.status === 500 && (
            <div style={{ marginTop: 8, paddingTop: 6, borderTop: '1px solid #fecaca' }}>
              <button
                onClick={flushOpcacheAndRetry}
                disabled={flushing}
                data-testid="field-map-flush-opcache"
                style={{
                  background: '#991b1b', color: '#fff', border: 'none', cursor: 'pointer',
                  fontSize: 11, padding: '4px 10px', borderRadius: 4, fontWeight: 600,
                }}
              >
                {flushing ? 'Flushing…' : 'Flush server cache & retry'}
              </button>
              {flushMsg && (
                <span data-testid="field-map-flush-msg"
                      style={{ marginLeft: 10, fontSize: 11 }}>{flushMsg}</span>
              )}
              <div style={{ marginTop: 4, fontSize: 10, color: '#7f1d1d' }}>
                Tip: if you just deployed a backend fix and this endpoint still 500s with an empty body,
                PHP-FPM is likely serving stale bytecode. This forces an opcache reset.
              </div>
            </div>
          )}
        </div>
      )}
      {opError && (
        <p data-testid="field-map-op-error"
           style={{ fontSize: 11, color: '#991b1b', background: '#fef2f2',
                    border: '1px solid #fecaca', padding: '4px 8px',
                    borderRadius: 4, margin: '0 0 6px' }}>{opError}</p>
      )}

      {!loading && (
        <table data-testid="field-map-table" style={{ width: '100%', fontSize: 12 }}>
          <thead>
            <tr style={{ color: '#64748b', textAlign: 'left' }}>
              <th style={cellStyle}>External field (payload key)</th>
              <th style={cellStyle}>→ Internal field</th>
              <th style={cellStyle}>Transform</th>
              <th style={{ ...cellStyle, width: 70 }}></th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && !adding && (
              <tr><td colSpan={4} style={{ ...cellStyle, color: '#94a3b8', fontStyle: 'italic' }}>
                No tenant overrides yet — the syncer is using built-in JobDiva field
                candidates. Add a mapping below to override (e.g. <code>name → title</code>)
                if your placements are showing as &quot;JobDiva Placement {`{id}`}&quot;.
              </td></tr>
            )}
            {rows.map(r => {
              const isEd = editing === r.id;
              return (
                <tr key={r.id} data-testid={`field-map-row-${r.internal_field}`}>
                  <td style={cellStyle}>
                    {isEd ? (
                      <input
                        list={`payloadkeys-${integration}-${entityType}`}
                        value={draft.external_field}
                        onChange={e => setDraft(d => ({ ...d, external_field: e.target.value }))}
                        data-testid={`field-map-edit-external-${r.internal_field}`}
                        style={inputStyle}
                      />
                    ) : (
                      <code data-testid={`field-map-external-${r.internal_field}`}>{r.external_field}</code>
                    )}
                  </td>
                  <td style={cellStyle}>
                    <code style={{ fontWeight: 600 }}>{r.internal_field}</code>
                  </td>
                  <td style={cellStyle}>
                    {isEd ? (
                      <select
                        value={draft.transform}
                        onChange={e => setDraft(d => ({ ...d, transform: e.target.value }))}
                        data-testid={`field-map-edit-transform-${r.internal_field}`}
                        style={{ ...inputStyle, fontFamily: 'inherit' }}
                      >
                        {transforms.map(t => <option key={t} value={t}>{t}</option>)}
                      </select>
                    ) : (
                      <span style={{ fontSize: 11, color: '#475569', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                        {r.transform || 'none'}
                        {r.transform === 'date_normalise' && !isDateField(r.internal_field) && (
                          <span
                            data-testid={`field-map-transform-warn-${r.internal_field}`}
                            title="date_normalise will discard non-date values (e.g. 'Public Storage' → null). Edit and switch to 'none' or 'trim'."
                            style={{
                              background: '#fef3c7', border: '1px solid #fde68a',
                              color: '#92400e', borderRadius: 3, padding: '0 4px',
                              fontSize: 10, fontWeight: 700,
                            }}
                          >!</span>
                        )}
                      </span>
                    )}
                  </td>
                  <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                    {isEd ? (
                      <>
                        <button onClick={() => saveEdit(r)} disabled={busy} title="Save"
                                data-testid={`field-map-save-${r.internal_field}`} style={{ ...btnIcon, color: '#059669' }}>
                          <Save size={14} />
                        </button>
                        <button onClick={() => setEditing(null)} disabled={busy} title="Cancel"
                                data-testid={`field-map-cancel-${r.internal_field}`} style={btnIcon}>
                          <X size={14} />
                        </button>
                      </>
                    ) : (
                      <>
                        <button onClick={() => startEdit(r)} disabled={busy} title="Edit"
                                data-testid={`field-map-edit-${r.internal_field}`} style={btnIcon}>
                          <Pencil size={13} />
                        </button>
                        <button onClick={() => remove(r)} disabled={busy} title="Delete"
                                data-testid={`field-map-delete-${r.internal_field}`} style={{ ...btnIcon, color: '#dc2626' }}>
                          <Trash2 size={13} />
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              );
            })}
            {adding && (
              <tr data-testid="field-map-row-new">
                <td style={cellStyle}>
                  <input
                    list={`payloadkeys-${integration}-${entityType}`}
                    placeholder="e.g. jobTitle, name, job.title"
                    value={newRow.external_field}
                    onChange={e => setNewRow(r => ({ ...r, external_field: e.target.value }))}
                    data-testid="field-map-new-external"
                    style={inputStyle}
                  />
                </td>
                <td style={cellStyle}>
                  <select
                    value={newRow.internal_field}
                    onChange={e => setNewRow(r => ({ ...r, internal_field: e.target.value }))}
                    data-testid="field-map-new-internal"
                    style={{ ...inputStyle, fontFamily: 'inherit' }}
                  >
                    <option value="">— pick CoreFlux field —</option>
                    {unmappedInternal.map(f => <option key={f} value={f}>{f}</option>)}
                  </select>
                </td>
                <td style={cellStyle}>
                  <select
                    value={newRow.transform}
                    onChange={e => setNewRow(r => ({ ...r, transform: e.target.value }))}
                    data-testid="field-map-new-transform"
                    style={{ ...inputStyle, fontFamily: 'inherit' }}
                  >
                    {transforms.map(t => <option key={t} value={t}>{t}</option>)}
                  </select>
                </td>
                <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <button onClick={saveNew} disabled={busy} title="Save new mapping"
                          data-testid="field-map-new-save" style={{ ...btnIcon, color: '#059669' }}>
                    <Save size={14} />
                  </button>
                  <button onClick={() => { setAdding(false); setNewRow({ internal_field: '', external_field: '', transform: 'none' }); }}
                          disabled={busy} title="Cancel"
                          data-testid="field-map-new-cancel" style={btnIcon}>
                    <X size={14} />
                  </button>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      )}

      {/* Shared autocomplete source — top-level payload keys */}
      <datalist id={`payloadkeys-${integration}-${entityType}`}>
        {payloadKeys.map(k => <option key={k} value={k} />)}
      </datalist>

      {!adding && unmappedInternal.length > 0 && (
        <button
          onClick={() => { setAdding(true); setOpError(null); }}
          data-testid="field-map-add"
          style={{
            marginTop: 6, background: 'transparent',
            border: '1px dashed #c7d2fe', color: '#4338ca',
            cursor: 'pointer', fontSize: 11, padding: '3px 8px',
            borderRadius: 4, display: 'inline-flex', alignItems: 'center', gap: 4,
          }}
        >
          <Plus size={11} /> Add mapping
        </button>
      )}
      {!adding && unmappedInternal.length === 0 && rows.length > 0 && (
        <p style={{ fontSize: 11, color: '#64748b', margin: '6px 0 0' }}>
          Every mappable internal field is already configured.
        </p>
      )}
    </div>
  );
}

function DetailRow({ mapping, entityType }) {
  const [showRaw, setShowRaw] = useState(false);
  const [showSuggest, setShowSuggest] = useState(false);
  const payload = mapping.payload_snapshot || {};
  const idFields = SOURCE_ID_FIELDS[mapping.source_system]?.[entityType] || [];

  // Slice-3 — surface the source-system deep-link when the payload
  // carries one. Airtable populates `_airtable_record_url` during
  // sync; QBO/Zoho can graft similar links in the future without
  // a UI change.
  const externalUrl = pluckPayloadValue(payload, [
    '_airtable_record_url',
    '_external_url',
    'externalUrl',
    'permalink',
  ]) || null;

  return (
    <tr data-testid={`linked-systems-detail-${mapping.source_system}`}>
      <td colSpan={6} style={{ background: '#f8fafc', padding: '12px 16px', borderTop: '1px solid #e2e8f0' }}>
        {idFields.length > 0 && (
          <dl
            data-testid={`linked-systems-fields-${mapping.source_system}`}
            style={{
              display: 'grid',
              gridTemplateColumns: 'max-content 1fr',
              columnGap: '0.75rem', rowGap: '0.25rem',
              margin: 0, fontSize: 13,
            }}
          >
            {idFields.map(([displayLabel, keys]) => {
              const v = pluckPayloadValue(payload, keys);
              if (v === '') return null;
              const slug = displayLabel.replace(/\W+/g, '-').toLowerCase();
              return (
                <React.Fragment key={displayLabel}>
                  <dt style={{ color: '#64748b' }}>{displayLabel}</dt>
                  <dd
                    data-testid={`linked-systems-field-${mapping.source_system}-${slug}`}
                    style={{ margin: 0, fontFamily: 'ui-monospace, SFMono-Regular, monospace' }}
                  >
                    {v}
                  </dd>
                </React.Fragment>
              );
            })}
          </dl>
        )}
        {externalUrl && (
          <a
            data-testid={`linked-systems-external-url-${mapping.source_system}`}
            href={externalUrl}
            target="_blank"
            rel="noopener noreferrer"
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 4,
              marginTop: idFields.length > 0 ? 8 : 0,
              fontSize: 12, color: '#4338ca',
              padding: '3px 8px', border: '1px solid #c7d2fe',
              borderRadius: 6, textDecoration: 'none',
            }}
          >
            <ExternalLink size={11} /> Open in {SOURCE_LABEL[mapping.source_system] || mapping.source_system}
          </a>
        )}
        <FieldMapEditor
          integration={mapping.source_system}
          entityType={entityType}
          payload={payload}
        />
        <div style={{ marginTop: idFields.length > 0 ? '0.6rem' : 0, display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
          <button
            onClick={() => setShowRaw(s => !s)}
            data-testid={`linked-systems-raw-toggle-${mapping.source_system}`}
            style={{
              background: 'transparent', border: 'none', cursor: 'pointer',
              color: '#4f46e5', fontSize: 12,
              display: 'inline-flex', alignItems: 'center', gap: 4, padding: 0,
            }}
          >
            {showRaw ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
            {showRaw ? 'Hide raw payload' : 'View raw payload'}
          </button>
          <button
            onClick={() => setShowSuggest(true)}
            data-testid={`linked-systems-suggest-${mapping.source_system}`}
            style={{
              background: 'transparent', border: '1px solid #c7d2fe', borderRadius: 6,
              cursor: 'pointer', color: '#4338ca', fontSize: 12,
              display: 'inline-flex', alignItems: 'center', gap: 4,
              padding: '2px 8px',
            }}
            title="Scan this payload and propose field mappings"
          >
            <Sparkles size={11} /> Suggest mappings
          </button>
        </div>
        {showRaw && (
          <pre
            data-testid={`linked-systems-raw-${mapping.source_system}`}
            style={{
              marginTop: '0.5rem', marginBottom: 0,
              background: '#0f172a', color: '#e2e8f0', padding: '0.6rem',
              borderRadius: 6, fontSize: 11, overflow: 'auto', maxHeight: 280,
            }}
          >
            {JSON.stringify(payload, null, 2)}
          </pre>
        )}
        <SuggestMappingModal
          open={showSuggest}
          onClose={() => setShowSuggest(false)}
          mapping={mapping}
          entityType={entityType}
        />
      </td>
    </tr>
  );
}

export default function LinkedExternalSystemsPanel({ entityType, internalId }) {
  const url = (entityType && internalId)
    ? `/api/integrations/mappings.php?action=list_for_internal&entity_type=${encodeURIComponent(entityType)}&internal_id=${encodeURIComponent(internalId)}`
    : null;
  const { data, loading, error } = useApi(url);
  const [expanded, setExpanded] = useState({});

  if (loading) {
    return <p data-testid="linked-systems-loading" style={{ fontSize: 12, color: '#64748b' }}>Loading…</p>;
  }
  if (error) {
    return <p className="error" data-testid="linked-systems-error" style={{ fontSize: 12 }}>{error.message}</p>;
  }

  const mappings = data?.mappings || [];

  return (
    <div data-testid={`linked-systems-panel-${entityType}-${internalId}`}
         style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
        <Link2 size={14} color="#475569" />
        <strong style={{ fontSize: 14 }}>Linked external systems</strong>
      </div>
      <p style={{ color: '#64748b', fontSize: 12, margin: '0 0 12px' }}>
        Other systems that have a binding to this CoreFlux record. Click the chevron
        (▸) on a row to see the current field mappings, add or edit overrides
        (e.g. <code>name → title</code> if your placements show as &quot;JobDiva Placement&nbsp;
        {`{id}`}&quot;), inspect the raw payload, or run AI-suggested mappings.
      </p>

      {mappings.length === 0 && (
        <div data-testid="linked-systems-empty"
             style={{ padding: 12, background: '#f8fafc', borderRadius: 8, fontSize: 13, color: '#64748b' }}>
          Not currently linked to any external system.
        </div>
      )}

      {mappings.length > 0 && (
        <table className="data-table" style={{ width: '100%' }} data-testid="linked-systems-table">
          <thead>
            <tr style={{ fontSize: 11, color: '#64748b', textAlign: 'left' }}>
              <th style={{ padding: '6px 8px', width: 24 }}></th>
              <th style={{ padding: '6px 8px' }}>Source</th>
              <th style={{ padding: '6px 8px' }}>External ID</th>
              <th style={{ padding: '6px 8px' }}>Status</th>
              <th style={{ padding: '6px 8px' }}>Direction</th>
              <th style={{ padding: '6px 8px' }}>Last synced</th>
            </tr>
          </thead>
          <tbody>
            {mappings.map(m => {
              const palette = STATUS_PALETTE[m.sync_status] || STATUS_PALETTE.ok;
              const Icon = palette.icon;
              const label = SOURCE_LABEL[m.source_system] || m.source_system;
              const isOpen = !!expanded[m.id];
              return (
                <React.Fragment key={m.id}>
                  <tr
                    data-testid={`linked-systems-row-${m.source_system}`}
                    onClick={() => setExpanded(s => ({ ...s, [m.id]: !s[m.id] }))}
                    style={{ cursor: 'pointer' }}
                  >
                    <td style={{ padding: '8px', textAlign: 'center' }}>
                      <button
                        onClick={(e) => { e.stopPropagation(); setExpanded(s => ({ ...s, [m.id]: !s[m.id] })); }}
                        data-testid={`linked-systems-expand-${m.source_system}`}
                        aria-expanded={isOpen}
                        aria-label={isOpen ? 'Collapse details' : 'Expand details'}
                        style={{ background: 'transparent', border: 'none', cursor: 'pointer', color: '#64748b', padding: 0 }}
                      >
                        {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                      </button>
                    </td>
                    <td style={{ padding: '8px', fontWeight: 600 }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                        <ExternalLink size={11} color="#64748b" /> {label}
                      </span>
                    </td>
                    <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace', fontSize: 12 }}>
                      {m.external_id}
                    </td>
                    <td style={{ padding: '8px' }}>
                      <span data-testid={`linked-systems-status-${m.source_system}`}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                                     padding: '2px 8px', borderRadius: 999,
                                     background: palette.bg, color: palette.fg,
                                     border: `1px solid ${palette.border}`,
                                     fontSize: 11, fontWeight: 600 }}>
                        <Icon size={10} /> {palette.label}
                      </span>
                    </td>
                    <td style={{ padding: '8px', fontSize: 12, color: '#64748b' }}>
                      {DIRECTION_LABEL[m.direction] || m.direction}
                    </td>
                    <td style={{ padding: '8px', fontSize: 12, color: '#64748b', fontFamily: 'ui-monospace, monospace' }}>
                      {m.last_synced_at || '—'}
                    </td>
                  </tr>
                  {isOpen && <DetailRow mapping={m} entityType={entityType} />}
                </React.Fragment>
              );
            })}
          </tbody>
        </table>
      )}
    </div>
  );
}
