import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (cents) =>
  ((cents || 0) / 100).toLocaleString(undefined, { style: 'currency', currency: 'USD' });
const labelValue = (value) => String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());

export default function PayrollRuns() {
  const { data, loading, error } = useApi('/modules/payroll/api/runs.php');
  const runs = data?.runs ?? [];

  return (
    <section className="payroll-runs" data-testid="payroll-runs">
      <header>
        <h2>Payroll runs</h2>
        <p>Review approved inputs, calculate pay, approve the run, and record payment for each pay period.</p>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}

      {!loading && !error && runs.length === 0 && (
        <div className="empty-state" data-testid="payroll-runs-empty" style={{ display: 'grid', gap: 12, justifyItems: 'start' }}>
          <div>
            <strong>No payroll runs yet</strong>
            <p style={{ margin: '4px 0 0' }}>Choose an open pay period to review inputs and start the run.</p>
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Link className="btn btn--primary" to="../pay_periods" data-testid="payroll-runs-open-periods">
              Open pay periods
            </Link>
            <Link className="btn btn--ghost" to="../pay_schedules" data-testid="payroll-runs-open-schedules">
              Manage pay schedules
            </Link>
          </div>
        </div>
      )}

      {runs.length > 0 && (
        <table className="data-table" data-testid="payroll-runs-table">
          <thead>
            <tr>
              <th>Pay date</th><th>Pay cycle</th><th>Period</th><th>Type</th>
              <th>Employees</th><th>Gross</th><th>Net</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {runs.map((r) => (
              <tr key={r.id}>
                <td><Link to={`./${r.id}`} data-testid={`payroll-run-link-${r.id}`}>{r.pay_date}</Link></td>
                <td>{r.cycle_name || r.schedule_name || 'Legacy schedule'}</td>
                <td>{r.period_start} → {r.period_end}</td>
                <td>{labelValue(r.run_type)}</td>
                <td>{r.employee_count}</td>
                <td>{fmtMoney(r.gross_total_cents)}</td>
                <td>{fmtMoney(r.net_total_cents)}</td>
                <td><span className={`badge badge--${r.status}`}>{labelValue(r.status)}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
