import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { CheckCircle2, AlertTriangle, XCircle, Link as LinkIcon, Clock } from 'lucide-react';

/**
 * QboSyncHealthTile — CFO Dashboard tile summarising the QuickBooks
 * Online integration state.
 *
 * Hits /api/qbo/sync_health and renders:
 *   - red    → connection error, stale > 24h, or > 20 blocked JEs (7d)
 *   - yellow → probe > 2h old, or some blocked JEs in 7d
 *   - green  → healthy
 *   - not_connected → CTA to Admin → Integrations
 *
 * Cheap to render; one GET, no side effects.
 */
export default function QboSyncHealthTile() {
  const { data, loading, error } = useApi('/api/qbo/sync_health.php?action=sync_health');

  if (loading) return null;
  if (error) return null; // silently hide; CFO doesn't need to see a 5xx for an optional tile

  const status   = data?.status || 'not_connected';
  const meta     = STATUS_META[status] || STATUS_META.not_connected;
  const Icon     = meta.icon;

  return (
    <section
      data-testid="cfo-qbo-sync-health-tile"
      data-status={status}
      style={{
        marginTop: 'var(--cf-space-6, 24px)',
        padding: 'var(--cf-space-5, 20px)',
        border: '1px solid var(--cf-border, #e5e7eb)',
        borderLeft: `4px solid ${meta.barColor}`,
        borderRadius: 8,
        background: 'var(--cf-surface, #ffffff)',
      }}
    >
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div
            style={{
              width: 40, height: 40, borderRadius: 8,
              background: meta.bgColor, color: meta.fgColor,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}
          >
            <Icon size={20} />
          </div>
          <div>
            <h3 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>QuickBooks Online sync</h3>
            <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }} data-testid="cfo-qbo-sync-summary">
              {data?.connected
                ? <>{data.company_name || 'Connected'} · <span style={{ textTransform: 'uppercase', fontSize: 10, letterSpacing: 0.5 }}>{data.environment}</span></>
                : (data?.message || 'Not connected')}
            </p>
          </div>
        </div>
        <Link
          to="/admin/integrations/qbo"
          className="btn"
          data-testid="cfo-qbo-sync-manage-link"
          style={{ fontSize: 12, padding: '4px 10px' }}
        >
          <LinkIcon size={12} style={{ marginRight: 4 }} />
          Manage
        </Link>
      </header>

      {data?.connected && (
        <div
          style={{
            marginTop: 14,
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
            gap: 12,
          }}
        >
          <Stat
            testid="cfo-qbo-blocked-jes"
            label="Blocked JEs (7d)"
            value={data.blocked_jes_7d ?? 0}
            tone={(data.blocked_jes_7d ?? 0) > 20 ? 'red' : (data.blocked_jes_7d ?? 0) > 0 ? 'yellow' : 'green'}
          />
          <Stat
            testid="cfo-qbo-failed-runs"
            label="Failed runs (24h)"
            value={data.failed_runs_24h ?? 0}
            tone={(data.failed_runs_24h ?? 0) > 0 ? 'red' : 'green'}
          />
          <Stat
            testid="cfo-qbo-probe-age"
            label="Last probe"
            value={formatAge(data.probe_age_seconds)}
            tone={data.probe_age_seconds == null || data.probe_age_seconds > 86400
              ? 'red'
              : data.probe_age_seconds > 7200 ? 'yellow' : 'green'}
            icon={Clock}
          />
        </div>
      )}

      {Array.isArray(data?.reasons) && data.reasons.length > 0 && (
        <ul
          data-testid="cfo-qbo-sync-reasons"
          style={{ margin: '12px 0 0', paddingLeft: 18, fontSize: 12, color: meta.fgColor }}
        >
          {data.reasons.map((r, i) => <li key={i}>{r}</li>)}
        </ul>
      )}

      {data?.last_sync_by_entity && Object.keys(data.last_sync_by_entity).length > 0 && (
        <details style={{ marginTop: 12, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          <summary style={{ cursor: 'pointer' }}>Last successful sync per entity</summary>
          <ul style={{ margin: '6px 0 0', paddingLeft: 18 }} data-testid="cfo-qbo-last-sync-list">
            {Object.entries(data.last_sync_by_entity).map(([entity, ts]) => (
              <li key={entity}><code>{entity}</code> — {ts}</li>
            ))}
          </ul>
        </details>
      )}
    </section>
  );
}

const STATUS_META = {
  green:         { icon: CheckCircle2,  barColor: '#16a34a', bgColor: '#dcfce7', fgColor: '#166534' },
  yellow:        { icon: AlertTriangle, barColor: '#f59e0b', bgColor: '#fef3c7', fgColor: '#92400e' },
  red:           { icon: XCircle,       barColor: '#dc2626', bgColor: '#fee2e2', fgColor: '#991b1b' },
  not_connected: { icon: LinkIcon,      barColor: '#94a3b8', bgColor: '#f1f5f9', fgColor: '#475569' },
};

function Stat({ testid, label, value, tone, icon: Icon }) {
  const toneFg = { green: '#166534', yellow: '#92400e', red: '#991b1b' }[tone] || 'var(--cf-text)';
  return (
    <div style={{ padding: 10, border: '1px solid var(--cf-border-muted, #f1f5f9)', borderRadius: 6 }}>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 4 }}>{label}</div>
      <div data-testid={testid} style={{ fontSize: 18, fontWeight: 600, color: toneFg, display: 'flex', alignItems: 'center', gap: 6 }}>
        {Icon ? <Icon size={14} /> : null}
        {value}
      </div>
    </div>
  );
}

function formatAge(seconds) {
  if (seconds == null) return 'never';
  if (seconds < 60)    return `${seconds}s ago`;
  if (seconds < 3600)  return `${Math.round(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
  return `${Math.round(seconds / 86400)}d ago`;
}
