import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import ApprovedHoursReadyTile from '../../staffing/ui/ApprovedHoursReadyTile';

const fmtMoney = (cents) =>
  ((cents || 0) / 100).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

export default function PayrollOverview() {
  const runsApi    = useApi('/modules/payroll/api/runs.php');
  const periodsApi = useApi('/modules/payroll/api/pay_periods.php');
  const profilesApi = useApi('/modules/payroll/api/profiles.php');
  const anomApi    = useApi('/modules/payroll/api/anomalies.php?dashboard=1&limit=10');

  const recentRuns = runsApi.data?.runs ?? [];
  const upcomingPeriods = (periodsApi.data?.periods ?? [])
    .filter((p) => p.status === 'draft' || p.status === 'open')
    .slice(0, 5);
  const profiles = profilesApi.data?.profiles ?? [];
  const readyCount = profiles.filter((p) => p.ready).length;
  const gapCount = profiles.length - readyCount;
  const anomalies      = anomApi.data?.unacknowledged ?? [];
  const anomalyTotal   = anomApi.data?.count ?? 0;
  const criticalCount  = anomApi.data?.critical_count ?? 0;

  return (
    <section className="payroll-overview" data-testid="payroll-overview">
      <header className="payroll-overview__header">
        <h2>Payroll</h2>
        <p className="payroll-overview__subtitle">
          Schedules, employee setup, and gross-to-net runs. All numbers are computed
          deterministically — AI provides narrative review only.
        </p>
      </header>

      <ApprovedHoursReadyTile variant="payroll" to="../pay_periods" />

      <div className="payroll-stats" data-testid="payroll-overview-stats">
        <div className="stat-card">
          <div className="stat-card__value">{profiles.length}</div>
          <div className="stat-card__label">Employees on payroll</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value" data-testid="payroll-ready-count">{readyCount}</div>
          <div className="stat-card__label">Payroll-ready</div>
        </div>
        <div className="stat-card stat-card--warn">
          <div className="stat-card__value">{gapCount}</div>
          <div className="stat-card__label">Need setup</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{recentRuns.length}</div>
          <div className="stat-card__label">Recent runs</div>
        </div>
        {anomalyTotal > 0 && (
          <Link
            to="../anomalies"
            className={`stat-card stat-card--alert${criticalCount > 0 ? ' stat-card--critical' : ''}`}
            data-testid="payroll-overview-anomalies-badge"
          >
            <div className="stat-card__value" data-testid="payroll-overview-anomalies-count">
              {anomalyTotal}
            </div>
            <div className="stat-card__label">
              AI anomaly{anomalyTotal === 1 ? '' : 'ies'}
              {criticalCount > 0 && (
                <span className="badge badge--critical" data-testid="payroll-overview-anomalies-critical">
                  {' '}{criticalCount} critical
                </span>
              )}
            </div>
          </Link>
        )}
      </div>

      {anomalies.length > 0 && (
        <section className="payroll-overview__anomalies" data-testid="payroll-overview-anomalies-panel">
          <header className="payroll-overview__section-head">
            <h3>Latest unacknowledged anomalies</h3>
            <Link to="../anomalies" className="btn btn--ghost" data-testid="payroll-overview-anomalies-view-all">
              View all
            </Link>
          </header>
          <ul className="payroll-overview__anomaly-list">
            {anomalies.slice(0, 5).map((a) => (
              <li key={a.id} className={`payroll-overview__anomaly payroll-overview__anomaly--${a.severity}`}>
                <span className={`badge badge--${a.severity}`}>{a.severity}</span>
                <span className="payroll-overview__anomaly-msg">{a.message}</span>
                {a.pay_date && <span className="muted"> · {a.pay_date}</span>}
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="payroll-overview__grid">
        <section>
          <header className="payroll-overview__section-head">
            <h3>Upcoming pay periods</h3>
            <Link to="../pay_periods" className="btn btn--ghost" data-testid="payroll-overview-view-periods">
              View all
            </Link>
          </header>
          {upcomingPeriods.length === 0 ? (
            <p className="empty-state">
              No upcoming periods. <Link to="../pay_schedules">Create a pay schedule</Link> to generate them.
            </p>
          ) : (
            <table className="data-table" data-testid="payroll-overview-periods">
              <thead>
                <tr><th>#</th><th>Period</th><th>Pay date</th><th>Status</th></tr>
              </thead>
              <tbody>
                {upcomingPeriods.map((p) => (
                  <tr key={p.id}>
                    <td>{p.period_number}</td>
                    <td>{p.period_start} → {p.period_end}</td>
                    <td>{p.pay_date}</td>
                    <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>

        <section>
          <header className="payroll-overview__section-head">
            <h3>Recent runs</h3>
            <Link to="../runs" className="btn btn--ghost" data-testid="payroll-overview-view-runs">View all</Link>
          </header>
          {recentRuns.length === 0 ? (
            <p className="empty-state">No runs yet. Open a pay period and create a run to start.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Pay date</th><th>Employees</th><th>Gross</th><th>Net</th><th>Status</th></tr>
              </thead>
              <tbody>
                {recentRuns.slice(0, 5).map((r) => (
                  <tr key={r.id}>
                    <td><Link to={`../runs/${r.id}`}>{r.pay_date}</Link></td>
                    <td>{r.employee_count}</td>
                    <td>{fmtMoney(r.gross_total_cents)}</td>
                    <td>{fmtMoney(r.net_total_cents)}</td>
                    <td><span className={`badge badge--${r.status}`}>{r.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      </div>
    </section>
  );
}
