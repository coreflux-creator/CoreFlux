import React, { useState } from 'react';
import { useApi, api } from '../lib/api';
import {
  CheckCircle2, ExternalLink, RefreshCw, XCircle,
  ArrowRight, ArrowLeft, ArrowLeftRight, MinusCircle,
} from 'lucide-react';

/**
 * ZohoBooksSettings — Zoho Books OAuth connection + per-entity sync
 * direction picker. Mounted at /admin/integrations/zoho-books.
 *
 * Slice 1 (Foundation) scope: connect / disconnect / ping / per-entity
 * direction selector. Actual push/pull workers ship in subsequent
 * slices, so the "Manual sync" card from QboSettings is intentionally
 * absent here.
 *
 * Render branches keyed off /api/zoho_books/status:
 *   - configured=false               → "Pod not configured" notice
 *   - configured=true, connected=false → "Connect to Zoho Books" CTA
 *   - configured=true, connected=true  → org info + Ping + Disconnect
 *                                        + per-entity sync direction table
 */
export default function ZohoBooksSettings() {
  const status = useApi('/api/zoho_books/status.php?action=status');
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState(parseFlashFromUrl());
  const [draft, setDraft] = useState(null);

  const data       = status.data || {};
  const configured = !!data.configured;
  const connected  = !!data.connected;
  const config     = draft ?? data.sync_config ?? {};
  const dirty      = draft !== null && JSON.stringify(draft) !== JSON.stringify(data.sync_config || {});

  const handleConnect = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.get('/api/zoho_books/oauth_start.php?action=oauth_start');
      window.location.href = r.authorize_url;
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
      setBusy(false);
    }
  };

  const handleDisconnect = async () => {
    if (!window.confirm('Disconnect Zoho Books? Cached tokens will be revoked. No data is deleted from Zoho.')) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/zoho_books/disconnect.php?action=disconnect', {});
      setFlash({ kind: 'success', msg: 'Zoho Books disconnected.' });
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handlePing = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/zoho_books/ping.php?action=ping', {});
      setFlash({
        kind: r.ok ? 'success' : 'error',
        msg: r.ok
          ? `Ping OK (${r.latency_ms}ms) — ${r.organization_name || 'Connected'}`
          : `Ping failed: ${r.error}`,
      });
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleSaveConfig = async () => {
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/zoho_books/sync_config_set.php?action=sync_config_set', { sync_config: config });
      setFlash({ kind: 'success', msg: 'Sync settings saved.' });
      setDraft(null);
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  if (status.loading) return <div data-testid="zoho-books-settings-loading">Loading…</div>;

  return (
    <section data-testid="zoho-books-settings" style={{ maxWidth: 880 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Zoho Books — Connection</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Connect your tenant's Zoho Books organization via OAuth 2.0. CoreFlux stores the
          access + refresh tokens AES-256-GCM encrypted and auto-refreshes them before expiry.
          {data.dc && (
            <> Region: <code data-testid="zoho-books-dc">{data.dc}</code>.</>
          )}
        </p>
      </header>

      {flash && (
        <div
          data-testid={`zoho-books-flash-${flash.kind}`}
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
        <div
          data-testid="zoho-books-not-configured"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, background: '#fafafa' }}
        >
          <strong>Zoho Books is not configured on this pod.</strong>
          <p style={{ fontSize: 13, margin: '8px 0 0', color: 'var(--cf-text-secondary)' }}>
            An administrator must set <code>ZOHO_BOOKS_CLIENT_ID</code>, <code>ZOHO_BOOKS_CLIENT_SECRET</code>,
            and <code>ZOHO_BOOKS_REDIRECT_URI</code> in <code>core/config.local.php</code> (or the host env)
            and register the redirect URI in the{' '}
            <a href="https://api-console.zoho.com/" target="_blank" rel="noopener noreferrer">
              Zoho API Console <ExternalLink size={12} style={{ verticalAlign: 'middle' }} />
            </a>.
          </p>
        </div>
      )}

      {configured && !connected && (
        <div
          data-testid="zoho-books-not-connected"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
        >
          <div style={{ marginBottom: 12 }}>
            <span className="badge" style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
              Not connected
            </span>
          </div>
          <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '0 0 16px' }}>
            Click below to authorise CoreFlux against your Zoho organization. You'll be redirected to
            Zoho Accounts, sign in, pick the organization, and consent to the requested scopes — then
            Zoho returns you here with the connection live. Region is auto-detected.
          </p>
          <button
            type="button"
            className="btn btn--primary"
            onClick={handleConnect}
            disabled={busy}
            data-testid="zoho-books-connect-btn"
          >
            {busy ? 'Redirecting…' : 'Connect to Zoho Books'}
          </button>
        </div>
      )}

      {configured && connected && (
        <>
          <div
            data-testid="zoho-books-connected"
            className="card"
            style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, marginBottom: 24 }}
          >
            <div style={{ marginBottom: 12 }}>
              <span className="badge badge--success" style={{ padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
                <CheckCircle2 size={11} style={{ verticalAlign: 'middle', marginRight: 4 }} />Connected
              </span>
            </div>
            <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, fontSize: 13 }}>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Organization</dt>
              <dd style={{ margin: 0 }} data-testid="zoho-books-org-name">{data.organization_name || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Organization ID</dt>
              <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="zoho-books-org-id">{data.organization_id || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Region (DC)</dt>
              <dd style={{ margin: 0 }}>{data.dc || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Access token expires</dt>
              <dd style={{ margin: 0 }}>{data.access_token_exp || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Last probe</dt>
              <dd style={{ margin: 0 }}>{data.last_probe_at || '—'}</dd>
            </dl>
            {data.last_probe_error && (
              <p style={{ color: 'var(--cf-red, #b91c1c)', fontSize: 12, marginTop: 8 }} data-testid="zoho-books-probe-error">
                Last error: {data.last_probe_error}
              </p>
            )}
            <div style={{ marginTop: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button type="button" className="btn" onClick={handlePing} disabled={busy} data-testid="zoho-books-ping-btn">
                <RefreshCw size={14} style={{ marginRight: 6 }} />{busy ? 'Pinging…' : 'Test connection'}
              </button>
              <button type="button" className="btn" onClick={handleDisconnect} disabled={busy} data-testid="zoho-books-disconnect-btn">
                <XCircle size={14} style={{ marginRight: 6 }} />Disconnect
              </button>
            </div>
          </div>

          <SyncConfigCard
            entities={data.entities || []}
            config={config}
            onChange={(entity, dir) => setDraft({ ...(draft ?? data.sync_config ?? {}), [entity]: dir })}
            onSave={handleSaveConfig}
            onReset={() => setDraft(null)}
            dirty={dirty}
            busy={busy}
          />

          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 12 }} data-testid="zoho-books-slice1-note">
            <strong>Slice 1 (Foundation)</strong> ships the connection vault, region auto-detect, and direction picker.
            Push / pull workers for each entity ship in subsequent slices — opt-in directions are stored now so
            they fire as soon as the worker for that entity lands.
          </p>
        </>
      )}
    </section>
  );
}

/* --------------------------------------------------------------- */

const DIR_META = {
  push:    { icon: ArrowRight,     label: 'Push',    blurb: 'CoreFlux → Zoho Books' },
  pull:    { icon: ArrowLeft,      label: 'Pull',    blurb: 'Zoho Books → CoreFlux' },
  two_way: { icon: ArrowLeftRight, label: 'Two-way', blurb: 'Bidirectional with last-write-wins' },
  off:     { icon: MinusCircle,    label: 'Off',     blurb: 'No sync' },
};

const ENTITY_LABELS = {
  journal_entries:   'Journal Entries',
  contacts:          'Contacts (Customers + Vendors)',
  invoices:          'Invoices',
  bills:             'Bills',
  payments:          'Payments',
  chart_of_accounts: 'Chart of Accounts',
};

function SyncConfigCard({ entities, config, onChange, onSave, onReset, dirty, busy }) {
  return (
    <div
      data-testid="zoho-books-sync-config"
      className="card"
      style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
    >
      <header style={{ marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>Data flow settings</h4>
        <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          For each entity, choose whether CoreFlux pushes to Zoho Books, pulls from Zoho Books,
          syncs both ways, or stays off. Defaults are <strong>off</strong> — opt each entity in
          deliberately.
        </p>
      </header>
      <table data-testid="zoho-books-sync-config-table" style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
            <th style={{ padding: '6px 4px' }}>Entity</th>
            <th style={{ padding: '6px 4px' }}>Direction</th>
            <th style={{ padding: '6px 4px' }}>Behaviour</th>
          </tr>
        </thead>
        <tbody>
          {entities.map((entity) => {
            const dir = config[entity] || 'off';
            const meta = DIR_META[dir] || DIR_META.off;
            const Icon = meta.icon;
            return (
              <tr key={entity} data-testid={`zoho-books-sync-row-${entity}`} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
                <td style={{ padding: '8px 4px', fontWeight: 500 }}>{ENTITY_LABELS[entity] || entity}</td>
                <td style={{ padding: '8px 4px' }}>
                  <select
                    value={dir}
                    onChange={(e) => onChange(entity, e.target.value)}
                    data-testid={`zoho-books-sync-dir-${entity}`}
                    style={{ padding: '4px 8px', borderRadius: 4, border: '1px solid var(--cf-border)', fontSize: 13 }}
                  >
                    {Object.keys(DIR_META).map((k) => (
                      <option key={k} value={k}>{DIR_META[k].label}</option>
                    ))}
                  </select>
                </td>
                <td style={{ padding: '8px 4px', color: 'var(--cf-text-secondary)' }}>
                  <Icon size={12} style={{ verticalAlign: 'middle', marginRight: 4 }} />
                  {meta.blurb}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
        <button
          type="button"
          className="btn btn--primary"
          onClick={onSave}
          disabled={!dirty || busy}
          data-testid="zoho-books-sync-config-save"
        >
          {busy ? 'Saving…' : 'Save settings'}
        </button>
        {dirty && (
          <button type="button" className="btn" onClick={onReset} disabled={busy} data-testid="zoho-books-sync-config-reset">
            Discard changes
          </button>
        )}
      </div>
    </div>
  );
}

function parseFlashFromUrl() {
  if (typeof window === 'undefined') return null;
  const p = new URLSearchParams(window.location.search);
  if (p.get('connected') === '1') {
    window.history.replaceState({}, '', window.location.pathname);
    return { kind: 'success', msg: 'Zoho Books connected.' };
  }
  if (p.get('error')) {
    const err = p.get('error');
    window.history.replaceState({}, '', window.location.pathname);
    return { kind: 'error', msg: 'Zoho reported: ' + err };
  }
  return null;
}
