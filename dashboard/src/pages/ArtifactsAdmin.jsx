import React, { useCallback, useEffect, useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * <ArtifactsAdmin /> — first-class Artifact Layer browser (Slice A).
 *
 * Spec §2A: Reports, Workpapers, Close Packets, Reconciliations,
 * Tax XML, Approval Packets, and any AI-generated deliverable are
 * first-class platform objects with identity, lifecycle, version,
 * provenance, permissions, and relationships.  This page is the
 * read-only operator browser over `artifact_objects` +
 * `artifact_events` + `artifact_relationships`.
 *
 * Three regions:
 *   1. Distribution strip — per-artifact-type status counts (top).
 *   2. Filtered list — left rail, click a row to drill in.
 *   3. Detail panel — right side, shows artifact body + lineage
 *      (incoming/outgoing edges + event history).
 *
 * Mounted at /admin/ai/artifacts.  RBAC: `ai.audit.view`.
 */
export default function ArtifactsAdmin() {
  const [rows, setRows]               = useState([]);
  const [distribution, setDist]       = useState({});
  const [selectedId, setSel]          = useState(null);
  const [detail, setDetail]           = useState(null);
  const [loading, setLoading]         = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError]             = useState(null);
  const [filters, setFilters] = useState({
    artifact_type: '',
    status:        '',
    source_module: '',
  });

  const loadList = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const qs = new URLSearchParams({ action: 'list_artifacts', limit: '200' });
      if (filters.artifact_type) qs.set('artifact_type', filters.artifact_type);
      if (filters.status)        qs.set('status',        filters.status);
      if (filters.source_module) qs.set('source_module', filters.source_module);
      const r = await api.get(`/api/ai/admin.php?${qs.toString()}`);
      setRows(r.rows || []);
      setDist(r.distribution || {});
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  }, [filters]);

  const loadDetail = useCallback(async (id) => {
    if (!id) { setDetail(null); return; }
    setDetailLoading(true);
    try {
      const r = await api.get(`/api/ai/admin.php?action=get_artifact&id=${encodeURIComponent(id)}`);
      setDetail(r);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setDetailLoading(false);
    }
  }, []);

  useEffect(() => { loadList(); }, [loadList]);
  useEffect(() => { loadDetail(selectedId); }, [selectedId, loadDetail]);

  // Build the dropdown options off whatever's currently in the
  // tenant's artifact_objects table — no hardcoded type/module list.
  const knownTypes = useMemo(() => Object.keys(distribution).sort(), [distribution]);
  const knownStatuses = ['draft', 'review', 'approved', 'final', 'archived', 'rejected'];

  return (
    <div data-testid="artifacts-admin-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="artifacts-admin-title">
          Artifacts
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          First-class platform objects with identity, lifecycle, versions, and lineage.
          Spec §2A. <Link to="/admin/ai-gateway">→ AI Gateway trace explorer</Link>.
        </p>
      </header>

      {error && (
        <div className="alert alert--error" data-testid="artifacts-admin-error"
             style={{ marginBottom: 12 }}>
          {error}
        </div>
      )}

      {/* Distribution strip */}
      <section data-testid="artifacts-distribution"
               style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
        {Object.keys(distribution).length === 0 && (
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: 0 }}
             data-testid="artifacts-distribution-empty">
            No artifacts yet. Module-level wiring (close packets → artifact,
            recon packets → artifact, JE drafts → artifact) lands in
            Slices C / D.
          </p>
        )}
        {Object.entries(distribution).map(([type, byStatus]) => (
          <DistributionChip key={type} type={type} byStatus={byStatus} />
        ))}
      </section>

      {/* Filter bar */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12 }}>Type
          <select className="input" value={filters.artifact_type}
                  onChange={e => setFilters(f => ({ ...f, artifact_type: e.target.value }))}
                  data-testid="artifacts-filter-type"
                  style={{ marginLeft: 6, minWidth: 160 }}>
            <option value="">(all)</option>
            {knownTypes.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Status
          <select className="input" value={filters.status}
                  onChange={e => setFilters(f => ({ ...f, status: e.target.value }))}
                  data-testid="artifacts-filter-status"
                  style={{ marginLeft: 6, minWidth: 120 }}>
            <option value="">(all)</option>
            {knownStatuses.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Source module
          <input className="input" value={filters.source_module}
                 onChange={e => setFilters(f => ({ ...f, source_module: e.target.value }))}
                 data-testid="artifacts-filter-module"
                 placeholder="e.g. accounting"
                 style={{ marginLeft: 6, minWidth: 140 }} />
        </label>
        <button type="button" className="btn btn--ghost"
                onClick={() => setFilters({ artifact_type: '', status: '', source_module: '' })}
                data-testid="artifacts-filter-clear"
                style={{ fontSize: 12, alignSelf: 'flex-end' }}>
          Clear filters
        </button>
      </div>

      {/* Two-column layout: list + detail */}
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(280px, 1fr) 2fr', gap: 16 }}>
        <ArtifactList rows={rows} loading={loading} selectedId={selectedId}
                      onSelect={setSel} />
        <ArtifactDetail detail={detail} loading={detailLoading} selectedId={selectedId} />
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
/* Distribution chip                                       */
/* ─────────────────────────────────────────────────────── */
function DistributionChip({ type, byStatus }) {
  const total = byStatus.total || 0;
  const breakdown = Object.entries(byStatus)
    .filter(([k]) => k !== 'total')
    .map(([k, v]) => `${k}: ${v}`)
    .join(' · ');
  return (
    <div data-testid={`artifacts-dist-${type}`}
         style={{
           background: 'var(--cf-bg-elevated)',
           border: '1px solid var(--cf-border)',
           borderRadius: 6, padding: '8px 12px', minWidth: 140,
         }}>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
        {type}
      </div>
      <div style={{ fontSize: 20, fontWeight: 700, margin: '2px 0' }}>{total}</div>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{breakdown}</div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
/* Artifact list                                           */
/* ─────────────────────────────────────────────────────── */
function ArtifactList({ rows, loading, selectedId, onSelect }) {
  if (loading) return <p data-testid="artifacts-list-loading">Loading artifacts…</p>;
  if (rows.length === 0) {
    return (
      <p data-testid="artifacts-list-empty"
         style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        No artifacts match these filters.
      </p>
    );
  }
  return (
    <div data-testid="artifacts-list"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, overflow: 'hidden' }}>
      <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ background: 'var(--cf-bg-muted)', textAlign: 'left' }}>
            <th style={{ padding: '8px 10px' }}>Type</th>
            <th style={{ padding: '8px 10px' }}>Title</th>
            <th style={{ padding: '8px 10px' }}>Status</th>
            <th style={{ padding: '8px 10px', textAlign: 'right' }}>Created</th>
          </tr>
        </thead>
        <tbody>
          {rows.map(r => (
            <tr key={r.id}
                onClick={() => onSelect(r.id)}
                data-testid={`artifacts-list-row-${r.id}`}
                style={{
                  cursor: 'pointer',
                  background: r.id === selectedId ? 'var(--cf-bg-selected, #eff6ff)' : undefined,
                  borderTop: '1px solid var(--cf-border-muted, #f1f5f9)',
                }}>
              <td style={{ padding: '8px 10px', fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 11 }}>
                {r.artifact_type}
              </td>
              <td style={{ padding: '8px 10px' }}>
                {r.title || <span style={{ color: 'var(--cf-text-secondary)' }}>(untitled)</span>}
                <div style={{ fontSize: 10, color: 'var(--cf-text-secondary)' }}>v{r.version} · {String(r.id).slice(0, 8)}…</div>
              </td>
              <td style={{ padding: '8px 10px' }}>
                <StatusPill status={r.status} />
              </td>
              <td style={{ padding: '8px 10px', textAlign: 'right', fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                {(r.created_at || '').slice(0, 16).replace('T', ' ')}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
/* Artifact detail                                         */
/* ─────────────────────────────────────────────────────── */
function ArtifactDetail({ detail, loading, selectedId }) {
  if (!selectedId) {
    return (
      <div data-testid="artifacts-detail-placeholder"
           style={{ padding: 24, color: 'var(--cf-text-secondary)', fontSize: 13, border: '1px dashed var(--cf-border)', borderRadius: 6 }}>
        Select an artifact on the left to see its body, lineage, and event history.
      </div>
    );
  }
  if (loading) return <p data-testid="artifacts-detail-loading">Loading artifact…</p>;
  if (!detail) return <p data-testid="artifacts-detail-empty">Artifact not found.</p>;

  const art = detail.artifact;
  return (
    <div data-testid="artifacts-detail"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 16 }}>
      <header style={{ marginBottom: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
          <div>
            <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', fontFamily: 'var(--cf-mono, ui-monospace)' }}>
              {art.artifact_type} · v{art.version} · {art.id}
            </div>
            <h3 style={{ margin: '4px 0 0', fontSize: 16, fontWeight: 600 }}
                data-testid="artifacts-detail-title">
              {art.title || <span style={{ color: 'var(--cf-text-secondary)' }}>(untitled)</span>}
            </h3>
          </div>
          <StatusPill status={art.status} />
        </div>
        <div style={{ marginTop: 8, fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          {art.source_module && <>Source: <code>{art.source_module}</code>{art.source_record_type && <> / <code>{art.source_record_type} #{art.source_record_id}</code></>} · </>}
          Created {(art.created_at || '').slice(0, 16).replace('T', ' ')}
          {art.created_by_ai_run && <> · by AI run <code>{String(art.created_by_ai_run).slice(0, 8)}…</code></>}
          {art.archived_at && <> · archived {(art.archived_at || '').slice(0, 16).replace('T', ' ')}</>}
        </div>
      </header>

      <DetailSection title="Body" testId="artifacts-detail-payload">
        {art.payload === null ? (
          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>No structured payload.</p>
        ) : (
          <pre style={{
            background: 'var(--cf-bg-muted)', padding: 10, borderRadius: 4,
            fontSize: 11, maxHeight: 280, overflow: 'auto', margin: 0,
          }}>{JSON.stringify(art.payload, null, 2)}</pre>
        )}
        {art.storage_uri && (
          <p style={{ fontSize: 12, marginTop: 6 }}
             data-testid="artifacts-detail-storage">
            Storage: <code>{art.storage_uri}</code>
            {art.storage_bytes ? ` · ${formatBytes(art.storage_bytes)}` : ''}
            {art.storage_mime  ? ` · ${art.storage_mime}` : ''}
          </p>
        )}
      </DetailSection>

      <DetailSection title={`Event history (${detail.event_history.length})`}
                     testId="artifacts-detail-events">
        {detail.event_history.length === 0 ? (
          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>No events.</p>
        ) : (
          <ul style={{ listStyle: 'none', padding: 0, margin: 0, maxHeight: 220, overflow: 'auto' }}>
            {detail.event_history.map(e => (
              <li key={e.id}
                  data-testid={`artifacts-detail-event-${e.id}`}
                  style={{ padding: '6px 0', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)', fontSize: 11 }}>
                <strong style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>{e.event_type}</strong>
                {e.prior_status && e.new_status && e.prior_status !== e.new_status && (
                  <> · <code>{e.prior_status} → {e.new_status}</code></>
                )}
                {' · '}<span style={{ color: 'var(--cf-text-secondary)' }}>
                  {(e.created_at || '').slice(0, 16).replace('T', ' ')}
                </span>
                {e.actor_user_id && <> · user <code>#{e.actor_user_id}</code></>}
                {e.actor_ai_run  && <> · AI run <code>{String(e.actor_ai_run).slice(0, 8)}…</code></>}
              </li>
            ))}
          </ul>
        )}
      </DetailSection>

      <DetailSection title={`Outgoing edges (${detail.outgoing.length})`}
                     testId="artifacts-detail-outgoing">
        <EdgeList edges={detail.outgoing} direction="out" />
      </DetailSection>

      <DetailSection title={`Incoming edges (${detail.incoming.length})`}
                     testId="artifacts-detail-incoming">
        <EdgeList edges={detail.incoming} direction="in" />
      </DetailSection>
    </div>
  );
}

function DetailSection({ title, testId, children }) {
  return (
    <details open style={{ marginTop: 12 }} data-testid={testId}>
      <summary style={{ fontWeight: 600, fontSize: 12, cursor: 'pointer', color: 'var(--cf-text-secondary)' }}>
        {title}
      </summary>
      <div style={{ marginTop: 6 }}>{children}</div>
    </details>
  );
}

function EdgeList({ edges, direction }) {
  if (edges.length === 0) {
    return <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>None.</p>;
  }
  return (
    <ul style={{ listStyle: 'none', padding: 0, margin: 0, fontSize: 11 }}>
      {edges.map(e => (
        <li key={e.edge_id}
            data-testid={`artifacts-edge-${direction}-${e.edge_id}`}
            style={{ padding: '4px 0', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
          <code style={{ marginRight: 6 }}>{e.relationship_type}</code>
          {direction === 'out' ? (
            e.target_artifact_id ? (
              <>→ artifact <code>{String(e.target_artifact_id).slice(0, 8)}…</code></>
            ) : (
              <>→ <code>{e.target_table} #{e.target_record_id}</code></>
            )
          ) : (
            <>← from artifact <code>{String(e.source_artifact_id).slice(0, 8)}…</code></>
          )}
        </li>
      ))}
    </ul>
  );
}

/* ─────────────────────────────────────────────────────── */
/* Tiny atoms                                              */
/* ─────────────────────────────────────────────────────── */
function StatusPill({ status }) {
  const colors = {
    draft:    { bg: '#e0e7ff', fg: '#3730a3' },
    review:   { bg: '#fef3c7', fg: '#92400e' },
    approved: { bg: '#dcfce7', fg: '#166534' },
    final:    { bg: '#ddd6fe', fg: '#5b21b6' },
    archived: { bg: '#e5e7eb', fg: '#374151' },
    rejected: { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`artifacts-status-${status}`}
          style={{
            display: 'inline-block', padding: '2px 8px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600,
          }}>{status}</span>
  );
}

function formatBytes(b) {
  if (!b) return '';
  const u = ['B', 'KB', 'MB', 'GB'];
  let i = 0, n = Number(b);
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(n < 10 ? 1 : 0)} ${u[i]}`;
}
