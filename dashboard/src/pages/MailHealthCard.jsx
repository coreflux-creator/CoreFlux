import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { Mail, ChevronRight, CheckCircle2, AlertTriangle, AlertOctagon, MinusCircle } from 'lucide-react';
import MailOutboxDetailModal from './MailOutboxDetailModal';

/**
 * MailHealthCard — live "Mail health" tile for the Integrations Hub.
 *
 * Calls /api/admin/mail_health.php and surfaces:
 *   • status pill (healthy / degraded / critical / silent)
 *   • 24h sent vs failed counts + failure %
 *   • Per-driver split (resend / log / phpmailer_smtp)
 *   • 7-day spark bars (sent + failed)
 *   • Top 5 purposes (most-active flows)
 *   • Last 5 failures with truncated error
 *   • RESEND_API_KEY + verified-from-domain readout
 *
 * Click anywhere on the card → /admin/mail-settings (where the
 * MailTestSendCard lives) so admins can fire a real send to verify.
 */
const STATUS_META = {
  healthy:  { label: 'Healthy',  bg: '#dcfce7', fg: '#065f46', Icon: CheckCircle2 },
  degraded: { label: 'Degraded', bg: '#fef3c7', fg: '#92400e', Icon: AlertTriangle },
  critical: { label: 'Critical', bg: '#fee2e2', fg: '#991b1b', Icon: AlertOctagon },
  silent:   { label: 'Silent',   bg: '#f1f5f9', fg: '#475569', Icon: MinusCircle },
};

const DRIVER_TONE = {
  resend:         { bg: '#dcfce7', fg: '#065f46' },
  log:            { bg: '#fef3c7', fg: '#92400e' },
  phpmailer_smtp: { bg: '#dbeafe', fg: '#1e40af' },
};

export default function MailHealthCard() {
  const { data, loading, error, reload } = useApi('/api/admin/mail_health.php');
  // Slice 3.3 — clicked failure row's mail_outbox id; null = closed.
  const [outboxOpen, setOutboxOpen] = useState(null);

  return (
    <div data-testid="integration-card-mail-health"
         style={{
           padding: 'var(--cf-space-5)',
           border: '1px solid var(--cf-border)',
           borderRadius: 8,
           background: 'var(--cf-surface)',
           display: 'flex', flexDirection: 'column',
           gap: 'var(--cf-space-3)',
         }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-3)' }}>
          <div style={{
            width: 40, height: 40, borderRadius: 8,
            background: 'var(--cf-blue-bg, #eff6ff)', color: 'var(--cf-blue, #2563eb)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <Mail size={20} />
          </div>
          <div>
            <div style={{ fontWeight: 600 }}>Mail health</div>
            <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              Resend delivery state · last 24 hours
            </div>
          </div>
        </div>
        <StatusPill status={data?.status} loading={loading} />
      </header>

      {error && (
        <div data-testid="mail-health-error"
             style={{ fontSize: 13, color: 'var(--cf-red, #b91c1c)' }}>
          Could not load mail health: {error.message || String(error)}
          <button type="button" className="btn"
                  data-testid="mail-health-retry"
                  onClick={reload}
                  style={{ marginLeft: 8, fontSize: 11 }}>
            Retry
          </button>
        </div>
      )}

      {!error && (
        <>
          {/* Headline numbers */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
            <MiniTile testid="mail-health-sent"
                      label="Sent (24h)" value={data?.rollup_24h?.sent ?? 0} />
            <MiniTile testid="mail-health-failed"
                      label="Failed (24h)"
                      value={(data?.rollup_24h?.failed ?? 0) +
                             (data?.rollup_24h?.bounced ?? 0) +
                             (data?.rollup_24h?.complaint ?? 0)}
                      tone={(data?.rollup_24h?.failure_pct ?? 0) > 0 ? 'warn' : 'ok'} />
            <MiniTile testid="mail-health-failure-pct"
                      label="Failure %"
                      value={`${(data?.rollup_24h?.failure_pct ?? 0).toFixed(1)}%`}
                      tone={(data?.rollup_24h?.failure_pct ?? 0) >= 25 ? 'err'
                          : (data?.rollup_24h?.failure_pct ?? 0) >=  5 ? 'warn'
                          :                                              'ok'} />
          </div>

          {/* Configuration readout */}
          <div data-testid="mail-health-config"
               style={{ fontSize: 12, color: 'var(--cf-text-secondary)',
                        display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
            <span
              data-testid="mail-health-resend-flag"
              style={{
                padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600,
                background: data?.resend_configured ? '#dcfce7' : '#fee2e2',
                color:      data?.resend_configured ? '#065f46' : '#991b1b',
              }}
            >
              RESEND_API_KEY: {data?.resend_configured ? 'configured' : 'not set'}
            </span>
            {data?.from_email && (
              <span data-testid="mail-health-from-email"
                    style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>
                from: {data.from_email}
              </span>
            )}
            {data?.default_driver && (
              <span
                data-testid="mail-health-default-driver"
                style={{
                  padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600,
                  ...(DRIVER_TONE[data.default_driver] || { bg: '#f1f5f9', fg: '#334155' }),
                }}
              >
                default: {data.default_driver}
              </span>
            )}
          </div>

          {/* Driver split */}
          {data?.rollup_24h?.drivers && Object.keys(data.rollup_24h.drivers).length > 0 && (
            <div data-testid="mail-health-driver-split"
                 style={{ display: 'flex', flexWrap: 'wrap', gap: 4, fontSize: 11 }}>
              {Object.entries(data.rollup_24h.drivers).map(([d, n]) => (
                <span key={d}
                      data-testid={`mail-health-driver-${d}`}
                      style={{
                        padding: '2px 8px', borderRadius: 999,
                        ...(DRIVER_TONE[d] || { bg: '#f1f5f9', fg: '#334155' }),
                      }}>
                  {d}: {n}
                </span>
              ))}
            </div>
          )}

          {/* 7-day spark */}
          {data?.daily_7d?.length > 0 && (
            <SparkBars daily={data.daily_7d} />
          )}

          {/* Top purposes */}
          {data?.top_purposes_24h?.length > 0 && (
            <div data-testid="mail-health-purposes" style={{ fontSize: 12 }}>
              <div style={{ color: 'var(--cf-text-secondary)', fontWeight: 600,
                            textTransform: 'uppercase', letterSpacing: 0.3, fontSize: 10,
                            marginBottom: 4 }}>
                Top flows (24h)
              </div>
              <ul style={{ listStyle: 'none', padding: 0, margin: 0,
                           display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                {data.top_purposes_24h.map((p) => (
                  <li key={p.purpose}
                      data-testid={`mail-health-purpose-${p.purpose}`}
                      style={{
                        padding: '2px 8px', borderRadius: 999,
                        background: '#eef2ff', color: '#3730a3',
                        border: '1px solid #c7d2fe',
                      }}>
                    <code>{p.purpose}</code> · {p.sent}
                    {p.failed > 0 && <span style={{ color: '#b91c1c' }}> · {p.failed} failed</span>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Recent failures */}
          {data?.recent_failures?.length > 0 && (
            <div data-testid="mail-health-failures">
              <div style={{ color: 'var(--cf-text-secondary)', fontWeight: 600,
                            textTransform: 'uppercase', letterSpacing: 0.3, fontSize: 10,
                            marginBottom: 4 }}>
                Recent failures
              </div>
              <ul style={{ listStyle: 'none', padding: 0, margin: 0, fontSize: 11 }}>
                {data.recent_failures.slice(0, 3).map((f) => (
                  <li key={f.id}
                      data-testid={`mail-health-failure-${f.id}`}
                      style={{ marginBottom: 4, padding: '4px 6px',
                               background: '#fef2f2', borderRadius: 4,
                               border: '1px solid #fecaca' }}>
                    <button
                      type="button"
                      data-testid={`mail-health-failure-open-${f.id}`}
                      onClick={() => setOutboxOpen(f.id)}
                      title="Open this row in the mail outbox detail viewer"
                      style={{
                        background: 'transparent', border: 'none', padding: 0,
                        textAlign: 'left', width: '100%', cursor: 'pointer',
                        color: 'inherit', font: 'inherit',
                      }}>
                      <div>
                        <strong>{f.purpose}</strong>{' '}
                        <span style={{ color: '#64748b' }}>· {f.driver} · {f.created_at}</span>
                        <ChevronRight size={11} style={{ verticalAlign: 'middle', marginLeft: 4, color: '#7c3aed' }} />
                      </div>
                      <div style={{ color: '#991b1b', fontFamily: 'var(--cf-mono, ui-monospace)' }}>
                        {f.error || '(no error message captured)'}
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Hint */}
          {data?.hint && (
            <div data-testid="mail-health-hint"
                 style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              {data.hint}
            </div>
          )}
        </>
      )}

      {/* Drill-through to Mail Settings (where the test-send card lives) */}
      <Link to="/admin/mail-settings"
            data-testid="mail-health-manage"
            style={{
              marginTop: 'auto',
              display: 'flex', alignItems: 'center', gap: 4,
              fontSize: 12, color: 'var(--cf-accent, #2563eb)',
              textDecoration: 'none',
            }}>
        Manage & send test <ChevronRight size={14} />
      </Link>

      {outboxOpen !== null && (
        <MailOutboxDetailModal
          outboxId={outboxOpen}
          onClose={() => setOutboxOpen(null)}
        />
      )}
    </div>
  );
}

function StatusPill({ status, loading }) {
  if (loading || !status) {
    return (
      <span data-testid="mail-health-status-loading"
            style={{
              fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 4,
              background: '#f1f5f9', color: '#475569',
            }}>
        …
      </span>
    );
  }
  const meta = STATUS_META[status] || STATUS_META.silent;
  const { Icon } = meta;
  return (
    <span
      data-testid={`mail-health-status-${status}`}
      style={{
        fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 4,
        background: meta.bg, color: meta.fg,
        display: 'inline-flex', alignItems: 'center', gap: 4,
      }}
    >
      <Icon size={12} /> {meta.label}
    </span>
  );
}

function MiniTile({ testid, label, value, tone = 'neutral' }) {
  const palette = tone === 'ok'   ? { bg: '#ecfdf5', fg: '#065f46', border: '#a7f3d0' }
                : tone === 'warn' ? { bg: '#fef3c7', fg: '#92400e', border: '#fde68a' }
                : tone === 'err'  ? { bg: '#fef2f2', fg: '#991b1b', border: '#fecaca' }
                :                   { bg: '#f8fafc', fg: '#0f172a', border: '#e2e8f0' };
  return (
    <div data-testid={testid}
         style={{
           padding: '8px 10px',
           background: palette.bg,
           border: `1px solid ${palette.border}`,
           borderRadius: 6,
         }}>
      <div style={{ fontSize: 9, fontWeight: 700, color: palette.fg,
                    textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {label}
      </div>
      <div style={{ fontSize: 18, fontWeight: 700, color: palette.fg,
                    fontVariantNumeric: 'tabular-nums', marginTop: 2 }}>
        {value}
      </div>
    </div>
  );
}

function SparkBars({ daily }) {
  const max = Math.max(1, ...daily.map((d) => (d.sent || 0) + (d.failed || 0)));
  return (
    <div data-testid="mail-health-spark"
         style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 44,
                  padding: '6px 0', borderTop: '1px dashed var(--cf-border-muted, #e2e8f0)' }}>
      {daily.map((d) => {
        const totalH = Math.max(2, Math.round(((d.sent || 0) + (d.failed || 0)) / max * 32));
        const failedH = d.failed > 0
          ? Math.max(1, Math.round((d.failed / Math.max(1, d.sent + d.failed)) * totalH))
          : 0;
        const sentH = Math.max(0, totalH - failedH);
        return (
          <div
            key={d.day}
            data-testid={`mail-health-spark-${d.day}`}
            title={`${d.day} · ${d.sent} sent · ${d.failed} failed`}
            style={{ flex: 1, display: 'flex', flexDirection: 'column-reverse', gap: 1,
                     cursor: 'help' }}
          >
            <div style={{ height: sentH,   background: '#22c55e', borderRadius: 2 }} />
            {failedH > 0 && (
              <div style={{ height: failedH, background: '#ef4444', borderRadius: 2 }} />
            )}
          </div>
        );
      })}
    </div>
  );
}
