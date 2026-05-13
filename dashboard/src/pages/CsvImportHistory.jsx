import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * CSV Import History — auditor + CFO view of every bulk import committed
 * via /api/<module>/csv_import.php or the bulk wizard.
 *
 * Reads from /api/admin/csv_import_history.php, which is populated by the
 * SPA right after each successful commit (CsvImportPage.jsx +
 * CsvBulkImport.jsx both POST a row on success).
 *
 * Filterable by entity, status, and date range. Migration may not have
 * run on a brand-new tenant; in that case the endpoint returns
 * { rows: [], migration_pending: true } and we render an empty state.
 */

const ENTITY_LABELS = {
  people:           'People',
  ap_vendors:       'Vendors',
  staffing_clients: 'Clients',
  placements:       'Placements',
  time:             'Time entries',
  ap_bills:         'AP Bills',
  billing_invoices: 'AR Invoices',
  ap_payments:      'AP Payments',
  billing_payments: 'Billing Payments',
};

const STATUS_COLORS = {
  success: { bg: 'rgba(34,197,94,0.12)',  fg: '#047857' },
  partial: { bg: 'rgba(245,158,11,0.12)', fg: '#a16207' },
  failed:  { bg: 'rgba(239,68,68,0.12)',  fg: '#b91c1c' },
};

function fmtDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso.replace(' ', 'T'));
  if (isNaN(d.getTime())) return iso;
  return d.toLocaleString();
}

function StatusPill({ status }) {
  const c = STATUS_COLORS[status] || { bg: '#eee', fg: '#333' };
  return (
    <span
      data-testid={`csv-history-status-${status}`}
      style={{
        display: 'inline-block', padding: '2px 8px', borderRadius: 999,
        background: c.bg, color: c.fg, fontSize: 12, fontWeight: 600, textTransform: 'capitalize',
      }}
    >
      {status}
    </span>
  );
}

export default function CsvImportHistory() {
  const [rows,       setRows]       = useState([]);
  const [loading,    setLoading]    = useState(true);
  const [error,      setError]      = useState(null);
  const [migPending, setMigPending] = useState(false);

  // Filters
  const [entity, setEntity] = useState('');
  const [status, setStatus] = useState('');
  const [from,   setFrom]   = useState('');
  const [to,     setTo]     = useState('');
  const [expanded, setExpanded] = useState({}); // { rowId: bool }

  const fetchHistory = async () => {
    setLoading(true); setError(null);
    try {
      const qs = new URLSearchParams();
      if (entity) qs.set('entity', entity);
      if (status) qs.set('status', status);
      if (from)   qs.set('from',   from);
      if (to)     qs.set('to',     to);
      const res = await api.get(`/api/admin/csv_import_history.php${qs.toString() ? '?' + qs.toString() : ''}`);
      setRows(res?.rows || []);
      setMigPending(!!res?.migration_pending);
    } catch (e) { setError(e); }
    finally     { setLoading(false); }
  };

  // Initial load + on filter change.
  useEffect(() => { fetchHistory(); /* eslint-disable-next-line */ }, [entity, status, from, to]);

  const totals = useMemo(() => {
    return rows.reduce((acc, r) => {
      acc.imports  += 1;
      acc.imported += Number(r.rows_imported || 0);
      acc.skipped  += Number(r.rows_skipped  || 0);
      acc.failed   += r.status === 'failed' ? 1 : 0;
      return acc;
    }, { imports: 0, imported: 0, skipped: 0, failed: 0 });
  }, [rows]);

  return (
    <section data-testid="csv-import-history">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 'var(--cf-space-3)' }}>
        <div>
          <h2>CSV Import History</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Audit trail of every bulk CSV import committed through the platform.
            Filter by entity, status, or date range.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="/data/bulk-import" className="btn" data-testid="csv-history-go-bulk">+ New bulk import</Link>
          <Link to="/" className="btn btn--ghost" data-testid="csv-history-back">← Dashboard</Link>
        </div>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        {/* KPI strip */}
        <div data-testid="csv-history-kpi-strip" style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-4)' }}>
          <Stat label="Imports"        value={totals.imports}  testid="csv-history-kpi-imports" />
          <Stat label="Rows imported"  value={totals.imported} testid="csv-history-kpi-imported" />
          <Stat label="Rows skipped"   value={totals.skipped}  testid="csv-history-kpi-skipped" />
          <Stat label="Failed imports" value={totals.failed}   testid="csv-history-kpi-failed" />
        </div>

        {/* Filters */}
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-4)', alignItems: 'flex-end' }}>
          <Field label="Entity">
            <select value={entity} onChange={e => setEntity(e.target.value)} data-testid="csv-history-filter-entity">
              <option value="">All</option>
              {Object.entries(ENTITY_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label="Status">
            <select value={status} onChange={e => setStatus(e.target.value)} data-testid="csv-history-filter-status">
              <option value="">All</option>
              <option value="success">Success</option>
              <option value="partial">Partial</option>
              <option value="failed">Failed</option>
            </select>
          </Field>
          <Field label="From">
            <input type="date" value={from} onChange={e => setFrom(e.target.value)} data-testid="csv-history-filter-from" />
          </Field>
          <Field label="To">
            <input type="date" value={to} onChange={e => setTo(e.target.value)} data-testid="csv-history-filter-to" />
          </Field>
          <button className="btn" onClick={fetchHistory} data-testid="csv-history-refresh">Refresh</button>
          {(entity || status || from || to) && (
            <button className="btn btn--ghost" onClick={() => { setEntity(''); setStatus(''); setFrom(''); setTo(''); }} data-testid="csv-history-clear-filters">
              Clear filters
            </button>
          )}
        </div>

        {migPending && (
          <p data-testid="csv-history-migration-pending" style={{ background: 'rgba(245,158,11,0.1)', color: '#a16207', padding: 12, borderRadius: 6 }}>
            History table not provisioned yet for this tenant — it&apos;ll populate as
            soon as the next CSV import commits.
          </p>
        )}

        {error && <p className="error" data-testid="csv-history-error">Error: {error.message}</p>}

        {loading && <p data-testid="csv-history-loading" style={{ color: 'var(--cf-text-secondary)' }}>Loading…</p>}

        {!loading && !error && rows.length === 0 && !migPending && (
          <p className="empty" data-testid="csv-history-empty">
            No CSV imports recorded yet. Run a bulk import to populate this view.
          </p>
        )}

        {!loading && rows.length > 0 && (
          <table className="data-table" data-testid="csv-history-table">
            <thead>
              <tr>
                <th>When</th>
                <th>User</th>
                <th>Entity</th>
                <th>File</th>
                <th style={{ textAlign: 'right' }}>Imported</th>
                <th style={{ textAlign: 'right' }}>Skipped</th>
                <th style={{ textAlign: 'right' }}>Errors</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map(r => {
                const open = !!expanded[r.id];
                const errs = r.error_summary && typeof r.error_summary === 'object' ? r.error_summary : null;
                const map  = r.column_map    && typeof r.column_map    === 'object' ? r.column_map    : null;
                return (
                  <React.Fragment key={r.id}>
                    <tr data-testid={`csv-history-row-${r.id}`}>
                      <td>{fmtDateTime(r.created_at)}</td>
                      <td>{r.created_by_email || '—'}</td>
                      <td>{ENTITY_LABELS[r.entity] || r.entity}</td>
                      <td title={r.file_name || ''} style={{ maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {r.file_name || '—'}
                        {r.preset_name && (
                          <span style={{ marginLeft: 6, fontSize: 11, padding: '1px 6px', borderRadius: 3, background: 'rgba(34,197,94,0.15)', color: '#047857' }}>
                            preset: {r.preset_name}
                          </span>
                        )}
                        {r.ai_used ? (
                          <span style={{ marginLeft: 6, fontSize: 11, padding: '1px 6px', borderRadius: 3, background: 'rgba(124,58,237,0.15)', color: '#5b21b6' }}>
                            AI
                          </span>
                        ) : null}
                      </td>
                      <td style={{ textAlign: 'right' }} data-testid={`csv-history-row-${r.id}-imported`}>{r.rows_imported}</td>
                      <td style={{ textAlign: 'right' }}>{r.rows_skipped}</td>
                      <td style={{ textAlign: 'right' }}>{r.errors_count}</td>
                      <td><StatusPill status={r.status} /></td>
                      <td>
                        {(errs || map) && (
                          <button
                            className="btn btn--ghost"
                            onClick={() => setExpanded(prev => ({ ...prev, [r.id]: !prev[r.id] }))}
                            data-testid={`csv-history-row-${r.id}-toggle`}
                          >
                            {open ? 'Hide' : 'Details'}
                          </button>
                        )}
                      </td>
                    </tr>
                    {open && (
                      <tr data-testid={`csv-history-row-${r.id}-details`}>
                        <td colSpan={9} style={{ background: 'rgba(0,0,0,0.02)' }}>
                          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--cf-space-4)', padding: 'var(--cf-space-3)' }}>
                            <div>
                              <strong>Column mapping</strong>
                              {map ? (
                                <table className="data-table" style={{ marginTop: 6 }}>
                                  <thead><tr><th>Source</th><th>→</th><th>Target</th></tr></thead>
                                  <tbody>
                                    {Object.entries(map).map(([src, tgt]) => (
                                      <tr key={src}><td><code>{src}</code></td><td>→</td><td>{tgt || <em>skipped</em>}</td></tr>
                                    ))}
                                  </tbody>
                                </table>
                              ) : <p style={{ color: 'var(--cf-text-secondary)' }}>No mapping captured.</p>}
                              {r.skip_invalid    ? <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>• skip_invalid was on</p> : null}
                              {r.update_existing ? <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>• update_existing was on</p> : null}
                              {r.duration_ms     ? <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>• duration: {r.duration_ms} ms</p> : null}
                            </div>
                            <div>
                              <strong>Errors</strong>
                              {errs && Object.keys(errs).length > 0 ? (
                                <ul style={{ marginTop: 6, paddingLeft: '1rem', maxHeight: 240, overflow: 'auto' }}>
                                  {Object.entries(errs).map(([rn, msgs]) => (
                                    <li key={rn}>
                                      <strong>Row {rn}:</strong>{' '}
                                      {(Array.isArray(msgs) ? msgs : [String(msgs)]).join('; ')}
                                    </li>
                                  ))}
                                </ul>
                              ) : <p style={{ color: 'var(--cf-text-secondary)' }}>No errors recorded.</p>}
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}

function Stat({ label, value, testid }) {
  return (
    <div data-testid={testid} style={{ background: 'var(--cf-surface-elevated, #f8fafc)', padding: '12px 16px', borderRadius: 'var(--cf-radius-md, 8px)', minWidth: 140 }}>
      <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{Number(value || 0).toLocaleString('en-US')}</div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
      {label}
      {children}
    </label>
  );
}
