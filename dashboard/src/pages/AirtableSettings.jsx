import React, { useState, useEffect, useMemo } from 'react';
import { useApi, api } from '../lib/api';
import {
  CheckCircle2, XCircle, RefreshCw, ExternalLink, Save, Trash2,
  Database, Table2, AlertTriangle, Send, Plus, Eye, EyeOff, Copy, X,
} from 'lucide-react';

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

/* ─────────────────────────────────────────────────────────── mappings */

function MappingEditor({ mappings, entities, directions, busy, setBusy, setFlash, reload }) {
  const [adding, setAdding] = useState(false);
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
        <button
          type="button" className="btn btn--primary" data-testid="airtable-add-mapping-btn"
          onClick={() => setAdding(true)} disabled={busy}
        >
          <Plus size={14} style={{ marginRight: 6 }} />Add mapping
        </button>
      </header>

      {mappings.length === 0 && !adding && (
        <p data-testid="airtable-no-mappings" style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '8px 0' }}>
          No mappings yet. Click <strong>Add mapping</strong> to start syncing a table.
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
    </div>
  );
}

function MappingRow({ mapping, entities, directions, busy, setBusy, setFlash, reload }) {
  const [editing, setEditing] = useState(false);
  const [duplicating, setDuplicating] = useState(false);
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
              onClick={handleSyncNow} disabled={busy || mapping.direction !== 'pull'}
            >
              <Send size={13} style={{ marginRight: 4 }} />Sync now
            </button>
            <button
              type="button" className="btn"
              data-testid={`airtable-relink-${mapping.id}`}
              onClick={handleRelink} disabled={busy}
              title="Re-run the linkage resolver against every existing row for this mapping"
            >
              Relink
            </button>
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
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/airtable/mapping_save.php?action=mapping_save', {
        base_id: baseId, base_name: baseName,
        table_id: tableId, table_name: tableName,
        internal_entity: entity, direction: dir,
        field_map: parsed, primary_field: primary,
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
