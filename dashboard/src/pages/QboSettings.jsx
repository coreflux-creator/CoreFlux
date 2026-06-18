import React, { useState } from 'react';
import { useApi, api } from '../lib/api';
import { CheckCircle2, ExternalLink, RefreshCw, XCircle, ArrowRight, ArrowLeft, ArrowLeftRight, MinusCircle, Send, AlertTriangle } from 'lucide-react';

const ACCOUNTING_ACCOUNTS_API = '/api/v1/accounting/accounts';

/**
 * QboSettings — QuickBooks Online connection + per-entity sync direction
 * picker. Mounted at /admin/integrations/qbo.
 *
 * Render branches keyed off /api/qbo/status:
 *   - configured=false              → "Pod not configured" notice
 *   - configured=true, connected=false → "Connect to QuickBooks" CTA
 *   - configured=true, connected=true  → company info + Ping + Disconnect
 *                                        + per-entity sync direction table
 */
export default function QboSettings() {
  const status = useApi('/api/qbo/status.php?action=status');
  const skipped = useApi('/api/qbo/skipped_jes.php?action=skipped_jes');
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState(parseFlashFromUrl());
  const [draft, setDraft] = useState(null); // editable sync_config
  // Live result of the most recent CoA pull — drives the unmapped-
  // accounts card below.  Reset on disconnect.
  const [coaPullResult, setCoaPullResult] = useState(null);
  // CF accounts list for the inline "Map to existing CF account"
  // dropdown.  Loaded lazily on first render of the unmapped card.
  const cfAccounts = useApi(`${ACCOUNTING_ACCOUNTS_API}?active=1`);
  // Per-row "Removed/Imported/Mapped" status so the operator sees
  // immediate feedback without re-pulling.  Keyed on QBO id.
  const [accountRowAction, setAccountRowAction] = useState({});

  const data       = status.data || {};
  const configured = !!data.configured;
  const connected  = !!data.connected;
  const config     = draft ?? data.sync_config ?? {};
  const dirty      = draft !== null && JSON.stringify(draft) !== JSON.stringify(data.sync_config || {});

  const handleConnect = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.get('/api/qbo/oauth_start.php?action=oauth_start');
      window.location.href = r.authorize_url;
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
      setBusy(false);
    }
  };

  const handleDisconnect = async () => {
    if (!window.confirm('Disconnect QuickBooks Online? Cached tokens will be revoked. No data is deleted from QBO.')) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/qbo/disconnect.php?action=disconnect', {});
      setFlash({ kind: 'success', msg: 'QuickBooks disconnected.' });
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
      const r = await api.post('/api/qbo/ping.php?action=ping', {});
      setFlash({ kind: r.ok ? 'success' : 'error', msg: r.ok ? `Ping OK (${r.latency_ms}ms) — ${r.company_name || 'Connected'}` : `Ping failed: ${r.error}` });
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
      const r = await api.post('/api/qbo/sync_config_set.php?action=sync_config_set', { sync_config: config });
      setFlash({ kind: 'success', msg: 'Sync settings saved.' });
      setDraft(null);
      status.reload();
      // Also surface what was saved so the user sees the merged config.
      if (r.sync_config) status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleSyncJe = async (dryRun = false) => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/qbo/sync_je.php?action=sync_je', { dry_run: dryRun, limit: 50 });
      setFlash({
        kind: r.failed > 0 ? 'error' : 'success',
        msg: `${dryRun ? 'Dry-run' : 'Sync'}: ${r.pushed} pushed · ${r.skipped_unmapped} skipped (unmapped accounts) · ${r.failed} failed (${r.considered} considered, ${r.latency_ms}ms)`,
      });
      status.reload();
      skipped.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handlePullMaster = async (entity, opts = {}) => {
    setBusy(true); setFlash(null);
    try {
      const body = { limit: 1000, ...opts };
      const r = await api.post(`/api/qbo/sync_${entity}.php?action=sync_${entity}`, body);
      let msg;
      if (entity === 'accounts') {
        const importPart = (r.imported || 0) > 0
          ? ` · ${r.imported} imported into CoreFlux`
          : '';
        const errPart = (r.import_errors || []).length > 0
          ? ` · ${r.import_errors.length} import errors`
          : '';
        msg = `Pulled COA: ${r.matched} matched · ${r.newly_mapped} newly mapped${importPart} · ${r.unmapped_in_qbo} still unmapped${errPart} (${r.pulled} from ${r.pages} page${r.pages === 1 ? '' : 's'}, ${r.latency_ms}ms)`;
        setCoaPullResult(r);
        setAccountRowAction({}); // fresh pull → reset per-row toasts
        cfAccounts.reload();     // newly-imported rows are now in CF too
      } else {
        msg = `Pulled ${entity}: ${r.created} created · ${r.updated} updated · ${r.unchanged} unchanged · ${r.failed} failed (${r.pulled} from ${r.pages} page${r.pages === 1 ? '' : 's'}, ${r.latency_ms}ms)`;
      }
      const errored = (r.failed || 0) > 0 || (r.import_errors || []).length > 0;
      setFlash({ kind: errored ? 'error' : 'success', msg });
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  // ── Inline per-row actions for the unmapped-accounts card ─────────
  const handleImportOneAccount = async (sample) => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/qbo/sync_accounts.php?action=sync_accounts', {
        // Single-shot: tell the puller to import unmapped rows. Since
        // we just pulled, the rows are still flagged unmapped in QBO —
        // a fresh pull with import_unmapped will allocate codes for the
        // remainder.  Operator can scope to just one by removing the
        // others first or running this once and letting the batch handle
        // everything.
        import_unmapped: true,
      });
      const imported = (r.imported_codes || {})[sample.qbo_id];
      setCoaPullResult(r);
      cfAccounts.reload();
      if (imported) {
        setAccountRowAction(prev => ({ ...prev, [sample.qbo_id]: { kind: 'imported', code: imported } }));
        setFlash({ kind: 'success', msg: `Imported ${sample.name} as CF code ${imported}.` });
      } else {
        setFlash({ kind: 'success', msg: `Imported ${r.imported} account(s) from QBO.` });
      }
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleMapAccountManual = async (sample, cfAccountId) => {
    if (!cfAccountId) return;
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/qbo/account_map_manual.php?action=account_map_manual', {
        qbo_id: sample.qbo_id,
        cf_account_id: parseInt(cfAccountId, 10),
      });
      const cf = r.cf_account || {};
      setAccountRowAction(prev => ({
        ...prev,
        [sample.qbo_id]: { kind: 'mapped', cf_code: cf.code, cf_name: cf.name },
      }));
      setFlash({ kind: 'success', msg: `Mapped QBO "${sample.name}" → CF ${cf.code || ''} ${cf.name || ''}.` });
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleRemoveCfAccount = async (cfAccountId, cfLabel) => {
    if (!cfAccountId) return;
    if (!window.confirm(`Permanently delete CF account "${cfLabel}"? Refused if any posted journal lines reference it.`)) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/accounting.php?action=account_delete', {
        coreflux_account_id: cfAccountId,
      });
      setFlash({ kind: 'success', msg: `Removed CF account "${cfLabel}".` });
      cfAccounts.reload();
    } catch (e) {
      // 409 → offer deactivate fallback (matches the Jaz UX).
      const status409 = (e.status === 409) || /409/.test(String(e.message || ''));
      if (status409 && window.confirm(`${e.message}\n\nDeactivate "${cfLabel}" instead (soft archive)?`)) {
        try {
          await api.post('/api/accounting.php?action=account_deactivate', {
            coreflux_account_id: cfAccountId,
          });
          setFlash({ kind: 'success', msg: `Deactivated CF account "${cfLabel}".` });
          cfAccounts.reload();
        } catch (e2) {
          setFlash({ kind: 'error', msg: e2.message || String(e2) });
        }
      } else {
        setFlash({ kind: 'error', msg: e.message || String(e) });
      }
    } finally {
      setBusy(false);
    }
  };

  const handleGenericSync = async (action, label) => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post(`/api/qbo/${action}.php?action=${action}`, { limit: 50 });
      const msg = action === 'sync_items'
        ? `Items: ${r.newly_mapped} newly mapped · ${r.unchanged} unchanged · ${r.services} services (${r.pulled} pulled, ${r.latency_ms}ms)`
        : `${label}: ${r.pushed} pushed · ${r.skipped} skipped · ${r.failed} failed (${r.considered} considered, ${r.latency_ms}ms)`;
      setFlash({ kind: (r.failed || 0) > 0 ? 'error' : 'success', msg });
      status.reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  if (status.loading) return <div data-testid="qbo-settings-loading">Loading…</div>;

  return (
    <section data-testid="qbo-settings" style={{ maxWidth: 880 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>QuickBooks Online — Connection</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Connect your tenant's Intuit QuickBooks Online company via OAuth 2.0. CoreFlux stores the
          access + refresh tokens AES-256-GCM encrypted and auto-refreshes them before expiry.
          {data.environment && (
            <> Environment: <code data-testid="qbo-environment">{data.environment}</code>.</>
          )}
        </p>
      </header>

      {flash && (
        <div
          data-testid={`qbo-flash-${flash.kind}`}
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
          data-testid="qbo-not-configured"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, background: '#fafafa' }}
        >
          <strong>QuickBooks Online is not configured on this pod.</strong>
          <p style={{ fontSize: 13, margin: '8px 0 0', color: 'var(--cf-text-secondary)' }}>
            An administrator must set <code>QBO_CLIENT_ID</code>, <code>QBO_CLIENT_SECRET</code>,
            and <code>QBO_REDIRECT_URI</code> in <code>core/config.local.php</code> (or the host env)
            and register the redirect URI in the{' '}
            <a href="https://developer.intuit.com/app/developer/dashboard" target="_blank" rel="noopener noreferrer">
              Intuit Developer Dashboard <ExternalLink size={12} style={{ verticalAlign: 'middle' }} />
            </a>.
          </p>
        </div>
      )}

      {configured && !connected && (
        <div
          data-testid="qbo-not-connected"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
        >
          <div style={{ marginBottom: 12 }}>
            <span className="badge" style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
              Not connected
            </span>
          </div>
          <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '0 0 16px' }}>
            Click below to authorise CoreFlux against your Intuit company. You'll be redirected to
            QuickBooks, sign in, pick the company, and consent to the requested scopes — then
            Intuit returns you here with the connection live.
          </p>
          <button
            type="button"
            className="btn btn--primary"
            onClick={handleConnect}
            disabled={busy}
            data-testid="qbo-connect-btn"
          >
            {busy ? 'Redirecting…' : 'Connect to QuickBooks'}
          </button>
        </div>
      )}

      {configured && connected && (
        <>
          <div
            data-testid="qbo-connected"
            className="card"
            style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, marginBottom: 24 }}
          >
            <div style={{ marginBottom: 12 }}>
              <span className="badge badge--success" style={{ padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
                <CheckCircle2 size={11} style={{ verticalAlign: 'middle', marginRight: 4 }} />Connected
              </span>
            </div>
            <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, fontSize: 13 }}>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Company</dt>
              <dd style={{ margin: 0 }} data-testid="qbo-company-name">{data.company_name || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Realm ID</dt>
              <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="qbo-realm-id">{data.realm_id || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Environment</dt>
              <dd style={{ margin: 0 }}>{data.environment || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Access token expires</dt>
              <dd style={{ margin: 0 }}>{data.access_token_exp || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Refresh token expires</dt>
              <dd style={{ margin: 0 }}>{data.refresh_token_exp || '—'}</dd>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>Last probe</dt>
              <dd style={{ margin: 0 }}>{data.last_probe_at || '—'}</dd>
            </dl>
            {data.last_probe_error && (
              <p style={{ color: 'var(--cf-red, #b91c1c)', fontSize: 12, marginTop: 8 }} data-testid="qbo-probe-error">
                Last error: {data.last_probe_error}
              </p>
            )}
            <div style={{ marginTop: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button type="button" className="btn" onClick={handlePing} disabled={busy} data-testid="qbo-ping-btn">
                <RefreshCw size={14} style={{ marginRight: 6 }} />{busy ? 'Pinging…' : 'Test connection'}
              </button>
              <button type="button" className="btn" onClick={handleDisconnect} disabled={busy} data-testid="qbo-disconnect-btn">
                <XCircle size={14} style={{ marginRight: 6 }} />Disconnect
              </button>
            </div>
          </div>

          {/* Per-entity sync direction picker */}
          <SyncConfigCard
            entities={data.entities || []}
            config={config}
            onChange={(entity, dir) => setDraft({ ...(draft ?? data.sync_config ?? {}), [entity]: dir })}
            onSave={handleSaveConfig}
            onReset={() => setDraft(null)}
            dirty={dirty}
            busy={busy}
          />

          {/* Manual sync triggers — buttons appear per-entity based on
              its sync direction. Cron workers cover the scheduled paths;
              these are for on-demand pushes/pulls. */}
          <ManualSyncCard
            config={data.sync_config || {}}
            busy={busy}
            onPushJe={() => handleSyncJe(false)}
            onDryRunJe={() => handleSyncJe(true)}
            onPullCustomers={() => handlePullMaster('customers')}
            onPullVendors={() => handlePullMaster('vendors')}
            onPullAccounts={() => handlePullMaster('accounts')}
            onPullAccountsAndImport={() => handlePullMaster('accounts', { import_unmapped: true })}
            onPullItems={() => handleGenericSync('sync_items', 'Items', false)}
            onPushBills={() => handleGenericSync('sync_bills', 'Bills', false)}
            onPushInvoices={() => handleGenericSync('sync_invoices', 'Invoices', false)}
            onPushPayments={() => handleGenericSync('sync_payments', 'Payments', false)}
          />

          {/* Unmapped QBO accounts inbox — Jaz-parity surface. Lets the
              operator import any QBO row that has no CF counterpart,
              hand-map it to an existing CF account, or remove the
              over-eager CF row entirely. Hidden until the operator has
              run a pull at least once during this session. */}
          <UnmappedQboAccountsCard
            result={coaPullResult}
            cfAccounts={cfAccounts.data?.rows || []}
            rowAction={accountRowAction}
            busy={busy}
            onImportOne={handleImportOneAccount}
            onMapManual={handleMapAccountManual}
            onRemoveCf={handleRemoveCfAccount}
          />

          {/* Skipped JE inbox — surfaces JEs the cron has had to skip
              because their account has no QBO mapping yet. One row per
              blocking account with the count + recent JE numbers. */}
          <SkippedJeInbox data={skipped.data} loading={skipped.loading} />
        </>
      )}
    </section>
  );
}

/* --------------------------------------------------------------- */

const DIR_META = {
  push:     { icon: ArrowRight,       label: 'Push',     blurb: 'CoreFlux → QuickBooks' },
  pull:     { icon: ArrowLeft,        label: 'Pull',     blurb: 'QuickBooks → CoreFlux' },
  two_way:  { icon: ArrowLeftRight,   label: 'Two-way',  blurb: 'Bidirectional with last-write-wins' },
  off:      { icon: MinusCircle,      label: 'Off',      blurb: 'No sync' },
};

const ENTITY_LABELS = {
  journal_entries:   'Journal Entries',
  customers:         'Customers',
  vendors:           'Vendors',
  invoices:          'Invoices',
  bills:             'Bills',
  payments:          'Payments',
  chart_of_accounts: 'Chart of Accounts',
};

function SyncConfigCard({ entities, config, onChange, onSave, onReset, dirty, busy }) {
  return (
    <div
      data-testid="qbo-sync-config"
      className="card"
      style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
    >
      <header style={{ marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>Data flow settings</h4>
        <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          For each entity, choose whether CoreFlux pushes to QuickBooks, pulls from QuickBooks,
          syncs both ways, or stays off. Defaults are <strong>off</strong> — opt each entity in
          deliberately.
        </p>
      </header>
      <table data-testid="qbo-sync-config-table" style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
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
              <tr key={entity} data-testid={`qbo-sync-row-${entity}`} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
                <td style={{ padding: '8px 4px', fontWeight: 500 }}>{ENTITY_LABELS[entity] || entity}</td>
                <td style={{ padding: '8px 4px' }}>
                  <select
                    value={dir}
                    onChange={(e) => onChange(entity, e.target.value)}
                    data-testid={`qbo-sync-dir-${entity}`}
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
          data-testid="qbo-sync-config-save"
        >
          {busy ? 'Saving…' : 'Save settings'}
        </button>
        {dirty && (
          <button type="button" className="btn" onClick={onReset} disabled={busy} data-testid="qbo-sync-config-reset">
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
    // Clean the query so a reload doesn't re-fire this flash.
    window.history.replaceState({}, '', window.location.pathname);
    return { kind: 'success', msg: 'QuickBooks connected.' };
  }
  if (p.get('error')) {
    const err = p.get('error');
    window.history.replaceState({}, '', window.location.pathname);
    return { kind: 'error', msg: 'QuickBooks reported: ' + err };
  }
  return null;
}

function ManualSyncCard({ config, busy, onPushJe, onDryRunJe, onPullCustomers, onPullVendors, onPullAccounts, onPullAccountsAndImport, onPullItems, onPushBills, onPushInvoices, onPushPayments }) {
  const jeDir   = config.journal_entries;
  const custDir = config.customers;
  const vendDir = config.vendors;
  const coaDir  = config.chart_of_accounts;
  const billDir = config.bills;
  const invDir  = config.invoices;
  const payDir  = config.payments;
  const showJe   = ['push', 'two_way'].includes(jeDir);
  const showCust = ['pull', 'two_way'].includes(custDir);
  const showVend = ['pull', 'two_way'].includes(vendDir);
  const showCoa  = ['pull', 'two_way'].includes(coaDir);
  const showBill = ['push', 'two_way'].includes(billDir);
  const showInv  = ['push', 'two_way'].includes(invDir);
  const showPay  = ['push', 'two_way'].includes(payDir);
  if (!showJe && !showCust && !showVend && !showCoa && !showBill && !showInv && !showPay) return null;
  return (
    <div
      data-testid="qbo-sync-actions"
      className="card"
      style={{ marginTop: 24, padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
    >
      <header style={{ marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>Manual sync</h4>
        <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          QBO sync runs on a schedule (outbound every 15 minutes; inbound nightly).
          Use these buttons to push or pull immediately.
        </p>
      </header>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {showJe && (
          <>
            <button
              type="button"
              className="btn btn--primary"
              onClick={onPushJe}
              disabled={busy}
              data-testid="qbo-sync-je-btn"
            >
              <Send size={14} style={{ marginRight: 6 }} />
              {busy ? 'Working…' : 'Push journal entries now'}
            </button>
            <button
              type="button"
              className="btn"
              onClick={onDryRunJe}
              disabled={busy}
              data-testid="qbo-sync-je-dry-run-btn"
            >
              Dry run (preview)
            </button>
          </>
        )}
        {showCust && (
          <button
            type="button"
            className="btn"
            onClick={onPullCustomers}
            disabled={busy}
            data-testid="qbo-sync-customers-btn"
          >
            <ArrowLeft size={14} style={{ marginRight: 6 }} />
            Pull customers
          </button>
        )}
        {showVend && (
          <button
            type="button"
            className="btn"
            onClick={onPullVendors}
            disabled={busy}
            data-testid="qbo-sync-vendors-btn"
          >
            <ArrowLeft size={14} style={{ marginRight: 6 }} />
            Pull vendors
          </button>
        )}
        {showCoa && (
          <>
            <button
              type="button"
              className="btn"
              onClick={onPullAccounts}
              disabled={busy}
              data-testid="qbo-sync-accounts-btn"
            >
              <ArrowLeft size={14} style={{ marginRight: 6 }} />
              Pull chart of accounts
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={onPullAccountsAndImport}
              disabled={busy}
              data-testid="qbo-sync-accounts-import-btn"
              title="Pulls QBO chart of accounts AND imports any unmapped QBO row into CoreFlux. Mirrors Jaz 'true pull' behaviour."
            >
              <ArrowLeft size={14} style={{ marginRight: 6 }} />
              Pull & import unmapped
            </button>
          </>
        )}
        {showInv && (
          <button type="button" className="btn" onClick={onPullItems} disabled={busy} data-testid="qbo-sync-items-btn">
            <ArrowLeft size={14} style={{ marginRight: 6 }} />
            Pull QBO items
          </button>
        )}
        {showBill && (
          <button type="button" className="btn btn--primary" onClick={onPushBills} disabled={busy} data-testid="qbo-sync-bills-btn">
            <Send size={14} style={{ marginRight: 6 }} />
            Push bills
          </button>
        )}
        {showInv && (
          <button type="button" className="btn btn--primary" onClick={onPushInvoices} disabled={busy} data-testid="qbo-sync-invoices-btn">
            <Send size={14} style={{ marginRight: 6 }} />
            Push invoices
          </button>
        )}
        {showPay && (
          <button type="button" className="btn btn--primary" onClick={onPushPayments} disabled={busy} data-testid="qbo-sync-payments-btn">
            <Send size={14} style={{ marginRight: 6 }} />
            Push bill payments
          </button>
        )}
      </div>
    </div>
  );
}

function SkippedJeInbox({ data, loading }) {
  const blockers = data?.blockers || [];
  if (loading) return null;
  if (blockers.length === 0) return null;
  const totalBlocked = blockers.reduce((s, b) => s + (b.blocked_je_count || 0), 0);
  return (
    <div
      data-testid="qbo-skipped-je-inbox"
      className="card"
      style={{ marginTop: 24, padding: 16, border: '1px solid var(--cf-amber, #f59e0b)44', borderRadius: 8, background: '#fffbeb' }}
    >
      <header style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <AlertTriangle size={18} style={{ color: 'var(--cf-amber, #92400e)' }} />
        <div>
          <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }} data-testid="qbo-skipped-je-inbox-title">
            {totalBlocked} skipped journal {totalBlocked === 1 ? 'entry' : 'entries'} —
            {' '}{blockers.length} unmapped {blockers.length === 1 ? 'account' : 'accounts'}
          </h4>
          <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Within the last {data?.window_days ?? 30} days. Map these accounts in QuickBooks (use the same <code>AcctNum</code> as the CoreFlux account code) and the next sync will pick them up automatically.
          </p>
        </div>
      </header>
      <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
            <th style={{ padding: '6px 4px' }}>Account</th>
            <th style={{ padding: '6px 4px', textAlign: 'right' }}>Blocked JEs</th>
            <th style={{ padding: '6px 4px' }}>Recent</th>
            <th style={{ padding: '6px 4px' }}></th>
          </tr>
        </thead>
        <tbody>
          {blockers.map((b) => (
            <tr key={b.account_id} data-testid={`qbo-skipped-row-${b.account_id}`} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
              <td style={{ padding: '8px 4px' }}>
                <div style={{ fontWeight: 500 }}>{b.account_code || `#${b.account_id}`} — {b.account_name || '(unknown)'}</div>
              </td>
              <td style={{ padding: '8px 4px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }} data-testid={`qbo-skipped-count-${b.account_id}`}>
                {b.blocked_je_count}
              </td>
              <td style={{ padding: '8px 4px', color: 'var(--cf-text-secondary)', fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 12 }}>
                {(b.recent_je_numbers || []).join(', ')}
              </td>
              <td style={{ padding: '8px 4px' }}>
                <a
                  href={`/modules/accounting/coa?focus=${b.account_id}`}
                  className="btn"
                  data-testid={`qbo-skipped-map-link-${b.account_id}`}
                  style={{ fontSize: 12, padding: '4px 10px' }}
                >
                  Map account →
                </a>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* --------------------------------------------------------------- */

function UnmappedQboAccountsCard({ result, cfAccounts, rowAction, busy, onImportOne, onMapManual, onRemoveCf }) {
  if (!result) return null;
  const samples = result.unmapped_samples || [];
  const importErrors = result.import_errors || [];
  const importedCount = result.imported || 0;
  const importedCodes = result.imported_codes || {};

  // Build a friendly index of CF accounts so the dropdown can show
  // "code · name (type)" labels.
  const cfOptions = (cfAccounts || []).map(a => ({
    id: a.id,
    label: `${a.code || '—'} · ${a.name || '(no name)'} (${a.account_type || ''})`,
  }));

  return (
    <div
      data-testid="qbo-unmapped-accounts-card"
      className="card"
      style={{
        marginTop: 24, padding: 16, borderRadius: 8,
        background: importedCount > 0 ? '#ecfdf5' : '#f8fafc',
        border: `1px solid ${importedCount > 0 ? '#10b981' : '#e5e7eb'}`,
      }}
    >
      <header style={{ marginBottom: 12 }}>
        <h4 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}
            data-testid="qbo-unmapped-accounts-title">
          {importedCount > 0
            ? <>✓ Imported {importedCount} QBO account{importedCount === 1 ? '' : 's'} into CoreFlux</>
            : <>{samples.length === 0 ? 'No unmapped QBO accounts' : `${result.unmapped_in_qbo || samples.length} unmapped QBO account${(result.unmapped_in_qbo || samples.length) === 1 ? '' : 's'}`}</>}
        </h4>
        <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          QBO returned these rows but they have no CoreFlux counterpart. Either <strong>import</strong> them
          as new CF accounts (one-click), <strong>map</strong> them to an existing CF account, or
          <strong> remove</strong> a CF account that shouldn't have been created in the first place.
        </p>
      </header>

      {importErrors.length > 0 && (
        <details data-testid="qbo-unmapped-import-errors"
                 style={{ background: '#fef2f2', padding: 8, borderRadius: 4, fontSize: 12, marginBottom: 12 }}>
          <summary style={{ cursor: 'pointer', color: '#991b1b', fontWeight: 600 }}>
            Show {importErrors.length} import error{importErrors.length === 1 ? '' : 's'}
          </summary>
          <ul style={{ margin: '6px 0 0 20px', padding: 0 }}>
            {importErrors.map((err, i) => (
              <li key={i} data-testid={`qbo-unmapped-import-error-${i}`} style={{ marginBottom: 4 }}>
                <code>{err.qbo_id}</code> — {err.name || ''} — {err.error}
              </li>
            ))}
          </ul>
        </details>
      )}

      {samples.length === 0 && (
        <p style={{ fontSize: 13, color: '#475569', margin: 0 }}
           data-testid="qbo-unmapped-empty">
          Every QBO account is now linked to a CoreFlux row.
        </p>
      )}

      {samples.length > 0 && (
        <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}
               data-testid="qbo-unmapped-table">
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
              <th style={{ padding: '6px 4px' }}>QBO account</th>
              <th style={{ padding: '6px 4px' }}>Classification</th>
              <th style={{ padding: '6px 4px' }}>Map to existing CF</th>
              <th style={{ padding: '6px 4px', textAlign: 'right' }}></th>
            </tr>
          </thead>
          <tbody>
            {samples.map((s, idx) => {
              const action = rowAction[s.qbo_id];
              const importedCode = importedCodes[s.qbo_id] || (action?.kind === 'imported' ? action.code : null);
              return (
                <tr key={s.qbo_id}
                    data-testid={`qbo-unmapped-row-${idx}`}
                    style={{
                      borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                      opacity: (action || importedCode) ? 0.6 : 1,
                    }}>
                  <td style={{ padding: '8px 4px' }}>
                    <div style={{ fontWeight: 500 }}>
                      {s.acct_num && <code style={{ marginRight: 6, color: '#64748b' }}>{s.acct_num}</code>}
                      {s.name}
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b', fontFamily: 'var(--cf-mono, ui-monospace)' }}>
                      QBO id: {s.qbo_id}
                    </div>
                  </td>
                  <td style={{ padding: '8px 4px', fontSize: 12 }}>
                    {s.classification || '—'}
                    {s.account_type && <span style={{ display: 'block', color: '#64748b' }}>{s.account_type}</span>}
                  </td>
                  <td style={{ padding: '8px 4px' }}>
                    {importedCode ? (
                      <span data-testid={`qbo-unmapped-imported-${idx}`}
                            style={{ fontSize: 12, color: '#047857', fontWeight: 600 }}>
                        ✓ Imported as CF {importedCode}
                      </span>
                    ) : action?.kind === 'mapped' ? (
                      <span data-testid={`qbo-unmapped-mapped-${idx}`}
                            style={{ fontSize: 12, color: '#047857', fontWeight: 600 }}>
                        ✓ Mapped → {action.cf_code || ''} {action.cf_name || ''}
                      </span>
                    ) : (
                      <select
                        disabled={busy || cfOptions.length === 0}
                        defaultValue=""
                        onChange={(e) => { if (e.target.value) onMapManual(s, e.target.value); }}
                        data-testid={`qbo-unmapped-map-select-${idx}`}
                        style={{ fontSize: 12, padding: '4px 6px', maxWidth: 280 }}
                      >
                        <option value="">— pick an existing CF account —</option>
                        {cfOptions.map(opt => (
                          <option key={opt.id} value={opt.id}>{opt.label}</option>
                        ))}
                      </select>
                    )}
                  </td>
                  <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                    {!importedCode && action?.kind !== 'mapped' && (
                      <button
                        type="button"
                        className="btn btn--primary"
                        onClick={() => onImportOne(s)}
                        disabled={busy}
                        data-testid={`qbo-unmapped-import-${idx}`}
                        style={{ fontSize: 11, padding: '4px 10px', marginRight: 4 }}
                        title="Import this QBO account into CoreFlux. Allocates a fresh CF code in the right bucket (asset/liability/etc.) and seeds the QBO mapping so the next JE push picks it up."
                      >
                        Import
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}

      {cfAccounts && cfAccounts.length > 0 && (
        <details style={{ marginTop: 12, fontSize: 12 }}>
          <summary style={{ cursor: 'pointer', color: '#64748b' }}
                   data-testid="qbo-unmapped-remove-toggle">
            Remove a CoreFlux account from the chart of accounts (advanced)
          </summary>
          <p style={{ fontSize: 12, color: '#64748b', margin: '6px 0' }}>
            Picks a CF account and hard-deletes it. Refused automatically if any posted journal lines reference it
            (offers a soft-deactivate fallback instead).
          </p>
          <select
            defaultValue=""
            onChange={(e) => {
              const cfId = parseInt(e.target.value, 10);
              const cf = (cfAccounts || []).find(a => a.id === cfId);
              if (cf) onRemoveCf(cfId, `${cf.code} · ${cf.name}`);
              e.target.value = '';
            }}
            data-testid="qbo-unmapped-remove-select"
            style={{ fontSize: 12, padding: '4px 6px', maxWidth: 320 }}
          >
            <option value="">— pick a CF account to remove —</option>
            {cfOptions.map(opt => (
              <option key={opt.id} value={opt.id}>{opt.label}</option>
            ))}
          </select>
        </details>
      )}
    </div>
  );
}
