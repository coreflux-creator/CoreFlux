import React, { useState, useMemo } from 'react';
import { api } from '../lib/api';
import { ScrollText, Download, RefreshCw } from 'lucide-react';

/**
 * AuditLogViewer — admin-gated viewer for the unified audit_log
 * (Sprint 4 / A3). Filters by event substring, user_id, date range.
 * CSV export hits the same endpoint with `?format=csv` and downloads
 * via a one-click anchor (the PHP side sets Content-Disposition).
 *
 *   GET /api/audit_log.php?event=&user_id=&from=&to=&limit=&format=json|csv
 *
 * Mounted under /admin/audit-log.
 */
export default function AuditLogViewer({ session }) {
  const [filters, setFilters] = useState({ event: '', user_id: '', from: '', to: '', limit: 200 });
  const [rows, setRows]       = useState([]);
  const [count, setCount]     = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError]     = useState(null);
  const [expanded, setExpanded] = useState(null);

  const queryString = useMemo(() => {
    const p = new URLSearchParams();
    if (filters.event)   p.set('event',   filters.event);
    if (filters.user_id) p.set('user_id', filters.user_id);
    if (filters.from)    p.set('from',    filters.from);
    if (filters.to)      p.set('to',      filters.to);
    if (filters.limit)   p.set('limit',   filters.limit);
    return p.toString();
  }, [filters]);

  const search = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/audit_log.php?${queryString}`);
      setRows(r?.rows ?? []);
      setCount(r?.count ?? 0);
    } catch (e) {
      setError(e);
      setRows([]); setCount(0);
    } finally { setLoading(false); }
  };

  const csvHref = `/api/audit_log.php?${queryString}${queryString ? '&' : ''}format=csv`;

  return (
    <section data-testid="audit-log-viewer" style={{ padding: 'var(--cf-space-6)' }}>
      <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
        <ScrollText size={24} color="#2563eb" />
        <div>
          <h1 style={{ margin: 0, fontSize: 24, fontWeight: 700 }}>Audit Log</h1>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 13 }}>
            Tenant-scoped audit trail across every module. Filter, inspect, and export to CSV for compliance.
          </p>
        </div>
      </header>

      {/* Filter bar */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 12, marginBottom: 16, padding: 16, background: '#f8fafc', borderRadius: 10 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' }}>
          Event contains
          <input className="input" data-testid="audit-filter-event" value={filters.event}
                 onChange={e => setFilters({ ...filters, event: e.target.value })}
                 placeholder="e.g. ap.bill.approved" />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' }}>
          User id
          <input className="input" type="number" data-testid="audit-filter-user" value={filters.user_id}
                 onChange={e => setFilters({ ...filters, user_id: e.target.value })} />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' }}>
          From
          <input className="input" type="date" data-testid="audit-filter-from" value={filters.from}
                 onChange={e => setFilters({ ...filters, from: e.target.value })} />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' }}>
          To
          <input className="input" type="date" data-testid="audit-filter-to" value={filters.to}
                 onChange={e => setFilters({ ...filters, to: e.target.value })} />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' }}>
          Limit
          <input className="input" type="number" data-testid="audit-filter-limit" value={filters.limit}
                 onChange={e => setFilters({ ...filters, limit: Number(e.target.value || 200) })} min={1} max={5000} />
        </label>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
        <button className="btn btn--primary" data-testid="audit-search" onClick={search} disabled={loading}>
          <RefreshCw size={14} style={{ marginRight: 6, verticalAlign: 'middle' }} />
          {loading ? 'Searching…' : 'Search'}
        </button>
        <a className="btn btn--ghost"
           data-testid="audit-export-csv"
           href={csvHref}
           target="_blank"
           rel="noopener noreferrer"
           style={{ textDecoration: 'none' }}>
          <Download size={14} style={{ marginRight: 6, verticalAlign: 'middle' }} />
          Export CSV
        </a>
        <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 13, color: '#64748b' }}
              data-testid="audit-count">
          {count} row{count === 1 ? '' : 's'}
        </span>
      </div>

      {error && <p className="error" data-testid="audit-error">Error: {error.message}</p>}

      <div style={{ overflow: 'auto', border: '1px solid #e2e8f0', borderRadius: 10 }}>
        <table className="data-table" style={{ width: '100%', minWidth: 900 }}>
          <thead>
            <tr>
              <th>When</th><th>Event</th><th>User</th><th>Target</th><th>IP</th><th></th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && !loading && (
              <tr><td colSpan={6} className="empty" data-testid="audit-empty" style={{ padding: 24, textAlign: 'center', color: '#64748b' }}>
                No matching events. Try widening filters or click Search.
              </td></tr>
            )}
            {rows.map(r => (
              <React.Fragment key={r.id}>
                <tr data-testid={`audit-row-${r.id}`}>
                  <td style={{ whiteSpace: 'nowrap', fontFamily: 'monospace', fontSize: 12 }}>{r.created_at}</td>
                  <td><span className="badge">{r.event}</span></td>
                  <td>{r.user_name || r.user_email || (r.user_id ? `#${r.user_id}` : '—')}</td>
                  <td>{r.target_id || '—'}</td>
                  <td style={{ fontFamily: 'monospace', fontSize: 12, color: '#64748b' }}>{r.ip_address || '—'}</td>
                  <td>
                    <button className="btn btn--ghost" style={{ fontSize: 12 }}
                            data-testid={`audit-toggle-${r.id}`}
                            onClick={() => setExpanded(expanded === r.id ? null : r.id)}>
                      {expanded === r.id ? 'Hide' : 'Meta'}
                    </button>
                  </td>
                </tr>
                {expanded === r.id && (
                  <tr>
                    <td colSpan={6} style={{ background: '#f8fafc' }}>
                      <pre data-testid={`audit-meta-${r.id}`}
                           style={{ margin: 0, padding: 12, fontSize: 12, fontFamily: 'monospace', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                        {r.meta_json || '(no meta)'}
                      </pre>
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
