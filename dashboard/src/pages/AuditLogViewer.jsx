import React, { useState, useMemo } from 'react';
import { api } from '../lib/api';
import { ScrollText, Download, RefreshCw, Sparkles } from 'lucide-react';

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
  const [anomaly, setAnomaly] = useState(null);
  const [anomalyLoading, setAnomalyLoading] = useState(false);
  const [anomalyHours, setAnomalyHours] = useState(24);

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

  const runAnomalyCheck = async () => {
    setAnomalyLoading(true);
    try {
      const r = await api.post(`/api/audit_anomaly.php?action=spot&hours=${anomalyHours}`);
      setAnomaly(r);
    } catch (e) {
      setAnomaly({ error: e?.message || 'Failed to run anomaly check' });
    } finally {
      setAnomalyLoading(false);
    }
  };

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

      {/* Anomaly Spotter — AI advisory only */}
      <div data-testid="audit-anomaly-card"
           style={{ marginBottom: 16, padding: 14, background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 10 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <Sparkles size={16} color="#0369a1" />
          <strong style={{ fontSize: 13, color: '#075985' }}>AI anomaly spotter · advisory only</strong>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#475569', marginLeft: 8 }}>
            Window
            <select className="input"
                    data-testid="audit-anomaly-hours"
                    value={anomalyHours}
                    onChange={e => setAnomalyHours(Number(e.target.value))}
                    style={{ padding: '2px 6px', fontSize: 12 }}>
              <option value={1}>1 hour</option>
              <option value={6}>6 hours</option>
              <option value={24}>24 hours</option>
              <option value={72}>3 days</option>
              <option value={168}>7 days</option>
            </select>
          </label>
          <button className="btn btn--primary"
                  data-testid="audit-anomaly-run"
                  onClick={runAnomalyCheck}
                  disabled={anomalyLoading}
                  style={{ fontSize: 12, marginLeft: 'auto' }}>
            <Sparkles size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {anomalyLoading ? 'Analysing…' : 'Spot anomalies'}
          </button>
        </div>

        {anomaly?.error && (
          <p className="error" data-testid="audit-anomaly-error" style={{ marginTop: 10, marginBottom: 0 }}>
            {anomaly.error}
          </p>
        )}

        {anomaly && !anomaly.error && (
          <div style={{ marginTop: 12 }}>
            {anomaly.summary
              ? <p data-testid="audit-anomaly-summary" style={{ margin: 0, fontSize: 13, color: '#0c4a6e', lineHeight: 1.55 }}>
                  {anomaly.summary}
                </p>
              : <p data-testid="audit-anomaly-empty" style={{ margin: 0, fontSize: 13, color: '#64748b', fontStyle: 'italic' }}>
                  AI summary unavailable. Review the raw signals below.
                </p>}

            <div data-testid="audit-anomaly-signals"
                 style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 10 }}>
              <span className="badge" data-testid="audit-anomaly-signal-total">
                {anomaly.signals?.total_events ?? 0} total events
              </span>
              <span className="badge" data-testid="audit-anomaly-signal-offhours">
                {anomaly.signals?.off_hours_count ?? 0} off-hours
              </span>
              <span className="badge" data-testid="audit-anomaly-signal-spikes">
                {(anomaly.signals?.spike_events ?? []).length} spike event{(anomaly.signals?.spike_events ?? []).length === 1 ? '' : 's'}
              </span>
              <span className="badge" data-testid="audit-anomaly-signal-mass-exports">
                {(anomaly.signals?.mass_export_users ?? []).length} mass-export user{(anomaly.signals?.mass_export_users ?? []).length === 1 ? '' : 's'}
              </span>
            </div>

            {(anomaly.signals?.spike_events?.length > 0 || anomaly.signals?.mass_export_users?.length > 0 || anomaly.signals?.top_users?.length > 0) && (
              <div style={{ marginTop: 10, fontSize: 12, color: '#334155' }}>
                {anomaly.signals?.spike_events?.length > 0 && (
                  <div data-testid="audit-anomaly-spike-list" style={{ marginBottom: 4 }}>
                    <strong>Spikes:</strong> {anomaly.signals.spike_events.map(s => `${s.event} (${s.count})`).join(', ')}
                  </div>
                )}
                {anomaly.signals?.mass_export_users?.length > 0 && (
                  <div data-testid="audit-anomaly-mass-list" style={{ marginBottom: 4 }}>
                    <strong>Mass exports (last hour):</strong> {anomaly.signals.mass_export_users.map(u => `${u.name} (${u.count})`).join(', ')}
                  </div>
                )}
                {anomaly.signals?.top_users?.length > 0 && (
                  <div data-testid="audit-anomaly-top-users">
                    <strong>Top users:</strong> {anomaly.signals.top_users.map(u => `${u.name} (${u.count})`).join(', ')}
                  </div>
                )}
              </div>
            )}
          </div>
        )}
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
