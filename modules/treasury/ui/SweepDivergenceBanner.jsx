import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * <SweepDivergenceBanner /> — in-app surface for Treasury Sweep
 * divergence. Mirrors the daily-email cron at
 * `/cron/treasury_sweep_divergence_alert.php` so operators don't
 * have to wait 24h for the report.
 *
 * Renders a coloured banner at the top of the Sweep admin views.
 * Tone follows the highest-severity alert in the window:
 *   - any failed sweep            → red (error)
 *   - dry-run sweeps planned only → amber (warn — "ready to go live")
 *   - everything clean            → green (info, collapsed by default)
 *
 * Auto-refreshes every 5 min while mounted. Expanding the banner
 * shows the per-rule alert list with timestamps + drill links.
 *
 * Props:
 *   - hours: window size in hours (default 24)
 *   - defaultOpen: render expanded immediately (default false)
 */
export default function SweepDivergenceBanner({ hours = 24, defaultOpen = false }) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError]     = useState(null);
  const [open, setOpen]       = useState(defaultOpen);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/admin/treasury/sweep_divergence.php?hours=${hours}`);
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  };
  useEffect(() => {
    reload();
    const h = setInterval(reload, 5 * 60 * 1000);
    return () => clearInterval(h);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hours]);

  if (error) {
    return (
      <div
        data-testid="sweep-divergence-banner-error"
        style={{ ...bannerBase, background: '#fee2e2', borderColor: '#fca5a5', color: '#991b1b' }}
      >
        Sweep divergence check failed: {error}
      </div>
    );
  }
  if (!data) {
    return (
      <div data-testid="sweep-divergence-banner-loading" style={{ ...bannerBase, background: '#f1f5f9', color: '#64748b' }}>
        Checking sweep activity…
      </div>
    );
  }

  const { totals = {}, alerts = [] } = data;
  const errorCount = alerts.filter(a => a.severity === 'error').length;
  const warnCount  = alerts.filter(a => a.severity === 'warn').length;
  const hasError   = errorCount > 0;
  const hasWarn    = warnCount  > 0;
  const tone =
    hasError ? { bg: '#fee2e2', border: '#fca5a5', fg: '#991b1b', kind: 'error' } :
    hasWarn  ? { bg: '#fef3c7', border: '#fde68a', fg: '#92400e', kind: 'warn'  } :
               { bg: '#dcfce7', border: '#86efac', fg: '#166534', kind: 'clear' };

  const headline =
    hasError ? `${errorCount} sweep failure${errorCount === 1 ? '' : 's'} in the last ${hours}h` :
    hasWarn  ? `${warnCount} planned dry-run sweep${warnCount === 1 ? '' : 's'} — ready to go live` :
               (totals.total_runs > 0
                 ? `Sweep clean: ${totals.total_runs} run${totals.total_runs === 1 ? '' : 's'} / 0 divergences in the last ${hours}h`
                 : `No sweep activity in the last ${hours}h`);

  return (
    <div
      data-testid="sweep-divergence-banner"
      data-tone={tone.kind}
      data-error-count={errorCount}
      data-warn-count={warnCount}
      style={{ ...bannerBase, background: tone.bg, borderColor: tone.border, color: tone.fg }}
    >
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <strong data-testid="sweep-divergence-banner-headline">{headline}</strong>
          {data.live_mode === false && (
            <span
              data-testid="sweep-divergence-banner-mode"
              style={{
                fontSize: 11, padding: '1px 6px', borderRadius: 4,
                background: tone.fg + '22', fontWeight: 600,
              }}
            >
              DRY-RUN MODE
            </span>
          )}
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button
            type="button"
            data-testid="sweep-divergence-banner-refresh"
            onClick={reload}
            disabled={loading}
            style={iconBtn(tone)}
            title="Refresh"
          >
            {loading ? '…' : '↻'}
          </button>
          {alerts.length > 0 && (
            <button
              type="button"
              data-testid="sweep-divergence-banner-toggle"
              onClick={() => setOpen(o => !o)}
              style={iconBtn(tone)}
            >
              {open ? 'Hide' : `Show ${alerts.length}`}
            </button>
          )}
        </div>
      </div>

      {open && alerts.length > 0 && (
        <ul
          data-testid="sweep-divergence-banner-list"
          style={{ margin: '10px 0 0', paddingLeft: 18, fontSize: 12, color: tone.fg, listStyle: 'none' }}
        >
          {alerts.map(a => (
            <li
              key={a.id}
              data-testid={`sweep-divergence-banner-alert-${a.id}`}
              data-severity={a.severity}
              style={{ marginBottom: 6, borderLeft: `3px solid ${tone.fg}`, paddingLeft: 8 }}
            >
              <strong>{a.rule_name}</strong>{' '}
              <span style={{ fontSize: 11, opacity: 0.7 }}>
                {a.ran_at} · {a.outcome}{a.dry_run ? ' (dry-run)' : ''}
              </span>
              <div style={{ marginTop: 2 }}>{a.message}</div>
              {a.payment_instruction_id && (
                <div style={{ fontSize: 11, opacity: 0.8 }}>
                  PI #{a.payment_instruction_id}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const bannerBase = {
  marginBottom: 12,
  padding: '10px 14px',
  border: '1px solid',
  borderRadius: 8,
  fontSize: 13,
  lineHeight: 1.4,
};

const iconBtn = (tone) => ({
  padding: '2px 8px',
  fontSize: 12,
  border: `1px solid ${tone.fg}33`,
  background: 'transparent',
  color: tone.fg,
  borderRadius: 4,
  cursor: 'pointer',
});
