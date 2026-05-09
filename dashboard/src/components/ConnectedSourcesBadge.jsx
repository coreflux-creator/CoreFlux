import React from 'react';
import { useApi } from '../lib/api';
import { Link2, AlertCircle, CheckCircle2 } from 'lucide-react';

/**
 * Renders a "Connected to {source}" badge per external system that has a
 * binding for the given internal record. Reads
 *   GET /api/integrations/mappings.php?action=list_for_internal
 *   &entity_type={type}&internal_id={id}
 *
 * Usage:
 *   <ConnectedSourcesBadge entityType="company"   internalId={c.id} />
 *   <ConnectedSourcesBadge entityType="person"    internalId={p.id} />
 *   <ConnectedSourcesBadge entityType="placement" internalId={pl.id} />
 *
 * Renders nothing when there are zero mappings (silent for tenants without
 * any integration configured). Sync_status='ok' is green; 'stale' / 'error' /
 * 'deleted_in_source' is amber/red.
 */
const SOURCE_LABEL = {
  jobdiva: 'JobDiva',
  bullhorn: 'Bullhorn',
  greenhouse: 'Greenhouse',
};

const STATUS_PALETTE = {
  ok:                 { bg: '#ecfdf5', fg: '#065f46', border: '#a7f3d0', icon: CheckCircle2 },
  stale:              { bg: '#fef3c7', fg: '#92400e', border: '#fde68a', icon: AlertCircle },
  error:              { bg: '#fef2f2', fg: '#991b1b', border: '#fecaca', icon: AlertCircle },
  deleted_in_source:  { bg: '#f1f5f9', fg: '#475569', border: '#cbd5e1', icon: AlertCircle },
};

export default function ConnectedSourcesBadge({ entityType, internalId }) {
  const url = (entityType && internalId)
    ? `/api/integrations/mappings.php?action=list_for_internal&entity_type=${encodeURIComponent(entityType)}&internal_id=${encodeURIComponent(internalId)}`
    : null;
  const { data } = useApi(url);
  const mappings = data?.mappings || [];

  if (mappings.length === 0) return null;

  return (
    <span data-testid={`connected-sources-${entityType}-${internalId}`}
          style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
      {mappings.map(m => {
        const palette = STATUS_PALETTE[m.sync_status] || STATUS_PALETTE.ok;
        const Icon = palette.icon;
        const label = SOURCE_LABEL[m.source_system] || m.source_system;
        return (
          <span key={m.id}
                title={`${label} ID ${m.external_id}${m.last_synced_at ? ' · synced ' + m.last_synced_at : ''}`}
                data-testid={`connected-source-chip-${m.source_system}`}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                         padding: '3px 9px', borderRadius: 999,
                         background: palette.bg, color: palette.fg,
                         border: `1px solid ${palette.border}`,
                         fontSize: 11, fontWeight: 600 }}>
            <Icon size={11} />
            <Link2 size={10} style={{ opacity: 0.7 }} />
            {label}
            {m.sync_status !== 'ok' && (
              <span style={{ fontSize: 10, opacity: 0.85 }}>· {m.sync_status}</span>
            )}
          </span>
        );
      })}
    </span>
  );
}
