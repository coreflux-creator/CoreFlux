import React from 'react';
import { useApi } from '../lib/api';
import { Link2, AlertCircle, CheckCircle2, Clock, ExternalLink } from 'lucide-react';

/**
 * Linked External Systems panel — full per-record mapping list.
 *
 * Reads /api/integrations/mappings.php?action=list_for_internal and renders
 * one row per source system that has bound this internal record. Goes
 * deeper than <ConnectedSourcesBadge /> (the header chip) — shows external
 * id, sync status, direction, last-synced timestamp.
 *
 * Renders a "no integrations" hint when the list is empty so the operator
 * knows the panel is intentional, not broken.
 */
const SOURCE_LABEL = {
  jobdiva: 'JobDiva',
  bullhorn: 'Bullhorn',
  greenhouse: 'Greenhouse',
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

export default function LinkedExternalSystemsPanel({ entityType, internalId }) {
  const url = (entityType && internalId)
    ? `/api/integrations/mappings.php?action=list_for_internal&entity_type=${encodeURIComponent(entityType)}&internal_id=${encodeURIComponent(internalId)}`
    : null;
  const { data, loading, error } = useApi(url);

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
        Other systems that have a binding to this CoreFlux record. Sync state and last-seen timestamp per source.
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
              return (
                <tr key={m.id} data-testid={`linked-systems-row-${m.source_system}`}>
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
              );
            })}
          </tbody>
        </table>
      )}
    </div>
  );
}
