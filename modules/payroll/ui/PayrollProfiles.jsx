import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const GAP_LABEL = {
  hire_date:    'Hire date',
  ssn:          'SSN',
  compensation: 'Compensation',
  tax_federal:  'Federal W-4',
  bank_account: 'Bank account',
  i9_verified:  'I-9 verification',
  employee_not_found: 'Employee record',
};

export default function PayrollProfiles() {
  const { data, loading, error, reload } = useApi('/api/v1/payroll/profiles');
  const profiles = data?.profiles ?? [];

  return (
    <section className="payroll-profiles" data-testid="payroll-profiles">
      <header>
        <h2>Employee Setup</h2>
        <p>Each active employee needs a payroll profile + complete People-module data before they can be paid.</p>
        <button onClick={reload} className="btn btn--ghost" data-testid="payroll-profiles-refresh">
          Refresh
        </button>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}

      {!loading && profiles.length === 0 && (
        <p className="empty-state">No active employees. Add employees in the People module first.</p>
      )}

      {profiles.length > 0 && (
        <table className="data-table" data-testid="payroll-profiles-table">
          <thead>
            <tr>
              <th>#</th><th>Name</th><th>Department</th>
              <th>Schedule</th><th>State</th><th>Setup gaps</th>
              <th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            {profiles.map((p) => (
              <tr key={p.employee_id}>
                <td>{p.employee_number}</td>
                <td>{p.name}</td>
                <td>{p.department || '—'}</td>
                <td>{p.has_profile ? (p.schedule_id ? `#${p.schedule_id}` : '—') : <em>none</em>}</td>
                <td>{p.work_state || '—'}</td>
                <td>
                  {p.gaps.length === 0 ? (
                    <span className="muted">none</span>
                  ) : (
                    <span className="badge badge--warn" data-testid={`payroll-profile-gaps-${p.employee_id}`}>
                      {p.gaps.map((g) => GAP_LABEL[g] || g).join(', ')}
                    </span>
                  )}
                </td>
                <td>
                  <span className={`badge badge--${p.ready ? 'active' : 'inactive'}`}>
                    {p.ready ? 'ready' : 'not ready'}
                  </span>
                </td>
                <td>
                  <Link
                    to={`./${p.employee_id}`}
                    className="btn btn--ghost"
                    data-testid={`payroll-profile-edit-${p.employee_id}`}
                  >
                    {p.has_profile ? 'Edit' : 'Set up'}
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
