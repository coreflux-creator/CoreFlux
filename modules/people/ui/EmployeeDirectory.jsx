import React, { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const API = '/modules/people/api/employees.php';

export default function EmployeeDirectory() {
  const [query, setQuery] = useState('');
  const [department, setDepartment] = useState('');
  const path = useMemo(() => {
    const params = new URLSearchParams();
    if (query) params.set('q', query);
    if (department) params.set('department', department);
    const s = params.toString();
    return s ? `${API}?${s}` : API;
  }, [query, department]);

  const { data, loading, error, reload } = useApi(path);
  const employees = data?.employees ?? [];

  const departments = useMemo(() => {
    const set = new Set(employees.map((e) => e.department).filter(Boolean));
    return Array.from(set).sort();
  }, [employees]);

  return (
    <section className="people-directory" data-testid="people-directory">
      <header className="people-directory__header">
        <div>
          <h2>Employees</h2>
          <p className="people-directory__subtitle">
            {data ? `${data.count} active` : ''}
          </p>
        </div>
        <div className="people-directory__actions">
          <Link to="../new" className="btn btn--primary" data-testid="people-add-employee-btn">
            Add employee
          </Link>
        </div>
      </header>

      <div className="people-directory__filters">
        <input
          type="search"
          placeholder="Search name, number, or email…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          data-testid="people-directory-search"
          className="input"
        />
        <select
          value={department}
          onChange={(e) => setDepartment(e.target.value)}
          data-testid="people-directory-dept-filter"
          className="input"
        >
          <option value="">All departments</option>
          {departments.map((d) => <option key={d} value={d}>{d}</option>)}
        </select>
        <button onClick={reload} className="btn btn--ghost" data-testid="people-directory-refresh">
          Refresh
        </button>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      {!loading && !error && (
        <table className="data-table" data-testid="people-directory-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Title</th>
              <th>Department</th>
              <th>Location</th>
              <th>Type</th>
              <th>Status</th>
              <th>Hire date</th>
            </tr>
          </thead>
          <tbody>
            {employees.length === 0 && (
              <tr><td colSpan={8} className="empty">No employees match.</td></tr>
            )}
            {employees.map((e) => (
              <tr key={e.id} data-testid={`people-row-${e.id}`}>
                <td>{e.employee_number}</td>
                <td>
                  <Link to={`../${e.id}`}>
                    {e.preferred_name || e.legal_first_name} {e.legal_last_name}
                  </Link>
                </td>
                <td>{e.job_title || '—'}</td>
                <td>{e.department || '—'}</td>
                <td>{e.location || '—'}</td>
                <td>{e.employment_type?.replace('_', ' ')}</td>
                <td><span className={`badge badge--${e.status}`}>{e.status}</span></td>
                <td>{e.hire_date || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
