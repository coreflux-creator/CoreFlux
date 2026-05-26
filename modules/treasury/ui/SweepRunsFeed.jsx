import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
import { Sparkles, AlertCircle, CheckCircle2 } from 'lucide-react';

/**
 * SweepRunsFeed — operator-facing audit feed for the Treasury Sweep
 * worker. Reads /api/admin/treasury/sweep_runs.php.
 *
 * Two things this view answers, both critical before flipping
 * TREASURY_SWEEP_LIVE=1:
 *   1. "Is the worker firing on the right cadence?" — every fire
 *      (including skipped/failed) lands in the table.
 *   2. "Does the engine math match my mental model?" — the planned
 *      vs. live totals + per-rule per-day amounts let an operator
 *      eyeball drift before any money moves.
 */
const formatCents = (c) => (c == null ? '—' : `$${(Number(c) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
const formatDt    = (s) => (s ? new Date(s.replace(' ', 'T') + 'Z').toLocaleString() : '—');

const OUTCOME_META = {
  swept:                 { label: 'Swept',                 color: '#16a34a', kind: 'ok'    },
  skipped_under_floor:   { label: 'Below floor',           color: '#64748b', kind: 'idle'  },
  skipped_not_due:       { label: 'Not scheduled',         color: '#94a3b8', kind: 'idle'  },
  skipped_disabled:      { label: 'Rule disabled',         color: '#94a3b8', kind: 'idle'  },
  failed_no_connection:  { label: 'No Mercury connection', color: '#dc2626', kind: 'fail'  },
  failed_balance_fetch:  { label: 'Balance fetch failed',  color: '#dc2626', kind: 'fail'  },
  failed_execute:        { label: 'Execute failed',        color: '#dc2626', kind: 'fail'  },
};

export default function SweepRunsFeed() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [days, setDays] = useState(30);

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError(null);
    api.get(`/api/admin/treasury/sweep_runs.php?days=${days}`)
      .then(r => { if (!cancelled) setData(r); })
      .catch(e => { if (!cancelled) setError(e.message || 'Failed to load'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [days]);

  const rows    = data?.rows || [];
  const summary = data?.summary || {};
  const liveMode = !!data?.live_mode;
  const migrationPending = !!data?.migration_pending;

  // Severity banner — when dry-run mode and we have a streak of clean
  // 'swept' outcomes, surface that as go-live readiness evidence.
  const goLiveReadiness = useMemo(() => {
    if (liveMode || rows.length === 0) return null;
    const fails = rows.filter(r => String(r.outcome).startsWith('failed_')).length;
    const swept = rows.filter(r => r.outcome === 'swept').length;
    if (fails === 0 && swept >= 4) return 'ready';
    if (fails > 0)                  return 'blocked';
    return null;
  }, [rows, liveMode]);

  return (
    <section data-testid="sweep-runs-feed" style={{ marginTop: '2rem' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: '0.75rem' }}>
        <div>
          <h3 style={{ margin: 0, fontSize: 16 }}>Worker audit feed</h3>
          <p style={{ color: '#64748b', fontSize: 13, marginTop: 2 }}>
            Every evaluation the worker does — dry-run or live — lands here. Tail this before flipping <code>TREASURY_SWEEP_LIVE=1</code>.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <span data-testid="sweep-runs-mode-badge" style={{
            display: 'inline-flex', alignItems: 'center', gap: 4,
            padding: '2px 8px', fontSize: 11, fontWeight: 600,
            background: liveMode ? '#dcfce7' : '#fef3c7',
            color:      liveMode ? '#15803d' : '#92400e',
            borderRadius: 999,
          }}>
            {liveMode ? <CheckCircle2 size={12} /> : <Sparkles size={12} />}
            {liveMode ? 'LIVE' : 'DRY-RUN'}
          </span>
          <select
            data-testid="sweep-runs-window"
            value={days}
            onChange={e => setDays(Number(e.target.value))}
            style={{ padding: '4px 8px', border: '1px solid #e5e7eb', borderRadius: 4, fontSize: 13 }}
          >
            <option value={7}>Last 7 days</option>
            <option value={30}>Last 30 days</option>
            <option value={90}>Last 90 days</option>
          </select>
        </div>
      </header>

      {migrationPending && (
        <div data-testid="sweep-runs-migration-banner" style={{
          padding: '0.5rem 0.75rem', background: '#fef3c7', border: '1px solid #fde68a',
          color: '#92400e', borderRadius: 6, fontSize: 13, marginBottom: '0.75rem',
        }}>
          Migration 074 hasn&apos;t run yet — the audit table doesn&apos;t exist. Run pending migrations to start collecting worker evidence.
        </div>
      )}

      {error && <p className="error" data-testid="sweep-runs-error">{error}</p>}
      {loading && <p data-testid="sweep-runs-loading">Loading…</p>}

      {!loading && !migrationPending && (
        <>
          <div data-testid="sweep-runs-summary" style={{
            display: 'grid', gap: 12, marginBottom: '1rem',
            gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
          }}>
            <SummaryCard
              testid="sweep-runs-total" label="Total evaluations"
              value={summary.total_runs || 0}
            />
            <SummaryCard
              testid="sweep-runs-swept-dryrun" label="Planned (dry-run)"
              value={formatCents(summary.total_planned_cents_dryrun)}
              hint={`${summary.dry_run_count || 0} evaluations`}
            />
            <SummaryCard
              testid="sweep-runs-swept-live" label="Swept (live)"
              value={formatCents(summary.total_swept_cents_live)}
              hint={`${summary.live_count || 0} evaluations`}
              accent={summary.live_count > 0 ? '#15803d' : null}
            />
            <SummaryCard
              testid="sweep-runs-fails"
              label="Failures"
              value={Object.entries(summary.by_outcome || {})
                .filter(([k]) => k.startsWith('failed_'))
                .reduce((a, [, v]) => a + v, 0)}
              accent={'#dc2626'}
            />
          </div>

          {goLiveReadiness === 'ready' && (
            <div data-testid="sweep-runs-readiness-ready" style={{
              padding: '0.5rem 0.75rem', background: '#dcfce7', border: '1px solid #bbf7d0',
              color: '#15803d', borderRadius: 6, fontSize: 13, marginBottom: '0.75rem',
              display: 'flex', alignItems: 'center', gap: 8,
            }}>
              <CheckCircle2 size={16} />
              <span>
                <strong>Go-live evidence:</strong> {days} days of clean dry-run with zero failures.
                Safe to flip <code>TREASURY_SWEEP_LIVE=1</code> when the internal-transfer recipient model lands.
              </span>
            </div>
          )}
          {goLiveReadiness === 'blocked' && (
            <div data-testid="sweep-runs-readiness-blocked" style={{
              padding: '0.5rem 0.75rem', background: '#fef2f2', border: '1px solid #fecaca',
              color: '#991b1b', borderRadius: 6, fontSize: 13, marginBottom: '0.75rem',
              display: 'flex', alignItems: 'center', gap: 8,
            }}>
              <AlertCircle size={16} />
              <span>
                <strong>Not go-live ready:</strong> there are failed evaluations in the window. Investigate the rows below before flipping live mode.
              </span>
            </div>
          )}

          <table className="data-table" data-testid="sweep-runs-table" style={{ width: '100%', fontSize: 13 }}>
            <thead>
              <tr style={{ fontSize: 11, color: '#64748b', textAlign: 'left' }}>
                <th style={{ padding: '6px 8px' }}>When</th>
                <th style={{ padding: '6px 8px' }}>Rule</th>
                <th style={{ padding: '6px 8px', textAlign: 'right' }}>Source balance</th>
                <th style={{ padding: '6px 8px', textAlign: 'right' }}>Sweep amount</th>
                <th style={{ padding: '6px 8px' }}>Outcome</th>
                <th style={{ padding: '6px 8px' }}>Mode</th>
                <th style={{ padding: '6px 8px' }}>Notes</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={7} className="empty" data-testid="sweep-runs-empty" style={{ padding: 16, color: '#94a3b8' }}>
                  No worker runs in the last {days} days yet.
                  The cron driver fires at 08:30 daily — check <code>/var/log/cron.log</code> if you expected runs by now.
                </td></tr>
              )}
              {rows.map(r => {
                const meta = OUTCOME_META[r.outcome] || { label: r.outcome, color: '#475569' };
                return (
                  <tr key={r.id} data-testid={`sweep-run-${r.id}`} data-outcome={r.outcome}>
                    <td style={{ padding: '6px 8px', color: '#64748b' }}>{formatDt(r.ran_at)}</td>
                    <td style={{ padding: '6px 8px', fontWeight: 500 }}>{r.rule_name || `#${r.rule_id}`}</td>
                    <td style={{ padding: '6px 8px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{formatCents(r.source_balance_cents)}</td>
                    <td style={{ padding: '6px 8px', textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 500 }}>{formatCents(r.sweep_amount_cents)}</td>
                    <td style={{ padding: '6px 8px' }}>
                      <span style={{
                        display: 'inline-block', padding: '1px 8px', borderRadius: 999,
                        fontSize: 11, fontWeight: 600,
                        background: meta.color + '1a', color: meta.color,
                      }}>{meta.label}</span>
                    </td>
                    <td style={{ padding: '6px 8px', fontSize: 11, color: r.dry_run ? '#92400e' : '#15803d' }}>
                      {r.dry_run ? 'DRY-RUN' : 'LIVE'}
                    </td>
                    <td style={{ padding: '6px 8px', color: '#64748b', fontSize: 12 }}>
                      {r.error_message
                        ? <code style={{ background: '#fef2f2', color: '#991b1b', padding: '0 4px', borderRadius: 3 }}>{r.error_message}</code>
                        : r.payment_instruction_id
                          ? <span>PI #{r.payment_instruction_id}</span>
                          : '—'}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

function SummaryCard({ testid, label, value, hint, accent }) {
  return (
    <div data-testid={testid} style={{
      padding: 12, borderRadius: 8,
      border: '1px solid #e5e7eb', background: '#ffffff',
    }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 700, color: accent || '#0f172a', marginTop: 2 }}>{value}</div>
      {hint && <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>{hint}</div>}
    </div>
  );
}
