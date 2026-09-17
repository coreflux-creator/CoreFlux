import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { AlertTriangle, CheckCircle2, Database, RefreshCw, XCircle } from 'lucide-react';

/**
 * Admin healthcheck — pings every recently-shipped endpoint in one round
 * trip and renders the result as a flat green/amber/red dot grid.
 *
 * No live actions (mints / sends / writes) happen here. Safe to reload
 * during heavy testing.
 */
export default function HealthcheckAdmin() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [ranAt, setRanAt] = useState(null);
  const [migrating, setMigrating] = useState(false);
  const [migrationMessage, setMigrationMessage] = useState(null);

  const run = async () => {
    setLoading(true); setError(null);
    try {
      const d = await api.get('/api/admin_healthcheck.php');
      setData(d);
      setRanAt(d.ran_at);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  };

  useEffect(() => { run(); }, []);

  const runMigrations = async () => {
    setMigrating(true);
    setMigrationMessage(null);
    try {
      const result = await api.post('/api/admin/migrate.php');
      const applied = result.status?.applied_files?.length ?? 0;
      const errors = result.status?.errors ?? [];
      setMigrationMessage(errors.length > 0
        ? { kind: 'error', text: `Migration run finished with ${errors.length} issue${errors.length === 1 ? '' : 's'}. Review the failed rows below.` }
        : { kind: 'ok', text: applied > 0 ? `${applied} pending migration${applied === 1 ? '' : 's'} applied.` : 'Schema is already current.' });
      await run();
    } catch (e) {
      setMigrationMessage({ kind: 'error', text: e.message || 'Migration run failed.' });
    } finally {
      setMigrating(false);
    }
  };

  const dotColor = (s) => ({ ok: '#16a34a', warn: '#f59e0b', fail: '#dc2626', skipped: '#94a3b8' }[s] || '#94a3b8');
  const dotLabel = (s) => ({ ok: 'PASS', warn: 'WARN', fail: 'FAIL', skipped: 'SKIP' }[s] || s);

  const tally = data?.tally || {};
  const summary = `${tally.ok || 0} pass · ${tally.warn || 0} warn · ${tally.fail || 0} fail · ${tally.skipped || 0} skip`;
  const SummaryIcon = tally.fail > 0 ? XCircle : tally.warn > 0 ? AlertTriangle : CheckCircle2;

  return (
    <section data-testid="admin-healthcheck" style={{ maxWidth: 920 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 18 }}>
        <div>
          <h1 style={{ margin: 0 }}>Healthcheck</h1>
          <p style={{ margin: '6px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Checks the core workflow reads, required schema, mail delivery, and accounting event registry.
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          {ranAt && <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }} data-testid="admin-healthcheck-ran-at">Ran {new Date(ranAt).toLocaleTimeString()}</span>}
          <button className="btn btn--ghost" onClick={runMigrations} disabled={migrating || loading} data-testid="admin-healthcheck-migrate">
            <Database size={15} aria-hidden="true" /> {migrating ? 'Applying…' : 'Run pending migrations'}
          </button>
          <button className="btn btn--primary" onClick={run} disabled={loading} data-testid="admin-healthcheck-rerun">
            <RefreshCw size={15} aria-hidden="true" /> {loading ? 'Running…' : 'Run again'}
          </button>
        </div>
      </header>

      {error && <p className="error" data-testid="admin-healthcheck-error">{error}</p>}
      {migrationMessage && (
        <p className={migrationMessage.kind === 'error' ? 'error' : 'success'} data-testid="admin-healthcheck-migration-result" aria-live="polite">
          {migrationMessage.text}
        </p>
      )}
      {loading && !data && <p>Running checks…</p>}

      {data && (
        <>
          <div data-testid="admin-healthcheck-summary"
               style={{
                 padding: 12, marginBottom: 16, borderRadius: 8,
                 background: tally.fail > 0 ? '#fef2f2' : tally.warn > 0 ? '#fffbeb' : '#f0fdf4',
                 border: `1px solid ${tally.fail > 0 ? '#fecaca' : tally.warn > 0 ? '#fde68a' : '#bbf7d0'}`,
                 color: tally.fail > 0 ? '#b91c1c' : tally.warn > 0 ? '#92400e' : '#065f46',
                 fontWeight: 600, fontSize: 14, display: 'flex', alignItems: 'center', gap: 8,
               }}>
            <SummaryIcon size={17} aria-hidden="true" />
            <span>{tally.fail > 0 ? 'Some checks failed' : tally.warn > 0 ? 'Passed with warnings' : 'All systems nominal'} — {summary}</span>
          </div>

          <table className="data-table" data-testid="admin-healthcheck-rows" style={{ fontSize: 13 }}>
            <thead>
              <tr>
                <th style={{ width: 16 }}></th>
                <th>Check</th>
                <th>Detail</th>
                <th style={{ textAlign: 'right' }}>ms</th>
                <th style={{ textAlign: 'right', width: 60 }}>Status</th>
              </tr>
            </thead>
            <tbody>
              {data.results.map((r) => (
                <tr key={r.key} data-testid={`admin-healthcheck-row-${r.key}`}>
                  <td>
                    <span
                      title={dotLabel(r.status)}
                      style={{ display: 'inline-block', width: 10, height: 10, borderRadius: '50%', background: dotColor(r.status) }}
                      data-testid={`admin-healthcheck-dot-${r.key}`}
                    />
                  </td>
                  <td style={{ fontWeight: 500 }}>{r.label}</td>
                  <td style={{ color: 'var(--cf-text-secondary)', fontFamily: 'monospace', fontSize: 12 }}>{r.detail || '—'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--cf-text-secondary)' }}>{r.duration_ms}</td>
                  <td style={{ textAlign: 'right', fontWeight: 600, color: dotColor(r.status) }}>{dotLabel(r.status)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <p style={{ marginTop: 16, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            <strong>Legend:</strong>{' '}
            <span style={{ color: '#16a34a' }}>● pass</span>{' · '}
            <span style={{ color: '#f59e0b' }}>● warn (passed, with caveat)</span>{' · '}
            <span style={{ color: '#dc2626' }}>● fail</span>{' · '}
            <span style={{ color: '#94a3b8' }}>● skip (not configured)</span>
          </p>
        </>
      )}
    </section>
  );
}
