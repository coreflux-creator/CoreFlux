import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * Simulation Harness Dashboard — auditor + engineer view of the
 * deterministic financial wind tunnel.
 *
 * Tabs:
 *   • Runs           — recent scenario executions, click → drill in
 *   • Scenarios      — what's available to run (from /app/sim/scenarios)
 *   • Discipline     — every legacy direct-GL fallback fire (Phase 2a)
 *
 * Reads from /api/admin/simulation_runs.php. Drill-in shows the
 * scenario's assertions, failures, replay log, and a (copyable) CLI
 * command to reproduce the run.
 *
 * NB: Runs are kicked off via CLI (php /app/sim/runner.php …).  The
 * "Replay" button on a run copies the exact `php sim/runner.php …`
 * command to the clipboard rather than spawning a process from the
 * web request — keeping the harness CLI-driven matches the harness
 * design doc §10 (runner is its own process).
 */
const STATUS_COLOR = {
  running: { bg: 'rgba(59,130,246,0.12)', fg: '#1d4ed8' },
  passed:  { bg: 'rgba(34,197,94,0.12)',  fg: '#047857' },
  failed:  { bg: 'rgba(239,68,68,0.12)',  fg: '#b91c1c' },
  aborted: { bg: 'rgba(245,158,11,0.12)', fg: '#a16207' },
};

function StatusPill({ status }) {
  const c = STATUS_COLOR[status] || { bg: '#eee', fg: '#333' };
  return (
    <span data-testid={`sim-status-${status}`} style={{
      display: 'inline-block', padding: '2px 10px', borderRadius: 999,
      background: c.bg, color: c.fg, fontSize: 12, fontWeight: 600, textTransform: 'capitalize',
    }}>{status}</span>
  );
}

function fmtDateTime(s) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d.getTime()) ? s : d.toLocaleString();
}

export default function SimulationDashboard() {
  const [tab,        setTab]        = useState('runs');
  const [rows,       setRows]       = useState([]);
  const [kpi,        setKpi]        = useState({});
  const [scenarios,  setScenarios]  = useState([]);
  const [discipline, setDiscipline] = useState([]);
  const [loading,    setLoading]    = useState(true);
  const [error,      setError]      = useState(null);
  const [migPending, setMigPending] = useState(false);

  const [selectedId, setSelectedId] = useState(null);
  const [detail,     setDetail]     = useState(null);
  const [spawning,   setSpawning]   = useState(null); // scenario name being spawned

  // Filters (Runs tab)
  const [filterScenario, setFilterScenario] = useState('');
  const [filterStatus,   setFilterStatus]   = useState('');

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const qs = new URLSearchParams();
      if (filterScenario) qs.set('scenario', filterScenario);
      if (filterStatus)   qs.set('status',   filterStatus);
      const [r, s, d] = await Promise.all([
        api.get(`/api/admin/simulation_runs.php${qs.toString() ? '?' + qs.toString() : ''}`),
        api.get('/api/admin/simulation_runs.php?action=scenarios'),
        api.get('/api/admin/simulation_runs.php?action=discipline'),
      ]);
      setRows(r?.rows || []);
      setKpi(r?.kpi  || {});
      setMigPending(!!r?.migration_pending);
      setScenarios(s?.scenarios || []);
      setDiscipline(d?.rows || []);
    } catch (e) { setError(e); }
    finally     { setLoading(false); }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [filterScenario, filterStatus]);

  const openDetail = async (id) => {
    setSelectedId(id); setDetail(null);
    try {
      const res = await api.get(`/api/admin/simulation_runs.php?id=${id}&detail=1`);
      setDetail(res);
    } catch (e) { setError(e); }
  };

  const copyReplay = (run) => {
    const cmd = `php /app/sim/runner.php --scenario=${run.scenario_name} --seed=${run.seed} --tenant=${run.tenant_id || '<sim-tenant-id>'}`;
    try { navigator.clipboard.writeText(cmd); } catch { /* clipboard may be blocked */ }
  };

  // Run-from-web — synchronous POST to spawn the runner against the sim
  // tenant. Up to 60s wait, then refresh the runs list so the new row
  // shows up. Same code path the CLI uses (the endpoint just shell_execs
  // /app/sim/runner.php).
  const runScenario = async (scenarioName, seed = null) => {
    setSpawning(scenarioName);
    try {
      const res = await api.post('/api/admin/simulation_runs.php?action=run', {
        scenario: scenarioName,
        ...(seed !== null ? { seed } : {}),
      });
      await load();
      if (res?.run_id) openDetail(res.run_id);
    } catch (e) { setError(e); }
    finally     { setSpawning(null); }
  };

  const Tab = ({ id, label, count, testid }) => (
    <button
      data-testid={`sim-tab-${id}`}
      onClick={() => setTab(id)}
      className={tab === id ? 'btn btn--primary' : 'btn btn--ghost'}
      style={{ marginRight: 8 }}
    >
      {label}{typeof count === 'number' ? ` (${count})` : ''}
    </button>
  );

  return (
    <section data-testid="simulation-dashboard">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 'var(--cf-space-3)' }}>
        <div>
          <h2>Simulation Harness</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Deterministic financial wind tunnel — replayable scenarios,
            ledger invariants, discipline telemetry. Runs are CLI-driven:
            <code style={{ marginLeft: 6 }}>php /app/sim/runner.php --scenario=… --seed=… --tenant=…</code>
          </p>
        </div>
        <Link to="/" className="btn btn--ghost" data-testid="sim-back">← Dashboard</Link>
      </header>

      <div data-testid="sim-kpi-strip" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-4)' }}>
        <Stat testid="sim-kpi-total"        label="Runs (30d)"           value={kpi.total_runs || 0} />
        <Stat testid="sim-kpi-passed"       label="Passed"               value={kpi.passed     || 0} />
        <Stat testid="sim-kpi-failed"       label="Failed"               value={kpi.failed     || 0} fg={(kpi.failed || 0) > 0 ? '#b91c1c' : undefined} />
        <Stat testid="sim-kpi-assertions"   label="Invariant failures"   value={kpi.assertion_failures || 0} />
        <Stat testid="sim-kpi-avg-duration" label="Avg duration (ms)"    value={Math.round(kpi.avg_duration_ms || 0)} />
      </div>

      <div style={{ marginBottom: 'var(--cf-space-3)' }}>
        <Tab id="runs"       label="Runs"               count={rows.length}       testid="sim-tab-runs" />
        <Tab id="scenarios"  label="Scenarios"          count={scenarios.length}  testid="sim-tab-scenarios" />
        <Tab id="discipline" label="Discipline log"     count={discipline.length} testid="sim-tab-discipline" />
      </div>

      {error && <p className="error" data-testid="sim-error">Error: {error.message}</p>}
      {loading && <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="sim-loading">Loading…</p>}
      {migPending && (
        <p data-testid="sim-migration-pending" style={{ background: 'rgba(245,158,11,0.1)', color: '#a16207', padding: 12, borderRadius: 6 }}>
          Harness tables not provisioned yet — run migration 043 (auto-applies on next API request) and execute a scenario via the CLI to populate this view.
        </p>
      )}

      {tab === 'runs' && (
        <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-4)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <Field label="Scenario">
              <select value={filterScenario} onChange={e => setFilterScenario(e.target.value)} data-testid="sim-filter-scenario">
                <option value="">All</option>
                {scenarios.map(s => <option key={s.name} value={s.name}>{s.name}</option>)}
              </select>
            </Field>
            <Field label="Status">
              <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} data-testid="sim-filter-status">
                <option value="">All</option>
                <option value="passed">Passed</option>
                <option value="failed">Failed</option>
                <option value="running">Running</option>
                <option value="aborted">Aborted</option>
              </select>
            </Field>
            <button className="btn" onClick={load} data-testid="sim-refresh" style={{ alignSelf: 'flex-end' }}>Refresh</button>
          </div>
          {rows.length === 0 ? (
            <p className="empty" data-testid="sim-runs-empty">No runs recorded yet. Execute a scenario via the CLI to get started.</p>
          ) : (
            <table className="data-table" data-testid="sim-runs-table">
              <thead>
                <tr>
                  <th>When</th><th>Scenario</th><th>Seed</th>
                  <th style={{ textAlign: 'right' }}>Events</th>
                  <th style={{ textAlign: 'right' }}>JE</th>
                  <th style={{ textAlign: 'right' }}>Assertions</th>
                  <th>Status</th><th>Duration</th><th></th>
                </tr>
              </thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id} data-testid={`sim-run-${r.id}`}>
                    <td>{fmtDateTime(r.started_at)}</td>
                    <td>{r.scenario_name}</td>
                    <td><code>{r.seed}</code></td>
                    <td style={{ textAlign: 'right' }}>{r.events_emitted}</td>
                    <td style={{ textAlign: 'right' }}>{r.je_posted}</td>
                    <td style={{ textAlign: 'right' }}>
                      {r.assertions_run - r.assertions_failed}/{r.assertions_run}
                    </td>
                    <td><StatusPill status={r.status} /></td>
                    <td>{r.duration_ms ? `${r.duration_ms}ms` : '—'}</td>
                    <td>
                      <button className="btn btn--primary" data-testid={`sim-run-${r.id}-rerun`} onClick={() => runScenario(r.scenario_name, r.seed)} disabled={spawning === r.scenario_name}>
                        {spawning === r.scenario_name ? 'Running…' : 'Run again'}
                      </button>
                      <button className="btn btn--ghost" data-testid={`sim-run-${r.id}-detail`} onClick={() => openDetail(r.id)}>Details</button>
                      <button className="btn btn--ghost" data-testid={`sim-run-${r.id}-replay`} onClick={() => copyReplay(r)} title="Copy reproduce-this-run command">Copy CLI</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {tab === 'scenarios' && (
        <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-4)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
          {scenarios.length === 0 ? (
            <p className="empty" data-testid="sim-scenarios-empty">No scenarios found in /app/sim/scenarios.</p>
          ) : (
            <table className="data-table" data-testid="sim-scenarios-table">
              <thead>
                <tr><th>Name</th><th>Description</th><th>Default seed</th><th style={{ textAlign: 'right' }}>Steps</th><th>Invariants</th><th></th></tr>
              </thead>
              <tbody>
                {scenarios.map(s => (
                  <tr key={s.name} data-testid={`sim-scenario-${s.name}`}>
                    <td><code>{s.name}</code></td>
                    <td style={{ maxWidth: 420 }}>{s.description}</td>
                    <td><code>{s.default_seed}</code></td>
                    <td style={{ textAlign: 'right' }}>{s.step_count}</td>
                    <td style={{ fontSize: 11 }}>
                      {(s.invariants || []).map(i => (
                        <span key={i} style={{ display: 'inline-block', padding: '1px 6px', marginRight: 4, marginBottom: 2, borderRadius: 4, background: 'rgba(99,102,241,0.12)', color: '#4338ca' }}>{i}</span>
                      ))}
                    </td>
                    <td>
                      <button
                        className="btn btn--primary"
                        data-testid={`sim-scenario-${s.name}-run`}
                        onClick={() => runScenario(s.name)}
                        disabled={spawning === s.name}
                      >
                        {spawning === s.name ? 'Running…' : 'Run'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {tab === 'discipline' && (
        <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-4)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
          {discipline.length === 0 ? (
            <p className="empty" data-testid="sim-discipline-empty">
              Zero direct-GL fallback fires recorded. This is the goal state for Phase-2a step 5 (kill-switch).
            </p>
          ) : (
            <table className="data-table" data-testid="sim-discipline-table">
              <thead>
                <tr><th>When</th><th>Module</th><th>Event type</th><th>Context</th></tr>
              </thead>
              <tbody>
                {discipline.map(d => (
                  <tr key={d.id} data-testid={`sim-discipline-${d.id}`}>
                    <td>{fmtDateTime(d.created_at)}</td>
                    <td><code>{d.source_module}</code></td>
                    <td><code>{d.event_type}</code></td>
                    <td><pre style={{ margin: 0, fontSize: 11, maxWidth: 540, overflow: 'auto' }}>{JSON.stringify(d.context, null, 2)}</pre></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {/* Drill-in panel for a single run */}
      {selectedId && (
        <div
          data-testid="sim-detail-overlay"
          onClick={() => { setSelectedId(null); setDetail(null); }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', display: 'flex', justifyContent: 'flex-end', zIndex: 50 }}
        >
          <div
            data-testid="sim-detail-panel"
            onClick={e => e.stopPropagation()}
            style={{ width: 'min(960px, 100%)', background: 'var(--cf-surface)', padding: 'var(--cf-space-5)', overflow: 'auto' }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
              <h3 style={{ margin: 0 }}>Run #{selectedId}</h3>
              <button className="btn btn--ghost" onClick={() => { setSelectedId(null); setDetail(null); }} data-testid="sim-detail-close">Close</button>
            </div>
            {!detail && <p style={{ color: 'var(--cf-text-secondary)' }}>Loading…</p>}
            {detail && (
              <>
                <p>
                  <strong>{detail.run.scenario_name}</strong> · seed <code>{detail.run.seed}</code> ·{' '}
                  <StatusPill status={detail.run.status} /> · {detail.run.duration_ms}ms
                </p>
                <h4>Assertions</h4>
                <table className="data-table" data-testid="sim-detail-assertions">
                  <thead><tr><th>Name</th><th>OK?</th><th>Severity</th><th>Details</th></tr></thead>
                  <tbody>
                    {(detail.assertions || []).map(a => (
                      <tr key={a.id}>
                        <td><code>{a.name}</code></td>
                        <td>{a.ok ? '✓' : '✗'}</td>
                        <td>{a.severity}</td>
                        <td><pre style={{ margin: 0, fontSize: 11, maxWidth: 480, overflow: 'auto' }}>{JSON.stringify(a.details, null, 2)}</pre></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {(detail.failures || []).length > 0 && (
                  <>
                    <h4 style={{ color: '#b91c1c' }}>Failures</h4>
                    <ul data-testid="sim-detail-failures">
                      {detail.failures.map(f => (
                        <li key={f.id}>
                          <strong>{f.invariant}:</strong> {f.message}
                        </li>
                      ))}
                    </ul>
                  </>
                )}
                <h4>Replay log</h4>
                <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  Re-running with seed <code>{detail.run.seed}</code> must produce the same (event_type, payload_hash, je_hash) sequence.
                </p>
                <table className="data-table" data-testid="sim-detail-replay">
                  <thead><tr><th>#</th><th>event_type</th><th>payload_hash</th><th>je_hash</th></tr></thead>
                  <tbody>
                    {(detail.replay || []).map(r => (
                      <tr key={r.event_index}>
                        <td>{r.event_index}</td>
                        <td><code>{r.event_type}</code></td>
                        <td><code style={{ fontSize: 10 }}>{(r.payload_hash || '').substring(0, 16)}…</code></td>
                        <td><code style={{ fontSize: 10 }}>{(r.je_hash || '').substring(0, 16) || '—'}</code></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </>
            )}
          </div>
        </div>
      )}
    </section>
  );
}

function Stat({ label, value, fg, testid }) {
  return (
    <div data-testid={testid} style={{ background: 'var(--cf-surface-elevated, #f8fafc)', padding: '12px 16px', borderRadius: 'var(--cf-radius-md, 8px)', minWidth: 140 }}>
      <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4, color: fg }}>{Number(value || 0).toLocaleString('en-US')}</div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
      {label}
      {children}
    </label>
  );
}
