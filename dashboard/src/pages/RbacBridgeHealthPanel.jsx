import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { ShieldCheck, ShieldAlert, RefreshCw } from 'lucide-react';

/**
 * RbacBridgeHealthPanel — surfaces moments when the legacy RBAC config
 * and the new RBACResolver disagree about a permission. While the bridge
 * runs in dual-check mode (the default), disagreements are harmless at
 * runtime — they just mean the more-restrictive layer won. But they show
 * us exactly which perms still need attention before flipping
 * CF_RBAC_BRIDGE_MODE to `new`.
 *
 * Zero disagreements in the last 24h = green light to flip the bridge.
 */
export default function RbacBridgeHealthPanel({ windowHours = 24 }) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/admin/rbac_bridge_health.php?window_hours=${windowHours}`);
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load bridge health'); }
    finally { setLoading(false); }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [windowHours]);

  const healthy = data && data.configured && (data.total_disagreements === 0);
  const Icon    = healthy ? ShieldCheck : ShieldAlert;
  const tone    = !data?.configured ? '#94a3b8' : healthy ? '#16a34a' : '#b94a4a';

  return (
    <Card data-testid="rbac-bridge-health">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, gap: 8 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Icon size={16} color={tone} />
          <strong style={{ fontSize: 14 }}>RBAC bridge agreement</strong>
        </div>
        <button onClick={load} className="btn btn--ghost btn--sm"
                aria-label="Refresh" data-testid="rbac-bridge-health-refresh"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
          <RefreshCw size={14} />
        </button>
      </div>

      {loading && <div style={{ color: 'var(--cf-text-secondary)' }}>Loading…</div>}
      {error && !loading && <div style={{ color: '#b94a4a' }} data-testid="rbac-bridge-health-error">{error}</div>}

      {!loading && !error && data && !data.configured && (
        <div style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }} data-testid="rbac-bridge-health-unconfigured">
          Migration 056 not applied yet — bridge audit table will start collecting once it lands.
        </div>
      )}

      {!loading && !error && data?.configured && (
        <div data-testid="rbac-bridge-health-body">
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 12, flexWrap: 'wrap', marginBottom: 10 }}>
            <div>
              <div style={{ fontSize: 22, fontWeight: 700, color: tone, lineHeight: 1 }}
                   data-testid="rbac-bridge-health-total">
                {data.total_disagreements}
              </div>
              <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                disagreements in last {data.window_hours}h
              </div>
            </div>
            <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              <div data-testid="rbac-bridge-legacy-only">legacy-only grants: <strong>{data.legacy_only_grants}</strong></div>
              <div data-testid="rbac-bridge-new-only">new-only grants: <strong>{data.new_only_grants}</strong></div>
            </div>
          </div>

          {healthy ? (
            <div style={{ fontSize: 12, color: '#16a34a', padding: 8, background: '#f0fdf4',
                          border: '1px solid #bbf7d0', borderRadius: 6 }}
                 data-testid="rbac-bridge-health-green">
              Bridge agrees across the board. Safe to consider flipping <code>CF_RBAC_BRIDGE_MODE=new</code>.
            </div>
          ) : (
            <>
              <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 6 }}>
                Top disagreeing permissions
              </div>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}
                     data-testid="rbac-bridge-top-perms">
                <thead>
                  <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)' }}>
                    <th style={{ padding: '4px 0' }}>Permission</th>
                    <th>Legacy</th>
                    <th>New</th>
                    <th style={{ textAlign: 'right' }}>Hits</th>
                  </tr>
                </thead>
                <tbody>
                  {data.top_perms.map((p) => (
                    <tr key={`${p.perm}-${p.legacy_ok}-${p.new_ok}`}
                        data-testid={`rbac-bridge-perm-${p.perm}`}
                        style={{ borderTop: '1px solid var(--cf-border)' }}>
                      <td style={{ padding: '6px 0', fontFamily: 'monospace' }}>{p.perm}</td>
                      <td style={{ color: p.legacy_ok ? '#16a34a' : '#b94a4a' }}>
                        {p.legacy_ok ? '✓' : '✗'}
                      </td>
                      <td style={{ color: p.new_ok ? '#16a34a' : '#b94a4a' }}>
                        {p.new_ok ? '✓' : '✗'}
                      </td>
                      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{p.count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {Array.isArray(data.recent) && data.recent.length > 0 && (
                <div style={{ marginTop: 14 }} data-testid="rbac-bridge-recent-section">
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 6 }}>
                    Recent disagreements
                  </div>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 11 }}
                         data-testid="rbac-bridge-recent">
                    <thead>
                      <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)' }}>
                        <th style={{ padding: '4px 0' }}>When</th>
                        <th>Permission</th>
                        <th>Module:action</th>
                        <th>User</th>
                        <th>Legacy</th>
                        <th>New</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent.slice(0, 10).map((r) => (
                        <tr key={r.id}
                            data-testid={`rbac-bridge-recent-row-${r.id}`}
                            style={{ borderTop: '1px solid var(--cf-border)' }}>
                          <td style={{ padding: '6px 0', whiteSpace: 'nowrap', color: 'var(--cf-text-secondary)' }}>
                            {String(r.occurred_at).replace('T', ' ').slice(0, 16)}
                          </td>
                          <td style={{ fontFamily: 'monospace' }}>{r.perm}</td>
                          <td style={{ fontFamily: 'monospace', color: 'var(--cf-text-secondary)' }}>
                            {r.module}:{r.action}
                          </td>
                          <td style={{ color: 'var(--cf-text-secondary)' }}>
                            {r.user_id ? `#${r.user_id}` : '—'}
                          </td>
                          <td style={{ color: r.legacy_ok ? '#16a34a' : '#b94a4a' }}>
                            {r.legacy_ok ? '✓' : '✗'}
                          </td>
                          <td style={{ color: r.new_ok ? '#16a34a' : '#b94a4a' }}>
                            {r.new_ok ? '✓' : '✗'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}
        </div>
      )}
    </Card>
  );
}
