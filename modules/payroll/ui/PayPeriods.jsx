import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useApi, api } from '../../../dashboard/src/lib/api';
import CsvUploadWidget from '../../../dashboard/src/components/CsvUploadWidget';

const statusLabel = (value) => String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());

export default function PayPeriods() {
  const navigate = useNavigate();
  const periodsApi = useApi('/modules/payroll/api/pay_periods.php');
  const schedulesApi = useApi('/modules/payroll/api/pay_schedules.php');
  const cyclesApi = useApi('/modules/payroll/api/cycles.php');
  const periods = periodsApi.data?.periods ?? [];
  const schedules = schedulesApi.data?.schedules ?? [];
  const cycles = cyclesApi.data?.cycles ?? [];
  const [busy, setBusy] = useState(null);
  const [csvPeriodId, setCsvPeriodId] = useState(null);

  const advanceCycle = async (cycleId) => {
    setBusy(`cycle-${cycleId}`);
    try {
      const result = await api.post('/modules/payroll/api/pay_periods.php', { cycle_id: cycleId });
      periodsApi.reload();
      cyclesApi.reload();
      if (result?.run_id) navigate(`/modules/payroll/runs/${result.run_id}`);
    } finally { setBusy(null); }
  };

  const generateLegacy = async (scheduleId) => {
    setBusy(`schedule-${scheduleId}`);
    try {
      await api.post('/modules/payroll/api/pay_periods.php', { schedule_id: scheduleId, count: 6 });
      periodsApi.reload();
    } finally { setBusy(null); }
  };

  const startRun = async (periodId) => {
    setBusy(`run-${periodId}`);
    try {
      const res = await api.post('/modules/payroll/api/runs.php', { pay_period_id: periodId });
      navigate(`/modules/payroll/runs/${res.id}`);
    } finally { setBusy(null); }
  };

  return (
    <section className="payroll-periods" data-testid="payroll-periods">
      <header>
        <h2>Pay periods</h2>
        <p>Choose a period, review readiness, and start its pay run. Pay groups are optional and only needed when teams follow different calendars.</p>
      </header>

      {cycles.some((cycle) => cycle.active) && (
        <div className="payroll-periods__sched-actions" data-testid="payroll-periods-sched-actions">
          {cycles.filter((cycle) => cycle.active).map((cycle) => (
            <button
              key={cycle.id}
              className="btn btn--ghost"
              disabled={busy === `cycle-${cycle.id}`}
              onClick={() => advanceCycle(cycle.id)}
              data-testid={`payroll-periods-advance-cycle-${cycle.id}`}
            >
              {busy === `cycle-${cycle.id}` ? 'Opening...' : `Open next pay period: ${cycle.name}`}
            </button>
          ))}
        </div>
      )}

      {cycles.length === 0 && schedules.length > 0 && (
        <div className="payroll-periods__sched-actions" data-testid="payroll-periods-legacy-actions">
          {schedules.filter((schedule) => schedule.active).map((schedule) => (
            <button key={schedule.id} className="btn btn--ghost"
                    disabled={busy === `schedule-${schedule.id}`}
                    onClick={() => generateLegacy(schedule.id)}>
              {busy === `schedule-${schedule.id}` ? 'Generating...' : `Generate periods: ${schedule.name}`}
            </button>
          ))}
        </div>
      )}

      {periodsApi.loading && <p>Loading…</p>}
      {periodsApi.error && <p className="error">{periodsApi.error.message}</p>}
      {!periodsApi.loading && periods.length === 0 && (
        <p className="empty-state">
          {schedules.length === 0
            ? <>No pay periods. <Link to="../pay_schedules">Create a pay schedule</Link> to generate them.</>
            : <>No pay periods are available. Generate more above or <Link to="../pay_schedules">review the schedule</Link>.</>}
        </p>
      )}

      {periods.length > 0 && (
        <table className="data-table" data-testid="payroll-periods-table">
          <thead>
            <tr><th>Pay schedule</th><th>#</th><th>Period</th><th>Pay date</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            {periods.map((p) => (
              <React.Fragment key={p.id}>
              <tr>
                <td>
                  <strong>{p.cycle_name || p.schedule_name || 'Legacy schedule'}</strong>
                  {p.cycle_name && <div className="muted">{p.schedule_name}</div>}
                </td>
                <td>{p.period_number}</td>
                <td>{p.period_start} → {p.period_end}</td>
                <td>{p.pay_date}</td>
                <td><span className={`badge badge--${p.status}`}>{statusLabel(p.status)}</span></td>
                <td>
                  {(p.status === 'draft' || p.status === 'open') && (
                    <>
                      {p.latest_run_id ? (
                        <button className="btn btn--primary"
                                onClick={() => navigate(`/modules/payroll/runs/${p.latest_run_id}`)}
                                data-testid={`payroll-period-open-run-${p.id}`}>
                          Open {statusLabel(p.latest_run_status || 'draft').toLowerCase()} run
                        </button>
                      ) : (
                        <button
                          className="btn btn--primary"
                          disabled={busy === `run-${p.id}`}
                          onClick={() => startRun(p.id)}
                          data-testid={`payroll-period-start-run-${p.id}`}
                        >
                          {busy === `run-${p.id}` ? 'Starting...' : 'Start run'}
                        </button>
                      )}
                      {(!p.latest_run_id || p.latest_run_status === 'draft') && (
                        <button
                          className="btn btn--ghost"
                          onClick={() => setCsvPeriodId(p.id === csvPeriodId ? null : p.id)}
                          data-testid={`payroll-period-csv-toggle-${p.id}`}
                          style={{ marginLeft: 6 }}
                        >
                          {csvPeriodId === p.id ? 'Hide import' : 'Import payroll register'}
                        </button>
                      )}
                    </>
                  )}
                  {(p.status === 'approved' || p.status === 'paid') && (
                    <span className="muted">Run created</span>
                  )}
                </td>
              </tr>
              {csvPeriodId === p.id && (p.status === 'draft' || p.status === 'open') && (
                <tr data-testid={`payroll-period-csv-row-${p.id}`}>
                  <td colSpan={6} style={{ background: '#fafafa', padding: 12 }}>
                    <CsvUploadWidget
                      testIdPrefix={`payroll-period-${p.id}-csv`}
                      endpoint="/api/payroll/import_csv.php"
                      extraFields={{ pay_period_id: p.id, run_type: 'regular' }}
                      templateHref={`/api/payroll/import_csv.php?action=template&pay_period_id=${p.id}`}
                      templateLabel="Download employee template"
                      accept=".csv,text/csv"
                      label={`Import payroll register CSV into period ${p.period_number}`}
                      hint="Use employee_number, employee_id, work email, or full name. Gross and net are required. Optional state, payment method, rate, and frequency fall back to employee payroll setup. The entire file must validate before the existing draft is updated."
                      onSuccess={(r) => {
                        setCsvPeriodId(null);
                        if (r?.run_id) {
                          navigate(`/modules/payroll/runs/${r.run_id}`);
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
