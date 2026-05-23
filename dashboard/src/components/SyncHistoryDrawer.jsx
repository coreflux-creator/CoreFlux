import React, { useState, useMemo } from 'react';
import { api, useApi } from '../lib/api';
import { History, ChevronRight, ChevronDown, X, ArrowRight } from 'lucide-react';

/**
 * Sync History Drawer — slide-out panel listing every content change
 * the syncer has written for a single CoreFlux entity across all
 * source integrations.
 *
 * Backed by /api/integrations/sync_history.php which returns rows from
 * the entity_sync_history table populated by mappingUpsert() whenever
 * content_hash drifts.
 *
 * Each row is expandable to show the field-level diff between
 * payload_before and payload_after — keys that changed, with the old
 * value → new value side-by-side. Unchanged keys are hidden so the
 * operator sees signal, not noise.
 *
 * Usage:
 *   <SyncHistoryDrawer entityType="placement" internalId={pl.id} />
 *
 * The component owns its own open/closed state; renders a header
 * button "Sync history" that opens the drawer. Used on PlacementDetail,
 * PersonDetail, and Company detail.
 */
const SOURCE_LABEL = {
  jobdiva: 'JobDiva',
  bullhorn: 'Bullhorn',
  quickbooks: 'QuickBooks',
  zoho_books: 'Zoho Books',
  airtable: 'Airtable',
};

/**
 * Compute the per-key diff between two payload objects, returning an
 * array of {key, before, after} entries for keys whose values
 * differ. Object equality uses JSON stringification — exact enough for
 * sync payloads (which are themselves JSON), and avoids dragging in a
 * deep-equal dependency.
 */
function diffPayloads(before, after) {
  const a = before || {};
  const b = after  || {};
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  const changes = [];
  for (const k of keys) {
    const av = a[k];
    const bv = b[k];
    const as = JSON.stringify(av);
    const bs = JSON.stringify(bv);
    if (as !== bs) changes.push({ key: k, before: av, after: bv });
  }
  // Stable sort — alphabetical so the operator can scan consistently
  // across multiple sync rows.
  changes.sort((x, y) => x.key.localeCompare(y.key));
  return changes;
}

/**
 * Render a single payload value in a compact, readable form. Strings
 * are quoted, nulls are dimmed, objects are JSON-stringified inline
 * for short ones (≤ 60 chars) or replaced with a "[object]" marker
 * otherwise so the row doesn't blow out.
 */
function PayloadValue({ value, dim }) {
  let display;
  if (value === null || value === undefined) {
    display = '∅';
  } else if (typeof value === 'string') {
    display = `"${value}"`;
  } else if (typeof value === 'number' || typeof value === 'boolean') {
    display = String(value);
  } else {
    const s = JSON.stringify(value);
    display = s.length <= 60 ? s : `[${Array.isArray(value) ? 'array' : 'object'} ${s.length}b]`;
  }
  return (
    <span style={{
      fontFamily: 'ui-monospace, SFMono-Regular, monospace',
      fontSize: 11,
      color: dim ? '#94a3b8' : '#0f172a',
      background: dim ? '#f8fafc' : '#fef9c3',
      padding: '1px 5px', borderRadius: 4,
      maxWidth: 280, overflow: 'hidden', textOverflow: 'ellipsis',
      whiteSpace: 'nowrap', display: 'inline-block',
    }}>{display}</span>
  );
}

function HistoryRow({ row }) {
  const [open, setOpen] = useState(false);
  const changes = useMemo(() => diffPayloads(row.payload_before, row.payload_after), [row]);
  const sourceLabel = SOURCE_LABEL[row.source_system] || row.source_system;
  const actorLabel = row.actor?.email || (row.actor_user_id ? `User #${row.actor_user_id}` : 'system (cron)');

  return (
    <div
      data-testid={`sync-history-row-${row.id}`}
      style={{ borderBottom: '1px solid #e2e8f0', padding: '10px 14px' }}
    >
      <button
        onClick={() => setOpen(o => !o)}
        data-testid={`sync-history-row-toggle-${row.id}`}
        aria-expanded={open}
        style={{
          width: '100%', background: 'transparent', border: 'none', cursor: 'pointer',
          textAlign: 'left', padding: 0, display: 'flex', alignItems: 'center', gap: 8,
        }}
      >
        {open ? <ChevronDown size={14} color="#64748b" /> : <ChevronRight size={14} color="#64748b" />}
        <span style={{ fontWeight: 600, fontSize: 13 }}>{sourceLabel}</span>
        <span style={{
          fontSize: 11, background: '#eef2ff', color: '#4338ca',
          padding: '1px 6px', borderRadius: 999, fontWeight: 600,
        }}>
          {changes.length} field{changes.length === 1 ? '' : 's'} changed
        </span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 11, color: '#64748b' }}>{row.created_at}</span>
      </button>
      <div style={{ fontSize: 11, color: '#64748b', marginLeft: 22, marginTop: 2 }}>
        by <span data-testid={`sync-history-actor-${row.id}`}>{actorLabel}</span>
        {' · '}
        external id <code>{row.external_id}</code>
      </div>

      {open && (
        <div data-testid={`sync-history-diff-${row.id}`} style={{ marginTop: 8, marginLeft: 22 }}>
          {changes.length === 0 && (
            <p style={{ color: '#94a3b8', fontSize: 12, fontStyle: 'italic' }}>
              No field-level diff available (this is the first recorded snapshot).
            </p>
          )}
          {changes.length > 0 && (
            <table style={{ width: '100%', fontSize: 12 }}>
              <thead>
                <tr style={{ color: '#64748b', textAlign: 'left' }}>
                  <th style={{ padding: '4px 6px', fontWeight: 600 }}>Field</th>
                  <th style={{ padding: '4px 6px', fontWeight: 600 }}>Before</th>
                  <th style={{ padding: '4px 6px', fontWeight: 600 }}></th>
                  <th style={{ padding: '4px 6px', fontWeight: 600 }}>After</th>
                </tr>
              </thead>
              <tbody>
                {changes.map(c => (
                  <tr key={c.key} data-testid={`sync-history-diff-row-${row.id}-${c.key}`}>
                    <td style={{ padding: '3px 6px', fontFamily: 'ui-monospace, monospace', fontSize: 11 }}>
                      {c.key}
                    </td>
                    <td style={{ padding: '3px 6px' }}><PayloadValue value={c.before} dim /></td>
                    <td style={{ padding: '3px 6px', color: '#94a3b8' }}><ArrowRight size={11} /></td>
                    <td style={{ padding: '3px 6px' }}><PayloadValue value={c.after} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}

export default function SyncHistoryDrawer({ entityType, internalId }) {
  const [open, setOpen] = useState(false);
  const [migrating, setMigrating] = useState(false);
  const [migrateMsg, setMigrateMsg] = useState(null);
  const url = open && entityType && internalId
    ? `/api/integrations/sync_history.php?entity_type=${encodeURIComponent(entityType)}&internal_id=${encodeURIComponent(internalId)}&limit=50`
    : null;
  const { data, loading, error, reload } = useApi(url);
  const rows = data?.rows || [];
  const migrationPending = !!data?.migration_pending;

  const runMigration = async () => {
    setMigrating(true); setMigrateMsg(null);
    try {
      const r = await api.post('/api/admin/migrate.php');
      const errs = (r.status?.errors || []).length;
      setMigrateMsg(errs === 0 ? 'Migrations applied. Reloading…' : `Applied with ${errs} error(s).`);
      if (reload) reload();
    } catch (e) {
      setMigrateMsg('Failed: ' + (e.message || e));
    } finally {
      setMigrating(false);
    }
  };

  return (
    <>
      <button
        onClick={() => setOpen(true)}
        data-testid={`sync-history-open-${entityType}-${internalId}`}
        className="btn btn--ghost"
        style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12 }}
      >
        <History size={12} /> Sync history
      </button>

      {open && (
        <div
          data-testid={`sync-history-drawer-${entityType}-${internalId}`}
          onClick={() => setOpen(false)}
          style={{
            position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)',
            zIndex: 9998, display: 'flex', justifyContent: 'flex-end',
          }}
        >
          <aside
            onClick={(e) => e.stopPropagation()}
            style={{
              background: '#fff', width: 'min(640px, 100vw)', height: '100%',
              display: 'flex', flexDirection: 'column', boxShadow: '-8px 0 24px rgba(0,0,0,0.12)',
            }}
          >
            <header style={{
              padding: '14px 18px', borderBottom: '1px solid #e2e8f0',
              display: 'flex', alignItems: 'center', gap: 8,
            }}>
              <History size={16} color="#475569" />
              <strong style={{ flex: 1 }}>Sync history</strong>
              <button
                onClick={() => setOpen(false)}
                data-testid="sync-history-close"
                style={{ background: 'transparent', border: 'none', cursor: 'pointer', color: '#64748b' }}
              >
                <X size={18} />
              </button>
            </header>
            <p style={{ padding: '8px 18px 0', margin: 0, fontSize: 12, color: '#64748b' }}>
              Every sync that changed at least one field for this record. Click a row to see the field-level diff.
            </p>

            <div style={{ flex: 1, overflowY: 'auto' }}>
              {loading && <p data-testid="sync-history-loading" style={{ padding: 18, color: '#64748b' }}>Loading…</p>}
              {error && <p className="error" data-testid="sync-history-error" style={{ padding: 18 }}>{error.message}</p>}
              {!loading && !error && migrationPending && (
                <div data-testid="sync-history-migration-pending"
                     style={{ padding: 18, color: '#92400e', fontSize: 13,
                              background: '#fef3c7', border: '1px solid #fde68a',
                              borderRadius: 8, margin: 18 }}>
                  <strong>Migration pending.</strong>{' '}
                  The sync-history table hasn't been created yet on this environment.
                  Click below to apply pending migrations (idempotent — safe to retry).
                  <div style={{ marginTop: 8 }}>
                    <button
                      onClick={runMigration}
                      disabled={migrating}
                      data-testid="sync-history-run-migration"
                      className="btn btn--primary"
                      style={{ fontSize: 12 }}
                    >
                      {migrating ? 'Running…' : 'Run pending migrations'}
                    </button>
                    {migrateMsg && (
                      <span data-testid="sync-history-migration-msg"
                            style={{ marginLeft: 12, fontSize: 12 }}>{migrateMsg}</span>
                    )}
                  </div>
                </div>
              )}
              {!loading && !error && !migrationPending && rows.length === 0 && (
                <p data-testid="sync-history-empty" style={{ padding: 18, color: '#64748b', fontSize: 13 }}>
                  No sync changes recorded yet for this record. The history populates on the next sync that
                  actually modifies a field.
                </p>
              )}
              {rows.map(r => <HistoryRow key={r.id} row={r} />)}
            </div>
          </aside>
        </div>
      )}
    </>
  );
}
