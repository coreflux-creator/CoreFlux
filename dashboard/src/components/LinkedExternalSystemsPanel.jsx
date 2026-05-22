import React, { useState } from 'react';
import { useApi } from '../lib/api';
import { Link2, AlertCircle, CheckCircle2, Clock, ExternalLink, ChevronRight, ChevronDown } from 'lucide-react';

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

function DetailRow({ mapping, entityType }) {
  const [showRaw, setShowRaw] = useState(false);
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
        <div style={{ marginTop: idFields.length > 0 ? '0.6rem' : 0 }}>
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
