import React, { useState } from 'react';
import { Database, ChevronDown, ChevronRight, Clock, AlertCircle, CheckCircle2 } from 'lucide-react';
import { useApi } from '../lib/api';

/**
 * <FscHealthPanel /> — collapsible "Financial State Cache" health section.
 *
 * Mounts in the CFO Dashboard footer in its own section so it doesn't
 * overwhelm the main grid. Collapsed by default — only operators looking
 * for "is the dashboard fast and fresh?" expand it.
 *
 * Data source: /api/admin/fsc_health.php (read-only, 5s computation, no
 * RBAC tier — same data class as the dashboard payload itself).
 */
export default function FscHealthPanel() {
  const [open, setOpen] = useState(false);
  const { data, error } = useApi(open ? '/api/admin/fsc_health.php' : null, [open]);

  return (
    <section data-testid="fsc-health-panel"
             style={{
               marginTop: 'var(--cf-space-4, 24px)',
               border: '1px solid #e2e8f0',
               borderRadius: 8,
               background: '#fff',
             }}>
      <button onClick={() => setOpen(o => !o)}
              data-testid="fsc-health-toggle"
              style={{
                width: '100%',
                background: 'transparent',
                border: 'none',
                padding: 14,
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                cursor: 'pointer',
                color: '#475569',
                fontSize: 13,
                fontWeight: 600,
                textAlign: 'left',
              }}>
        {open ? <ChevronDown size={14}/> : <ChevronRight size={14}/>}
        <Database size={14}/> Cache Health
        {data && data.configured && (
          <span style={{
            marginLeft: 'auto',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 4,
            fontSize: 11,
            fontWeight: 500,
            color: data.dirty_count > 0 ? '#b45309' : '#15803d',
          }}>
            {data.dirty_count > 0
              ? <><AlertCircle size={11}/> {data.dirty_count} pending</>
              : <><CheckCircle2 size={11}/> all fresh</>}
          </span>
        )}
      </button>

      {open && (
        <div style={{ padding: '0 14px 14px', fontSize: 13 }}>
          {error && (
            <p className="error" data-testid="fsc-health-error" style={{ color:'#dc2626' }}>
              Could not load cache health.
            </p>
          )}
          {data && data.configured === false && (
            <p data-testid="fsc-health-not-configured" style={{ color: '#64748b' }}>
              {data.reason || 'Migration 045 has not run yet on this tenant.'}
            </p>
          )}
          {data && data.configured && (
            <>
              <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
                gap: 10,
                marginBottom: 12,
              }}>
                <Tile label="Rows cached"      value={data.rows_cached?.toLocaleString() ?? '0'} testid="fsc-rows"/>
                <Tile label="Scopes"           value={data.scopes_cached?.toLocaleString() ?? '0'} testid="fsc-scopes"/>
                <Tile label="Pending dirty"    value={data.dirty_count?.toLocaleString() ?? '0'} testid="fsc-dirty"
                      emphasis={data.dirty_count > 0 ? 'warn' : 'good'}/>
                <Tile label="Last rebuild"     value={relTime(data.newest_metric_age_s)} testid="fsc-last-rebuild"/>
                <Tile label="Oldest pending"   value={relTime(data.oldest_dirty_age_s)} testid="fsc-oldest-dirty"
                      emphasis={data.oldest_dirty_age_s > 600 ? 'warn' : 'normal'}/>
              </div>

              {data.per_scope?.length > 0 && (
                <div data-testid="fsc-per-scope" style={{ marginBottom: 12 }}>
                  <h4 style={{ fontSize: 12, color: '#64748b', textTransform: 'uppercase', letterSpacing: '.04em', margin: '0 0 6px' }}>
                    Rebuild runtime by scope
                  </h4>
                  <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
                    <thead>
                      <tr style={{ color: '#94a3b8', textAlign: 'left' }}>
                        <th style={{ padding: '4px 6px', fontWeight: 500 }}>Scope</th>
                        <th style={{ padding: '4px 6px', fontWeight: 500, textAlign: 'right' }}>Metrics</th>
                        <th style={{ padding: '4px 6px', fontWeight: 500, textAlign: 'right' }}>Avg ms</th>
                        <th style={{ padding: '4px 6px', fontWeight: 500, textAlign: 'right' }}>Max ms</th>
                        <th style={{ padding: '4px 6px', fontWeight: 500 }}>Last</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.per_scope.map(s => (
                        <tr key={s.scope_key} data-testid={`fsc-scope-row-${s.scope_key}`}
                            style={{ borderTop: '1px solid #f1f5f9' }}>
                          <td style={{ padding: '4px 6px', fontFamily:'monospace' }}>{s.scope_key}</td>
                          <td style={{ padding: '4px 6px', textAlign: 'right' }}>{s.metrics}</td>
                          <td style={{ padding: '4px 6px', textAlign: 'right' }}>{s.avg_ms ?? '—'}</td>
                          <td style={{ padding: '4px 6px', textAlign: 'right' }}>{s.max_ms ?? '—'}</td>
                          <td style={{ padding: '4px 6px', color: '#94a3b8' }}>
                            {s.last_at ? new Date(s.last_at.replace(' ', 'T') + 'Z').toLocaleString() : '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {data.top_dirty_reasons?.length > 0 && (
                <div data-testid="fsc-dirty-reasons">
                  <h4 style={{ fontSize: 12, color: '#64748b', textTransform: 'uppercase', letterSpacing: '.04em', margin: '0 0 6px' }}>
                    Dirty reasons (last 24h)
                  </h4>
                  <div style={{ display:'flex', flexWrap:'wrap', gap:6 }}>
                    {data.top_dirty_reasons.map(r => (
                      <span key={r.reason}
                            data-testid={`fsc-reason-${r.reason}`}
                            style={{
                              padding: '2px 8px', borderRadius: 999,
                              background: '#f1f5f9', color: '#475569',
                              fontSize: 11,
                            }}>
                        {r.reason} · {r.count}
                      </span>
                    ))}
                  </div>
                </div>
              )}

              <p style={{ marginTop: 12, fontSize: 11, color: '#94a3b8', display:'inline-flex', alignItems:'center', gap:4 }}>
                <Clock size={10}/> Snapshot {relTime((new Date() - new Date(data.snapshot_at)) / 1000)}
              </p>
            </>
          )}
        </div>
      )}
    </section>
  );
}

function Tile({ label, value, testid, emphasis = 'normal' }) {
  const color = emphasis === 'warn' ? '#b45309' : emphasis === 'good' ? '#15803d' : '#0f172a';
  return (
    <div data-testid={testid}
         style={{ padding: 8, border: '1px solid #f1f5f9', borderRadius: 6 }}>
      <div style={{ fontSize: 10, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</div>
      <div style={{ fontSize: 16, fontWeight: 600, color, marginTop: 2 }}>{value}</div>
    </div>
  );
}

function relTime(seconds) {
  if (seconds == null) return '—';
  const s = Math.round(seconds);
  if (s < 60)      return `${s}s ago`;
  if (s < 3600)    return `${Math.round(s / 60)}m ago`;
  if (s < 86400)   return `${Math.round(s / 3600)}h ago`;
  return `${Math.round(s / 86400)}d ago`;
}
