import React, { useState, useEffect, useMemo } from 'react';
import { useApi, api } from '../lib/api';
import {
  CheckCircle2, XCircle, RefreshCw, ExternalLink, Save, Trash2,
  Database, Table2, AlertTriangle, Send, Plus, Eye, EyeOff, Copy, X,
  GitMerge, Sparkles as PromoteIcon,
} from 'lucide-react';
import ReconciliationModal from './ReconciliationModal';
import PromoteVaultModal   from './PromoteVaultModal';

/**
 * AirtableSettings — Personal Access Token connect + per-(base, table)
 * mapping editor. Mounted at /admin/integrations/airtable.
 *
 * Render branches keyed off /api/airtable/status:
 *   - configured=false → "Pod not configured" notice (today: never; PAT is per-tenant)
 *   - connected=false  → "Paste your PAT" CTA
 *   - connected=true   → PAT summary + table-mapping editor + manual sync
 */
export default function AirtableSettings() {
  const status = useApi('/api/airtable/status.php?action=status');
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState(null);

  const data = status.data || {};
  const configured = !!data.configured;
  const connected  = !!data.connected;
  const mappings   = data.mappings || [];
  const entities   = data.entities || [];
  const directions = data.directions || ['pull', 'off'];

  if (status.loading) return <div data-testid="airtable-settings-loading">Loading…</div>;

  return (
    <section data-testid="airtable-settings" style={{ maxWidth: 980 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Airtable — Connection</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Connect your tenant's Airtable workspace via a Personal Access Token. CoreFlux pulls records
          from any base/table you map below into the integrations vault (read-only v1). The PAT is
          stored AES-256-GCM encrypted; only the last 4 characters are ever displayed.
        </p>
      </header>

      {flash && (
        <div
          data-testid={`airtable-flash-${flash.kind}`}
          style={{
            padding: '10px 14px', borderRadius: 6, marginBottom: 16,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color:      flash.kind === 'success' ? 'var(--cf-green, #047857)'    : 'var(--cf-red, #b91c1c)',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

      {!configured && (
        <div data-testid="airtable-not-configured" className="card" style={cardStyle}>
          <strong>Airtable is not configured on this pod.</strong>
        </div>
      )}

      {configured && !connected && (
        <ConnectCard
          busy={busy} setBusy={setBusy} setFlash={setFlash} reload={status.reload}
        />
      )}

      {configured && connected && (
        <>
          <ConnectedSummary
            data={data} busy={busy} setBusy={setBusy}
            setFlash={setFlash} reload={status.reload}
          />
          <HealthPanel reload={status.reload} />
          <MappingEditor
            mappings={mappings} entities={entities} directions={directions}
            busy={busy} setBusy={setBusy}
            setFlash={setFlash} reload={status.reload}
          />
          <ActivityFeed audit={data.audit || []} />
        </>
      )}
    </section>
  );
}

const cardStyle = {
  padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8,
  background: 'var(--cf-surface)', marginBottom: 24,
};

/* ─────────────────────────────────────────────────────────── connect */

function ConnectCard({ busy, setBusy, setFlash, reload }) {
  const [pat, setPat] = useState('');
  const [label, setLabel] = useState('');
  const [showPat, setShowPat] = useState(false);

  const submit = async () => {
    if (!pat.trim()) { setFlash({ kind: 'error', msg: 'Paste your Airtable Personal Access Token first.' }); return; }
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/airtable/connect.php?action=connect', { pat: pat.trim(), workspace_label: label.trim() });
      setFlash({ kind: 'success', msg: `Connected (PAT ends in ${r.last4}).` });
      setPat(''); setLabel('');
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid="airtable-not-connected" className="card" style={cardStyle}>
      <div style={{ marginBottom: 12 }}>
        <span className="badge" style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
          Not connected
        </span>
      </div>
      <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '0 0 16px' }}>
        Create a Personal Access Token in your{' '}
        <a href="https://airtable.com/create/tokens" target="_blank" rel="noopener noreferrer">
          Airtable account settings <ExternalLink size={12} style={{ verticalAlign: 'middle' }} />
        </a>{' '}
        with at least <code>data.records:read</code> and <code>schema.bases:read</code> scopes,
        and add every base you want CoreFlux to read.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 10, maxWidth: 520 }}>
        <label style={{ fontSize: 12, fontWeight: 600 }}>
          Personal Access Token
          <div style={{ display: 'flex', gap: 4, marginTop: 4 }}>
            <input
              data-testid="airtable-pat-input"
              type={showPat ? 'text' : 'password'}
              value={pat}
              onChange={(e) => setPat(e.target.value)}
              placeholder="patXXXXXXXXXXXXXX..."
              style={{ flex: 1, padding: '6px 10px', borderRadius: 4, border: '1px solid var(--cf-border)', fontSize: 13, fontFamily: 'var(--cf-mono, ui-monospace)' }}
            />
            <button
              type="button"
              className="btn"
              data-testid="airtable-pat-toggle"
              onClick={() => setShowPat((v) => !v)}
              title={showPat ? 'Hide' : 'Show'}
            >
              {showPat ? <EyeOff size={14} /> : <Eye size={14} />}
            </button>
          </div>
        </label>
        <label style={{ fontSize: 12, fontWeight: 600 }}>
          Workspace label (optional)
          <input
            data-testid="airtable-label-input"
            type="text"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder="e.g. Ops Sidecar"
            style={{ width: '100%', padding: '6px 10px', borderRadius: 4, border: '1px solid var(--cf-border)', fontSize: 13, marginTop: 4 }}
          />
        </label>
        <div>
          <button
            type="button"
            className="btn btn--primary"
            data-testid="airtable-connect-btn"
            onClick={submit}
            disabled={busy}
          >
            {busy ? 'Connecting…' : 'Connect Airtable'}
          </button>
        </div>
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── connected */

function ConnectedSummary({ data, busy, setBusy, setFlash, reload }) {
  const handlePing = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/airtable/ping.php?action=ping', {});
      setFlash({ kind: r.ok ? 'success' : 'error', msg: r.ok ? `Ping OK (${r.latency_ms}ms)` : `Ping failed: ${r.error}` });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };
  const handleDisconnect = async () => {
    if (!window.confirm('Disconnect Airtable? The stored PAT will be erased.')) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/airtable/disconnect.php?action=disconnect', {});
      setFlash({ kind: 'success', msg: 'Airtable disconnected.' });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid="airtable-connected" className="card" style={cardStyle}>
      <div style={{ marginBottom: 12 }}>
        <span className="badge badge--success" style={{ padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
          <CheckCircle2 size={11} style={{ verticalAlign: 'middle', marginRight: 4 }} />Connected
        </span>
      </div>
      <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, fontSize: 13 }}>
        <dt style={{ color: 'var(--cf-text-secondary)' }}>Workspace label</dt>
        <dd style={{ margin: 0 }} data-testid="airtable-workspace-label">{data.workspace_label || '—'}</dd>
        <dt style={{ color: 'var(--cf-text-secondary)' }}>PAT</dt>
        <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="airtable-pat-last4">
          ••••{data.pat_last4 || '••••'}
        </dd>
        <dt style={{ color: 'var(--cf-text-secondary)' }}>Scopes</dt>
        <dd style={{ margin: 0, fontSize: 12, color: 'var(--cf-text-secondary)' }} data-testid="airtable-scopes">{data.scopes || '—'}</dd>
        <dt style={{ color: 'var(--cf-text-secondary)' }}>Last probe</dt>
        <dd style={{ margin: 0 }}>{data.last_probe_at || '—'}</dd>
      </dl>
      {data.last_probe_error && (
        <p style={{ color: 'var(--cf-red, #b91c1c)', fontSize: 12, marginTop: 8 }} data-testid="airtable-probe-error">
          Last error: {data.last_probe_error}
        </p>
      )}
      <div style={{ marginTop: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <button type="button" className="btn" onClick={handlePing} disabled={busy} data-testid="airtable-ping-btn">
          <RefreshCw size={14} style={{ marginRight: 6 }} />{busy ? 'Pinging…' : 'Test connection'}
        </button>
        <button type="button" className="btn" onClick={handleDisconnect} disabled={busy} data-testid="airtable-disconnect-btn">
          <XCircle size={14} style={{ marginRight: 6 }} />Disconnect
        </button>
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── health */

function HealthPanel({ reload }) {
  // Slice-3 — tenant-wide Airtable health & troubleshooting roll-up.
  // Surfaces connection state, per-mapping linkage %, recent sync
  // errors, Studio field-mapping coverage, and actionable hints.
  const health = useApi('/api/airtable/health.php?action=health');
  const [expanded, setExpanded] = useState(false);
  // Slice-3.1 — mapping_id currently being browsed in the Records Vault.
  const [vaultMappingId, setVaultMappingId] = useState(null);

  if (health.loading) {
    return (
      <div data-testid="airtable-health-loading" className="card" style={cardStyle}>
        Loading health…
      </div>
    );
  }
  if (health.error) {
    return (
      <div data-testid="airtable-health-error" className="card" style={cardStyle}>
        <strong style={{ color: 'var(--cf-red, #b91c1c)' }}>Could not load health:</strong>{' '}
        {health.error.message || String(health.error)}
      </div>
    );
  }
  const d = health.data || {};
  const rollup    = d.rollup || {};
  const perMap    = d.per_mapping || [];
  const hints     = d.hints || [];
  const coverage  = d.field_map_coverage || [];

  const healthPct = rollup.total_records > 0
    ? Math.round(100 * (rollup.linked || 0) / Math.max(1, rollup.total_records))
    : null;

  const healthBg = healthPct === null
    ? '#f1f5f9'
    : healthPct >= 90 ? '#ecfdf5'
    : healthPct >= 70 ? '#fef3c7'
    : '#fef2f2';
  const healthFg = healthPct === null
    ? '#475569'
    : healthPct >= 90 ? '#065f46'
    : healthPct >= 70 ? '#92400e'
    : '#991b1b';

  return (
    <div data-testid="airtable-health" className="card" style={cardStyle}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <div>
          <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>Health & troubleshooting</h4>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Tenant-wide rollup of Airtable linkage health, recent sync errors, and Studio field-mapping coverage.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <button
            type="button" className="btn"
            data-testid="airtable-health-refresh"
            onClick={() => { health.reload && health.reload(); reload && reload(); }}
            style={{ fontSize: 12 }}
          >
            <RefreshCw size={13} style={{ marginRight: 4 }} />Refresh
          </button>
        </div>
      </header>

      {/* Rollup tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 8, marginTop: 12 }}>
        <Tile testid="airtable-health-tile-records" label="Records synced"   value={rollup.total_records || 0} />
        <Tile testid="airtable-health-tile-linked"
              label="Linked to CoreFlux row"
              value={`${rollup.linked || 0}${healthPct !== null ? ` (${healthPct}%)` : ''}`}
              tone={healthPct === null ? 'neutral' : healthPct >= 90 ? 'ok' : healthPct >= 70 ? 'warn' : 'err'} />
        <Tile testid="airtable-health-tile-stored-only"
              label="Stored only (no link)"
              value={rollup.stored_only || 0}
              tone={(rollup.stored_only || 0) > 0 ? 'warn' : 'neutral'} />
        <Tile testid="airtable-health-tile-unmatched"
              label="Unmatched" value={rollup.unmatched || 0}
              tone={(rollup.unmatched || 0) > 0 ? 'warn' : 'ok'} />
        <Tile testid="airtable-health-tile-ambiguous"
              label="Ambiguous" value={rollup.ambiguous || 0}
              tone={(rollup.ambiguous || 0) > 0 ? 'warn' : 'ok'} />
        <Tile testid="airtable-health-tile-mappings"
              label="Mappings configured"
              value={`${rollup.mappings || 0}${(rollup.mappings_failed || 0) > 0 ? ` (${rollup.mappings_failed} failing)` : ''}`}
              tone={(rollup.mappings_failed || 0) > 0 ? 'err' : 'neutral'} />
        <Tile testid="airtable-health-tile-fieldmaps"
              label="Studio field mappings"
              value={coverage.reduce((acc, c) => acc + (c.field_mappings || 0), 0)} />
      </div>

      {/* Hints */}
      {hints.length > 0 && (
        <div data-testid="airtable-health-hints" style={{ marginTop: 16 }}>
          <strong style={{ fontSize: 13, display: 'block', marginBottom: 6 }}>
            {hints.length} thing{hints.length === 1 ? '' : 's'} to look at
          </strong>
          <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
            {hints.map((h, i) => (
              <li key={i}
                  data-testid={`airtable-health-hint-${h.code}`}
                  style={{
                    fontSize: 12, padding: '8px 10px', marginBottom: 4,
                    borderRadius: 6,
                    background: h.severity === 'error' ? 'var(--cf-red-bg, #fef2f2)'
                              : h.severity === 'warn'  ? 'var(--cf-amber-bg, #fef3c7)'
                              :                          'var(--cf-blue-bg, #eff6ff)',
                    color:      h.severity === 'error' ? 'var(--cf-red, #b91c1c)'
                              : h.severity === 'warn'  ? 'var(--cf-amber, #92400e)'
                              :                          'var(--cf-blue, #1e3a8a)',
                    border: '1px solid',
                    borderColor: h.severity === 'error' ? 'var(--cf-red-border, #fecaca)'
                               : h.severity === 'warn'  ? 'var(--cf-amber-border, #fde68a)'
                               :                          'var(--cf-blue-border, #bfdbfe)',
                  }}>
                <strong style={{ marginRight: 4, textTransform: 'uppercase', letterSpacing: 0.3, fontSize: 10 }}>
                  {h.severity}
                </strong>
                {h.message}
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Per-mapping detail (collapsible) */}
      <details data-testid="airtable-health-per-mapping-details"
               open={expanded} onToggle={(e) => setExpanded(e.target.open)}
               style={{ marginTop: 16 }}>
        <summary style={{ cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
          Per-mapping detail ({perMap.length})
        </summary>
        {perMap.length === 0 && (
          <p data-testid="airtable-health-per-mapping-empty"
             style={{ fontSize: 12, color: 'var(--cf-text-secondary)', margin: '8px 0' }}>
            No mappings yet.
          </p>
        )}
        {perMap.length > 0 && (
          <table data-testid="airtable-health-per-mapping-table"
                 style={{ width: '100%', marginTop: 8, fontSize: 12, borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
                <th style={{ padding: '4px 6px' }}>Table</th>
                <th style={{ padding: '4px 6px' }}>Entity</th>
                <th style={{ padding: '4px 6px' }}>Strategy</th>
                <th style={{ padding: '4px 6px', textAlign: 'right' }}>Linked</th>
                <th style={{ padding: '4px 6px', textAlign: 'right' }}>Stored only</th>
                <th style={{ padding: '4px 6px', textAlign: 'right' }}>Unmatched</th>
                <th style={{ padding: '4px 6px', textAlign: 'right' }}>Ambig.</th>
                <th style={{ padding: '4px 6px', textAlign: 'right' }}>Health %</th>
                <th style={{ padding: '4px 6px' }}>Last sync</th>
                <th style={{ padding: '4px 6px' }}></th>
              </tr>
            </thead>
            <tbody>
              {perMap.map((m) => (
                <tr key={m.id}
                    data-testid={`airtable-health-per-mapping-row-${m.id}`}
                    style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
                  <td style={{ padding: '4px 6px' }}>
                    {m.base_name || m.base_id} / {m.table_name || m.table_id}
                    {m.is_stored_only && (
                      <span data-testid={`airtable-health-stored-badge-${m.id}`}
                            style={{ marginLeft: 6, padding: '1px 6px', borderRadius: 4,
                                     background: '#fef3c7', color: '#92400e',
                                     fontSize: 10, fontWeight: 700, letterSpacing: 0.3 }}>
                        STORAGE ONLY
                      </span>
                    )}
                  </td>
                  <td style={{ padding: '4px 6px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{m.internal_entity}</td>
                  <td style={{ padding: '4px 6px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{m.link_strategy}</td>
                  <td style={{ padding: '4px 6px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{m.stats?.linked || 0}</td>
                  <td style={{ padding: '4px 6px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{m.stats?.stored_only || 0}</td>
                  <td style={{ padding: '4px 6px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{m.stats?.unmatched || 0}</td>
                  <td style={{ padding: '4px 6px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{m.stats?.ambiguous || 0}</td>
                  <td style={{ padding: '4px 6px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {m.health_pct === null ? '—' : `${m.health_pct}%`}
                  </td>
                  <td style={{ padding: '4px 6px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>
                    {m.last_sync_at || 'never'}
                  </td>
                  <td style={{ padding: '4px 6px', textAlign: 'right' }}>
                    <button
                      type="button" className="btn"
                      data-testid={`airtable-health-vault-btn-${m.id}`}
                      onClick={() => setVaultMappingId(m.id)}
                      style={{ fontSize: 11, padding: '2px 8px' }}
                      title="Browse the raw Airtable records synced for this mapping"
                    >
                      Records vault
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </details>

      {/* Field-map coverage */}
      {coverage.length > 0 && (
        <div data-testid="airtable-health-coverage" style={{ marginTop: 16 }}>
          <strong style={{ fontSize: 13, display: 'block', marginBottom: 6 }}>
            Studio field-mapping coverage
          </strong>
          <p style={{ fontSize: 11, color: 'var(--cf-text-secondary)', margin: '0 0 6px' }}>
            How many Studio mappings will run on each Airtable sync to write into CoreFlux columns.
          </p>
          <ul style={{ listStyle: 'none', padding: 0, margin: 0,
                       display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {coverage.map((c, i) => (
              <li key={i}
                  data-testid={`airtable-health-coverage-${c.entity_type}`}
                  style={{ fontSize: 11, padding: '3px 8px', borderRadius: 999,
                           background: '#eef2ff', color: '#3730a3',
                           border: '1px solid #c7d2fe' }}>
                <code>{c.entity_type}</code>: {c.field_mappings}
              </li>
            ))}
          </ul>
        </div>
      )}
      <p style={{ fontSize: 11, color: 'var(--cf-text-secondary)', margin: '12px 0 0' }}>
        Use the Health rollup before running large back-fills. <span style={{ color: healthFg, background: healthBg, padding: '1px 6px', borderRadius: 4 }}>
          Status: {healthPct === null ? 'no data yet' : healthPct >= 90 ? 'healthy' : healthPct >= 70 ? 'mostly healthy' : 'needs attention'}
        </span>
      </p>
      {vaultMappingId !== null && (
        <VaultBrowser
          mappingId={vaultMappingId}
          onClose={() => setVaultMappingId(null)}
        />
      )}
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── vault */

function VaultBrowser({ mappingId, onClose }) {
  // Slice-3.1 — drill-down into the integrations vault for one
  // Airtable mapping. Shows the actual external_entity_mappings rows
  // we synced so operators can see "what we stored", browse fields
  // detected in the payload, and decide whether to wire field
  // mappings / link strategies.
  const [data, setData]   = useState(null);
  const [busy, setBusy]   = useState(false);
  const [error, setError] = useState(null);
  const [q, setQ]         = useState('');
  const [offset, setOffset] = useState(0);
  const limit = 50;
  const [expanded, setExpanded] = useState({}); // {row_id: true}

  const load = async () => {
    setBusy(true); setError(null);
    try {
      const params = new URLSearchParams({
        action: 'vault',
        mapping_id: String(mappingId),
        limit: String(limit),
        offset: String(offset),
      });
      if (q.trim()) params.set('q', q.trim());
      const r = await api.get(`/api/airtable/vault.php?${params.toString()}`);
      setData(r);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [mappingId, offset]);

  const rows = data?.rows || [];
  const topFields = data?.top_fields || [];
  const total = data?.total || 0;
  const pageStart = offset + 1;
  const pageEnd = Math.min(offset + rows.length, total);

  return (
    <div
      data-testid="airtable-vault-modal"
      role="dialog" aria-modal="true"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000, padding: 24,
      }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        style={{
          background: 'var(--cf-surface, #fff)', borderRadius: 8,
          width: 'min(960px, 96vw)', maxHeight: '92vh',
          overflow: 'hidden', display: 'flex', flexDirection: 'column',
          boxShadow: '0 20px 50px rgba(0,0,0,0.25)',
        }}
      >
        <header style={{ padding: '14px 20px', borderBottom: '1px solid var(--cf-border)',
                         display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 600 }}>
              Records vault
              {data?.is_stored_only && (
                <span style={{ marginLeft: 8, padding: '2px 8px',
                               background: '#fef3c7', color: '#92400e',
                               borderRadius: 4, fontSize: 11, fontWeight: 700,
                               letterSpacing: 0.3 }}>
                  STORAGE ONLY · link_strategy=none
                </span>
              )}
            </h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              Showing {rows.length > 0 ? `${pageStart}–${pageEnd}` : 0} of {total} record(s) from
              the integrations vault for <code>{data?.internal_entity || ''}</code>.
              {data?.is_stored_only && ' These rows are stored verbatim but never linked to a real CoreFlux entity. Adjust the mapping\u2019s entity type + link strategy, or wire Studio field mappings to make them useful.'}
            </p>
          </div>
          <button type="button" className="btn"
                  data-testid="airtable-vault-close"
                  onClick={onClose} style={{ fontSize: 13 }}>
            <X size={14} />
          </button>
        </header>

        <div style={{ padding: '10px 20px', display: 'flex', gap: 10, alignItems: 'center',
                      borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
          <input
            data-testid="airtable-vault-search"
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { setOffset(0); load(); } }}
            placeholder="Search external_id or payload content…"
            style={{ flex: 1, padding: '6px 10px', borderRadius: 4,
                     border: '1px solid var(--cf-border)', fontSize: 13 }}
          />
          <button type="button" className="btn"
                  data-testid="airtable-vault-search-btn"
                  onClick={() => { setOffset(0); load(); }}
                  disabled={busy}>
            Search
          </button>
        </div>

        {topFields.length > 0 && (
          <div data-testid="airtable-vault-top-fields"
               style={{ padding: '8px 20px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
            <div style={{ fontSize: 11, fontWeight: 700, color: '#475569',
                          textTransform: 'uppercase', letterSpacing: 0.3, marginBottom: 4 }}>
              Detected Airtable fields ({topFields.length}{topFields.length === 25 ? '+ — top 25' : ''})
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
              {topFields.map((f) => (
                <span key={f.field}
                      data-testid={`airtable-vault-field-${f.field}`}
                      title={`Appears in ${f.occurrences} record(s)`}
                      style={{ fontSize: 11, padding: '2px 8px', borderRadius: 999,
                               background: '#eef2ff', color: '#3730a3',
                               border: '1px solid #c7d2fe' }}>
                  {f.field} <span style={{ opacity: 0.7 }}>({f.occurrences})</span>
                </span>
              ))}
            </div>
          </div>
        )}

        <div style={{ flex: 1, overflow: 'auto', padding: '0 20px' }}>
          {busy && <p data-testid="airtable-vault-loading">Loading…</p>}
          {error && <p data-testid="airtable-vault-error" style={{ color: 'var(--cf-red, #b91c1c)' }}>{error}</p>}
          {!busy && !error && rows.length === 0 && (
            <p data-testid="airtable-vault-empty" style={{ color: 'var(--cf-text-secondary)' }}>
              No records found in the vault for this mapping.
            </p>
          )}
          {!busy && !error && rows.length > 0 && (
            <table data-testid="airtable-vault-table"
                   style={{ width: '100%', fontSize: 12, marginTop: 10, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)',
                             borderBottom: '1px solid var(--cf-border)', position: 'sticky', top: 0,
                             background: 'var(--cf-surface, #fff)' }}>
                  <th style={{ padding: '6px' }}>Airtable Rec</th>
                  <th style={{ padding: '6px' }}>Status</th>
                  <th style={{ padding: '6px', textAlign: 'right' }}>Fields</th>
                  <th style={{ padding: '6px' }}>Last seen</th>
                  <th style={{ padding: '6px' }}></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <React.Fragment key={r.id}>
                    <tr data-testid={`airtable-vault-row-${r.id}`}
                        style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
                      <td style={{ padding: '6px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{r.external_id}</td>
                      <td style={{ padding: '6px' }}>{r.sync_status}</td>
                      <td style={{ padding: '6px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.field_count}</td>
                      <td style={{ padding: '6px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{r.last_seen_at || '—'}</td>
                      <td style={{ padding: '6px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                        {r.airtable_record_url && (
                          <a
                            data-testid={`airtable-vault-open-${r.id}`}
                            href={r.airtable_record_url}
                            target="_blank" rel="noopener noreferrer"
                            style={{ fontSize: 11, marginRight: 8, color: '#4338ca' }}>
                            <ExternalLink size={11} style={{ verticalAlign: 'middle' }} /> Open
                          </a>
                        )}
                        <button type="button" className="btn"
                                data-testid={`airtable-vault-toggle-${r.id}`}
                                onClick={() => setExpanded((s) => ({ ...s, [r.id]: !s[r.id] }))}
                                style={{ fontSize: 11, padding: '2px 6px' }}>
                          {expanded[r.id] ? 'Hide' : 'Inspect'}
                        </button>
                      </td>
                    </tr>
                    {expanded[r.id] && (
                      <tr data-testid={`airtable-vault-detail-${r.id}`}>
                        <td colSpan={5} style={{ padding: '6px 12px 12px', background: '#f8fafc' }}>
                          <pre style={{ margin: 0, fontSize: 11, background: '#0f172a',
                                        color: '#e2e8f0', padding: 10, borderRadius: 6,
                                        maxHeight: 280, overflow: 'auto' }}>
                            {JSON.stringify(r.payload_snapshot, null, 2)}
                          </pre>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <footer style={{ padding: '10px 20px', borderTop: '1px solid var(--cf-border)',
                         display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Page {Math.floor(offset / limit) + 1} of {Math.max(1, Math.ceil(total / limit))}
          </span>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="button" className="btn"
                    data-testid="airtable-vault-prev"
                    onClick={() => setOffset(Math.max(0, offset - limit))}
                    disabled={busy || offset === 0}>
              ← Prev
            </button>
            <button type="button" className="btn"
                    data-testid="airtable-vault-next"
                    onClick={() => setOffset(offset + limit)}
                    disabled={busy || offset + limit >= total}>
              Next →
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function Tile({ testid, label, value, tone = 'neutral' }) {
  const palette = tone === 'ok'   ? { bg: '#ecfdf5', fg: '#065f46', border: '#a7f3d0' }
                : tone === 'warn' ? { bg: '#fef3c7', fg: '#92400e', border: '#fde68a' }
                : tone === 'err'  ? { bg: '#fef2f2', fg: '#991b1b', border: '#fecaca' }
                :                   { bg: '#f8fafc', fg: '#0f172a', border: '#e2e8f0' };
  return (
    <div data-testid={testid}
         style={{
           padding: '10px 12px',
           background: palette.bg,
           border: `1px solid ${palette.border}`,
           borderRadius: 6,
         }}>
      <div style={{ fontSize: 10, fontWeight: 700, color: palette.fg,
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

/* ─────────────────────────────────────────────────────────── mappings */

function MappingEditor({ mappings, entities, directions, busy, setBusy, setFlash, reload }) {
  const [adding, setAdding] = useState(false);
  const [discoverOpen, setDiscoverOpen] = useState(false);
  return (
    <div data-testid="airtable-mappings" className="card" style={cardStyle}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div>
          <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>Table mappings</h4>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Pick a base + table, map fields, and CoreFlux will sync the records on a 15-minute cron
            (or via the per-row "Sync now" button). v1 is pull-only.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button
            type="button" className="btn"
            data-testid="airtable-discover-tables-btn"
            onClick={() => setDiscoverOpen(true)}
            disabled={busy}
            title="See every base + table the PAT can read and bulk-add mappings"
          >
            <Database size={14} style={{ marginRight: 6 }} />Sync more tables
          </button>
          <button
            type="button" className="btn btn--primary" data-testid="airtable-add-mapping-btn"
            onClick={() => setAdding(true)} disabled={busy}
          >
            <Plus size={14} style={{ marginRight: 6 }} />Add mapping
          </button>
        </div>
      </header>

      {mappings.length === 0 && !adding && (
        <p data-testid="airtable-no-mappings" style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '8px 0' }}>
          No mappings yet. Click <strong>Add mapping</strong> to start syncing a table, or
          use <strong>Sync more tables</strong> to discover every base + table at once.
        </p>
      )}

      {mappings.map((m) => (
        <MappingRow
          key={m.id} mapping={m} entities={entities} directions={directions}
          busy={busy} setBusy={setBusy}
          setFlash={setFlash} reload={reload}
        />
      ))}

      {adding && (
        <MappingForm
          key="new"
          mapping={null} entities={entities} directions={directions}
          busy={busy} setBusy={setBusy}
          setFlash={setFlash} reload={reload}
          onClose={() => setAdding(false)}
        />
      )}

      {discoverOpen && (
        <DiscoverTablesModal
          entities={entities}
          busy={busy} setBusy={setBusy}
          setFlash={setFlash} reload={reload}
          onClose={() => setDiscoverOpen(false)}
        />
      )}
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── discover */

function DiscoverTablesModal({ entities, busy, setBusy, setFlash, reload, onClose }) {
  // Slice-3.1 — bulk-discover every base + table reachable with the
  // tenant's PAT and let the operator pick multiple at once. Drops
  // them all into airtable_table_mappings via mapping_save_bulk.
  const [data, setData]    = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]  = useState(null);
  const [selected, setSelected] = useState({}); // key: 'base|table' → {entity, direction}
  const [defaultEntity, setDefaultEntity] = useState('generic');
  const [filter, setFilter] = useState('');

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    api.get('/api/airtable/discover_tables.php?action=discover_tables')
      .then((r) => { if (mounted) { setData(r); setLoading(false); } })
      .catch((e) => { if (mounted) { setError(e.message || String(e)); setLoading(false); } });
    return () => { mounted = false; };
  }, []);

  const toggle = (baseId, baseName, table) => {
    if (table.mapped) return;
    const key = `${baseId}|${table.id}`;
    setSelected((s) => {
      const next = { ...s };
      if (next[key]) {
        delete next[key];
      } else {
        next[key] = {
          base_id:     baseId,
          base_name:   baseName,
          table_id:    table.id,
          table_name:  table.name,
          internal_entity: defaultEntity,
          direction:   'pull',
        };
      }
      return next;
    });
  };

  const updateEntityForRow = (key, entity) => {
    setSelected((s) => ({ ...s, [key]: { ...s[key], internal_entity: entity } }));
  };

  const selectedKeys = Object.keys(selected);
  const apply = async () => {
    if (selectedKeys.length === 0) return;
    setBusy(true);
    try {
      const r = await api.post('/api/airtable/mapping_save_bulk.php?action=mapping_save_bulk', {
        items: selectedKeys.map((k) => selected[k]),
      });
      const created = (r.created || []).length;
      const errors  = (r.errors  || []).length;
      const skipped = (r.skipped || []).length;
      setFlash({
        kind: errors > 0 ? 'error' : 'success',
        msg:  `Bulk add: ${created} created · ${skipped} skipped · ${errors} errors`,
      });
      reload();
      if (errors === 0) onClose();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const filt = filter.trim().toLowerCase();
  const bases = data?.bases || [];

  return (
    <div
      data-testid="airtable-discover-modal"
      role="dialog" aria-modal="true"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000, padding: 24,
      }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div style={{
        background: 'var(--cf-surface, #fff)', borderRadius: 8,
        width: 'min(880px, 96vw)', maxHeight: '92vh',
        overflow: 'hidden', display: 'flex', flexDirection: 'column',
        boxShadow: '0 20px 50px rgba(0,0,0,0.25)',
      }}>
        <header style={{ padding: '14px 20px', borderBottom: '1px solid var(--cf-border)',
                         display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 600 }}>Sync more tables</h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              Every base + table reachable with this tenant's PAT. Tick the ones you want to start
              syncing — they'll be added as new pull-only mappings under the entity you pick.
              {data && (
                <> · <code data-testid="airtable-discover-stats">{data.tables_mapped}/{data.tables_total} tables already mapped</code></>
              )}
            </p>
          </div>
          <button type="button" className="btn"
                  data-testid="airtable-discover-close" onClick={onClose}>
            <X size={14} />
          </button>
        </header>

        <div style={{ padding: '10px 20px', display: 'flex', gap: 10, alignItems: 'center',
                      borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
          <label style={{ fontSize: 12, fontWeight: 600 }}>
            Default entity for new mappings:
            <select
              data-testid="airtable-discover-default-entity"
              value={defaultEntity}
              onChange={(e) => {
                const next = e.target.value;
                setDefaultEntity(next);
                // Update every already-selected row that's still on the previous default.
                setSelected((s) => {
                  const out = { ...s };
                  for (const k of Object.keys(out)) {
                    if (out[k].internal_entity === defaultEntity) {
                      out[k] = { ...out[k], internal_entity: next };
                    }
                  }
                  return out;
                });
              }}
              style={{ marginLeft: 6, padding: '4px 8px', fontSize: 12 }}
            >
              {entities.map((en) => <option key={en} value={en}>{en}</option>)}
            </select>
          </label>
          <input
            type="search"
            data-testid="airtable-discover-filter"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            placeholder="Filter base or table name…"
            style={{ flex: 1, padding: '6px 10px', borderRadius: 4,
                     border: '1px solid var(--cf-border)', fontSize: 13 }}
          />
        </div>

        <div style={{ flex: 1, overflow: 'auto', padding: '8px 20px' }}>
          {loading && <p data-testid="airtable-discover-loading">Loading bases…</p>}
          {error && <p data-testid="airtable-discover-error" style={{ color: 'var(--cf-red, #b91c1c)' }}>{error}</p>}
          {!loading && !error && bases.length === 0 && (
            <p data-testid="airtable-discover-empty" style={{ color: 'var(--cf-text-secondary)' }}>
              No bases reachable with this PAT. Check that the token grants <code>schema.bases:read</code> + access to the bases you want.
            </p>
          )}
          {!loading && !error && bases.map((b) => {
            const matchesBase = filt === '' || b.name.toLowerCase().includes(filt);
            const tables = (b.tables || []).filter(
              (t) => matchesBase || t.name.toLowerCase().includes(filt)
            );
            if (tables.length === 0) return null;
            return (
              <section key={b.id}
                       data-testid={`airtable-discover-base-${b.id}`}
                       style={{ marginBottom: 14 }}>
                <header style={{ display: 'flex', alignItems: 'center', gap: 8,
                                 fontSize: 13, fontWeight: 600, marginBottom: 6 }}>
                  <Database size={13} />
                  <span>{b.name}</span>
                  <code style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{b.id}</code>
                  {b.error && (
                    <span style={{ fontSize: 11, color: 'var(--cf-red, #b91c1c)' }}>
                      {b.error}
                    </span>
                  )}
                </header>
                <ul style={{ listStyle: 'none', padding: 0, margin: 0,
                             border: '1px solid var(--cf-border-muted, #f1f5f9)', borderRadius: 6 }}>
                  {tables.map((t) => {
                    const key = `${b.id}|${t.id}`;
                    const isSel = !!selected[key];
                    const disabled = t.mapped;
                    return (
                      <li key={t.id}
                          data-testid={`airtable-discover-table-${t.id}`}
                          style={{ padding: '6px 10px',
                                   borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                                   display: 'flex', alignItems: 'center', gap: 8,
                                   opacity: disabled ? 0.55 : 1, cursor: disabled ? 'not-allowed' : 'pointer' }}
                          onClick={() => toggle(b.id, b.name, t)}>
                        <input
                          type="checkbox"
                          data-testid={`airtable-discover-check-${t.id}`}
                          checked={isSel}
                          disabled={disabled}
                          onChange={() => {}}
                          style={{ pointerEvents: 'none' }}
                        />
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 13, fontWeight: 500 }}>
                            <Table2 size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
                            {t.name}
                            {disabled && (
                              <span style={{ marginLeft: 8, fontSize: 11, color: 'var(--cf-green, #047857)' }}>
                                already mapped
                              </span>
                            )}
                          </div>
                          <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                            {t.field_count} field(s) · <code>{t.id}</code>
                          </div>
                        </div>
                        {isSel && (
                          <select
                            data-testid={`airtable-discover-entity-${t.id}`}
                            value={selected[key].internal_entity}
                            onClick={(e) => e.stopPropagation()}
                            onChange={(e) => updateEntityForRow(key, e.target.value)}
                            style={{ fontSize: 12, padding: '2px 6px' }}>
                            {entities.map((en) => <option key={en} value={en}>{en}</option>)}
                          </select>
                        )}
                      </li>
                    );
                  })}
                </ul>
              </section>
            );
          })}
        </div>

        <footer style={{ padding: '12px 20px', borderTop: '1px solid var(--cf-border)',
                         display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            {selectedKeys.length} table(s) selected
          </span>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="button" className="btn"
                    data-testid="airtable-discover-cancel" onClick={onClose} disabled={busy}>
              Cancel
            </button>
            <button type="button" className="btn btn--primary"
                    data-testid="airtable-discover-apply"
                    onClick={apply}
                    disabled={busy || selectedKeys.length === 0}>
              {busy ? 'Adding…' : `Add ${selectedKeys.length} mapping${selectedKeys.length === 1 ? '' : 's'}`}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function MappingRow({ mapping, entities, directions, busy, setBusy, setFlash, reload }) {
  const [editing, setEditing] = useState(false);
  const [duplicating, setDuplicating] = useState(false);
  // Slice 4 — open the Reconciliation queue or Promote-vault wizard
  // for this mapping in particular.
  const [reconciling, setReconciling] = useState(false);
  const [promoting, setPromoting]     = useState(false);
  // Slice 2 — fire-and-forget link stats fetch so the row badge surfaces
  // linked / unmatched / ambiguous counts inline.
  const [stats, setStats] = useState(null);
  useEffect(() => {
    let mounted = true;
    api.get(`/api/airtable/link_stats.php?action=link_stats&mapping_id=${mapping.id}`)
       .then(r => { if (mounted) setStats(r); })
       .catch(() => { if (mounted) setStats(null); });
    return () => { mounted = false; };
  }, [mapping.id, mapping.last_sync_at]);

  const handleSyncNow = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/airtable/sync_now.php?action=sync_now', { mapping_id: mapping.id });
      const linkBits = (r.link_strategy && r.link_strategy !== 'none')
        ? ` · linked ${r.linked || 0}, unmatched ${r.unmatched || 0}, ambiguous ${r.ambiguous || 0}`
        : '';
      setFlash({
        kind: r.failed > 0 ? 'error' : 'success',
        msg: `Sync: ${r.records} records · ${r.created} created · ${r.updated} updated · ${r.unchanged} unchanged · ${r.failed} failed${linkBits} (${r.pages} page${r.pages === 1 ? '' : 's'}, ${r.latency_ms}ms)`,
      });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handlePushNow = async () => {
    // Slice 5 — push CoreFlux rows to Airtable. Direction must be
    // 'push' or 'both' AND reverse_field_map must be non-empty.
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/airtable/push_now.php?action=push_now', { mapping_id: mapping.id });
      setFlash({
        kind: r.errored > 0 ? 'error' : 'success',
        msg: `Push: scanned ${r.scanned} · ${r.pushed} pushed (${r.created} created · ${r.updated} updated) · ${r.skipped_unmatched} skipped · ${r.errored} errored.`,
      });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleRelink = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/airtable/relink.php?action=relink', { mapping_id: mapping.id });
      setFlash({
        kind: 'success',
        msg: `Relink (${r.link_strategy}): scanned ${r.scanned} · ${r.relinked} now linked · ${r.still_unmatched} unmatched · ${r.still_ambiguous} ambiguous.`,
      });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!window.confirm(`Delete mapping for ${mapping.base_name || mapping.base_id} / ${mapping.table_name || mapping.table_id}?`)) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/airtable/mapping_delete.php?action=mapping_delete', { id: mapping.id });
      setFlash({ kind: 'success', msg: 'Mapping deleted.' });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid={`airtable-mapping-row-${mapping.id}`} style={{ border: '1px solid var(--cf-border-muted, #f1f5f9)', borderRadius: 6, padding: 12, marginBottom: 10 }}>
      {!editing ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 220 }}>
            <div style={{ fontWeight: 600, fontSize: 14 }}>
              <Database size={13} style={{ verticalAlign: 'middle', marginRight: 4 }} />
              {mapping.base_name || mapping.base_id}
              {' / '}
              <Table2 size={13} style={{ verticalAlign: 'middle', marginLeft: 4, marginRight: 4 }} />
              {mapping.table_name || mapping.table_id}
            </div>
            <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 2 }}>
              → <code>{mapping.internal_entity}</code> · {mapping.direction} · last sync: {mapping.last_sync_at || 'never'}
              {mapping.last_records > 0 && <> · {mapping.last_records} records</>}
            </div>
            {/* Slice 5 — last push metadata. Only render for mappings
                that actually have push enabled. */}
            {(mapping.direction === 'push' || mapping.direction === 'both') && (
              <div data-testid={`airtable-push-meta-${mapping.id}`}
                   style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 2 }}>
                last push: {mapping.last_push_at || 'never'}
                {mapping.last_push_records > 0 && <> · {mapping.last_push_records} pushed</>}
                {mapping.last_push_error && (
                  <span style={{ color: 'var(--cf-red, #b91c1c)', marginLeft: 6 }}>
                    <AlertTriangle size={11} style={{ verticalAlign: 'middle', marginRight: 3 }} />
                    {mapping.last_push_error}
                  </span>
                )}
              </div>
            )}
            {/* Slice 2 linkage badge */}
            <div data-testid={`airtable-link-badge-${mapping.id}`}
                 style={{ fontSize: 11, marginTop: 4, display: 'flex',
                          gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
              <span style={{ color: '#475569', fontWeight: 600,
                             textTransform: 'uppercase', letterSpacing: 0.3 }}>
                Linkage:
              </span>
              <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                strategy=<code>{mapping.link_strategy || 'none'}</code>
              </span>
              {stats && (
                <>
                  <Badge tone="ok"        label={`${stats.linked} linked`}        testid={`airtable-link-linked-${mapping.id}`} />
                  {stats.unmatched > 0 && <Badge tone="warn" label={`${stats.unmatched} unmatched`} testid={`airtable-link-unmatched-${mapping.id}`} />}
                  {stats.ambiguous > 0 && <Badge tone="warn" label={`${stats.ambiguous} ambiguous`} testid={`airtable-link-ambiguous-${mapping.id}`} />}
                </>
              )}
            </div>
            {mapping.last_sync_error && (
              <div data-testid={`airtable-mapping-error-${mapping.id}`} style={{ fontSize: 12, color: 'var(--cf-red, #b91c1c)', marginTop: 2 }}>
                <AlertTriangle size={11} style={{ verticalAlign: 'middle', marginRight: 4 }} />
                {mapping.last_sync_error}
              </div>
            )}
          </div>
          <div style={{ display: 'flex', gap: 6 }}>
            <button
              type="button" className="btn btn--primary"
              data-testid={`airtable-sync-now-${mapping.id}`}
              onClick={handleSyncNow}
              disabled={busy || !(mapping.direction === 'pull' || mapping.direction === 'both')}
              title={(mapping.direction === 'pull' || mapping.direction === 'both')
                ? 'Pull Airtable records into CoreFlux now'
                : 'Sync now only runs when direction is pull or both'}
            >
              <Send size={13} style={{ marginRight: 4 }} />Sync now
            </button>
            {(mapping.direction === 'push' || mapping.direction === 'both') && (
              <button
                type="button" className="btn"
                data-testid={`airtable-push-now-${mapping.id}`}
                onClick={handlePushNow} disabled={busy}
                title="Push CoreFlux rows to Airtable now"
                style={{ background: '#0ea5e9', color: '#fff', borderColor: '#0ea5e9' }}
              >
                <Send size={13} style={{ marginRight: 4, transform: 'rotate(180deg)' }} />
                Push now
              </button>
            )}
            <button
              type="button" className="btn"
              data-testid={`airtable-relink-${mapping.id}`}
              onClick={handleRelink} disabled={busy}
              title="Re-run the linkage resolver against every existing row for this mapping"
            >
              Relink
            </button>
            {(mapping.link_strategy !== 'none' || (stats && stats.unmatched + stats.ambiguous > 0)) && (
              <button
                type="button" className="btn"
                data-testid={`airtable-reconcile-btn-${mapping.id}`}
                onClick={() => setReconciling(true)} disabled={busy}
                title="Manually triage unmatched / ambiguous rows"
              >
                <GitMerge size={13} style={{ marginRight: 4 }} />
                Reconcile
                {stats && (stats.unmatched + stats.ambiguous) > 0 && (
                  <span data-testid={`airtable-reconcile-count-${mapping.id}`}
                        style={{ marginLeft: 4, padding: '0 5px', borderRadius: 999,
                                 background: '#fef3c7', color: '#92400e',
                                 fontSize: 10, fontWeight: 700 }}>
                    {stats.unmatched + stats.ambiguous}
                  </span>
                )}
              </button>
            )}
            {mapping.link_strategy === 'none' && stats && stats.stored_only > 0 && (
              <button
                type="button" className="btn"
                data-testid={`airtable-promote-btn-${mapping.id}`}
                onClick={() => setPromoting(true)} disabled={busy}
                title="Convert this storage-only mapping into a real CoreFlux entity"
                style={{ background: '#7c3aed', color: '#fff', borderColor: '#7c3aed' }}
              >
                <PromoteIcon size={13} style={{ marginRight: 4 }} />
                Promote vault
              </button>
            )}
            <button
              type="button" className="btn"
              data-testid={`airtable-duplicate-mapping-${mapping.id}`}
              onClick={() => setDuplicating(true)} disabled={busy}
              title="Duplicate this mapping to other tenants you manage"
            >
              <Copy size={13} style={{ marginRight: 4 }} />Duplicate
            </button>
            <button
              type="button" className="btn"
              data-testid={`airtable-edit-mapping-${mapping.id}`}
              onClick={() => setEditing(true)} disabled={busy}
            >
              Edit
            </button>
            <button
              type="button" className="btn"
              data-testid={`airtable-delete-mapping-${mapping.id}`}
              onClick={handleDelete} disabled={busy}
            >
              <Trash2 size={13} />
            </button>
          </div>
        </div>
      ) : (
        <MappingForm
          mapping={mapping} entities={entities} directions={directions}
          busy={busy} setBusy={setBusy}
          setFlash={setFlash} reload={reload}
          onClose={() => setEditing(false)}
        />
      )}
      {duplicating && (
        <DuplicateModal
          mapping={mapping} busy={busy} setBusy={setBusy}
          setFlash={setFlash} reload={reload}
          onClose={() => setDuplicating(false)}
        />
      )}
      {reconciling && (
        <ReconciliationModal
          mappingId={mapping.id}
          entity={mapping.internal_entity}
          onClose={() => { setReconciling(false); reload(); }}
        />
      )}
      {promoting && (
        <PromoteVaultModal
          mapping={mapping}
          onClose={() => { setPromoting(false); reload(); }}
          onComplete={() => reload()}
        />
      )}
    </div>
  );
}

function MappingForm({ mapping, entities, directions, busy, setBusy, setFlash, reload, onClose }) {
  const isNew = !mapping;
  const [baseId,   setBaseId]   = useState(mapping?.base_id   || '');
  const [baseName, setBaseName] = useState(mapping?.base_name || '');
  const [tableId,  setTableId]  = useState(mapping?.table_id  || '');
  const [tableName,setTableName]= useState(mapping?.table_name|| '');
  const [entity,   setEntity]   = useState(mapping?.internal_entity || 'generic');
  const [dir,      setDir]      = useState(mapping?.direction || 'pull');
  const [primary,  setPrimary]  = useState(mapping?.primary_field || '');
  const [fieldMap, setFieldMap] = useState(mapping?.field_map ? JSON.stringify(mapping.field_map, null, 2) : '{}');
  // Slice 5 — push direction config. Independent of the pull field_map.
  const [reverseFieldMap, setReverseFieldMap] = useState(
    mapping?.reverse_field_map && Object.keys(mapping.reverse_field_map).length > 0
      ? JSON.stringify(mapping.reverse_field_map, null, 2)
      : '{}'
  );
  const [pushUnmatched, setPushUnmatched] = useState(mapping?.push_unmatched_action || 'create_new');
  // Slice 2 — linkage policy (defaults filled by backend on first save
  // if left empty; we surface them here once the operator picks an
  // entity so they understand what'll be applied).
  const [linkStrategy,   setLinkStrategy]   = useState(mapping?.link_strategy   || '');
  const [linkAirField,   setLinkAirField]   = useState(mapping?.link_match_airtable_field   || '');
  const [linkIntColumn,  setLinkIntColumn]  = useState(mapping?.link_match_internal_column  || '');
  const [linkUnmatched,  setLinkUnmatched]  = useState(mapping?.link_unmatched_action || 'park');
  const [bases, setBases] = useState([]);
  const [tables, setTables] = useState([]);
  const [loadingBases, setLoadingBases] = useState(false);
  const [loadingTables, setLoadingTables] = useState(false);

  const loadBases = async () => {
    setLoadingBases(true);
    try {
      const r = await api.get('/api/airtable/list_bases.php?action=list_bases');
      setBases(r.bases || []);
    } catch (e) {
      setFlash({ kind: 'error', msg: 'Failed to list bases: ' + (e.message || e) });
    } finally {
      setLoadingBases(false);
    }
  };
  const loadTables = async (bid) => {
    if (!bid) return;
    setLoadingTables(true);
    try {
      const r = await api.get(`/api/airtable/list_tables.php?action=list_tables&base_id=${encodeURIComponent(bid)}`);
      setTables(r.tables || []);
    } catch (e) {
      setFlash({ kind: 'error', msg: 'Failed to list tables: ' + (e.message || e) });
    } finally {
      setLoadingTables(false);
    }
  };

  useEffect(() => { if (isNew) loadBases(); }, [isNew]);
  useEffect(() => { if (baseId) loadTables(baseId); }, [baseId]);

  const selectedTable = useMemo(() => tables.find((t) => t.id === tableId), [tables, tableId]);

  const onPickBase = (id) => {
    setBaseId(id);
    const b = bases.find((x) => x.id === id);
    setBaseName(b?.name || '');
    setTableId(''); setTableName('');
  };
  const onPickTable = (id) => {
    setTableId(id);
    const t = tables.find((x) => x.id === id);
    setTableName(t?.name || '');
    if (t && !primary) {
      const pf = t.fields?.find((f) => f.id === t.primaryFieldId);
      if (pf) setPrimary(pf.name);
    }
  };

  const submit = async () => {
    let parsed = {};
    try { parsed = JSON.parse(fieldMap || '{}'); }
    catch { setFlash({ kind: 'error', msg: 'field_map must be valid JSON.' }); return; }
    let parsedReverse = null;
    if (dir === 'push' || dir === 'both') {
      try { parsedReverse = JSON.parse(reverseFieldMap || '{}'); }
      catch { setFlash({ kind: 'error', msg: 'reverse_field_map must be valid JSON.' }); return; }
      if (!parsedReverse || typeof parsedReverse !== 'object' || Array.isArray(parsedReverse)) {
        setFlash({ kind: 'error', msg: 'reverse_field_map must be a JSON object.' }); return;
      }
    }
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/airtable/mapping_save.php?action=mapping_save', {
        base_id: baseId, base_name: baseName,
        table_id: tableId, table_name: tableName,
        internal_entity: entity, direction: dir,
        field_map: parsed, primary_field: primary,
        reverse_field_map:    parsedReverse,
        push_unmatched_action: pushUnmatched,
        link_strategy:                 linkStrategy   || undefined,
        link_match_airtable_field:     linkAirField   || undefined,
        link_match_internal_column:    linkIntColumn  || undefined,
        link_unmatched_action:         linkUnmatched  || undefined,
      });
      setFlash({ kind: 'success', msg: isNew ? 'Mapping created.' : 'Mapping updated.' });
      reload();
      onClose();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid={isNew ? 'airtable-mapping-form-new' : `airtable-mapping-form-${mapping.id}`} style={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 10 }}>
      <label style={{ fontSize: 12, fontWeight: 600, gridColumn: '1 / 2' }}>
        Base
        {isNew ? (
          <select
            data-testid="airtable-base-select"
            value={baseId}
            onChange={(e) => onPickBase(e.target.value)}
            style={inputStyle}
          >
            <option value="">{loadingBases ? 'Loading bases…' : '— pick a base —'}</option>
            {bases.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        ) : (
          <input value={`${baseName || ''} (${baseId})`} readOnly style={{ ...inputStyle, background: '#f9fafb' }} />
        )}
      </label>
      <label style={{ fontSize: 12, fontWeight: 600, gridColumn: '2 / 3' }}>
        Table
        {isNew ? (
          <select
            data-testid="airtable-table-select"
            value={tableId}
            onChange={(e) => onPickTable(e.target.value)}
            style={inputStyle}
            disabled={!baseId || loadingTables}
          >
            <option value="">{loadingTables ? 'Loading tables…' : '— pick a table —'}</option>
            {tables.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        ) : (
          <input value={`${tableName || ''} (${tableId})`} readOnly style={{ ...inputStyle, background: '#f9fafb' }} />
        )}
      </label>
      <label style={{ fontSize: 12, fontWeight: 600 }}>
        CoreFlux entity
        <select
          data-testid="airtable-entity-select"
          value={entity}
          onChange={(e) => setEntity(e.target.value)}
          style={inputStyle}
        >
          {entities.map((en) => <option key={en} value={en}>{en}</option>)}
        </select>
      </label>
      <label style={{ fontSize: 12, fontWeight: 600 }}>
        Direction
        <select
          data-testid="airtable-direction-select"
          value={dir}
          onChange={(e) => setDir(e.target.value)}
          style={inputStyle}
        >
          {directions.map((d) => <option key={d} value={d}>{d}</option>)}
        </select>
      </label>
      <label style={{ fontSize: 12, fontWeight: 600, gridColumn: '1 / 3' }}>
        Primary field (Airtable field name used for match — usually the table's primary field)
        <input
          data-testid="airtable-primary-input"
          value={primary}
          onChange={(e) => setPrimary(e.target.value)}
          placeholder="e.g. Name"
          style={inputStyle}
        />
      </label>
      <label style={{ fontSize: 12, fontWeight: 600, gridColumn: '1 / 3' }}>
        Field map (JSON object: <code>{`{ "Airtable field": "coreflux_key" }`}</code>)
        {selectedTable && (
          <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginLeft: 8 }}>
            Available fields: {selectedTable.fields.map((f) => f.name).join(', ')}
          </span>
        )}
        <textarea
          data-testid="airtable-fieldmap-input"
          value={fieldMap}
          onChange={(e) => setFieldMap(e.target.value)}
          rows={6}
          spellCheck={false}
          style={{ ...inputStyle, fontFamily: 'var(--cf-mono, ui-monospace)', resize: 'vertical' }}
        />
      </label>

      {/* Slice 5 — Push direction configuration. Only shown when
          the mapping pushes CoreFlux rows back into Airtable. */}
      {(dir === 'push' || dir === 'both') && (
        <fieldset data-testid="airtable-push-section"
                  style={{
                    gridColumn: '1 / 3',
                    border: '1px solid #0ea5e9',
                    borderRadius: 6, padding: '10px 14px 12px',
                    margin: '4px 0 0', background: '#f0f9ff',
                  }}>
          <legend style={{ fontSize: 11, fontWeight: 700, color: '#0369a1',
                           textTransform: 'uppercase', letterSpacing: 0.4,
                           padding: '0 6px' }}>
            Push (CoreFlux → Airtable)
          </legend>
          <p style={{ margin: '0 0 10px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Map CoreFlux column → Airtable field for the push leg. Independent
            of the pull-side field map so push can write a subset of columns
            back (e.g. push only status, pull everything).
          </p>
          <label style={{ fontSize: 12, fontWeight: 600, display: 'block' }}>
            Reverse field map (JSON object: <code>{`{ "coreflux_col": "Airtable Field" }`}</code>)
            <textarea
              data-testid="airtable-reverse-fieldmap-input"
              value={reverseFieldMap}
              onChange={(e) => setReverseFieldMap(e.target.value)}
              rows={5}
              spellCheck={false}
              placeholder={`{\n  "status": "Status",\n  "start_date": "Start Date"\n}`}
              style={{ ...inputStyle, fontFamily: 'var(--cf-mono, ui-monospace)', resize: 'vertical' }}
            />
          </label>
          <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginTop: 10 }}>
            When a CoreFlux row has no linked Airtable record
            <select data-testid="airtable-push-unmatched"
                    value={pushUnmatched}
                    onChange={(e) => setPushUnmatched(e.target.value)}
                    style={inputStyle}>
              <option value="create_new">Create a new Airtable record (default)</option>
              <option value="update_only">Skip — only update existing linked records</option>
              <option value="error">Error — mark the row as failed</option>
            </select>
          </label>
        </fieldset>
      )}

      {/* Slice 2 — Linkage policy. Connects this Airtable table to a
          real CoreFlux entity row instead of the synthetic Slice-1 id. */}
      <fieldset data-testid="airtable-linkage-section"
                style={{
                  gridColumn: '1 / 3',
                  border: '1px solid var(--cf-border)',
                  borderRadius: 6, padding: '10px 14px 12px',
                  margin: '4px 0 0',
                }}>
        <legend style={{ fontSize: 11, fontWeight: 700, color: '#475569',
                         textTransform: 'uppercase', letterSpacing: 0.4,
                         padding: '0 6px' }}>
          Entity linkage
        </legend>
        <p style={{ margin: '0 0 10px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          How should each Airtable row be linked to a real CoreFlux <code>{entity}</code> row?
          Leave on default to auto-pick by entity type (placement → external_id,
          vendor/company/customer → name, contact → email).
        </p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 10 }}>
          <label style={{ fontSize: 12, fontWeight: 600 }}>
            Strategy
            <select data-testid="airtable-link-strategy"
                    value={linkStrategy}
                    onChange={(e) => setLinkStrategy(e.target.value)}
                    style={inputStyle}>
              <option value="">(default for {entity})</option>
              <option value="external_id">External ID — match on target.external_id</option>
              <option value="match_column">Match column — pick fields below</option>
              <option value="manual">Manual — never auto-link</option>
              <option value="none">None — Slice-1 synthetic ID (no real linkage)</option>
            </select>
          </label>
          <label style={{ fontSize: 12, fontWeight: 600 }}>
            Unmatched action
            <select data-testid="airtable-link-unmatched"
                    value={linkUnmatched}
                    onChange={(e) => setLinkUnmatched(e.target.value)}
                    style={inputStyle}>
              <option value="park">Park — keep in queue for manual review</option>
              <option value="skip">Skip — drop the record from this sync</option>
              <option value="create_stub">Create stub (Slice-3 future)</option>
            </select>
          </label>
          {linkStrategy === 'match_column' && (
            <>
              <label style={{ fontSize: 12, fontWeight: 600 }}>
                Airtable field (lookup value)
                <input
                  data-testid="airtable-link-airfield"
                  value={linkAirField}
                  onChange={(e) => setLinkAirField(e.target.value)}
                  placeholder="e.g. Vendor ID, Email"
                  style={inputStyle}
                />
              </label>
              <label style={{ fontSize: 12, fontWeight: 600 }}>
                CoreFlux column (target)
                <input
                  data-testid="airtable-link-intcolumn"
                  value={linkIntColumn}
                  onChange={(e) => setLinkIntColumn(e.target.value)}
                  placeholder="e.g. vendor_name, name, email_primary"
                  style={inputStyle}
                />
              </label>
            </>
          )}
        </div>
      </fieldset>
      <div style={{ gridColumn: '1 / 3', display: 'flex', gap: 8 }}>
        <button
          type="button" className="btn btn--primary"
          data-testid="airtable-mapping-save-btn"
          onClick={submit} disabled={busy || !baseId || !tableId}
        >
          <Save size={13} style={{ marginRight: 4 }} />{busy ? 'Saving…' : 'Save mapping'}
        </button>
        <button
          type="button" className="btn"
          data-testid="airtable-mapping-cancel-btn"
          onClick={onClose} disabled={busy}
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

const inputStyle = {
  width: '100%', padding: '6px 10px', borderRadius: 4,
  border: '1px solid var(--cf-border)', fontSize: 13, marginTop: 4,
};

function Badge({ tone, label, testid }) {
  const palette = tone === 'warn'
    ? { bg: '#fef3c7', fg: '#92400e' }
    : tone === 'err'
      ? { bg: '#fee2e2', fg: '#991b1b' }
      : { bg: '#dcfce7', fg: '#166534' };
  return (
    <span data-testid={testid}
          style={{ background: palette.bg, color: palette.fg,
                   padding: '1px 8px', borderRadius: 10,
                   fontSize: 10, fontWeight: 700,
                   textTransform: 'uppercase', letterSpacing: 0.3,
                   fontVariantNumeric: 'tabular-nums' }}>
      {label}
    </span>
  );
}

/* ─────────────────────────────────────────────────────────── duplicate */

function DuplicateModal({ mapping, busy, setBusy, setFlash, reload, onClose }) {
  const [targets, setTargets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState(new Set());
  const [result, setResult] = useState(null);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    api.get('/api/airtable/duplicate_targets.php?action=duplicate_targets')
      .then((r) => { if (mounted) { setTargets(r.targets || []); setLoading(false); } })
      .catch((e) => {
        if (mounted) {
          setFlash({ kind: 'error', msg: 'Failed to load tenants: ' + (e.message || e) });
          setLoading(false);
        }
      });
    return () => { mounted = false; };
  }, [setFlash]);

  const toggle = (id) => {
    const n = new Set(selected);
    if (n.has(id)) n.delete(id); else n.add(id);
    setSelected(n);
  };
  const toggleAll = (filterFn) => {
    const eligible = targets.filter(filterFn).map((t) => t.id);
    const allOn = eligible.every((id) => selected.has(id));
    const n = new Set(selected);
    if (allOn) eligible.forEach((id) => n.delete(id));
    else       eligible.forEach((id) => n.add(id));
    setSelected(n);
  };

  const submit = async () => {
    const ids = [...selected];
    if (ids.length === 0) return;
    setBusy(true);
    try {
      const r = await api.post('/api/airtable/mapping_duplicate.php?action=mapping_duplicate', {
        source_mapping_id: mapping.id,
        target_tenant_ids: ids,
      });
      setResult(r);
      const total = (r.created?.length || 0) + (r.updated?.length || 0);
      setFlash({
        kind: (r.errors?.length || 0) === 0 ? 'success' : 'error',
        msg: `Duplicate complete: ${r.created?.length || 0} created · ${r.updated?.length || 0} updated · ${r.skipped?.length || 0} skipped · ${r.errors?.length || 0} errors (${total} mappings synced).`,
      });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const connectedTargets   = targets.filter((t) => t.connected);
  const unconnectedTargets = targets.filter((t) => !t.connected);

  return (
    <div
      data-testid="airtable-duplicate-modal"
      role="dialog" aria-modal="true"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.5)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
      }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        style={{
          background: 'var(--cf-surface, #fff)', borderRadius: 8,
          width: 'min(640px, 92vw)', maxHeight: '88vh', overflow: 'auto',
          boxShadow: '0 20px 50px rgba(0,0,0,0.25)', padding: 20,
        }}
      >
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
          <div>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 600 }}>Duplicate mapping</h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              Copy <strong>{mapping.base_name || mapping.base_id} / {mapping.table_name || mapping.table_id}</strong>
              {' → '}<code>{mapping.internal_entity}</code> to other tenants you manage. Each target must already
              have an Airtable PAT connected; targets without one are listed but disabled.
            </p>
          </div>
          <button
            type="button" className="btn"
            data-testid="airtable-duplicate-close-btn"
            onClick={onClose} disabled={busy}
          >
            <X size={14} />
          </button>
        </header>

        {loading && <p data-testid="airtable-duplicate-loading">Loading tenants…</p>}

        {!loading && targets.length === 0 && (
          <p data-testid="airtable-duplicate-empty" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            You don't have admin access to any other tenants. Duplicate isn't available here.
          </p>
        )}

        {!loading && targets.length > 0 && (
          <>
            {connectedTargets.length > 0 && (
              <section data-testid="airtable-duplicate-connected-section" style={{ marginBottom: 16 }}>
                <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                  <strong style={{ fontSize: 13 }}>Connected tenants ({connectedTargets.length})</strong>
                  <button
                    type="button" className="btn"
                    data-testid="airtable-duplicate-select-all-connected"
                    onClick={() => toggleAll((t) => t.connected)}
                    disabled={busy}
                    style={{ fontSize: 11, padding: '2px 8px' }}
                  >
                    Select all
                  </button>
                </header>
                <ul style={{ listStyle: 'none', padding: 0, margin: 0, border: '1px solid var(--cf-border-muted, #f1f5f9)', borderRadius: 6 }}>
                  {connectedTargets.map((t) => (
                    <TargetRow key={t.id} target={t} selected={selected.has(t.id)} onToggle={() => toggle(t.id)} disabled={busy} />
                  ))}
                </ul>
              </section>
            )}

            {unconnectedTargets.length > 0 && (
              <section data-testid="airtable-duplicate-unconnected-section" style={{ marginBottom: 16 }}>
                <header style={{ marginBottom: 6 }}>
                  <strong style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
                    Without Airtable connection ({unconnectedTargets.length})
                  </strong>
                  <p style={{ margin: '2px 0 0', fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                    Connect Airtable in each tenant's Integrations page before duplicating.
                  </p>
                </header>
                <ul style={{ listStyle: 'none', padding: 0, margin: 0, border: '1px dashed var(--cf-border, #e5e7eb)', borderRadius: 6, opacity: 0.6 }}>
                  {unconnectedTargets.map((t) => (
                    <TargetRow key={t.id} target={t} selected={false} onToggle={() => {}} disabled />
                  ))}
                </ul>
              </section>
            )}

            {result && (
              <div data-testid="airtable-duplicate-result" style={{ marginTop: 12, padding: 10, borderRadius: 6, background: 'var(--cf-blue-bg, #eff6ff)', fontSize: 12 }}>
                <div><strong>Created:</strong> {result.created?.map((c) => c.tenant_id).join(', ') || 'none'}</div>
                <div><strong>Updated:</strong> {result.updated?.map((c) => c.tenant_id).join(', ') || 'none'}</div>
                {result.skipped?.length > 0 && (
                  <div><strong>Skipped:</strong> {result.skipped.map((s) => `${s.tenant_id} (${s.reason})`).join(', ')}</div>
                )}
                {result.errors?.length > 0 && (
                  <div style={{ color: 'var(--cf-red, #b91c1c)' }}>
                    <strong>Errors:</strong> {result.errors.map((e) => `${e.tenant_id}: ${e.error}`).join('; ')}
                  </div>
                )}
              </div>
            )}

            <footer style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
              <button type="button" className="btn" onClick={onClose} disabled={busy} data-testid="airtable-duplicate-cancel-btn">
                Close
              </button>
              <button
                type="button" className="btn btn--primary"
                data-testid="airtable-duplicate-apply-btn"
                onClick={submit}
                disabled={busy || selected.size === 0}
              >
                <Copy size={13} style={{ marginRight: 4 }} />
                {busy ? 'Applying…' : `Duplicate to ${selected.size} tenant${selected.size === 1 ? '' : 's'}`}
              </button>
            </footer>
          </>
        )}
      </div>
    </div>
  );
}

function TargetRow({ target, selected, onToggle, disabled }) {
  return (
    <li
      data-testid={`airtable-duplicate-target-${target.id}`}
      style={{
        padding: '8px 12px',
        borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
        display: 'flex', alignItems: 'center', gap: 10, cursor: disabled ? 'not-allowed' : 'pointer',
      }}
      onClick={() => { if (!disabled) onToggle(); }}
    >
      <input
        type="checkbox"
        data-testid={`airtable-duplicate-checkbox-${target.id}`}
        checked={selected}
        onChange={() => {}}
        disabled={disabled}
        style={{ pointerEvents: 'none' }}
      />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 500, fontSize: 13 }}>
          {target.name}
          {target.tenant_type === 'sub' && (
            <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginLeft: 6 }}>
              (sub-tenant of #{target.parent_id})
            </span>
          )}
        </div>
        <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          tenant #{target.id} ·{' '}
          {target.connected
            ? <>PAT ••••{target.pat_last4} · <span style={{ color: 'var(--cf-green, #047857)' }}>connected</span></>
            : <span style={{ color: 'var(--cf-amber, #92400e)' }}>no connection</span>}
        </div>
      </div>
    </li>
  );
}

/* ─────────────────────────────────────────────────────────── activity */

function ActivityFeed({ audit }) {
  if (!audit?.length) return null;
  return (
    <div data-testid="airtable-activity" className="card" style={cardStyle}>
      <h4 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 600 }}>Recent activity</h4>
      <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
            <th style={{ padding: '4px' }}>When</th>
            <th style={{ padding: '4px' }}>Action</th>
            <th style={{ padding: '4px' }}>Base/Table</th>
            <th style={{ padding: '4px', textAlign: 'right' }}>Items</th>
            <th style={{ padding: '4px' }}>Result</th>
          </tr>
        </thead>
        <tbody>
          {audit.map((r) => (
            <tr key={r.id} data-testid={`airtable-activity-row-${r.id}`} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
              <td style={{ padding: '4px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{r.occurred_at}</td>
              <td style={{ padding: '4px' }}>{r.action}</td>
              <td style={{ padding: '4px', fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 11 }}>{r.base_id || ''}{r.table_id ? `/${r.table_id}` : ''}</td>
              <td style={{ padding: '4px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.items_processed}</td>
              <td style={{ padding: '4px', color: r.ok ? 'var(--cf-green, #047857)' : 'var(--cf-red, #b91c1c)' }}>{r.ok ? 'ok' : 'fail'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
