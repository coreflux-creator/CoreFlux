import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useApi, api } from '../lib/api';
import {
  CheckCircle2, ExternalLink, RefreshCw, XCircle,
  ArrowRight, ArrowLeft, ArrowLeftRight, MinusCircle, Send,
} from 'lucide-react';

/**
 * ZohoBooksSettings — Zoho Books OAuth connection + per-entity sync
 * direction picker + per-entity account mapping. Mounted at
 * /admin/integrations/zoho-books.
 *
 * Per migration 099 every (master tenant, sub-tenant) pair gets its own
 * Zoho Books connection. The legal-entity picker at the top scopes
 * every read + write on this page. "Copy sync config from another
 * entity" lets an admin clone a fully-tuned entity's settings into a
 * newly-connected one in a single click.
 */
export default function ZohoBooksSettings() {
  // 1) Sub-tenant list (parent + active sub-tenants). The endpoint
  //    returns the parent as a first-class entity so the picker covers
  //    every legal entity an operator might want to connect.
  const subTenants = useApi('/api/sub_tenants.php');
  const subs = useMemo(() => {
    const r = subTenants.data;
    if (!r) return [];
    const list = Array.isArray(r) ? r : (r.rows || r.sub_tenants || r.tenants || []);
    const parent = r && !Array.isArray(r) ? (r.parent || null) : null;
    if (parent && !list.find(s => Number(s.id) === Number(parent.id))) {
      return [parent, ...list];
    }
    return list;
  }, [subTenants.data]);

  // Default entity: from ?entity=N query param (post-OAuth redirect) or
  // the parent. Falls back to the first sub-tenant when no parent row.
  const [subTenantId, setSubTenantId] = useState(() => {
    const fromQs = new URLSearchParams(window.location.search).get('entity');
    return fromQs ? Number(fromQs) : null;
  });
  useEffect(() => {
    if (subTenantId == null && subs.length) {
      setSubTenantId(Number(subs[0].id));
    }
  }, [subs, subTenantId]);

  const statusUrl = subTenantId
    ? `/api/zoho_books/status.php?action=status&sub_tenant_id=${subTenantId}`
    : null;
  const status = useApi(statusUrl);
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState(parseFlashFromUrl());
  const [draft, setDraft] = useState(null);
  const [copyFrom, setCopyFrom] = useState('');

  const data       = status.data || {};
  const configured = !!data.configured;
  const connected  = !!data.connected;
  const config     = draft ?? data.sync_config ?? {};
  const dirty      = draft !== null && JSON.stringify(draft) !== JSON.stringify(data.sync_config || {});

  // Other entities in this tenant that ALREADY have a Zoho connection
  // (used by the "Copy sync config from" picker).
  const otherConnections = useMemo(() => {
    const list = Array.isArray(data.all_connections) ? data.all_connections : [];
    return list.filter(c => Number(c.sub_tenant_id) !== Number(subTenantId) && c.status === 'active');
  }, [data.all_connections, subTenantId]);

  const handleConnect = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.get(`/api/zoho_books/oauth_start.php?action=oauth_start&sub_tenant_id=${subTenantId}`);
      window.location.href = r.authorize_url;
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
      setBusy(false);
    }
  };

  const handleDisconnect = async () => {
    if (!window.confirm('Disconnect Zoho Books for THIS entity? Cached tokens will be revoked. No data is deleted from Zoho.')) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/zoho_books/disconnect.php?action=disconnect', { sub_tenant_id: subTenantId });
      setFlash({ kind: 'success', msg: 'Zoho Books disconnected for this entity.' });
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
      const r = await api.post('/api/zoho_books/ping.php?action=ping', { sub_tenant_id: subTenantId });
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
      await api.post('/api/zoho_books/sync_config_set.php?action=sync_config_set',
                     { sub_tenant_id: subTenantId, sync_config: config });
      setFlash({ kind: 'success', msg: 'Sync settings saved.' });
      setDraft(null);
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleCopyConfig = async () => {
    const from = Number(copyFrom);
    if (!from || from === Number(subTenantId)) {
      setFlash({ kind: 'error', msg: 'Pick a different source entity first.' });
      return;
    }
    const sourceName = otherConnections.find(c => Number(c.sub_tenant_id) === from)?.organization_name || `entity #${from}`;
    if (!window.confirm(`Replace this entity's sync settings + account mappings with those from "${sourceName}"? Existing settings on this entity will be overwritten.`)) {
      return;
    }
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/zoho_books.php?action=sync_config_copy', {
        from_sub_tenant_id: from,
        to_sub_tenant_id:   subTenantId,
        overwrite_existing: true,
      });
      setFlash({
        kind: 'success',
        msg: `Copied: sync config replaced · ${r.mappings_copied} account mappings imported · ${r.mappings_skipped} skipped.`,
      });
      setCopyFrom('');
      setDraft(null);
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally { setBusy(false); }
  };

  if (status.loading || subTenants.loading) return <div data-testid="zoho-books-settings-loading">Loading…</div>;

  return (
    <section data-testid="zoho-books-settings" style={{ maxWidth: 880 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Zoho Books — Connection</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          One connection per legal entity. Pick the entity to configure
          below. Each entity's sync direction matrix + account map is
          independent; <strong>Copy sync config</strong> below lets you
          clone the rules from any already-configured entity in one click.
          {data.dc && (
            <> Region: <code data-testid="zoho-books-dc">{data.dc}</code>.</>
          )}
        </p>
      </header>

      {/* Step 0 — Legal entity picker. Every API call on this page scopes
          by the value selected here. */}
      <div data-testid="zoho-books-entity-picker"
           style={{ marginBottom: 16, padding: 12, background: '#f8fafc',
                    border: '1px solid #e2e8f0', borderRadius: 8 }}>
        <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 6 }}>
          Step 1 — Legal entity
        </label>
        <select
          value={subTenantId || ''}
          onChange={(e) => { setSubTenantId(Number(e.target.value)); setDraft(null); }}
          data-testid="zoho-books-entity-select"
          className="input"
          style={{ width: '100%', maxWidth: 380, fontSize: 13 }}
        >
          {subs.length === 0 && <option value="">— no entities visible —</option>}
          {subs.map(s => (
            <option key={s.id} value={s.id}>
              {s.name || s.tenant_name || `Entity #${s.id}`}
              {s.is_parent ? ' (master)' : ''}
            </option>
          ))}
        </select>
      </div>

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

          {/* Step 3 — Copy sync config from another entity. Hidden when
              this is the only configured entity. */}
          {otherConnections.length > 0 && (
            <div data-testid="zoho-books-copy-config-card"
                 className="card"
                 style={{ padding: 12, marginBottom: 16, background: '#f0f9ff',
                          border: '1px solid #bae6fd', borderRadius: 8 }}>
              <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 4, color: '#075985' }}>
                Copy sync config from another entity
              </div>
              <p style={{ fontSize: 12, color: '#0c4a6e', margin: '0 0 8px' }}>
                Replace this entity's sync direction matrix + account
                mappings with those of another entity. Useful when you
                want every sub-entity to behave identically.
              </p>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <select
                  value={copyFrom}
                  onChange={(e) => setCopyFrom(e.target.value)}
                  className="input"
                  style={{ fontSize: 13, padding: '4px 8px', minWidth: 240 }}
                  data-testid="zoho-books-copy-from-select"
                >
                  <option value="">— pick source entity —</option>
                  {otherConnections.map(c => (
                    <option key={c.sub_tenant_id} value={c.sub_tenant_id}>
                      {c.organization_name || `Entity #${c.sub_tenant_id}`}
                    </option>
                  ))}
                </select>
                <button type="button" className="btn"
                        onClick={handleCopyConfig}
                        disabled={busy || !copyFrom}
                        data-testid="zoho-books-copy-config-btn">
                  Copy settings
                </button>
              </div>
            </div>
          )}

          <ZohoAccountMappingCard
            subTenantId={subTenantId}
            onFlash={setFlash}
          />

          <ManualSyncCard
            currentConfig={data.sync_config || {}}
            subTenantId={subTenantId}
            busy={busy} setBusy={setBusy}
            setFlash={setFlash} reload={status.reload}
          />

          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 12 }} data-testid="zoho-books-slice1-note">
            <strong>Slice 2 — Journal Entries</strong> is live (push only). Subsequent slices ship
            chart-of-accounts pull, contacts pull, and invoices/bills/payments push.
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

function ManualSyncCard({ currentConfig, subTenantId, busy, setBusy, setFlash, reload }) {
  const jeDir  = currentConfig.journal_entries || 'off';
  const invDir = currentConfig.invoices        || 'off';
  const billDir= currentConfig.bills           || 'off';
  const payDir = currentConfig.payments        || 'off';
  const jeEligible   = jeDir   === 'push' || jeDir   === 'two_way';
  const invEligible  = invDir  === 'push' || invDir  === 'two_way';
  const billEligible = billDir === 'push' || billDir === 'two_way';
  const payEligible  = payDir  === 'push' || payDir  === 'two_way';

  const handleJe = async ({ dryRun }) => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/zoho_books/sync_je.php?action=sync_je', { limit: 50, dry_run: !!dryRun, sub_tenant_id: subTenantId });
      const parts = [
        `${r.pushed} ${dryRun ? 'would-push' : 'pushed'}`,
        `${r.skipped_unmapped} skipped (unmapped accounts)`,
        `${r.failed} failed`,
        `${r.considered} considered`,
        `${r.latency_ms}ms`,
      ];
      setFlash({
        kind: (r.failed || 0) === 0 ? 'success' : 'error',
        msg: `Zoho JE ${dryRun ? 'dry-run' : 'sync'}: ${parts.join(' · ')}`,
      });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handlePush = async (label, action, { dryRun }) => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post(`/api/zoho_books/${action}.php?action=${action}`, { limit: 50, dry_run: !!dryRun, sub_tenant_id: subTenantId });
      const parts = [
        `${r.pushed} ${dryRun ? 'would-push' : 'pushed'}`,
        `${r.skipped} skipped`,
        `${r.failed} failed`,
        `${r.considered} considered`,
        `${r.latency_ms}ms`,
      ];
      setFlash({
        kind: (r.failed || 0) === 0 ? 'success' : 'error',
        msg: `Zoho ${label} ${dryRun ? 'dry-run' : 'sync'}: ${parts.join(' · ')}`,
      });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const rows = [
    {
      label: 'Journal Entries', dir: jeDir, eligible: jeEligible,
      dryRunTid: 'zoho-books-sync-je-dryrun-btn', tid: 'zoho-books-sync-je-btn',
      onDry: () => handleJe({ dryRun: true }), onGo: () => handleJe({ dryRun: false }),
    },
    {
      label: 'Invoices', dir: invDir, eligible: invEligible,
      dryRunTid: 'zoho-books-sync-invoices-dryrun-btn', tid: 'zoho-books-sync-invoices-btn',
      onDry: () => handlePush('Invoices', 'sync_invoices', { dryRun: true }),
      onGo:  () => handlePush('Invoices', 'sync_invoices', { dryRun: false }),
    },
    {
      label: 'Bills', dir: billDir, eligible: billEligible,
      dryRunTid: 'zoho-books-sync-bills-dryrun-btn', tid: 'zoho-books-sync-bills-btn',
      onDry: () => handlePush('Bills', 'sync_bills', { dryRun: true }),
      onGo:  () => handlePush('Bills', 'sync_bills', { dryRun: false }),
    },
    {
      label: 'Payments', dir: payDir, eligible: payEligible,
      dryRunTid: 'zoho-books-sync-payments-dryrun-btn', tid: 'zoho-books-sync-payments-btn',
      onDry: () => handlePush('Payments', 'sync_payments', { dryRun: true }),
      onGo:  () => handlePush('Payments', 'sync_payments', { dryRun: false }),
    },
  ];

  return (
    <div
      data-testid="zoho-books-manual-sync"
      className="card"
      style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, marginTop: 16 }}
    >
      <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>Manual sync</h4>
      <p style={{ margin: '4px 0 12px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
        Run an entity's push worker on demand. Cron runs every 15 minutes; use these buttons to
        verify a connection or unblock a stuck queue. <strong>Dry run</strong> builds the Zoho payload
        without POSTing — useful for diagnosing unmapped accounts, vendors, or customers.
      </p>
      <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
        <tbody>
          {rows.map((row) => (
            <tr key={row.label} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
              <td style={{ padding: '8px 4px', fontWeight: 500 }}>{row.label}</td>
              <td style={{ padding: '8px 4px', color: 'var(--cf-text-secondary)', fontSize: 12 }}>
                Direction: <code>{row.dir}</code>
                {!row.eligible && <span> — set to push or two_way to enable</span>}
              </td>
              <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                <button
                  type="button"
                  className="btn"
                  data-testid={row.dryRunTid}
                  onClick={row.onDry}
                  disabled={!row.eligible || busy}
                  style={{ marginRight: 6 }}
                >
                  Dry run
                </button>
                <button
                  type="button"
                  className="btn btn--primary"
                  data-testid={row.tid}
                  onClick={row.onGo}
                  disabled={!row.eligible || busy}
                >
                  <Send size={12} style={{ marginRight: 4 }} />
                  {busy ? 'Syncing…' : 'Sync now'}
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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


/* ---------------------------------------------------------------
   Account mapping card — reuses the provider-neutral
   accounting_account_mappings table via /api/accounting.php
   with provider=zoho_books. Mirrors the Jaz UI verbatim so
   operators have one mental model across destinations.
--------------------------------------------------------------- */
function ZohoAccountMappingCard({ subTenantId, onFlash }) {
  const [mappings, setMappings] = useState([]);
  const [unmapped, setUnmapped] = useState([]);
  const [loading, setLoading]   = useState(false);
  const [error,   setError]     = useState(null);
  const [busy,    setBusy]      = useState(false);
  const [addRow,  setAddRow]    = useState(null);

  const reload = useCallback(async () => {
    if (!subTenantId) return;
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/accounting.php?action=account_mappings&sub_tenant_id=${subTenantId}&provider=zoho_books`);
      setMappings(r.mappings || []);
      setUnmapped(r.unmapped || []);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally     { setLoading(false); }
  }, [subTenantId]);
  useEffect(() => { reload(); }, [reload]);

  const handleAutoMap = async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/accounting.php?action=account_mapping_auto&provider=zoho_books', {
        sub_tenant_id: subTenantId,
      });
      const created = r.mapped ?? r.new_mappings?.length ?? 0;
      const noMatch = r.no_provider_match ?? 0;
      onFlash?.({
        kind: created > 0 ? 'success' : 'error',
        msg:  `Auto-map: ${created} mapped · ${noMatch} CoreFlux accounts had no match in Zoho Books.`,
      });
      reload();
    } catch (e) { setError(e.message || 'Auto-map failed'); }
    finally     { setBusy(false); }
  };

  const handleDelete = async (mappingId) => {
    if (!window.confirm('Remove this mapping?')) return;
    setBusy(true);
    try {
      await api.post('/api/accounting.php?action=account_mapping_delete&provider=zoho_books', {
        sub_tenant_id: subTenantId, mapping_id: mappingId,
      });
      reload();
    } catch (e) { setError(e.message || 'Delete failed'); }
    finally     { setBusy(false); }
  };

  const handleSaveAdd = async (e) => {
    e?.preventDefault?.();
    if (!addRow || !addRow.coreflux_account_id || !addRow.provider_account_id) return;
    setBusy(true); setError(null);
    try {
      await api.post('/api/accounting.php?action=account_mapping_save&provider=zoho_books', {
        sub_tenant_id:         subTenantId,
        coreflux_account_id:   addRow.coreflux_account_id,
        provider_account_id:   addRow.provider_account_id,
        provider_account_code: addRow.provider_account_code || null,
        provider_account_name: addRow.provider_account_name || null,
        source:                'manual',
      });
      setAddRow(null);
      reload();
    } catch (e) { setError(e.message || 'Save failed'); }
    finally     { setBusy(false); }
  };

  return (
    <div className="card"
         data-testid="zoho-books-account-mapping-card"
         style={{ padding: 16, marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
      <h4 style={{ margin: '0 0 8px', fontSize: 14, fontWeight: 600 }}>Step 4 — Account mapping</h4>
      <p style={{ margin: '0 0 12px', fontSize: 12, color: '#64748b' }}>
        Map each CoreFlux account to a Zoho Books account. The outbox
        consults this map when push/two-way is enabled. "Auto-map by
        code" fills in exact-code matches Zoho exposes; the rest you can
        add manually below.
      </p>
      <div style={{ marginBottom: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
        <button type="button" className="btn" onClick={handleAutoMap}
                disabled={busy || loading}
                data-testid="zoho-books-account-mapping-automap">
          {busy ? 'Auto-mapping…' : 'Auto-map by code'}
        </button>
        <button type="button" className="btn"
                onClick={() => setAddRow({ coreflux_account_id: '', provider_account_id: '' })}
                disabled={busy || unmapped.length === 0}
                data-testid="zoho-books-account-mapping-add">
          + Add mapping
        </button>
        <span style={{ fontSize: 12, color: '#64748b' }}>
          {mappings.length} mapped · {unmapped.length} unmapped
        </span>
      </div>
      {error && <p className="error" style={{ fontSize: 12 }} data-testid="zoho-books-account-mapping-error">{error}</p>}

      {addRow && (
        <form onSubmit={handleSaveAdd}
              data-testid="zoho-books-account-mapping-add-form"
              style={{ marginBottom: 12, padding: 10, background: '#f8fafc',
                       border: '1px solid #e2e8f0', borderRadius: 6,
                       display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'flex-end' }}>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 200px' }}>
            CoreFlux account
            <select className="input" value={addRow.coreflux_account_id}
                    onChange={(e) => setAddRow({ ...addRow, coreflux_account_id: e.target.value })}
                    data-testid="zoho-books-mapping-add-cf-select"
                    style={{ display: 'block', width: '100%', marginTop: 4 }}
                    required>
              <option value="">— pick unmapped account —</option>
              {unmapped.map(a => (
                <option key={a.id} value={a.id}>{a.code} · {a.name}</option>
              ))}
            </select>
          </label>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 180px' }}>
            Zoho account id
            <input className="input" value={addRow.provider_account_id}
                   onChange={(e) => setAddRow({ ...addRow, provider_account_id: e.target.value })}
                   placeholder="9999000000000123"
                   data-testid="zoho-books-mapping-add-provider-id"
                   style={{ display: 'block', width: '100%', marginTop: 4 }} required />
          </label>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 110px' }}>
            Zoho code
            <input className="input" value={addRow.provider_account_code || ''}
                   onChange={(e) => setAddRow({ ...addRow, provider_account_code: e.target.value })}
                   placeholder="1100"
                   data-testid="zoho-books-mapping-add-provider-code"
                   style={{ display: 'block', width: '100%', marginTop: 4 }} />
          </label>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 200px' }}>
            Zoho name
            <input className="input" value={addRow.provider_account_name || ''}
                   onChange={(e) => setAddRow({ ...addRow, provider_account_name: e.target.value })}
                   placeholder="Accounts Receivable"
                   data-testid="zoho-books-mapping-add-provider-name"
                   style={{ display: 'block', width: '100%', marginTop: 4 }} />
          </label>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="submit" className="btn btn--primary" disabled={busy}
                    data-testid="zoho-books-mapping-add-save">Save</button>
            <button type="button" className="btn" onClick={() => setAddRow(null)}
                    data-testid="zoho-books-mapping-add-cancel">Cancel</button>
          </div>
        </form>
      )}

      {loading ? <p style={{ fontSize: 12 }}>Loading…</p> : (
        <table data-testid="zoho-books-account-mapping-table"
               style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' }}>
              <th style={{ padding: '6px 4px' }}>CoreFlux</th>
              <th style={{ padding: '6px 4px' }}>Zoho Books</th>
              <th style={{ padding: '6px 4px' }}>Source</th>
              <th style={{ padding: '6px 4px', width: 60 }}></th>
            </tr>
          </thead>
          <tbody>
            {mappings.length === 0 && (
              <tr><td colSpan={4} style={{ padding: '12px 4px', color: '#94a3b8', fontStyle: 'italic' }}
                      data-testid="zoho-books-account-mapping-empty">
                No mappings yet. Click "Auto-map by code" or "+ Add mapping" to start.
              </td></tr>
            )}
            {mappings.map(m => (
              <tr key={m.id} data-testid={`zoho-books-mapping-row-${m.id}`} style={{ borderBottom: '1px solid #f1f5f9' }}>
                <td style={{ padding: '6px 4px' }}>
                  <code style={{ fontSize: 12 }}>{m.coreflux_account_code}</code>
                  <span style={{ color: '#64748b' }}> · {m.coreflux_account_name || '—'}</span>
                </td>
                <td style={{ padding: '6px 4px' }}>
                  <code style={{ fontSize: 12 }}>{m.provider_account_code || m.provider_account_id}</code>
                  <span style={{ color: '#64748b' }}> · {m.provider_account_name || '—'}</span>
                </td>
                <td style={{ padding: '6px 4px' }}>
                  <span style={{
                    fontSize: 10, padding: '2px 6px', borderRadius: 8,
                    background: m.source === 'manual' ? '#dbeafe' : '#fef3c7',
                    color:      m.source === 'manual' ? '#1e40af' : '#92400e',
                  }}>{m.source}</span>
                </td>
                <td style={{ padding: '6px 4px', textAlign: 'right' }}>
                  <button type="button" className="btn btn--ghost"
                          onClick={() => handleDelete(m.id)}
                          disabled={busy}
                          data-testid={`zoho-books-mapping-delete-${m.id}`}
                          style={{ fontSize: 11, padding: '2px 6px' }}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
