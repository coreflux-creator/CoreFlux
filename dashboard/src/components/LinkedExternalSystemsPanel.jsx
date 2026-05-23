import React, { useState } from 'react';
import { api, useApi } from '../lib/api';
import { Link2, AlertCircle, CheckCircle2, Clock, ExternalLink, ChevronRight, ChevronDown, Sparkles, X } from 'lucide-react';

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
};

const STATUS_PALETTE = {
  ok:                { bg: '#ecfdf5', fg: '#065f46', border: '#a7f3d0', icon: CheckCircle2, label: 'In sync' },
  stale:             { bg: '#fef3c7', fg: '#92400e', border: '#fde68a', icon: Clock,         label: 'Stale' },
  error:             { bg: '#fef2f2', fg: '#991b1b', border: '#fecaca', icon: AlertCircle,   label: 'Error' },
  deleted_in_source: { bg: '#f1f5f9', fg: '#475569', border: '#cbd5e1', icon: AlertCircle,   label: 'Deleted in source' },
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

function DetailRow({ mapping, entityType }) {
  const [showRaw, setShowRaw] = useState(false);
  const [showSuggest, setShowSuggest] = useState(false);
  const payload = mapping.payload_snapshot || {};
  const idFields = SOURCE_ID_FIELDS[mapping.source_system]?.[entityType] || [];

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
        Other systems that have a binding to this CoreFlux record. Click a row to see source-side identifiers and the raw payload.
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
                  <tr data-testid={`linked-systems-row-${m.source_system}`}>
                    <td style={{ padding: '8px', textAlign: 'center' }}>
                      <button
                        onClick={() => setExpanded(s => ({ ...s, [m.id]: !s[m.id] }))}
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
