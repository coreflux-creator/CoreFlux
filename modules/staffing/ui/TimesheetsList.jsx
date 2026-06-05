import React, { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useApiCached } from '../../../dashboard/src/lib/api';

/**
 * Timesheets List — Batch 2 (2026-02).
 *
 * Index page listing every timesheet visible to the operator
 * (powered by `/modules/staffing/api/timesheets.php?action=list`).
 * Click a row to drill into the single-timesheet detail view.
 *
 * Replaces the rigid "edit the current week only" experience with a
 * proper list/detail pattern. Filters: status, person, period range,
 * placement.
 */
const STATUSES = ['', 'draft', 'submitted', 'approved', 'rejected', 'locked', 'payroll_ready', 'billing_ready'];

export default function TimesheetsList({ session }) {
  const [filters, setFilters] = useState({
    status: '',
    period_start: '',
    period_end: '',
    person_id: '',
  });

  const query = useMemo(() => {
    const params = new URLSearchParams({ action: 'list' });
    Object.entries(filters).forEach(([k, v]) => { if (v) params.append(k, v); });
    return params.toString();
  }, [filters]);

  // LP-001 — SWR cache. Reopening Timesheets paints from cache instantly
  // and revalidates in the background. The cache is keyed by full query
  // so each filter combo gets its own warm entry.
  const timesheetsPath = `/modules/staffing/api/timesheets.php?${query}`;
  const { data, loading, error, reload } = useApiCached(
    timesheetsPath,
    { ttlMs: 30000, cacheKey: `timesheets-list:${query}` }
  );
  const rows = data?.rows ?? [];

  const setF = (k, v) => setFilters(f => ({ ...f, [k]: v }));

  return (
    <section data-testid="timesheets-list-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: '0 0 4px' }}>Timesheets</h2>
          <p style={{ color: '#666', fontSize: 13, margin: 0 }}>
            Click a row to open the timesheet detail. Use the "Current week" button to log new hours.
          </p>
        </div>
        <Link
          to="week"
          className="btn btn--primary"
          data-testid="timesheets-list-current-week"
        >
          Open current week →
        </Link>
      </header>

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12, padding: 10, background: '#f9fafb', borderRadius: 6 }}>
        <label style={{ fontSize: 12 }}>Status
          <select className="input" value={filters.status} onChange={e => setF('status', e.target.value)}
                  data-testid="timesheets-list-filter-status">
            {STATUSES.map(s => <option key={s} value={s}>{s || 'any'}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 12 }}>Period start ≥
          <input className="input" type="date" value={filters.period_start}
                 onChange={e => setF('period_start', e.target.value)}
                 data-testid="timesheets-list-filter-period-start" />
        </label>
        <label style={{ fontSize: 12 }}>Period end ≤
          <input className="input" type="date" value={filters.period_end}
                 onChange={e => setF('period_end', e.target.value)}
                 data-testid="timesheets-list-filter-period-end" />
        </label>
        <label style={{ fontSize: 12 }}>Person ID
          <input className="input" type="number" min="1" value={filters.person_id}
                 onChange={e => setF('person_id', e.target.value)}
                 placeholder="e.g. 42"
                 data-testid="timesheets-list-filter-person" />
        </label>
        <button type="button" className="btn btn--ghost" onClick={reload}
                data-testid="timesheets-list-reload">Reload</button>
      </div>

      {loading && <p data-testid="timesheets-list-loading">Loading…</p>}
      {error && <p className="error" data-testid="timesheets-list-error">{error.message}</p>}
      {!loading && rows.length === 0 && (
        <p style={{ color: '#999' }} data-testid="timesheets-list-empty">No timesheets match the current filters.</p>
      )}
      {rows.length > 0 && (
        <table className="data-table" data-testid="timesheets-list-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Worker</th>
              <th>Week</th>
              <th>Hours</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Approved</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} data-testid={`timesheets-list-row-${r.id}`}>
                <td><code>#{r.id}</code></td>
                <td>
                  {r.first_name || r.last_name
                    ? `${r.first_name || ''} ${r.last_name || ''}`.trim()
                    : `Person #${r.person_id}`}
                  <br/>
                  <span style={{ fontSize: 11, color: '#666' }}>{r.email_primary}</span>
                </td>
                <td style={{ fontSize: 12 }}>{r.period_start} → {r.period_end}</td>
                <td style={{ fontWeight: 600 }}>{Number(r.total_hours || 0).toFixed(2)}</td>
                <td><StatusBadge status={r.status} /></td>
                <td style={{ fontSize: 11, color: '#666' }}>{r.submitted_at || '—'}</td>
                <td style={{ fontSize: 11, color: '#666' }}>{r.approved_at || '—'}</td>
                <td>
                  <Link
                    to={`${r.id}`}
                    className="btn btn--ghost"
                    data-testid={`timesheets-list-open-${r.id}`}
                  >
                    Open →
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function StatusBadge({ status }) {
  const colors = {
    draft:         { bg: '#e0e7ff', fg: '#3730a3' },
    submitted:     { bg: '#fef3c7', fg: '#92400e' },
    approved:      { bg: '#dcfce7', fg: '#166534' },
    rejected:      { bg: '#fee2e2', fg: '#991b1b' },
    locked:        { bg: '#e5e7eb', fg: '#374151' },
    payroll_ready: { bg: '#cffafe', fg: '#155e75' },
    billing_ready: { bg: '#fae8ff', fg: '#86198f' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 999,
      background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600,
    }} data-testid={`timesheet-status-${status}`}>{status}</span>
  );
}
