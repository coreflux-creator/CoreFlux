import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <JazIntegrationSettings /> — operator-facing config for the
 * provider-neutral accounting backend (Slice 1). Per spec §24,
 * surfaces:
 *
 *   • Entity (sub-tenant) picker — accounting backend is per-entity
 *   • Connect / disconnect / rotate-key
 *   • Credential status (last4 only, never the plaintext)
 *   • Scope summary (permissions, shadow user) — once Phase 0 lands
 *   • Last validation + error
 *   • A loud "not_implemented_yet" banner while Jaz partner diligence
 *     is pending, so operators understand reads will be empty.
 *
 * The page is provider-aware via the ?provider= query param (defaults
 * to 'jaz') — the same component will swap behind a provider tab when
 * QBO/Xero adapters land.
 */
export default function JazIntegrationSettings() {
  // `entities` is the flat dropdown list — parent tenant + sub-tenants.
  // The parent's own books are a legitimate legal entity (it is NOT just a
  // consolidation layer over the sub-tenants). We model that by adding the
  // parent tenant as the first entry and letting the connection storage
  // use `sub_tenant_id = parent_tenant_id` for the parent row — no schema
  // change needed since accounting_provider_connections doesn't FK-constrain
  // sub_tenant_id and the (tenant_id, sub_tenant_id, provider) unique key
  // happily accepts parent_tenant_id in both columns.
  const [entities, setEntities]       = useState([]);    // [{id,name,kind:'parent'|'sub'}]
  const [subTenantId, setSubTenantId] = useState(null);
  const [connection, setConnection] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [flash, setFlash] = useState(null);
  const [apiKey, setApiKey] = useState('');
  const [orgId, setOrgId] = useState('');
  const [baseCurrency, setBaseCurrency] = useState('USD');
  const [busy, setBusy] = useState(false);

  // Load parent + sub-tenants as legal entities. Parent comes first so
  // first-time tenants without any sub-tenants can still connect their
  // own books.
  useEffect(() => {
    let mounted = true;
    api.get('/api/sub_tenants.php')
      .then(r => {
        if (!mounted) return;
        const subs = Array.isArray(r) ? r : (r.rows || r.sub_tenants || r.tenants || []);
        const parent = r?.parent || null;
        const parentId = r?.parent_tenant_id ?? parent?.id ?? null;
        const list = [];
        if (parent && parentId) {
          list.push({ id: parentId, name: parent.name || `Tenant ${parentId}`, kind: 'parent' });
        }
        for (const st of subs) {
          list.push({ id: st.id, name: st.name || st.subdomain || `Entity ${st.id}`, kind: 'sub' });
        }
        setEntities(list);
        if (list.length > 0) setSubTenantId(list[0].id);
      })
      .catch(() => mounted && setEntities([]));
    return () => { mounted = false; };
  }, []);

  const reload = useCallback(async () => {
    if (!subTenantId) { setLoading(false); return; }
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/accounting.php?action=status&sub_tenant_id=${subTenantId}&provider=jaz`);
      setConnection(r.connection || null);
      if (r.connection?.provider_org_id) setOrgId(r.connection.provider_org_id);
      if (r.connection?.base_currency)   setBaseCurrency(r.connection.base_currency);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  }, [subTenantId]);
  useEffect(() => { reload(); }, [reload]);

  const handleConnect = async (e) => {
    e.preventDefault();
    if (!subTenantId) return setError('Pick a legal entity first.');
    if (apiKey.length < 16) return setError('API key must be at least 16 characters.');
    setBusy(true); setError(null);
    try {
      await api.post('/api/accounting.php?action=connect&provider=jaz', {
        sub_tenant_id: subTenantId,
        api_key: apiKey,
        provider_org_id: orgId || undefined,
        base_currency: baseCurrency,
      });
      setApiKey('');
      setFlash({ kind: 'success', msg: 'Credentials stored. Click Validate to probe the connection.' });
      await reload();
    } catch (e) { setError(e.message || 'Connect failed'); }
    finally { setBusy(false); }
  };

  const handleValidate = async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/accounting.php?action=validate&provider=jaz', {
        sub_tenant_id: subTenantId,
      });
      setFlash({
        kind: r.ok ? 'success' : 'error',
        msg: r.ok
          ? `Validation status: ${r.status}`
          : `Validation failed: ${r.connection?.last_validation_error || 'unknown'}`,
      });
      await reload();
    } catch (e) { setError(e.message || 'Validate failed'); }
    finally { setBusy(false); }
  };

  const handleDisconnect = async () => {
    if (!confirm('Disconnect Jaz for this entity? Credentials will be wiped.')) return;
    setBusy(true); setError(null);
    try {
      await api.post('/api/accounting.php?action=disconnect&provider=jaz', { sub_tenant_id: subTenantId });
      setFlash({ kind: 'success', msg: 'Disconnected. You can re-connect any time.' });
      await reload();
    } catch (e) { setError(e.message || 'Disconnect failed'); }
    finally { setBusy(false); }
  };

  // Slice 2 — Phase 1 live wiring now ships. Reads + writes hit
  // Jaz directly; the "partner diligence pending" banner only shows
  // when validate explicitly reports the legacy not_implemented_yet
  // marker (kept for forward-compat with future not-yet-wired methods).
  const notReady = connection?.api_scope_summary?.not_implemented_yet === true;

  return (
    <section data-testid="jaz-integration-settings" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>Jaz.ai — Accounting backend</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          When enabled per legal entity, Jaz becomes CoreFlux's accounting backend
          (chart of accounts, GL, trial balance, posting). CoreFlux still owns the UI,
          workflows, approvals, and audit trail.
        </p>
        <p style={{ margin: '8px 0 0', fontSize: 12 }}>
          <a href="/admin/accounting/outbox"
             data-testid="jaz-link-outbox"
             style={{ color: '#2563eb', textDecoration: 'none' }}>
            → View accounting outbox (drafts queued to Jaz)
          </a>
        </p>
      </header>

      {error && <div className="error" data-testid="jaz-error" style={{ marginBottom: 12 }}>{error}</div>}
      {flash && (
        <div data-testid={`jaz-flash-${flash.kind}`}
             style={{
               padding: '8px 12px', borderRadius: 6, marginBottom: 12,
               background: flash.kind === 'success' ? '#ecfdf5'
                         : flash.kind === 'info'    ? '#eff6ff'
                         : '#fef2f2',
               color:      flash.kind === 'success' ? '#047857'
                         : flash.kind === 'info'    ? '#1e3a8a'
                         : '#b91c1c',
               fontSize: 13,
             }}>{flash.msg}</div>
      )}

      <fieldset style={fieldsetStyle}>
        <legend style={legendStyle}>Step 1 — Legal entity</legend>
        <p style={{ margin: '0 0 8px', fontSize: 12, color: '#64748b' }}>
          Connect Jaz <strong>per entity</strong>. The parent tenant keeps its own books too — pick it
          if your books live at the top level, or pick a sub-tenant for division-specific books.
        </p>
        {entities.length === 0 ? (
          <p style={{ fontSize: 12, color: '#92400e' }} data-testid="jaz-no-entities">
            No legal entities resolved. Make sure you're signed in to an active tenant.
          </p>
        ) : (
          <>
            <select data-testid="jaz-entity-select"
                    value={subTenantId || ''}
                    onChange={(e) => setSubTenantId(parseInt(e.target.value, 10) || null)}
                    className="input" style={{ minWidth: 260 }}>
              {entities.map(ent => (
                <option key={ent.id} value={ent.id}>
                  {ent.name}{ent.kind === 'parent' ? ' — parent entity' : ''}
                </option>
              ))}
            </select>
            {entities.find(e => e.id === subTenantId)?.kind === 'parent' && (
              <p data-testid="jaz-parent-entity-note"
                 style={{ margin: '6px 0 0', fontSize: 11, color: '#0369a1' }}>
                Books for the parent entity. Sub-tenants (if any) keep their own separate Jaz organisations.
              </p>
            )}
          </>
        )}
      </fieldset>

      {!loading && subTenantId && (
        <>
          <fieldset style={fieldsetStyle}>
            <legend style={legendStyle}>Step 2 — Credentials</legend>
            {connection ? (
              <div style={statusCardStyle} data-testid="jaz-current-status">
                <div style={{ fontSize: 14, fontWeight: 600 }}>
                  Status: <code data-testid="jaz-connection-status">{connection.connection_status}</code>
                  {connection.credential_last4 && (
                    <span style={{ marginLeft: 12, color: '#64748b', fontWeight: 400 }}>
                      Key ends with <code>…{connection.credential_last4}</code>
                    </span>
                  )}
                </div>
                {connection.provider_org_id && (
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>
                    Jaz org: <code>{connection.provider_org_id}</code>
                  </div>
                )}
                {connection.last_validated_at && (
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>
                    Last validated: {connection.last_validated_at}
                  </div>
                )}
                {connection.last_validation_error && (
                  <div style={{ fontSize: 12, color: '#b91c1c', marginTop: 4 }}>
                    Last error: {connection.last_validation_error}
                  </div>
                )}
                <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
                  <button type="button" className="btn"
                          onClick={handleValidate} disabled={busy}
                          data-testid="jaz-validate-btn">
                    {busy ? 'Validating…' : 'Validate'}
                  </button>
                  <button type="button" className="btn btn--ghost"
                          onClick={handleDisconnect} disabled={busy}
                          data-testid="jaz-disconnect-btn">
                    Disconnect
                  </button>
                </div>
              </div>
            ) : (
              <p style={{ fontSize: 13, color: '#64748b', marginBottom: 8 }}
                 data-testid="jaz-not-connected">
                Not connected yet for this entity. Paste a Jaz API key below to begin.
              </p>
            )}

            <form onSubmit={handleConnect}
                  style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 8 }}>
              <label style={{ fontSize: 12, fontWeight: 600 }}>
                Jaz API key {connection && '(paste new to rotate)'}
                <input type="password" className="input"
                       value={apiKey}
                       onChange={(e) => setApiKey(e.target.value)}
                       placeholder="jaz_…"
                       data-testid="jaz-api-key-input"
                       style={{ display: 'block', width: '100%', marginTop: 4,
                                fontFamily: 'ui-monospace, monospace' }} />
              </label>
              <div style={{ display: 'flex', gap: 12 }}>
                <label style={{ flex: 1, fontSize: 12, fontWeight: 600 }}>
                  Jaz organisation id (optional)
                  <input className="input" value={orgId}
                         onChange={(e) => setOrgId(e.target.value)}
                         placeholder="org_…"
                         data-testid="jaz-org-input"
                         style={{ display: 'block', width: '100%', marginTop: 4 }} />
                </label>
                <label style={{ width: 120, fontSize: 12, fontWeight: 600 }}>
                  Base currency
                  <input className="input" value={baseCurrency}
                         maxLength={3}
                         onChange={(e) => setBaseCurrency(e.target.value.toUpperCase())}
                         data-testid="jaz-currency-input"
                         style={{ display: 'block', width: '100%', marginTop: 4 }} />
                </label>
              </div>
              <button type="submit" className="btn btn--primary"
                      disabled={busy || apiKey.length < 16}
                      data-testid="jaz-connect-btn"
                      style={{ alignSelf: 'flex-start' }}>
                {busy ? 'Saving…' : connection ? 'Rotate key' : 'Connect Jaz'}
              </button>
            </form>
          </fieldset>

          {notReady && connection && (
            <fieldset data-testid="jaz-diligence-banner"
                      style={{ ...fieldsetStyle,
                               background: '#fffbeb',
                               border: '1px solid #fcd34d' }}>
              <legend style={{ ...legendStyle, color: '#b45309' }}>Partner diligence pending</legend>
              <p style={{ margin: 0, fontSize: 13, color: '#78350f' }}>
                Jaz endpoint-level API contracts are gated behind partner diligence
                (spec §2). Connections persist + the adapter contract is wired
                end-to-end, but live reads will return <code>not_implemented_yet</code>
                placeholders and writes will fail-safe to the outbox dead-letter queue
                until the contract is published. The moment that lands, only
                <code> core/accounting/jaz_adapter.php</code> changes.
              </p>
            </fieldset>
          )}

          {connection && connection.connection_status === 'active' && (
            <>
              <JazSyncConfigCard
                subTenantId={subTenantId}
                onFlash={setFlash}
              />
              <JazCopyConfigCard
                subTenantId={subTenantId}
                entities={entities}
                onFlash={setFlash}
              />
              <JazSyncNowCard
                subTenantId={subTenantId}
                onFlash={setFlash}
              />
              <JazAccountMappingCard
                subTenantId={subTenantId}
                onFlash={setFlash}
              />
            </>
          )}
        </>
      )}
    </section>
  );
}

/* -------------------------------------------------------------------
   Sync direction picker (per-entity, per-entity-type).
   Mirrors the affordance Zoho Books + QBO already expose so an admin
   can opt each entity TYPE in / out independently.
-------------------------------------------------------------------- */
const JAZ_DIR_META = {
  push:    { label: 'Push',     blurb: 'CoreFlux → Jaz' },
  pull:    { label: 'Pull',     blurb: 'Jaz → CoreFlux' },
  two_way: { label: 'Two-way',  blurb: 'Bidirectional (last-write-wins)' },
  off:     { label: 'Off',      blurb: 'No sync' },
};
const JAZ_ENTITY_LABELS = {
  journal_entries:   'Journal Entries (excludes consolidation + elimination)',
  intercompany:      'Intercompany JEs (per-entity legs)',
  contacts:          'Contacts (Customers + Vendors)',
  invoices:          'Invoices',
  bills:             'Bills',
  payments:          'Payments',
  chart_of_accounts: 'Chart of Accounts',
};

function JazSyncConfigCard({ subTenantId, onFlash }) {
  const [config, setConfig]   = useState(null);
  const [draft,  setDraft]    = useState(null);
  const [busy,   setBusy]     = useState(false);
  const [error,  setError]    = useState(null);
  const [allowedDirs, setAllowedDirs] = useState(Object.keys(JAZ_DIR_META));

  const reload = useCallback(async () => {
    if (!subTenantId) return;
    try {
      const r = await api.get(`/api/accounting.php?action=sync_config&sub_tenant_id=${subTenantId}&provider=jaz`);
      setConfig(r.sync_config || {});
      setDraft(null);
      if (Array.isArray(r.allowed_directions)) setAllowedDirs(r.allowed_directions);
    } catch (e) { setError(e.message || 'Failed to load'); }
  }, [subTenantId]);
  useEffect(() => { reload(); }, [reload]);

  const current = draft ?? config ?? {};
  const dirty   = draft !== null && JSON.stringify(draft) !== JSON.stringify(config || {});

  const save = async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/accounting.php?action=sync_config_set&provider=jaz', {
        sub_tenant_id: subTenantId,
        sync_config:   current,
      });
      setConfig(r.sync_config || current);
      setDraft(null);
      onFlash?.({ kind: 'success', msg: 'Sync settings saved for this entity.' });
    } catch (e) { setError(e.message || 'Save failed'); }
    finally     { setBusy(false); }
  };

  if (!config) return null;

  return (
    <fieldset style={fieldsetStyle} data-testid="jaz-sync-config-card">
      <legend style={legendStyle}>Step 3 — Sync direction (per entity-type)</legend>
      <p style={{ margin: '0 0 12px', fontSize: 12, color: '#64748b' }}>
        Pick which kinds of records flow to / from Jaz. <strong>Consolidation and elimination
        JEs never sync</strong> — those live in CoreFlux only. Intercompany JEs <em>do</em> sync
        from each leg's own books to Jaz; toggle them on the dedicated row below.
      </p>
      <table data-testid="jaz-sync-config-table" style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' }}>
            <th style={{ padding: '6px 4px' }}>Entity type</th>
            <th style={{ padding: '6px 4px' }}>Direction</th>
            <th style={{ padding: '6px 4px' }}>Behaviour</th>
          </tr>
        </thead>
        <tbody>
          {Object.keys(JAZ_ENTITY_LABELS).map((entity) => {
            const dir = current[entity] || 'off';
            const meta = JAZ_DIR_META[dir] || JAZ_DIR_META.off;
            // chart_of_accounts is now bi-directional capable — operator
            // can push CoreFlux's CoA to Jaz (creating any missing accounts
            // upstream) and pull mappings back at the same time.
            // (Restriction lifted 2026-02 per Kunal's direction.)
            const allowed = allowedDirs;
            return (
              <tr key={entity} data-testid={`jaz-sync-row-${entity}`} style={{ borderBottom: '1px solid #f1f5f9' }}>
                <td style={{ padding: '8px 4px', fontWeight: 500 }}>{JAZ_ENTITY_LABELS[entity]}</td>
                <td style={{ padding: '8px 4px' }}>
                  <select
                    value={dir}
                    onChange={(e) => setDraft({ ...(draft ?? config), [entity]: e.target.value })}
                    data-testid={`jaz-sync-dir-${entity}`}
                    className="input"
                    style={{ padding: '4px 8px', fontSize: 13, minWidth: 110 }}
                  >
                    {allowed.map((k) => (
                      <option key={k} value={k}>{JAZ_DIR_META[k]?.label ?? k}</option>
                    ))}
                  </select>
                </td>
                <td style={{ padding: '8px 4px', color: '#64748b' }}>{meta.blurb}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {error && <p className="error" style={{ marginTop: 8, fontSize: 12 }} data-testid="jaz-sync-config-error">{error}</p>}
      <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
        <button type="button" className="btn btn--primary" onClick={save}
                disabled={!dirty || busy}
                data-testid="jaz-sync-config-save">
          {busy ? 'Saving…' : 'Save settings'}
        </button>
        {dirty && (
          <button type="button" className="btn" onClick={() => setDraft(null)}
                  disabled={busy} data-testid="jaz-sync-config-reset">
            Discard changes
          </button>
        )}
      </div>
    </fieldset>
  );
}

/* -------------------------------------------------------------------
   Copy sync config from another entity. Hidden when only one entity
   is visible. Replaces this entity's sync_config + account mappings
   with the source entity's in a single click.
-------------------------------------------------------------------- */
function JazCopyConfigCard({ subTenantId, entities, onFlash }) {
  const [copyFrom, setCopyFrom] = useState('');
  const [busy, setBusy]         = useState(false);
  const otherEntities = (entities || []).filter(e => Number(e.id) !== Number(subTenantId));
  if (otherEntities.length === 0) return null;

  const doCopy = async () => {
    const from = Number(copyFrom);
    if (!from || from === Number(subTenantId)) {
      onFlash?.({ kind: 'error', msg: 'Pick a different source entity first.' });
      return;
    }
    const srcName = otherEntities.find(e => Number(e.id) === from)?.name || `entity #${from}`;
    if (!window.confirm(`Replace this entity's Jaz sync settings + account mappings with those from "${srcName}"? Existing settings on this entity will be overwritten.`)) {
      return;
    }
    setBusy(true);
    try {
      const r = await api.post('/api/accounting.php?action=sync_config_copy&provider=jaz', {
        from_sub_tenant_id: from,
        to_sub_tenant_id:   subTenantId,
        include_account_mappings: true,
        overwrite_existing:       true,
      });
      onFlash?.({
        kind: 'success',
        msg:  `Copied: sync config replaced · ${r.mappings_copied} account mappings imported · ${r.mappings_skipped} skipped.`,
      });
      setCopyFrom('');
    } catch (e) {
      onFlash?.({ kind: 'error', msg: e.message || String(e) });
    } finally { setBusy(false); }
  };

  return (
    <fieldset style={fieldsetStyle} data-testid="jaz-copy-config-card">
      <legend style={legendStyle}>Copy sync config from another entity</legend>
      <p style={{ margin: '0 0 8px', fontSize: 12, color: '#64748b' }}>
        Tenants with multiple legal entities usually want identical
        Jaz sync rules across all of them. This clones the source
        entity's sync direction matrix <em>and</em> its account
        mappings into THIS entity in one click. Existing settings on
        the current entity are overwritten.
      </p>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        <select
          value={copyFrom}
          onChange={(e) => setCopyFrom(e.target.value)}
          className="input"
          style={{ fontSize: 13, padding: '4px 8px', minWidth: 240 }}
          data-testid="jaz-copy-from-select"
        >
          <option value="">— pick source entity —</option>
          {otherEntities.map(e => (
            <option key={e.id} value={e.id}>{e.name}{e.kind === 'parent' ? ' (master)' : ''}</option>
          ))}
        </select>
        <button type="button"
                className="btn"
                onClick={doCopy}
                disabled={busy || !copyFrom}
                data-testid="jaz-copy-config-btn">
          {busy ? 'Copying…' : 'Copy settings'}
        </button>
      </div>
    </fieldset>
  );
}


/* -------------------------------------------------------------------
   Manual sync trigger.  Runs the currently-configured sync_config
   immediately (no cron wait).  Surfaces a per-entity result strip
   so the operator can see exactly what got pulled / pushed.

   For chart_of_accounts:
     · pull    → refreshes the Jaz → CoreFlux mapping (auto-map by code)
     · push    → creates the missing accounts on Jaz from CoreFlux's CoA
     · two_way → does both, pull-side first to dedupe by code

   Other entity types flow via the async Command Service outbox; this
   card surfaces a pointer to where to view them rather than re-running
   them inline (which would race with the cron worker).
-------------------------------------------------------------------- */
function JazSyncNowCard({ subTenantId, onFlash }) {
  const [busy,       setBusy]       = useState(false);
  const [result,     setResult]     = useState(null);
  const [error,      setError]      = useState(null);
  // Map of coreflux_account_id → { provider_id, name } once the operator
  // resolves an unmapped row via the inline dropdown.  Keeps the
  // resolved rows in place but greyed-out so the operator sees their
  // progress without losing the original telemetry context.
  const [mappedNow,  setMappedNow]  = useState({});
  // Tracks rows the operator removed/deactivated inline so they fade
  // out of the resolver but the request envelope is preserved for
  // re-runs.  Map: coreflux_account_id → 'deleted' | 'deactivated'.
  const [removedNow, setRemovedNow] = useState({});
  const [savingId,   setSavingId]   = useState(null);

  const saveMapping = async (cfId, cfName, providerOption) => {
    if (!cfId || !providerOption?.provider_id) return;
    setSavingId(cfId);
    try {
      const body = {
        sub_tenant_id:         subTenantId,
        coreflux_account_id:   cfId,
        provider_account_id:   providerOption.provider_id,
        provider_account_code: providerOption.code   || '',
        provider_account_name: providerOption.name   || '',
        provider_account_type: providerOption.type   || '',
        source:                'manual',
        confidence:            100,
      };
      await api.post('/api/accounting.php?action=account_mapping_save&provider=jaz', body);
      setMappedNow((prev) => ({
        ...prev,
        [cfId]: { provider_id: providerOption.provider_id, name: providerOption.name },
      }));
      onFlash?.({
        kind: 'success',
        msg:  `Mapped "${cfName}" → "${providerOption.name}" (source=manual, confidence=100). Visible in Step 4.`,
      });
    } catch (e) {
      onFlash?.({
        kind: 'error',
        msg:  `Failed to save mapping for "${cfName}": ${e.message || 'unknown error'}`,
      });
    } finally { setSavingId(null); }
  };

  // Hard-delete or soft-deactivate a CF account from the CoA.  Plaid /
  // imports occasionally seed rows the operator didn't actually want
  // in the chart (e.g. one row per bank sub-account) — this lets them
  // sweep without leaving the sync screen.
  const removeAccount = async (cfId, cfName, mode /* 'delete' | 'deactivate' */) => {
    if (!cfId) return;
    const confirmMsg = mode === 'delete'
      ? `Permanently delete "${cfName}" from the Chart of Accounts?\n\nThis will also drop any provider mappings tied to it.\n\nWe will refuse if the account has posted journal lines or backs an active bank feed.`
      : `Hide "${cfName}" from active-account pickers (active=0)?\n\nLedger history is preserved.`;
    // eslint-disable-next-line no-alert
    if (!window.confirm(confirmMsg)) return;
    setSavingId(cfId);
    try {
      const action = mode === 'delete' ? 'account_delete' : 'account_deactivate';
      await api.post(`/api/accounting.php?action=${action}&provider=jaz`, {
        coreflux_account_id: cfId,
      });
      setRemovedNow((prev) => ({ ...prev, [cfId]: mode === 'delete' ? 'deleted' : 'deactivated' }));
      onFlash?.({
        kind: 'success',
        msg:  mode === 'delete'
          ? `Removed "${cfName}" from the Chart of Accounts.`
          : `Deactivated "${cfName}" — no longer shown in active-account pickers.`,
      });
    } catch (e) {
      // 409 means the account has references; fall back to offering
      // soft-deactivate instead so the operator isn't stuck.
      const status = e?.status ?? e?.response?.status;
      const msg    = e?.message || 'unknown error';
      if (status === 409 && mode === 'delete') {
        // eslint-disable-next-line no-alert
        const fallback = window.confirm(
          `Cannot delete "${cfName}" — ${msg}\n\nDeactivate instead?`
        );
        if (fallback) { await removeAccount(cfId, cfName, 'deactivate'); return; }
      }
      onFlash?.({
        kind: 'error',
        msg:  `Failed to ${mode} "${cfName}": ${msg}`,
      });
    } finally { setSavingId(null); }
  };

  const runSync = async (entityTypes = null) => {
    if (!subTenantId) return;
    setBusy(true); setError(null); setResult(null); setMappedNow({}); setRemovedNow({});
    try {
      const payload = { sub_tenant_id: subTenantId };
      if (entityTypes) payload.entity_types = entityTypes;
      const r = await api.post('/api/accounting.php?action=sync_now&provider=jaz', payload);
      setResult(r);
      const coa  = r?.results?.chart_of_accounts;
      const pull = coa?.pull?.mapped         ?? 0;
      const push = coa?.push?.pushed         ?? 0;
      const skp  = coa?.push?.skipped_existing ?? 0;
      const errs = (coa?.push?.errors?.length || 0) + (coa?.pull?.error ? 1 : 0);
      if (errs > 0) {
        // Operators were getting a success-coloured "Synced." banner even
        // when every push failed. Flip the flash to a warning so they
        // realise the run had errors and scroll to the expandable details.
        onFlash?.({
          kind: 'error',
          msg: `Sync finished with issues. CoA · ${pull} mapped · ${push} pushed (${skp} already existed) · ${errs} error${errs === 1 ? '' : 's'} — expand the row below for details.`,
        });
      } else if (pull === 0 && push === 0 && skp === 0) {
        // No errors, but also no work done — surface that politely so
        // the operator scrolls down to the auto-map telemetry block
        // instead of trusting the silent green banner.
        onFlash?.({
          kind: 'info',
          msg:  `Sync finished with no changes. CoA · 0 mapped · 0 pushed — open "auto-map telemetry" below to see which CoreFlux rows didn't match a Jaz account.`,
        });
      } else {
        onFlash?.({
          kind: 'success',
          msg:  `Synced. CoA · ${pull} mapped from Jaz · ${push} pushed to Jaz (${skp} already existed).`,
        });
      }
    } catch (e) {
      setError(e.message || 'Sync failed');
    } finally { setBusy(false); }
  };

  return (
    <fieldset style={fieldsetStyle} data-testid="jaz-sync-now-card">
      <legend style={legendStyle}>Step 3b — Sync now</legend>
      <p style={{ margin: 0, color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        Run the configured sync immediately instead of waiting for the cron worker.
        Chart-of-accounts entries flow inline (pull, push, or both depending on the
        direction picker above).  Bills / Invoices / Payments / Journals flow via the
        Command Service async outbox — this button surfaces a pointer to
        <code> /api/accounting.php?action=command_status</code> for those.
      </p>
      <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
        <button
          onClick={() => runSync(null)}
          disabled={busy || !subTenantId}
          className="btn btn--primary"
          data-testid="jaz-sync-now-all"
        >
          {busy ? 'Syncing…' : 'Sync everything now'}
        </button>
        <button
          onClick={() => runSync(['chart_of_accounts'])}
          disabled={busy || !subTenantId}
          className="btn btn--ghost"
          data-testid="jaz-sync-now-coa"
        >
          {busy ? 'Syncing…' : 'CoA only'}
        </button>
      </div>
      {error && (
        <p data-testid="jaz-sync-now-error" style={{ marginTop: 10, color: '#b94a4a', fontSize: 13 }}>
          {error}
        </p>
      )}
      {result?.results && (
        <table data-testid="jaz-sync-now-results" style={{ width: '100%', marginTop: 12, fontSize: 12, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)' }}>
              <th style={{ padding: '4px 6px' }}>Entity</th>
              <th style={{ padding: '4px 6px' }}>Direction</th>
              <th style={{ padding: '4px 6px' }}>Outcome</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(result.results).map(([entity, r]) => {
              let outcome = '';
              const errorList = [];
              const infoList  = [];
              if (entity === 'chart_of_accounts') {
                const pull = r.pull?.mapped ?? null;
                const push = r.push?.pushed ?? null;
                const skp  = r.push?.skipped_existing ?? null;
                const pushErrs = Array.isArray(r.push?.errors) ? r.push.errors : [];
                const errs = pushErrs.length + (r.pull?.error ? 1 : 0);
                outcome = [
                  pull !== null ? `pull: ${pull} mapped` : null,
                  push !== null ? `push: ${push} created${skp ? ` (${skp} skipped)` : ''}` : null,
                  errs ? `${errs} error${errs === 1 ? '' : 's'}` : null,
                ].filter(Boolean).join(' · ') || 'no-op';
                // Carry the per-row errors out for the expandable detail block
                // so operators can finally see WHY each account failed instead
                // of just a count.
                for (const er of pushErrs.slice(0, 25)) {
                  errorList.push({
                    code: er.code || er.coreflux_account_code || `acct ${er.coreflux_account_id ?? '?'}`,
                    error: er.error || 'unknown error',
                  });
                }
                if (r.pull?.error) {
                  errorList.push({ code: 'pull', error: r.pull.error });
                }
                // Pull-side telemetry — show the operator WHAT happened
                // when the count is non-actionable (e.g. "pull: 0 mapped"
                // with no error).  This is the only way they can see
                // that the auto-mapper compared N CF accounts against M
                // provider rows and found zero matches.
                const pullR = r.pull;
                if (pullR && pullR.cf_unmapped_count !== undefined) {
                  const parts = [];
                  parts.push(`CoreFlux unmapped: ${pullR.cf_unmapped_count}`);
                  parts.push(`Jaz CoA rows: ${pullR.provider_row_count ?? '?'}`);
                  parts.push(`matched by code: ${pullR.matched_by_code ?? 0}`);
                  parts.push(`matched by name: ${pullR.matched_by_name ?? 0}`);
                  parts.push(`no match: ${pullR.no_provider_match ?? 0}`);
                  if (pullR.note) parts.push(`note: ${pullR.note}`);
                  infoList.push({ heading: 'Auto-map summary', lines: parts });
                  // Note: the unmapped_sample block is rendered separately
                  // below (NOT in infoList) so we can include an inline
                  // <select> dropdown per row.  Keeping it out of the
                  // string-only `lines` array lets that renderer stay
                  // simple.
                }
              } else {
                outcome = r.note || 'queued via outbox';
              }
              return (
                <React.Fragment key={entity}>
                  <tr data-testid={`jaz-sync-row-${entity}`} style={{ borderTop: '1px solid var(--cf-border, #eee)' }}>
                    <td style={{ padding: '4px 6px' }}>{entity}</td>
                    <td style={{ padding: '4px 6px' }}>{r.direction}</td>
                    <td style={{ padding: '4px 6px' }}>{outcome}</td>
                  </tr>
                  {errorList.length > 0 && (
                    <tr data-testid={`jaz-sync-errors-${entity}`}>
                      <td colSpan={3} style={{ padding: '4px 6px 10px 6px', background: '#fef2f2' }}>
                        <details>
                          <summary style={{ cursor: 'pointer', color: '#b91c1c', fontWeight: 600, fontSize: 12 }}>
                            Show {errorList.length} error detail{errorList.length === 1 ? '' : 's'}
                          </summary>
                          <ul style={{ margin: '6px 0 0 18px', padding: 0, fontSize: 11, color: '#7f1d1d' }}>
                            {errorList.map((er, i) => (
                              <li key={i} style={{ marginBottom: 2 }}
                                  data-testid={`jaz-sync-error-${entity}-${i}`}>
                                <strong>{er.code}</strong>: {er.error}
                              </li>
                            ))}
                          </ul>
                        </details>
                      </td>
                    </tr>
                  )}
                  {infoList.length > 0 && (
                    <tr data-testid={`jaz-sync-info-${entity}`}>
                      <td colSpan={3} style={{ padding: '4px 6px 10px 6px', background: '#f0f9ff' }}>
                        <details open={(r.pull?.mapped ?? 0) === 0 && (r.pull?.cf_unmapped_count ?? 0) > 0}>
                          <summary style={{ cursor: 'pointer', color: '#1e40af', fontWeight: 600, fontSize: 12 }}>
                            Show auto-map telemetry
                          </summary>
                          {infoList.map((block, bi) => (
                            <div key={bi} style={{ marginTop: 8 }}>
                              <div style={{ fontSize: 11, fontWeight: 600, color: '#1e3a8a', marginBottom: 4 }}>
                                {block.heading}
                              </div>
                              <ul style={{ margin: '0 0 0 18px', padding: 0, fontSize: 11, color: '#1e3a8a' }}>
                                {block.lines.map((ln, li) => (
                                  <li key={li} style={{ marginBottom: 2 }}
                                      data-testid={`jaz-sync-info-${entity}-${bi}-${li}`}>{ln}</li>
                                ))}
                              </ul>
                            </div>
                          ))}
                          {entity === 'chart_of_accounts'
                            && Array.isArray(r.pull?.unmapped_sample)
                            && r.pull.unmapped_sample.length > 0 && (
                            <div style={{ marginTop: 10 }} data-testid="jaz-sync-unmapped-resolver">
                              <div style={{ fontSize: 11, fontWeight: 600, color: '#1e3a8a', marginBottom: 4 }}>
                                Map unmapped CoreFlux accounts (first {r.pull.unmapped_sample.length}) — pick a Jaz row to lock the mapping inline:
                              </div>
                              <table style={{ width: '100%', fontSize: 11, borderCollapse: 'collapse' }}>
                                <tbody>
                                  {r.pull.unmapped_sample.map((u, ui) => {
                                    const cfId   = u.coreflux_account_id;
                                    const opts   = r.pull.provider_options || [];
                                    const done   = mappedNow[cfId];
                                    const gone   = removedNow[cfId];
                                    const saving = savingId === cfId;
                                    return (
                                      <tr key={cfId || ui}
                                          data-testid={`jaz-sync-unmapped-row-${ui}`}
                                          style={{
                                            borderTop: '1px dashed #cbd5e1',
                                            opacity: gone ? 0.5 : 1,
                                          }}>
                                        <td style={{ padding: '4px 6px', color: '#1e3a8a', whiteSpace: 'nowrap' }}>
                                          <strong>{u.code ? `${u.code} · ` : ''}{u.name}</strong>
                                          <span style={{ marginLeft: 6, color: '#64748b' }}>(norm: <code>{u.normalized}</code>)</span>
                                        </td>
                                        <td style={{ padding: '4px 6px', textAlign: 'right' }}>
                                          {gone ? (
                                            <span style={{ color: '#64748b', fontStyle: 'italic' }}
                                                  data-testid={`jaz-sync-unmapped-removed-${ui}`}>
                                              {gone === 'deleted' ? '✓ Removed from CoA' : '✓ Deactivated'}
                                            </span>
                                          ) : done ? (
                                            <span style={{ color: '#047857', fontWeight: 600 }}
                                                  data-testid={`jaz-sync-unmapped-mapped-${ui}`}>
                                              ✓ Mapped → {done.name}
                                            </span>
                                          ) : (
                                            <div style={{ display: 'inline-flex', gap: 6, alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                                              <select
                                                data-testid={`jaz-sync-unmapped-select-${ui}`}
                                                disabled={saving}
                                                defaultValue=""
                                                onChange={(e) => {
                                                  const pid = e.target.value;
                                                  if (!pid) return;
                                                  const picked = opts.find((o) => o.provider_id === pid);
                                                  if (!picked) return;
                                                  saveMapping(cfId, u.name, picked);
                                                }}
                                                style={{
                                                  fontSize: 11, padding: '2px 6px',
                                                  border: '1px solid #93c5fd', borderRadius: 4,
                                                  background: 'white', maxWidth: 280,
                                                }}>
                                                <option value="">
                                                  {saving ? 'Saving…' : `Map this to… (${opts.length} Jaz rows)`}
                                                </option>
                                                {opts.map((o) => (
                                                  <option key={o.provider_id} value={o.provider_id}>
                                                    {o.name}{o.subtype ? ` · ${o.subtype}` : ''}{o.type ? ` (${o.type})` : ''}
                                                  </option>
                                                ))}
                                              </select>
                                              <button
                                                type="button"
                                                disabled={saving || !cfId}
                                                onClick={() => removeAccount(cfId, u.name, 'delete')}
                                                data-testid={`jaz-sync-unmapped-remove-${ui}`}
                                                title="Permanently delete this CF account from the Chart of Accounts (only if no posted journal lines and no active bank feed)."
                                                style={{
                                                  fontSize: 11, padding: '2px 8px',
                                                  border: '1px solid #fca5a5', borderRadius: 4,
                                                  background: 'white', color: '#b91c1c',
                                                  cursor: saving ? 'wait' : 'pointer',
                                                }}>
                                                Remove
                                              </button>
                                            </div>
                                          )}
                                        </td>
                                      </tr>
                                    );
                                  })}
                                </tbody>
                              </table>
                            </div>
                          )}
                        </details>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              );
            })}
          </tbody>
        </table>
      )}
    </fieldset>
  );
}


/* -------------------------------------------------------------------
   Account mapping table — Jaz mapping like Zoho Books / QBO have.
-------------------------------------------------------------------- */
function JazAccountMappingCard({ subTenantId, onFlash }) {
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
      const r = await api.get(`/api/accounting.php?action=account_mappings&sub_tenant_id=${subTenantId}&provider=jaz`);
      setMappings(r.mappings || []);
      setUnmapped(r.unmapped || []);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally     { setLoading(false); }
  }, [subTenantId]);
  useEffect(() => { reload(); }, [reload]);

  const handleAutoMap = async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/accounting.php?action=account_mapping_auto&provider=jaz', {
        sub_tenant_id: subTenantId,
      });
      const created = r.mapped ?? r.new_mappings?.length ?? 0;
      const noMatch = r.no_provider_match ?? 0;
      onFlash?.({
        kind: created > 0 ? 'success' : 'error',
        msg:  `Auto-map: ${created} mapped · ${noMatch} CoreFlux accounts had no match in Jaz.`,
      });
      reload();
    } catch (e) {
      setError(e.message || 'Auto-map failed');
    } finally { setBusy(false); }
  };

  const handleDelete = async (mappingId) => {
    if (!confirm('Remove this mapping? Unmapped accounts can still post in CoreFlux but won\'t sync to Jaz.')) return;
    setBusy(true);
    try {
      await api.post('/api/accounting.php?action=account_mapping_delete&provider=jaz', {
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
      await api.post('/api/accounting.php?action=account_mapping_save&provider=jaz', {
        sub_tenant_id:        subTenantId,
        coreflux_account_id:  addRow.coreflux_account_id,
        provider_account_id:  addRow.provider_account_id,
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
    <fieldset style={fieldsetStyle} data-testid="jaz-account-mapping-card">
      <legend style={legendStyle}>Step 4 — Account mapping</legend>
      <p style={{ margin: '0 0 12px', fontSize: 12, color: '#64748b' }}>
        Map each CoreFlux account to a Jaz account. When sync direction
        is push or two-way, the outbox uses this map to render the
        destination payload. <strong>Auto-map by code</strong> fills in
        any exact-code matches Jaz currently exposes.
      </p>

      <div style={{ marginBottom: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
        <button type="button" className="btn" onClick={handleAutoMap}
                disabled={busy || loading}
                data-testid="jaz-account-mapping-automap">
          {busy ? 'Auto-mapping…' : 'Auto-map by code'}
        </button>
        <button type="button" className="btn"
                onClick={() => setAddRow({ coreflux_account_id: '', provider_account_id: '' })}
                disabled={busy || unmapped.length === 0}
                data-testid="jaz-account-mapping-add">
          + Add mapping
        </button>
        <span style={{ fontSize: 12, color: '#64748b' }}>
          {mappings.length} mapped · {unmapped.length} unmapped
        </span>
      </div>

      {error && <p className="error" style={{ fontSize: 12 }} data-testid="jaz-account-mapping-error">{error}</p>}

      {addRow && (
        <form onSubmit={handleSaveAdd}
              data-testid="jaz-account-mapping-add-form"
              style={{ marginBottom: 12, padding: 10, background: '#f8fafc',
                       border: '1px solid #e2e8f0', borderRadius: 6,
                       display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'flex-end' }}>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 200px' }}>
            CoreFlux account
            <select className="input" value={addRow.coreflux_account_id}
                    onChange={(e) => setAddRow({ ...addRow, coreflux_account_id: e.target.value })}
                    data-testid="jaz-mapping-add-cf-select"
                    style={{ display: 'block', width: '100%', marginTop: 4 }}
                    required>
              <option value="">— pick unmapped account —</option>
              {unmapped.map(a => (
                <option key={a.id} value={a.id}>{a.code} · {a.name}</option>
              ))}
            </select>
          </label>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 180px' }}>
            Jaz account id (resourceId)
            <input className="input" value={addRow.provider_account_id}
                   onChange={(e) => setAddRow({ ...addRow, provider_account_id: e.target.value })}
                   placeholder="acct_…"
                   data-testid="jaz-mapping-add-provider-id"
                   style={{ display: 'block', width: '100%', marginTop: 4 }} required />
          </label>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 110px' }}>
            Jaz code
            <input className="input" value={addRow.provider_account_code || ''}
                   onChange={(e) => setAddRow({ ...addRow, provider_account_code: e.target.value })}
                   placeholder="1100"
                   data-testid="jaz-mapping-add-provider-code"
                   style={{ display: 'block', width: '100%', marginTop: 4 }} />
          </label>
          <label style={{ fontSize: 11, fontWeight: 600, flex: '1 1 200px' }}>
            Jaz name
            <input className="input" value={addRow.provider_account_name || ''}
                   onChange={(e) => setAddRow({ ...addRow, provider_account_name: e.target.value })}
                   placeholder="Accounts Receivable"
                   data-testid="jaz-mapping-add-provider-name"
                   style={{ display: 'block', width: '100%', marginTop: 4 }} />
          </label>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="submit" className="btn btn--primary" disabled={busy}
                    data-testid="jaz-mapping-add-save">Save</button>
            <button type="button" className="btn" onClick={() => setAddRow(null)}
                    data-testid="jaz-mapping-add-cancel">Cancel</button>
          </div>
        </form>
      )}

      {loading ? <p style={{ fontSize: 12 }}>Loading…</p> : (
        <table data-testid="jaz-account-mapping-table"
               style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' }}>
              <th style={{ padding: '6px 4px' }}>CoreFlux</th>
              <th style={{ padding: '6px 4px' }}>Jaz</th>
              <th style={{ padding: '6px 4px' }}>Source</th>
              <th style={{ padding: '6px 4px', width: 60 }}></th>
            </tr>
          </thead>
          <tbody>
            {mappings.length === 0 && (
              <tr><td colSpan={4} style={{ padding: '12px 4px', color: '#94a3b8', fontStyle: 'italic' }}
                      data-testid="jaz-account-mapping-empty">
                No mappings yet. Click "Auto-map by code" to start, or "+ Add mapping" for manual control.
              </td></tr>
            )}
            {mappings.map(m => (
              <tr key={m.id} data-testid={`jaz-mapping-row-${m.id}`} style={{ borderBottom: '1px solid #f1f5f9' }}>
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
                          data-testid={`jaz-mapping-delete-${m.id}`}
                          style={{ fontSize: 11, padding: '2px 6px' }}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </fieldset>
  );
}

const fieldsetStyle = {
  border: '1px solid #e2e8f0', borderRadius: 8,
  padding: '12px 16px 16px', marginBottom: 16,
};
const legendStyle = {
  fontSize: 11, fontWeight: 700, color: '#475569',
  textTransform: 'uppercase', letterSpacing: 0.4,
  padding: '0 6px',
};
const statusCardStyle = {
  padding: 12, background: '#f8fafc',
  border: '1px solid #e2e8f0', borderRadius: 6,
};
