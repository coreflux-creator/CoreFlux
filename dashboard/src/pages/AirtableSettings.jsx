import React, { useState, useEffect, useMemo } from 'react';
import { useApi, api } from '../lib/api';
import {
  CheckCircle2, XCircle, RefreshCw, ExternalLink, Save, Trash2,
  Database, Table2, AlertTriangle, Send, Plus, Eye, EyeOff,
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

  const handleSyncNow = async () => {
    setBusy(true); setFlash(null);
    try {
      const r = await api.post('/api/airtable/sync_now.php?action=sync_now', { mapping_id: mapping.id });
      setFlash({
        kind: r.failed > 0 ? 'error' : 'success',
        msg: `Sync: ${r.records} records · ${r.created} created · ${r.updated} updated · ${r.unchanged} unchanged · ${r.failed} failed (${r.pages} page${r.pages === 1 ? '' : 's'}, ${r.latency_ms}ms)`,
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
