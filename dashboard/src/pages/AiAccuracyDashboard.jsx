import React, { useState } from 'react';
import { useApi } from '../lib/api';

const fmtPct = (n) => n === null || n === undefined ? '—' : `${Math.round(n * 100)}%`;
const fmtConf = (n) => n === null || n === undefined ? '—' : `${(Math.round(n * 1000) / 10).toFixed(1)}%`;
const fmtMs   = (n) => n === null || n === undefined ? '—' : `${n.toLocaleString()} ms`;

/**
 * Tenant-admin AI Accuracy Dashboard.
 *
 * Visible at /admin/ai-accuracy. Lets bookkeepers see, per feature_key:
 *   - Accept rate (over 7d / 30d / 90d)
 *   - Where AI is wrong most often (top overrides)
 *   - Confidence calibration (avg confidence when accepted vs. overridden)
 *   - Training-history depth (how much moat we've compounded)
 */
export default function AiAccuracyDashboard() {
  const [days, setDays] = useState(30);
  const { data, loading, error, reload } =
    useApi(`/api/ai_accuracy.php?days=${days}`);
  const totals  = data?.totals || {};
  const features = data?.features || [];
  const overrides = data?.top_overrides || [];
  const daily = data?.daily || [];
  const history = data?.history_snapshot || [];

  return (
    <section data-testid="ai-accuracy-dashboard" style={{ maxWidth: 1200, margin: '0 auto', padding: 20 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 28 }}>AI Accuracy</h1>
          <p className="muted" style={{ margin: '4px 0 0', fontSize: 14 }}>
            How often the categorizer is right, and where to invest in better prompts or training.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select
            className="input"
            value={days}
            onChange={(e) => setDays(Number(e.target.value))}
            data-testid="ai-accuracy-window"
          >
            <option value={7}>Last 7 days</option>
            <option value={30}>Last 30 days</option>
            <option value={90}>Last 90 days</option>
            <option value={365}>Last year</option>
          </select>
          <button
            className="btn btn--ghost"
            onClick={reload}
            data-testid="ai-accuracy-refresh"
          >
            Refresh
          </button>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}

      {!loading && !error && (
        <>
          <AiUsagePanel days={Math.min(days, 30)} />
          <div style={{
            display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
            gap: 12, marginBottom: 24,
          }}>
            <Stat label="Suggestions"        value={totals.suggestions_count || 0} />
            <Stat label="Accept rate"        value={fmtPct(totals.overall_accept_rate)}
                  hint={`${totals.accepted_count} accepted / ${(totals.accepted_count || 0) + (totals.overridden_count || 0) + (totals.rejected_count || 0)} reviewed`}
                  big />
            <Stat label="Overrides"          value={totals.overridden_count || 0}
                  hint="AI was close but user picked another account" />
            <Stat label="Rejected"           value={totals.rejected_count || 0}
                  hint="Suggestion ignored entirely" />
          </div>

          {history.length > 0 && (
            <section data-testid="ai-accuracy-moat-strength" style={{ marginBottom: 24 }}>
              <h2 style={{ fontSize: 16, marginBottom: 8 }}>Training moat depth</h2>
              <p className="muted" style={{ fontSize: 12, margin: '0 0 8px' }}>
                Every accept builds a per-tenant lookup that the next prediction reuses. Higher counts mean faster, more confident future suggestions.
              </p>
              <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                {history.map((h) => (
                  <div
                    key={h.signal_kind}
                    data-testid={`ai-accuracy-moat-${h.signal_kind}`}
                    style={{
                      padding: 12, background: 'var(--cf-surface)',
                      border: '1px solid var(--cf-border)', borderRadius: 6, minWidth: 160,
                    }}
                  >
                    <div style={{ fontSize: 12, color: '#64748b' }}>{h.signal_kind}</div>
                    <div style={{ fontSize: 22, fontWeight: 600 }}>{h.rows}</div>
                    <div style={{ fontSize: 11, color: '#64748b' }}>
                      {h.total_accepts} accepts total
                    </div>
                  </div>
                ))}
              </div>
            </section>
          )}

          <section style={{ marginBottom: 24 }}>
            <h2 style={{ fontSize: 16, marginBottom: 8 }}>Per-feature accuracy</h2>
            {features.length === 0 ? (
              <p className="muted" data-testid="ai-accuracy-features-empty">
                No reviewed AI suggestions yet in this window. Categorize a transaction (Treasury → any deposit/card → Categorize…) to see this populate.
              </p>
            ) : (
              <table className="data-table" data-testid="ai-accuracy-features-table">
                <thead>
                  <tr>
                    <th>Feature</th>
                    <th style={{ textAlign: 'right' }}>Reviewed</th>
                    <th style={{ textAlign: 'right' }}>Accept rate</th>
                    <th style={{ textAlign: 'right' }}>Overrides</th>
                    <th style={{ textAlign: 'right' }}>Avg conf — accepted</th>
                    <th style={{ textAlign: 'right' }}>Avg conf — overridden</th>
                  </tr>
                </thead>
                <tbody>
                  {features.map((f) => (
                    <tr key={f.feature_key} data-testid={`ai-accuracy-feature-${f.feature_key.replace(/\./g, '-')}`}>
                      <td><code style={{ fontSize: 12 }}>{f.feature_key}</code></td>
                      <td style={{ textAlign: 'right' }}>{f.review_count}</td>
                      <td style={{
                        textAlign: 'right',
                        color: f.accept_rate >= 0.8 ? '#065f46' : f.accept_rate >= 0.5 ? '#b45309' : '#b91c1c',
                        fontWeight: 600,
                      }}>
                        {fmtPct(f.accept_rate)}
                      </td>
                      <td style={{ textAlign: 'right' }}>{f.overridden_count || 0}</td>
                      <td style={{ textAlign: 'right' }}>{fmtConf(f.avg_accepted_conf)}</td>
                      <td style={{ textAlign: 'right' }}>{fmtConf(f.avg_overridden_conf)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>

          <section style={{ marginBottom: 24 }}>
            <h2 style={{ fontSize: 16, marginBottom: 8 }}>Top overrides — where AI was wrong</h2>
            <p className="muted" style={{ fontSize: 12, margin: '0 0 8px' }}>
              When this same AI → human swap happens repeatedly, consider seeding a bank rule or updating the prompt.
            </p>
            {overrides.length === 0 ? (
              <p className="muted" data-testid="ai-accuracy-overrides-empty">
                No overrides yet — AI was right every time it was reviewed. (Or you've only accepted suggestions as-is.)
              </p>
            ) : (
              <table className="data-table" data-testid="ai-accuracy-overrides-table">
                <thead>
                  <tr>
                    <th>Feature</th>
                    <th>AI suggested</th>
                    <th>You picked</th>
                    <th style={{ textAlign: 'right' }}>Times</th>
                    <th style={{ textAlign: 'right' }}>Avg confidence (mistake)</th>
                  </tr>
                </thead>
                <tbody>
                  {overrides.map((o, idx) => (
                    <tr key={idx} data-testid={`ai-accuracy-override-${idx}`}>
                      <td className="muted" style={{ fontSize: 11 }}>{o.feature_key}</td>
                      <td>
                        <code>{o.suggested_code || `#${o.suggested_value}`}</code>{' '}
                        {o.suggested_name || <span className="muted">(deleted)</span>}
                      </td>
                      <td>
                        <code>{o.final_code || `#${o.final_value}`}</code>{' '}
                        <span style={{ fontWeight: 600 }}>{o.final_name || ''}</span>
                      </td>
                      <td style={{ textAlign: 'right', fontWeight: 600 }}>{o.override_count}</td>
                      <td style={{ textAlign: 'right' }}>{fmtConf(o.avg_confidence_when_overridden)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>

          {daily.length > 0 && (
            <section style={{ marginBottom: 24 }}>
              <h2 style={{ fontSize: 16, marginBottom: 8 }}>Daily volume</h2>
              <div style={{ overflowX: 'auto' }}>
                <table className="data-table" data-testid="ai-accuracy-daily-table"
                       style={{ fontSize: 12, minWidth: 600 }}>
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th style={{ textAlign: 'right' }}>Accepted</th>
                      <th style={{ textAlign: 'right' }}>Overridden</th>
                      <th style={{ textAlign: 'right' }}>Rejected</th>
                      <th style={{ textAlign: 'right' }}>Avg confidence</th>
                    </tr>
                  </thead>
                  <tbody>
                    {daily.map((d) => (
                      <tr key={d.snapshot_date}>
                        <td>{d.snapshot_date}</td>
                        <td style={{ textAlign: 'right', color: '#065f46' }}>{d.accepted_count}</td>
                        <td style={{ textAlign: 'right', color: '#b45309' }}>{d.overridden_count}</td>
                        <td style={{ textAlign: 'right', color: '#6b7280' }}>{d.rejected_count}</td>
                        <td style={{ textAlign: 'right' }}>{fmtConf(d.avg_confidence)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </>
      )}
    </section>
  );
}

function Stat({ label, value, hint, big }) {
  return (
    <div style={{
      padding: 12, background: 'var(--cf-surface)',
      border: '1px solid var(--cf-border)', borderRadius: 8,
    }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</div>
      <div style={{ fontSize: big ? 28 : 22, fontWeight: 600, marginTop: 2 }}>{value}</div>
      {hint && <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>{hint}</div>}
    </div>
  );
}

/**
 * AiUsagePanel — last-N-day call volume + success rate + p50/p95 latency
 * pulled from /api/admin/ai_usage.php. Lives at the top of /admin/ai-accuracy
 * so the same page that explains "is AI right?" also answers "how much is AI
 * actually being used?".
 *
 * Cost is intentionally omitted — `ai_interactions` doesn't track tokens or
 * provider cost, so a fake estimate would mislead more than help.
 */
function AiUsagePanel({ days = 7 }) {
  const { data, loading, error } = useApi(`/api/admin/ai_usage.php?days=${days}`);
  if (loading) return <p data-testid="ai-usage-loading">Loading usage…</p>;
  if (error)   return <p className="error" data-testid="ai-usage-error">{error.message}</p>;
  if (!data)   return null;

  const totals = data.totals || {};
  const byClass = data.by_feature_class || [];
  const topKeys = data.top_feature_keys || [];

  return (
    <section data-testid="ai-usage-panel" style={{ marginBottom: 28, padding: 16, background: 'var(--cf-surface, #f8fafc)', border: '1px solid var(--cf-border)', borderRadius: 8 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 12 }}>
        <div>
          <h2 style={{ fontSize: 16, margin: 0 }}>Usage (last {data.window_days} days)</h2>
          <p className="muted" style={{ fontSize: 12, margin: '2px 0 0' }}>
            Call volume, success rate, and latency. Pulled live from <code>ai_interactions</code>.
          </p>
        </div>
      </header>

      <div style={{
        display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
        gap: 10, marginBottom: 16,
      }}>
        <Stat label="Total calls"   value={totals.calls || 0} big />
        <Stat label="Success rate"
              value={fmtPct(totals.success_rate)}
              hint={`${totals.ok_count || 0} ok / ${totals.error_count || 0} error / ${totals.disabled_count || 0} disabled`}
        />
        <Stat label="p50 latency"   value={fmtMs(totals.p50_latency_ms)} />
        <Stat label="p95 latency"   value={fmtMs(totals.p95_latency_ms)} />
      </div>

      {byClass.length === 0 ? (
        <p className="muted" data-testid="ai-usage-empty" style={{ fontSize: 13 }}>
          No AI calls in this window yet. Flip AI on at <a href="/admin/ai-settings">/admin/ai-settings</a> if a tenant hasn't been enabled, or pick a feature that uses AI (e.g. Treasury → categorize a transaction) to populate.
        </p>
      ) : (
        <>
          <h3 style={{ fontSize: 13, margin: '0 0 6px', color: '#475569' }}>By feature class</h3>
          <table className="data-table" data-testid="ai-usage-by-class" style={{ fontSize: 13, marginBottom: 16 }}>
            <thead>
              <tr>
                <th>Class</th>
                <th style={{ textAlign: 'right' }}>Calls</th>
                <th style={{ textAlign: 'right' }}>Success</th>
                <th style={{ textAlign: 'right' }}>p50</th>
                <th style={{ textAlign: 'right' }}>p95</th>
                <th style={{ textAlign: 'right' }}>Distinct features</th>
              </tr>
            </thead>
            <tbody>
              {byClass.map((c) => (
                <tr key={c.feature_class} data-testid={`ai-usage-class-${c.feature_class}`}>
                  <td><code style={{ fontSize: 12 }}>{c.feature_class}</code></td>
                  <td style={{ textAlign: 'right' }}>{c.calls}</td>
                  <td style={{
                    textAlign: 'right',
                    color: c.success_rate >= 0.95 ? '#065f46' : c.success_rate >= 0.8 ? '#b45309' : '#b91c1c',
                    fontWeight: 600,
                  }}>{fmtPct(c.success_rate)}</td>
                  <td style={{ textAlign: 'right' }}>{fmtMs(c.p50_latency_ms)}</td>
                  <td style={{ textAlign: 'right' }}>{fmtMs(c.p95_latency_ms)}</td>
                  <td style={{ textAlign: 'right' }}>{c.distinct_features}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {topKeys.length > 0 && (
            <>
              <h3 style={{ fontSize: 13, margin: '0 0 6px', color: '#475569' }}>Top feature keys (by volume)</h3>
              <table className="data-table" data-testid="ai-usage-top-keys" style={{ fontSize: 12 }}>
                <thead>
                  <tr>
                    <th>Feature key</th>
                    <th>Class</th>
                    <th style={{ textAlign: 'right' }}>Calls</th>
                    <th>Last call</th>
                  </tr>
                </thead>
                <tbody>
                  {topKeys.map((k) => (
                    <tr key={k.feature_key} data-testid={`ai-usage-key-${k.feature_key.replace(/\./g, '-')}`}>
                      <td><code style={{ fontSize: 11 }}>{k.feature_key}</code></td>
                      <td className="muted">{k.feature_class}</td>
                      <td style={{ textAlign: 'right' }}>{k.calls}</td>
                      <td className="muted" style={{ fontSize: 11 }}>{k.last_call_at}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </>
      )}
    </section>
  );
}
