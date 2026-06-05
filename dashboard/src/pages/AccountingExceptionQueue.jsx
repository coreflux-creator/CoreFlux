import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * <AccountingExceptionQueue /> — operator inbox over accounting_exceptions.
 *
 * Slice B / Spec §11: every classification graph that can't post a JE
 * with high confidence opens an exception row.  This page is the
 * reviewer's home for those rows.
 *
 * Two-column layout:
 *   left   — filterable list (status / severity / exception_type)
 *   right  — drill-down (full detail + recommended actions: assign,
 *            resolve, dismiss)
 *
 * Mounted at /admin/ai/exceptions. RBAC handled server-side
 * (`ai.audit.view` OR `accounting.review`).
 */
export default function AccountingExceptionQueue() {
  const [rows, setRows]           = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [selectedId, setSel]      = useState(null);
  const [detail, setDetail]       = useState(null);
  const [detailLoading, setDL]    = useState(false);
  const [busy, setBusy]           = useState(false);
  const [filters, setFilters] = useState({
    status:   'open',
    severity: '',
    type:     '',
  });

  const loadList = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/ai/exceptions.php?status=${encodeURIComponent(filters.status)}`);
      setRows(r.exceptions || []);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  }, [filters.status]);

  const loadDetail = useCallback(async (id) => {
    if (!id) { setDetail(null); return; }
    setDL(true);
    try {
      const r = await api.get(`/api/ai/exceptions.php?action=detail&id=${id}`);
      setDetail(r.exception);
    } catch (e) {
      setError(e.message || String(e));
    } finally { setDL(false); }
  }, []);

  useEffect(() => { loadList(); },          [loadList]);
  useEffect(() => { loadDetail(selectedId); }, [selectedId, loadDetail]);

  // Build the client-side filters by severity + type from whatever the
  // server returned — no hardcoded enum list (the table is free-form
  // for exception_type).
  const visibleRows = useMemo(() => rows.filter(r =>
    (!filters.severity || r.severity === filters.severity) &&
    (!filters.type     || r.exception_type === filters.type)
  ), [rows, filters.severity, filters.type]);

  const knownSeverities = useMemo(
    () => Array.from(new Set(rows.map(r => r.severity).filter(Boolean))).sort(),
    [rows]
  );
  const knownTypes = useMemo(
    () => Array.from(new Set(rows.map(r => r.exception_type).filter(Boolean))).sort(),
    [rows]
  );

  const handleAction = async (action, note = null) => {
    if (!detail) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/api/ai/exceptions.php?action=${action}`, {
        id: detail.id,
        resolution_note: note,
      });
      await loadList();
      await loadDetail(detail.id);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="exception-queue-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="exception-queue-title">
          Exception Queue
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Bank transactions, JE drafts, and workflow runs that need human attention.
          Filter, drill in, resolve. <Link to="/admin/ai-gateway/reviewer">→ Reviewer cockpit</Link>.
        </p>
      </header>

      {error && (
        <div className="alert alert--error"
             data-testid="exception-queue-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      {/* Filter bar */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12 }}>Status
          <select className="input" value={filters.status}
                  onChange={e => setFilters(f => ({ ...f, status: e.target.value }))}
                  data-testid="exception-queue-filter-status"
                  style={{ marginLeft: 6, minWidth: 120 }}>
            <option value="open">Open</option>
            <option value="assigned">Assigned</option>
            <option value="resolved">Resolved</option>
            <option value="dismissed">Dismissed</option>
            <option value="all">All</option>
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Severity
          <select className="input" value={filters.severity}
                  onChange={e => setFilters(f => ({ ...f, severity: e.target.value }))}
                  data-testid="exception-queue-filter-severity"
                  style={{ marginLeft: 6, minWidth: 110 }}>
            <option value="">(all)</option>
            {knownSeverities.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Type
          <select className="input" value={filters.type}
                  onChange={e => setFilters(f => ({ ...f, type: e.target.value }))}
                  data-testid="exception-queue-filter-type"
                  style={{ marginLeft: 6, minWidth: 160 }}>
            <option value="">(all)</option>
            {knownTypes.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <button type="button" className="btn btn--ghost"
                onClick={() => setFilters({ status: 'open', severity: '', type: '' })}
                data-testid="exception-queue-filter-clear"
                style={{ fontSize: 12, alignSelf: 'flex-end' }}>
          Reset
        </button>
        <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 11, color: 'var(--cf-text-secondary)' }}
              data-testid="exception-queue-count">
          {visibleRows.length} exception{visibleRows.length === 1 ? '' : 's'} shown
        </span>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(320px, 1fr) 2fr', gap: 16 }}>
        <ExceptionList rows={visibleRows} loading={loading}
                       selectedId={selectedId} onSelect={setSel} />
        <ExceptionDetail detail={detail} loading={detailLoading}
                         selectedId={selectedId} busy={busy}
                         onAction={handleAction} />
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
function ExceptionList({ rows, loading, selectedId, onSelect }) {
  if (loading) return <p data-testid="exception-queue-list-loading">Loading…</p>;
  if (rows.length === 0) {
    return (
      <p data-testid="exception-queue-list-empty"
         style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        No exceptions in this view. (✓ inbox zero.)
      </p>
    );
  }
  return (
    <div data-testid="exception-queue-list"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, overflow: 'hidden' }}>
      {rows.map(r => (
        <button key={r.id}
                type="button"
                onClick={() => onSelect(r.id)}
                data-testid={`exception-queue-row-${r.id}`}
                style={{
                  display: 'block', width: '100%', textAlign: 'left',
                  padding: '10px 12px', cursor: 'pointer',
                  background: r.id === selectedId ? 'var(--cf-bg-selected, #eff6ff)' : 'transparent',
                  borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                  border: 'none', borderTop: '1px solid var(--cf-border-muted, #f1f5f9)',
                }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <span style={{ fontWeight: 600, fontSize: 13 }}>{r.summary}</span>
            <SeverityChip severity={r.severity} />
          </div>
          <div style={{ display: 'flex', gap: 8, fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
            <code>{r.exception_type}</code>
            <StatusChip status={r.status} />
            {r.related_ref_type && r.related_ref_id && (
              <span><code>{r.related_ref_type} #{r.related_ref_id}</code></span>
            )}
            <span style={{ marginLeft: 'auto' }}>{(r.created_at || '').slice(0, 16).replace('T', ' ')}</span>
          </div>
        </button>
      ))}
    </div>
  );
}

function ExceptionDetail({ detail, loading, selectedId, busy, onAction }) {
  const [note, setNote] = useState('');
  useEffect(() => { setNote(''); }, [selectedId]);

  if (!selectedId) {
    return (
      <div data-testid="exception-queue-detail-placeholder"
           style={{ padding: 24, color: 'var(--cf-text-secondary)', fontSize: 13,
                    border: '1px dashed var(--cf-border)', borderRadius: 6 }}>
        Select an exception on the left to drill in.
      </div>
    );
  }
  if (loading) return <p data-testid="exception-queue-detail-loading">Loading…</p>;
  if (!detail) return <p data-testid="exception-queue-detail-empty">Exception not found.</p>;

  const canAct = ['open', 'assigned'].includes(detail.status);
  return (
    <div data-testid="exception-queue-detail"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 16 }}>
      <header style={{ marginBottom: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
          <div>
            <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
              <code>{detail.exception_type}</code> · #{detail.id} ·
              {' '}<StatusChip status={detail.status} /> · <SeverityChip severity={detail.severity} />
            </div>
            <h3 data-testid="exception-queue-detail-summary"
                style={{ margin: '4px 0 0', fontSize: 16, fontWeight: 600 }}>
              {detail.summary}
            </h3>
          </div>
        </div>
        <div style={{ marginTop: 6, fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          Created {(detail.created_at || '').slice(0, 16).replace('T', ' ')}
          {detail.related_ref_type && detail.related_ref_id && (
            <> · ref <code>{detail.related_ref_type} #{detail.related_ref_id}</code></>
          )}
          {detail.workflow_run_id && (
            <> · workflow <code>{String(detail.workflow_run_id).slice(0, 8)}…</code></>
          )}
          {detail.ai_run_id && (
            <> · AI run <Link to={`/admin/ai-gateway?run=${detail.ai_run_id}`}>
                <code>{String(detail.ai_run_id).slice(0, 8)}…</code>
              </Link></>
          )}
        </div>
      </header>

      {detail.detail !== null && (
        <details open data-testid="exception-queue-detail-payload" style={{ marginTop: 12 }}>
          <summary style={{ fontWeight: 600, fontSize: 12, cursor: 'pointer', color: 'var(--cf-text-secondary)' }}>
            Detail payload
          </summary>
          <pre style={{
            background: 'var(--cf-bg-muted)', padding: 10, borderRadius: 4,
            fontSize: 11, maxHeight: 260, overflow: 'auto', margin: '6px 0 0',
          }}>{JSON.stringify(detail.detail, null, 2)}</pre>
        </details>
      )}

      {canAct ? (
        <div style={{ marginTop: 16, borderTop: '1px solid var(--cf-border-muted, #f1f5f9)', paddingTop: 12 }}>
          <label style={{ display: 'block', fontSize: 11, color: 'var(--cf-text-secondary)' }}>
            Resolution note (optional, 500 chars max)
            <textarea data-testid="exception-queue-detail-note"
                      value={note}
                      onChange={e => setNote(e.target.value.slice(0, 500))}
                      rows={2}
                      className="input"
                      style={{ width: '100%', marginTop: 4, fontSize: 12 }} />
          </label>
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button type="button"
                    className="btn btn--primary"
                    disabled={busy}
                    onClick={() => onAction('resolve', note || null)}
                    data-testid="exception-queue-detail-resolve">
              Resolve
            </button>
            <button type="button"
                    className="btn btn--ghost"
                    disabled={busy}
                    onClick={() => onAction('dismiss', note || null)}
                    data-testid="exception-queue-detail-dismiss">
              Dismiss
            </button>
          </div>
        </div>
      ) : (
        <p data-testid="exception-queue-detail-readonly"
           style={{ marginTop: 12, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          This exception is <strong>{detail.status}</strong>{detail.resolved_at && <> as of {(detail.resolved_at || '').slice(0, 16).replace('T', ' ')}</>}.
          Read-only.
        </p>
      )}
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
function SeverityChip({ severity }) {
  const colors = {
    critical: { bg: '#fee2e2', fg: '#991b1b' },
    high:     { bg: '#fef3c7', fg: '#92400e' },
    medium:   { bg: '#e0e7ff', fg: '#3730a3' },
    low:      { bg: '#dcfce7', fg: '#166534' },
  };
  const c = colors[severity] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`exception-severity-${severity}`}
          style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 10, fontWeight: 600,
            verticalAlign: 'middle',
          }}>{severity}</span>
  );
}
function StatusChip({ status }) {
  const colors = {
    open:      { bg: '#fef3c7', fg: '#92400e' },
    assigned:  { bg: '#e0e7ff', fg: '#3730a3' },
    resolved:  { bg: '#dcfce7', fg: '#166534' },
    dismissed: { bg: '#e5e7eb', fg: '#374151' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`exception-status-${status}`}
          style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 10, fontWeight: 600,
          }}>{status}</span>
  );
}
