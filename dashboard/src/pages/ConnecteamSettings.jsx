import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertCircle, CheckCircle2, ChevronLeft, Clock3, KeyRound,
  RefreshCw, Search, ShieldCheck, Unplug, UsersRound,
} from 'lucide-react';
import { api, useApi } from '../lib/api';

const STATE_META = {
  available: { label: 'Available', color: '#047857', bg: '#d1fae5' },
  not_in_plan: { label: 'Plan restricted', color: '#92400e', bg: '#fef3c7' },
  not_authorized: { label: 'Not authorized', color: '#92400e', bg: '#fef3c7' },
  rate_limited: { label: 'Rate limited', color: '#9a3412', bg: '#ffedd5' },
  error: { label: 'Error', color: '#991b1b', bg: '#fee2e2' },
};

const MATCH_META = {
  exact: { label: 'Exact ID', color: '#047857', bg: '#d1fae5' },
  suggested: { label: 'Review match', color: '#1d4ed8', bg: '#dbeafe' },
  ambiguous: { label: 'Ambiguous', color: '#92400e', bg: '#fef3c7' },
  unmatched: { label: 'Unmatched', color: '#991b1b', bg: '#fee2e2' },
};

function Badge({ meta }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', minHeight: 22,
      padding: '2px 8px', borderRadius: 4, fontSize: 12, fontWeight: 600,
      color: meta.color, background: meta.bg, whiteSpace: 'nowrap',
    }}>
      {meta.label}
    </span>
  );
}

function Notice({ kind = 'info', children }) {
  const danger = kind === 'error';
  return (
    <div role={danger ? 'alert' : 'status'} style={{
      display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px 12px',
      border: `1px solid ${danger ? '#fecaca' : '#bfdbfe'}`,
      background: danger ? '#fef2f2' : '#eff6ff',
      color: danger ? '#991b1b' : '#1e3a8a', borderRadius: 6,
    }}>
      {danger ? <AlertCircle size={17} /> : <ShieldCheck size={17} />}
      <span>{children}</span>
    </div>
  );
}

function SummaryCell({ label, value, tone = 'default' }) {
  const color = tone === 'good' ? '#047857' : tone === 'warn' ? '#92400e' : tone === 'bad' ? '#991b1b' : 'var(--cf-text-primary)';
  return (
    <div style={{ minWidth: 130 }}>
      <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 3 }}>{label}</div>
      <div style={{ fontSize: 22, lineHeight: 1.2, fontWeight: 700, color }}>{value}</div>
    </div>
  );
}

function CapabilitiesTable({ probe }) {
  const rows = Object.values(probe?.capabilities || {});
  const inventory = probe?.inventory || {};
  if (!rows.length) return null;
  return (
    <div style={{ overflowX: 'auto', border: '1px solid var(--cf-border)', borderRadius: 6 }}>
      <table style={{ width: '100%', minWidth: 900, borderCollapse: 'collapse' }} data-testid="connecteam-capabilities-table">
        <thead>
          <tr style={{ background: 'var(--cf-surface-subtle, #f8fafc)' }}>
            {['FEATURE', 'ACCESS', 'VISIBLE', 'COREFLUX DESTINATION', 'DIRECTION'].map(label => (
              <th key={label} style={{ padding: '10px 12px', textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>{label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map(row => {
            const meta = STATE_META[row.state] || STATE_META.error;
            const inv = inventory[row.key];
            const count = inv?.reported_total ?? inv?.visible_count;
            return (
              <tr key={row.key}>
                <td style={cellStyle}>
                  <div style={{ fontWeight: 600 }}>{row.label}</div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{row.group}</div>
                </td>
                <td style={cellStyle} title={row.detail || ''}><Badge meta={meta} /></td>
                <td style={cellStyle}>{row.state === 'available' ? (count ?? 0) : '—'}</td>
                <td style={cellStyle}>{row.destination}</td>
                <td style={cellStyle}>{row.direction}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

const cellStyle = {
  padding: '10px 12px', borderBottom: '1px solid var(--cf-border)',
  verticalAlign: 'top', fontSize: 13,
};

function InventorySamples({ probe }) {
  const sections = Object.entries(probe?.inventory || {}).filter(([, item]) => item?.sample?.length);
  if (!sections.length) return null;
  return (
    <details style={{ marginTop: 12 }}>
      <summary style={{ cursor: 'pointer', fontWeight: 600 }}>Inventory sample</summary>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12, marginTop: 12 }}>
        {sections.map(([key, item]) => (
          <div key={key} style={{ borderLeft: '3px solid #93c5fd', padding: '4px 10px' }}>
            <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', color: 'var(--cf-text-secondary)', marginBottom: 6 }}>
              {key.replaceAll('_', ' ')}
            </div>
            {item.sample.map((sample, index) => (
              <div key={`${sample.id || index}`} style={{ fontSize: 13, marginBottom: 5, overflowWrap: 'anywhere' }}>
                <strong>{sample.name || sample.id || 'Unnamed'}</strong>
                {sample.code ? ` · ${sample.code}` : ''}
                {sample.email ? <div style={{ color: 'var(--cf-text-secondary)' }}>{sample.email}</div> : null}
              </div>
            ))}
          </div>
        ))}
      </div>
    </details>
  );
}

function ReconciliationSection({ title, icon: Icon, result }) {
  if (!result) return null;
  const counts = result.counts || {};
  return (
    <section style={{ marginTop: 24 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <Icon size={18} />
        <h3 style={{ margin: 0, fontSize: 16 }}>{title}</h3>
        <span style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>{result.source_count} Connecteam · {result.coreflux_count} CoreFlux</span>
      </div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, marginBottom: 12 }}>
        <SummaryCell label="Exact IDs" value={counts.exact || 0} tone="good" />
        <SummaryCell label="Review matches" value={counts.suggested || 0} />
        <SummaryCell label="Ambiguous" value={counts.ambiguous || 0} tone="warn" />
        <SummaryCell label="Unmatched" value={counts.unmatched || 0} tone="bad" />
      </div>
      <div style={{ overflowX: 'auto', border: '1px solid var(--cf-border)', borderRadius: 6 }}>
        <table style={{ width: '100%', minWidth: 760, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ background: 'var(--cf-surface-subtle, #f8fafc)' }}>
              {['CONNECTEAM', 'RESULT', 'COREFLUX', 'BASIS'].map(label => (
                <th key={label} style={{ padding: '9px 12px', textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>{label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(result.rows || []).map((row, index) => (
              <tr key={`${row.source_id}-${index}`}>
                <td style={cellStyle}>
                  <div style={{ fontWeight: 600 }}>{row.source_name || 'Unnamed'}</div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{row.source_code || row.source_email || row.source_id}</div>
                </td>
                <td style={cellStyle}><Badge meta={MATCH_META[row.state] || MATCH_META.unmatched} /></td>
                <td style={cellStyle}>{row.coreflux_name || '—'}{row.coreflux_id ? ` · ${title === 'People' ? 'P' : 'PL'}-${row.coreflux_id}` : ''}</td>
                <td style={cellStyle}>{row.method}</td>
              </tr>
            ))}
            {!result.rows?.length ? (
              <tr><td colSpan={4} style={{ ...cellStyle, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>No rows returned.</td></tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </section>
  );
}

export default function ConnecteamSettings() {
  const { data, error, loading, reload } = useApi('/api/connecteam/status.php?action=status');
  const [apiKey, setApiKey] = useState('');
  const [region, setRegion] = useState('us');
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState('');
  const [failure, setFailure] = useState('');
  const [liveProbe, setLiveProbe] = useState(null);
  const [preview, setPreview] = useState(null);

  useEffect(() => {
    if (data?.region) setRegion(data.region);
  }, [data?.region]);

  const probe = liveProbe || data?.probe;
  const connected = Boolean(data?.connected);
  const summary = probe?.summary;
  const accountName = probe?.account?.name || data?.account?.name || 'Connecteam account';
  const capabilityGroups = useMemo(() => Object.values(probe?.capabilities || {}).length, [probe]);
  const canPreview = probe?.capabilities?.users?.state === 'available'
    && probe?.capabilities?.jobs?.state === 'available';

  const clearMessages = () => { setMessage(''); setFailure(''); };

  const connect = async (event) => {
    event.preventDefault(); clearMessages(); setPreview(null);
    if (!apiKey.trim()) { setFailure('Enter a Connecteam API key.'); return; }
    setBusy('connect');
    try {
      const result = await api.post('/api/connecteam/connect.php?action=connect', { api_key: apiKey.trim(), region });
      setLiveProbe(result.probe);
      setApiKey('');
      setMessage(`Connected to ${result.probe?.account?.name || 'Connecteam'}. ${result.probe?.summary?.available || 0} features are available.`);
      await reload();
    } catch (e) {
      setFailure(e.message || 'Connecteam connection failed.');
    } finally { setBusy(''); }
  };

  const refreshProbe = async () => {
    clearMessages(); setBusy('probe');
    try {
      const result = await api.post('/api/connecteam/probe.php?action=probe', {});
      setLiveProbe(result.probe);
      setMessage(`Capability scan complete: ${result.probe?.summary?.available || 0} available, ${result.probe?.summary?.restricted || 0} restricted.`);
      await reload();
    } catch (e) { setFailure(e.message || 'Capability scan failed.'); }
    finally { setBusy(''); }
  };

  const runPreview = async () => {
    clearMessages(); setBusy('preview');
    try {
      const result = await api.post('/api/connecteam/preview.php?action=preview', {});
      setPreview(result.preview);
      setMessage('Dry run complete. No workforce or accounting records were changed.');
    } catch (e) { setFailure(e.message || 'Reconciliation preview failed.'); }
    finally { setBusy(''); }
  };

  const disconnect = async () => {
    if (!window.confirm('Disconnect Connecteam and remove the stored API key? Discovery history will remain available in the audit log.')) return;
    clearMessages(); setBusy('disconnect');
    try {
      await api.post('/api/connecteam/disconnect.php?action=disconnect', {});
      setLiveProbe(null); setPreview(null); setMessage('Connecteam disconnected.');
      await reload();
    } catch (e) { setFailure(e.message || 'Disconnect failed.'); }
    finally { setBusy(''); }
  };

  return (
    <div data-testid="connecteam-settings" style={{ maxWidth: 1500 }}>
      <Link to="/admin/integrations" style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginBottom: 16, textDecoration: 'none' }}>
        <ChevronLeft size={16} /> Integrations
      </Link>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 20 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 'var(--cf-text-2xl)', letterSpacing: 0 }}>Connecteam</h1>
          <p style={{ margin: '6px 0 0', color: 'var(--cf-text-secondary)' }}>Workforce identity, scheduling, approved time, leave, and operating policies.</p>
        </div>
        {connected ? <Badge meta={{ label: data?.status === 'error' ? 'Needs attention' : 'Connected', color: data?.status === 'error' ? '#991b1b' : '#047857', bg: data?.status === 'error' ? '#fee2e2' : '#d1fae5' }} /> : null}
      </div>

      {error ? <Notice kind="error">{error.message}</Notice> : null}
      {failure ? <Notice kind="error">{failure}</Notice> : null}
      {message ? <Notice>{message}</Notice> : null}
      {data?.migration_required ? <Notice kind="error">{data.message}</Notice> : null}

      {!connected && !loading ? (
        <section data-testid="connecteam-not-connected" style={{ marginTop: 20, borderTop: '1px solid var(--cf-border)', paddingTop: 20 }}>
          <h2 style={{ fontSize: 17, margin: '0 0 14px' }}>Connect account</h2>
          <form onSubmit={connect} style={{ display: 'grid', gridTemplateColumns: 'minmax(280px, 1fr) 180px auto', alignItems: 'end', gap: 12, maxWidth: 900 }}>
            <label style={{ display: 'grid', gap: 6 }}>
              <span style={{ fontSize: 13, fontWeight: 600 }}>API key</span>
              <div style={{ position: 'relative' }}>
                <KeyRound size={16} style={{ position: 'absolute', left: 10, top: 11, color: 'var(--cf-text-secondary)' }} />
                <input data-testid="connecteam-api-key-input" type="password" value={apiKey} onChange={e => setApiKey(e.target.value)} autoComplete="new-password" style={{ width: '100%', height: 38, padding: '0 10px 0 34px' }} />
              </div>
            </label>
            <label style={{ display: 'grid', gap: 6 }}>
              <span style={{ fontSize: 13, fontWeight: 600 }}>Data region</span>
              <select data-testid="connecteam-region-select" value={region} onChange={e => setRegion(e.target.value)} style={{ height: 38 }}>
                <option value="us">United States</option>
                <option value="au">Australia</option>
              </select>
            </label>
            <button data-testid="connecteam-connect-btn" className="btn btn-primary" type="submit" disabled={busy === 'connect'} style={{ height: 38 }}>
              <Search size={16} /> {busy === 'connect' ? 'Inspecting…' : 'Connect and inspect'}
            </button>
          </form>
          <p style={{ maxWidth: 900, marginTop: 12, fontSize: 13, color: 'var(--cf-text-secondary)' }}>The key is encrypted at rest. This connection starts in discovery mode and cannot import or change workforce records.</p>
        </section>
      ) : null}

      {connected ? (
        <div data-testid="connecteam-connected" style={{ marginTop: 20 }}>
          <section style={{ borderTop: '1px solid var(--cf-border)', paddingTop: 18 }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
              <div>
                <h2 style={{ margin: 0, fontSize: 18 }}>{accountName}</h2>
                <div style={{ marginTop: 4, color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                  {data?.region === 'au' ? 'Australia' : 'United States'} region · key ending {data?.api_key_last4 || '—'} · last checked {data?.last_probe_at || '—'}
                </div>
              </div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                <button data-testid="connecteam-probe-btn" className="btn btn-secondary" onClick={refreshProbe} disabled={Boolean(busy)}>
                  <RefreshCw size={16} /> {busy === 'probe' ? 'Checking…' : 'Refresh capabilities'}
                </button>
                <button data-testid="connecteam-preview-btn" className="btn btn-primary" onClick={runPreview} disabled={Boolean(busy) || !canPreview}>
                  <Search size={16} /> {busy === 'preview' ? 'Comparing…' : 'Run reconciliation dry run'}
                </button>
                <button data-testid="connecteam-disconnect-btn" className="btn btn-secondary" onClick={disconnect} disabled={Boolean(busy)} title="Disconnect Connecteam">
                  <Unplug size={16} /> Disconnect
                </button>
              </div>
            </div>
            {data?.last_probe_error ? <div style={{ marginTop: 10 }}><Notice kind="error">{data.last_probe_error}</Notice></div> : null}
          </section>

          {summary ? (
            <section style={{ marginTop: 24 }}>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 34, marginBottom: 14 }}>
                <SummaryCell label="Available features" value={summary.available} tone="good" />
                <SummaryCell label="Plan restricted" value={summary.restricted} tone={summary.restricted ? 'warn' : 'default'} />
                <SummaryCell label="Probe errors" value={summary.errors} tone={summary.errors ? 'bad' : 'default'} />
                <SummaryCell label="Capabilities checked" value={capabilityGroups} />
              </div>
              <CapabilitiesTable probe={probe} />
              <InventorySamples probe={probe} />
            </section>
          ) : null}

          <section style={{ marginTop: 28, borderTop: '1px solid var(--cf-border)', paddingTop: 20 }} data-testid="connecteam-reconciliation">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <ShieldCheck size={18} color="#047857" />
              <h2 style={{ margin: 0, fontSize: 18 }}>Identity and placement reconciliation</h2>
            </div>
            <p style={{ margin: '7px 0 0', color: 'var(--cf-text-secondary)', maxWidth: 1000 }}>Exact matches require a stored Connecteam identifier or an explicit CoreFlux placement code. Email, phone, and title matches remain review-only.</p>
            {preview ? (
              <>
                <ReconciliationSection title="People" icon={UsersRound} result={preview.people} />
                <ReconciliationSection title="Placement jobs" icon={Clock3} result={preview.jobs} />
              </>
            ) : (
              <div style={{ marginTop: 16, padding: '20px 0', color: 'var(--cf-text-secondary)' }}>No reconciliation preview has been run.</div>
            )}
          </section>
        </div>
      ) : null}
    </div>
  );
}
