import React, { useState, useEffect } from 'react';
import { api } from '../lib/api';
import { X, Search, Link2, Wand2, ExternalLink, ChevronDown, ChevronRight } from 'lucide-react';

/**
 * ReconciliationModal — Slice 4 surface for triaging unmatched (and
 * ambiguous) Airtable vault rows. Per row the operator can:
 *
 *   • Inspect the raw payload (expand toggle).
 *   • Search CoreFlux for an existing row to link to (typeahead via
 *     /api/airtable/search_entities.php).
 *   • Click "Create stub" to spin up a brand-new minimal CoreFlux
 *     entity from the payload (via /api/airtable/create_stub.php).
 *
 * Successful rows fall out of the queue as the operator works through
 * them. Designed for "I have 50 unmatched contacts; let me clear them
 * in one sitting" without context switching.
 */
export default function ReconciliationModal({ mappingId, entity, onClose }) {
  const [status, setStatus] = useState('unmatched'); // 'unmatched' | 'ambiguous'
  const [rows, setRows]     = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState(null);
  const [expanded, setExpanded] = useState({});
  const [flash, setFlash]   = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(
        `/api/airtable/unmatched.php?action=unmatched&mapping_id=${mappingId}&status=${status}&limit=200`
      );
      setRows(r.rows || []);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [mappingId, status]);

  const linkExisting = async (row, internalId) => {
    try {
      await api.post('/api/airtable/link_manual.php?action=link_manual', {
        mapping_row_id:     row.id,
        internal_entity_id: internalId,
      });
      setFlash({ kind: 'ok', msg: `Linked ${row.external_id} → #${internalId}` });
      setRows((rs) => rs.filter((r) => r.id !== row.id));
    } catch (e) {
      setFlash({ kind: 'err', msg: e.message || 'Link failed' });
    }
  };

  const createStub = async (row) => {
    try {
      const r = await api.post('/api/airtable/create_stub.php?action=create_stub', {
        mapping_row_id: row.id,
      });
      setFlash({ kind: 'ok', msg: `Stub created → new ${r.internal_entity} #${r.internal_entity_id}` });
      setRows((rs) => rs.filter((rr) => rr.id !== row.id));
    } catch (e) {
      setFlash({ kind: 'err', msg: e.message || 'Stub failed' });
    }
  };

  return (
    <div
      data-testid="airtable-reconcile-modal"
      role="dialog" aria-modal="true"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        zIndex: 1000, padding: 24,
      }}
    >
      <div style={{
        background: '#fff', borderRadius: 8,
        width: 'min(1000px, 96vw)', maxHeight: '92vh',
        display: 'flex', flexDirection: 'column', overflow: 'hidden',
        boxShadow: '0 20px 50px rgba(0,0,0,0.25)',
      }}>
        <header style={{ padding: '14px 20px', borderBottom: '1px solid #e5e7eb',
                         display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <div style={{ flex: 1 }}>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 600 }}>
              Reconciliation queue
            </h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: '#64748b' }}>
              Triage Airtable vault rows for <code>{entity}</code> that couldn't be linked
              automatically. Search CoreFlux for an existing match, or spin up a stub from the payload.
            </p>
          </div>
          <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <select
              data-testid="airtable-reconcile-status"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              style={{ fontSize: 12, padding: '4px 8px' }}>
              <option value="unmatched">Unmatched</option>
              <option value="ambiguous">Ambiguous</option>
            </select>
            <button type="button" className="btn"
                    data-testid="airtable-reconcile-close" onClick={onClose}>
              <X size={14} />
            </button>
          </div>
        </header>

        {flash && (
          <div data-testid="airtable-reconcile-flash"
               style={{ padding: '6px 20px', fontSize: 12,
                        background: flash.kind === 'ok' ? '#dcfce7' : '#fef2f2',
                        color:      flash.kind === 'ok' ? '#065f46' : '#991b1b' }}>
            {flash.msg}
          </div>
        )}

        <div style={{ flex: 1, overflow: 'auto', padding: '0 20px' }}>
          {loading && <p data-testid="airtable-reconcile-loading">Loading…</p>}
          {error   && <p data-testid="airtable-reconcile-error" style={{ color: '#b91c1c' }}>{error}</p>}
          {!loading && rows.length === 0 && (
            <p data-testid="airtable-reconcile-empty"
               style={{ color: '#64748b', margin: '16px 0' }}>
              The {status} queue is empty for this mapping. Nice work.
            </p>
          )}
          {!loading && rows.length > 0 && (
            <table data-testid="airtable-reconcile-table"
                   style={{ width: '100%', fontSize: 12, marginTop: 10, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#64748b',
                             borderBottom: '1px solid #e5e7eb',
                             position: 'sticky', top: 0, background: '#fff' }}>
                  <th style={{ padding: '6px', width: 28 }}></th>
                  <th style={{ padding: '6px' }}>Airtable Rec</th>
                  <th style={{ padding: '6px' }}>Last seen</th>
                  <th style={{ padding: '6px', minWidth: 360 }}>Action</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <ReconcileRow
                    key={r.id}
                    row={r}
                    entity={entity}
                    expanded={!!expanded[r.id]}
                    onToggle={() => setExpanded((s) => ({ ...s, [r.id]: !s[r.id] }))}
                    onLink={(internalId) => linkExisting(r, internalId)}
                    onStub={() => createStub(r)}
                  />
                ))}
              </tbody>
            </table>
          )}
        </div>

        <footer style={{ padding: '10px 20px', borderTop: '1px solid #e5e7eb',
                         fontSize: 12, color: '#64748b' }}>
          {rows.length} row(s) in queue · entity <code>{entity}</code>
        </footer>
      </div>
    </div>
  );
}

function ReconcileRow({ row, entity, expanded, onToggle, onLink, onStub }) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const externalUrl = (row.payload_snapshot && row.payload_snapshot._airtable_record_url) || null;

  useEffect(() => {
    if (q.trim().length < 2) { setResults([]); return; }
    let cancelled = false;
    const t = setTimeout(async () => {
      setSearching(true);
      try {
        const r = await api.get(
          `/api/airtable/search_entities.php?action=search_entities&entity=${entity}&q=${encodeURIComponent(q.trim())}&limit=8`
        );
        if (!cancelled) setResults(r.rows || []);
      } catch {
        if (!cancelled) setResults([]);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 280);
    return () => { cancelled = true; clearTimeout(t); };
  }, [q, entity]);

  return (
    <>
      <tr data-testid={`airtable-reconcile-row-${row.id}`}
          style={{ borderBottom: '1px solid #f1f5f9' }}>
        <td style={{ padding: '6px', verticalAlign: 'top' }}>
          <button type="button"
                  data-testid={`airtable-reconcile-toggle-${row.id}`}
                  onClick={onToggle}
                  style={{ background: 'transparent', border: 'none', cursor: 'pointer', padding: 0 }}>
            {expanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
          </button>
        </td>
        <td style={{ padding: '6px', fontFamily: 'ui-monospace', verticalAlign: 'top' }}>
          {row.external_id}
          {externalUrl && (
            <a data-testid={`airtable-reconcile-open-${row.id}`}
               href={externalUrl} target="_blank" rel="noopener noreferrer"
               style={{ marginLeft: 4, color: '#4338ca' }}>
              <ExternalLink size={10} />
            </a>
          )}
        </td>
        <td style={{ padding: '6px', fontFamily: 'ui-monospace', color: '#64748b', verticalAlign: 'top' }}>
          {row.last_seen_at}
        </td>
        <td style={{ padding: '6px', verticalAlign: 'top' }}>
          <div style={{ display: 'flex', gap: 6, alignItems: 'flex-start', flexWrap: 'wrap' }}>
            <div style={{ position: 'relative', flex: 1, minWidth: 200 }}>
              <Search size={11} style={{ position: 'absolute', top: 7, left: 6, color: '#94a3b8' }} />
              <input
                type="search"
                data-testid={`airtable-reconcile-search-${row.id}`}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder={`Search ${entity}…`}
                style={{ width: '100%', padding: '4px 6px 4px 22px',
                         border: '1px solid #e5e7eb', borderRadius: 4, fontSize: 12 }}
              />
              {results.length > 0 && (
                <ul data-testid={`airtable-reconcile-results-${row.id}`}
                    style={{ position: 'absolute', top: '100%', left: 0, right: 0,
                             marginTop: 2, background: '#fff', border: '1px solid #e5e7eb',
                             borderRadius: 4, boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
                             listStyle: 'none', padding: 0, zIndex: 5, maxHeight: 220, overflow: 'auto' }}>
                  {results.map((res) => (
                    <li key={res.id}>
                      <button
                        type="button"
                        data-testid={`airtable-reconcile-pick-${row.id}-${res.id}`}
                        onClick={() => { onLink(res.id); setQ(''); setResults([]); }}
                        style={{ width: '100%', textAlign: 'left',
                                 background: 'transparent', border: 'none',
                                 padding: '4px 8px', cursor: 'pointer', fontSize: 12 }}>
                        <Link2 size={10} style={{ marginRight: 4 }} />
                        {res.label}
                        {res.sublabel && (
                          <span style={{ marginLeft: 6, color: '#64748b' }}>· {res.sublabel}</span>
                        )}
                        <span style={{ marginLeft: 6, color: '#94a3b8' }}>#{res.id}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {searching && (
                <span style={{ position: 'absolute', right: 8, top: 6, fontSize: 10, color: '#94a3b8' }}>
                  …
                </span>
              )}
            </div>
            <button type="button" className="btn"
                    data-testid={`airtable-reconcile-stub-${row.id}`}
                    onClick={onStub}
                    style={{ fontSize: 11, padding: '3px 8px' }}>
              <Wand2 size={11} style={{ marginRight: 4 }} />Create stub
            </button>
          </div>
        </td>
      </tr>
      {expanded && (
        <tr data-testid={`airtable-reconcile-detail-${row.id}`}>
          <td colSpan={4} style={{ padding: '6px 12px 12px', background: '#f8fafc' }}>
            <pre style={{ margin: 0, fontSize: 11, background: '#0f172a',
                          color: '#e2e8f0', padding: 10, borderRadius: 4,
                          maxHeight: 240, overflow: 'auto' }}>
              {JSON.stringify(row.payload_snapshot, null, 2)}
            </pre>
          </td>
        </tr>
      )}
    </>
  );
}
