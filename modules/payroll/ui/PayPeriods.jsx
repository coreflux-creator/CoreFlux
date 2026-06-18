import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi, api } from '../../../dashboard/src/lib/api';
import CsvUploadWidget from '../../../dashboard/src/components/CsvUploadWidget';

export default function PayPeriods() {
  const periodsApi = useApi('/api/v1/payroll/pay-periods');
  const schedulesApi = useApi('/api/v1/payroll/pay-schedules');
  const periods = periodsApi.data?.periods ?? [];
  const schedules = schedulesApi.data?.schedules ?? [];
  const [busy, setBusy] = useState(null);
  const [csvPeriodId, setCsvPeriodId] = useState(null);

  const generateMore = async (scheduleId) => {
    setBusy(scheduleId);
    try {
      await api.post('/api/v1/payroll/pay-periods', { schedule_id: scheduleId, count: 6 });
      periodsApi.reload();
    } finally { setBusy(null); }
  };

  const startRun = async (periodId) => {
    setBusy(`run-${periodId}`);
    try {
      const res = await api.post('/api/v1/payroll/runs', { pay_period_id: periodId });
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
              <React.Fragment key={p.id}>
              <tr>
                <td>{p.period_number}</td>
                <td>{p.period_start} → {p.period_end}</td>
                <td>{p.pay_date}</td>
                <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
                <td>
                  {(p.status === 'draft' || p.status === 'open') && (
                    <>
                      <button
                        className="btn btn--primary"
                        disabled={busy === `run-${p.id}`}
                        onClick={() => startRun(p.id)}
                        data-testid={`payroll-period-start-run-${p.id}`}
                      >
                        Start run
                      </button>
                      <button
                        className="btn btn--ghost"
                        onClick={() => setCsvPeriodId(p.id === csvPeriodId ? null : p.id)}
                        data-testid={`payroll-period-csv-toggle-${p.id}`}
                        style={{ marginLeft: 6 }}
                      >
                        {csvPeriodId === p.id ? 'Hide CSV' : 'Import CSV'}
                      </button>
                    </>
                  )}
                  {(p.status === 'approved' || p.status === 'paid') && (
                    <span className="muted">Run created</span>
                  )}
                </td>
              </tr>
              {csvPeriodId === p.id && (p.status === 'draft' || p.status === 'open') && (
                <tr data-testid={`payroll-period-csv-row-${p.id}`}>
                  <td colSpan={5} style={{ background: '#fafafa', padding: 12 }}>
                    <CsvUploadWidget
                      testIdPrefix={`payroll-period-${p.id}-csv`}
                      endpoint="/api/payroll/import_csv.php"
                      extraFields={{ pay_period_id: p.id, run_type: 'regular' }}
                      accept=".csv,text/csv"
                      label={`Import payroll register CSV into period ${p.period_number}`}
                      hint="Header row required. Columns: employee_email or employee_name (matched against people) · work_state · pay_type (salary/hourly) · pay_rate · gross_pay · employee_taxes · pretax_deductions · posttax_deductions · net_pay · employer_taxes. Creates one payroll_run in status='computed' — approve via the existing flow."
                      onSuccess={(r) => {
                        setCsvPeriodId(null);
                        if (r?.run_id) {
                          window.location.assign(`#/modules/payroll/runs/${r.run_id}`);
                          window.location.reload();
                        }
                      }}
                    />
                  </td>
                </tr>
              )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
