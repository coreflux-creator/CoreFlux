import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * IntegrationsHealthPanel — at-a-glance freshness state for every
 * provider that follows the contract-smoke triplet (spec / contract
 * smoke / freshness smoke / refresh tool).
 *
 * Surfaces:
 *   • spec presence + age
 *   • HTML snapshot age (only for hand-rolled providers like QBO)
 *   • whether the companion smokes + refresh tool are wired up
 *   • a roll-up status pill: ok | attention | missing
 *
 * Hits `/api/admin/integrations_health.php`. Master/tenant admin gated.
 */
export default function IntegrationsHealthPanel() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/admin/integrations_health.php');
      setData(r);
    } catch (e) {
      setError(e.message || 'Failed to load integrations health');
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); }, []);

  return (
    <section
      data-testid="integrations-health-panel"
      style={{
        background: 'var(--cf-surface, white)',
        border: '1px solid var(--cf-border, #e5e7eb)',
        borderRadius: 8,
        padding: 16,
      }}
    >
      <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
        <h3 style={{ margin: 0, fontSize: 14 }}>Integrations — contract & spec freshness</h3>
        <button
          type="button"
          onClick={load}
          data-testid="integrations-health-refresh"
          style={{
            marginLeft: 'auto', padding: '4px 10px', fontSize: 12,
            background: 'transparent', border: '1px solid var(--cf-border, #e5e7eb)',
            borderRadius: 4, cursor: 'pointer',
          }}
        >Refresh</button>
      </header>

      {loading && <p data-testid="integrations-health-loading" style={{ color: 'var(--cf-text-secondary, #6b7280)', fontSize: 12 }}>Loading…</p>}
      {error && <p data-testid="integrations-health-error" className="error">{error}</p>}

      {!loading && !error && data && (
        <>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}
                 data-testid="integrations-health-table">
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary, #6b7280)' }}>
                <th style={{ padding: '4px 0' }}>Provider</th>
                <th>Status</th>
                <th>Spec</th>
                <th>Snapshot</th>
                <th>Smokes</th>
                <th>Tool</th>
              </tr>
            </thead>
            <tbody>
              {data.providers.map(p => (
                <tr key={p.id}
                    data-testid={`integrations-health-row-${p.id}`}
                    style={{ borderTop: '1px solid var(--cf-border, #e5e7eb)' }}>
                  <td style={{ padding: '8px 0', fontWeight: 600 }}>{p.label}</td>
                  <td><StatusPill overall={p.overall} /></td>
                  <td>
                    {p.spec?.exists === false
                      ? <span style={{ color: '#b91c1c' }}>missing</span>
                      : <Age days={p.spec?.days_old} size={p.spec?.size_bytes} />}
                  </td>
                  <td>
                    {p.snapshot === null
                      ? <span style={{ color: 'var(--cf-text-secondary, #6b7280)' }}>n/a</span>
                      : p.snapshot.status === 'missing'
                        ? <span style={{ color: '#b45309' }}>missing</span>
                        : <SnapshotCell snapshot={p.snapshot} stale={data.stale_after_days} />}
                  </td>
                  <td>
                    <SmokeBadge
                      label="contract"
                      ok={p.smokes?.contract?.exists}
                      testid={`integrations-health-${p.id}-contract`}
                    />{' '}
                    <SmokeBadge
                      label="freshness"
                      ok={p.smokes?.freshness?.exists}
                      testid={`integrations-health-${p.id}-freshness`}
                    />{' '}
                    <SmokeBadge
                      label="verify"
                      ok={p.verify_create}
                      testid={`integrations-health-${p.id}-verify`}
                    />
                  </td>
                  <td>
                    {p.tool?.exists
                      ? (p.tool.executable
                          ? <span style={{ color: '#16a34a' }}>✓</span>
                          : <span style={{ color: '#b45309' }}>not +x</span>)
                      : <span style={{ color: '#b91c1c' }}>missing</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p style={{ marginTop: 10, fontSize: 11, color: 'var(--cf-text-secondary, #6b7280)' }}>
            Snapshot turns <strong>attention</strong> after {data.stale_after_days} days.
            Generated {data.generated_at_iso?.slice(0, 16)?.replace('T', ' ')} UTC.
          </p>
        </>
      )}
    </section>
  );
}

function StatusPill({ overall }) {
  const styles = {
    ok:        { bg: '#dcfce7', fg: '#166534', label: 'ok' },
    attention: { bg: '#fef3c7', fg: '#78350f', label: 'attention' },
    missing:   { bg: '#fee2e2', fg: '#991b1b', label: 'missing' },
  };
  const s = styles[overall] || styles.missing;
  return (
    <span style={{
      display: 'inline-block', padding: '1px 8px', borderRadius: 999,
      background: s.bg, color: s.fg, fontSize: 11, fontWeight: 600,
    }} data-testid={`integrations-health-status-${overall}`}>{s.label}</span>
  );
}

function Age({ days, size }) {
  if (days == null || days < 0) return <span style={{ color: '#b45309' }}>unknown</span>;
  const kb = size ? Math.round(size / 1024) : null;
  return (
    <span>
      {days === 0 ? 'today' : `${days}d old`}
      {kb !== null && <span style={{ color: 'var(--cf-text-secondary, #6b7280)' }}> · {kb}kB</span>}
    </span>
  );
}

function SnapshotCell({ snapshot, stale }) {
  const days = snapshot.days_old ?? -1;
  const warn = days >= 0 && days > stale;
  return (
    <span style={{ color: warn ? '#b45309' : 'inherit' }}>
      {days === 0 ? 'today' : `${days}d old`}
      {warn && <span style={{ marginLeft: 4 }} title={`stale after ${stale}d`}>⚠</span>}
    </span>
  );
}

function SmokeBadge({ label, ok, testid }) {
  return (
    <span
      data-testid={testid}
      style={{
        display: 'inline-block', padding: '1px 6px', borderRadius: 3,
        fontSize: 10, fontWeight: 600, letterSpacing: 0.3,
        background: ok ? '#dcfce7' : '#fee2e2',
        color:      ok ? '#166534' : '#991b1b',
      }}
    >{ok ? '✓' : '✗'} {label}</span>
  );
}
