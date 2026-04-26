import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi, api } from '../../../dashboard/src/lib/api';

export default function PayPeriods() {
  const periodsApi = useApi('/modules/payroll/api/pay_periods.php');
  const schedulesApi = useApi('/modules/payroll/api/pay_schedules.php');
  const periods = periodsApi.data?.periods ?? [];
  const schedules = schedulesApi.data?.schedules ?? [];
  const [busy, setBusy] = useState(null);

  const generateMore = async (scheduleId) => {
    setBusy(scheduleId);
    try {
      await api.post('/modules/payroll/api/pay_periods.php', { schedule_id: scheduleId, count: 6 });
      periodsApi.reload();
    } finally { setBusy(null); }
  };

  const startRun = async (periodId) => {
    setBusy(`run-${periodId}`);
    try {
      const res = await api.post('/modules/payroll/api/runs.php', { pay_period_id: periodId });
      window.location.assign(`#/modules/payroll/runs/${res.id}`);
      window.location.reload();
    } finally { setBusy(null); }
  };

  return (
    <section className="payroll-periods" data-testid="payroll-periods">
      <header>
        <h2>Pay Periods</h2>
        <p>Each schedule generates concrete pay periods. Open a period to start a payroll run.</p>
      </header>

      {schedules.length > 0 && (
        <div className="payroll-periods__sched-actions" data-testid="payroll-periods-sched-actions">
          {schedules.filter((s) => s.active).map((s) => (
            <button
              key={s.id}
              className="btn btn--ghost"
              disabled={busy === s.id}
              onClick={() => generateMore(s.id)}
              data-testid={`payroll-periods-generate-${s.id}`}
            >
              {busy === s.id ? 'Generating…' : `Generate next 6 for "${s.name}"`}
            </button>
          ))}
        </div>
      )}

      {periodsApi.loading && <p>Loading…</p>}
      {periodsApi.error && <p className="error">{periodsApi.error.message}</p>}
      {!periodsApi.loading && periods.length === 0 && (
        <p className="empty-state">
          No pay periods. <Link to="../pay_schedules">Create a schedule</Link> first.
        </p>
      )}

      {periods.length > 0 && (
        <table className="data-table" data-testid="payroll-periods-table">
          <thead>
            <tr><th>#</th><th>Period</th><th>Pay date</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            {periods.map((p) => (
              <tr key={p.id}>
                <td>{p.period_number}</td>
                <td>{p.period_start} → {p.period_end}</td>
                <td>{p.pay_date}</td>
                <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
                <td>
                  {(p.status === 'draft' || p.status === 'open') && (
                    <button
                      className="btn btn--primary"
                      disabled={busy === `run-${p.id}`}
                      onClick={() => startRun(p.id)}
                      data-testid={`payroll-period-start-run-${p.id}`}
                    >
                      Start run
                    </button>
                  )}
                  {(p.status === 'approved' || p.status === 'paid') && (
                    <span className="muted">Run created</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
