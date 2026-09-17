import React, { useEffect, useMemo, useState } from 'react';
import { Download, RefreshCw, Search, Upload } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const GAP_LABEL = {
  payroll_profile: 'Payroll profile',
  pay_schedule: 'Pay schedule',
  pay_cycle: 'Pay cycle',
  hire_date: 'Hire date',
  ssn: 'SSN',
  compensation: 'Compensation',
  tax_federal: 'Federal W-4',
  bank_account: 'Bank account',
  i9_verified: 'I-9 verification',
  employee_not_found: 'Employee record',
};

export default function PayrollProfiles() {
  const { data, loading, error, reload } = useApi('/modules/payroll/api/profiles.php');
  const profiles = useMemo(() => data?.profiles ?? [], [data?.profiles]);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();
    return profiles.filter((profile) => {
      if (status === 'ready' && !profile.ready) return false;
      if (status === 'needs_setup' && profile.ready) return false;
      if (!query) return true;
      return [profile.employee_number, profile.name, profile.work_email, profile.department,
        profile.schedule_name, profile.cycle_name, profile.work_state]
        .some((value) => String(value || '').toLowerCase().includes(query));
    });
  }, [profiles, search, status]);
  const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
  const visible = filtered.slice((page - 1) * perPage, page * perPage);
  useEffect(() => { setPage(1); }, [search, status, perPage]);
  useEffect(() => { setPage((value) => Math.min(value, totalPages)); }, [totalPages]);

  const readyCount = profiles.filter((profile) => profile.ready).length;
  const exportParams = new URLSearchParams();
  if (search.trim()) exportParams.set('q', search.trim());
  if (status !== 'all') exportParams.set('status', status);
  const exportHref = `/modules/payroll/api/profiles_csv_export.php${exportParams.toString() ? `?${exportParams}` : ''}`;

  return (
    <section className="directory-page payroll-profiles" data-testid="payroll-profiles">
      <div className="directory-page__header">
        <div>
          <h2 className="directory-page__title">Employee setup</h2>
          <p className="directory-page__description">
            Assign payroll cycles and complete the People data each employee needs before a run.
          </p>
        </div>
        <div className="directory-page__actions">
          <button type="button" className="btn btn--ghost" onClick={reload}
                  title="Refresh employee setup" aria-label="Refresh employee setup"
                  data-testid="payroll-profiles-refresh">
            <RefreshCw size={15} aria-hidden="true" />
          </button>
          <Link to="csv_import" className="btn btn--ghost" data-testid="payroll-profiles-import-csv">
            <Upload size={15} aria-hidden="true" /> Import CSV
          </Link>
          <a href={exportHref} className="btn btn--ghost" data-testid="payroll-profiles-export-csv">
            <Download size={15} aria-hidden="true" /> Export current view
          </a>
        </div>
      </div>

      <div className="directory-summary" data-testid="payroll-profiles-summary">
        <span><strong>{profiles.length}</strong> active employees</span>
        <span><strong>{readyCount}</strong> ready</span>
        <span><strong>{profiles.length - readyCount}</strong> need setup</span>
      </div>

      <div className="directory-filter-bar">
        <div className="directory-filter-bar__search">
          <Search size={16} aria-hidden="true" />
          <input className="input" type="search" value={search}
                 onChange={(event) => setSearch(event.target.value)}
                 placeholder="Search employee, department, cycle, or state"
                 data-testid="payroll-profiles-search" />
        </div>
        <div className="directory-filter-bar__filters">
          <select className="input" value={status} onChange={(event) => setStatus(event.target.value)}
                  data-testid="payroll-profiles-status-filter">
            <option value="all">All setup statuses</option>
            <option value="ready">Ready</option>
            <option value="needs_setup">Needs setup</option>
          </select>
        </div>
      </div>

      {loading && <p>Loading...</p>}
      {error && <p className="error">{error.message}</p>}

      {!loading && profiles.length === 0 && (
        <p className="empty-state">No active employees. Add employees in People first.</p>
      )}

      {!loading && profiles.length > 0 && filtered.length === 0 && (
        <p className="empty-state">No employees match these filters.</p>
      )}

      {visible.length > 0 && (
        <>
          <p className="muted" style={{ margin: '0 0 8px', fontSize: 12 }} data-testid="payroll-profiles-result-count">
            Showing {((page - 1) * perPage) + 1}–{Math.min(page * perPage, filtered.length)} of {filtered.length} matching employees
          </p>
          <div className="data-table-wrap">
            <table className="data-table" data-testid="payroll-profiles-table">
            <thead>
              <tr>
                <th>Employee</th><th>Department</th><th>Pay cycle</th>
                <th>State</th><th>Setup gaps</th><th>Status</th><th aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {visible.map((profile) => (
                <tr key={profile.employee_id}>
                  <td>
                    <strong>{profile.name}</strong>
                    <div className="muted">{profile.employee_number}{profile.work_email ? ` · ${profile.work_email}` : ''}</div>
                  </td>
                  <td>{profile.department || '—'}</td>
                  <td>
                    {profile.cycle_name || profile.schedule_name ? (
                      <>
                        <strong>{profile.cycle_name || 'Default cycle'}</strong>
                        <div className="muted">{profile.schedule_name || 'No schedule'}</div>
                      </>
                    ) : <em>Not assigned</em>}
                  </td>
                  <td>{profile.work_state || '—'}</td>
                  <td>
                    {profile.gaps.length === 0 ? (
                      <span className="muted">None</span>
                    ) : (
                      <span className="badge badge--warn" data-testid={`payroll-profile-gaps-${profile.employee_id}`}>
                        {profile.gaps.map((gap) => GAP_LABEL[gap] || gap).join(', ')}
                      </span>
                    )}
                  </td>
                  <td>
                    <span className={`badge badge--${profile.ready ? 'active' : 'inactive'}`}>
                      {profile.ready ? 'Ready' : 'Needs setup'}
                    </span>
                  </td>
                  <td>
                    <Link to={`./${profile.employee_id}`} className="btn btn--ghost"
                          data-testid={`payroll-profile-edit-${profile.employee_id}`}>
                      {profile.has_profile ? 'Edit' : 'Set up'}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
            </table>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8, marginTop: 12, flexWrap: 'wrap' }} data-testid="payroll-profiles-pagination">
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
              Rows
              <select className="input" value={perPage} onChange={(event) => setPerPage(Number(event.target.value))}>
                {[25, 50, 100, 200].map((size) => <option key={size} value={size}>{size}</option>)}
              </select>
            </label>
            <button type="button" className="btn btn--ghost" onClick={() => setPage((value) => Math.max(1, value - 1))} disabled={page <= 1}>Previous</button>
            <span style={{ fontSize: 12 }}>Page {page} of {totalPages}</span>
            <button type="button" className="btn btn--ghost" onClick={() => setPage((value) => Math.min(totalPages, value + 1))} disabled={page >= totalPages}>Next</button>
          </div>
        </>
      )}
    </section>
  );
}
