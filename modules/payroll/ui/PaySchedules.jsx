import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';
import PayCyclesPanel from './PayCyclesPanel';

const FREQS = ['weekly', 'biweekly', 'semimonthly', 'monthly'];

export default function PaySchedules() {
  const { data, loading, error, reload } = useApi('/api/v1/payroll/pay-schedules');
  const schedules = data?.schedules ?? [];

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    name: '',
    frequency: 'biweekly',
    period_start_anchor: new Date().toISOString().slice(0, 10),
    pay_date_offset_days: 5,
  });
  const [submitting, setSubmitting] = useState(false);
  const [formErr, setFormErr] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setFormErr(null);
    try {
      await api.post('/api/v1/payroll/pay-schedules', form);
      setShowForm(false);
      setForm({ ...form, name: '' });
      reload();
    } catch (err) {
      setFormErr(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const toggleActive = async (s) => {
    if (s.active) {
      await api.delete(`/api/v1/payroll/pay-schedules?id=${s.id}`);
    } else {
      await api.put(`/api/v1/payroll/pay-schedules?id=${s.id}`, { active: 1 });
    }
    reload();
  };

  return (
    <section className="payroll-schedules" data-testid="payroll-schedules">
      <header className="payroll-schedules__header">
        <div>
          <h2>Pay Schedules</h2>
          <p>Define how often employees get paid and when. Periods auto-generate when a schedule is created.</p>
        </div>
        <button
          className="btn btn--primary"
          onClick={() => setShowForm((v) => !v)}
          data-testid="payroll-schedules-new-btn"
        >
          {showForm ? 'Cancel' : 'New schedule'}
        </button>
      </header>

      {showForm && (
        <form className="payroll-schedules__form" onSubmit={submit} data-testid="payroll-schedules-form">
          <label>
            <span>Name</span>
            <input
              type="text" required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. Bi-weekly Engineering"
              data-testid="payroll-schedule-name"
            />
          </label>
          <label>
            <span>Frequency</span>
            <select
              value={form.frequency}
              onChange={(e) => setForm({ ...form, frequency: e.target.value })}
              data-testid="payroll-schedule-frequency"
            >
              {FREQS.map((f) => <option key={f} value={f}>{f}</option>)}
            </select>
          </label>
          <label>
            <span>Period 1 starts on</span>
            <input
              type="date" required
              value={form.period_start_anchor}
              onChange={(e) => setForm({ ...form, period_start_anchor: e.target.value })}
              data-testid="payroll-schedule-anchor"
            />
          </label>
          <label>
            <span>Pay date offset (days after period end)</span>
            <input
              type="number" min="0" max="30" required
              value={form.pay_date_offset_days}
              onChange={(e) => setForm({ ...form, pay_date_offset_days: parseInt(e.target.value, 10) })}
              data-testid="payroll-schedule-offset"
            />
          </label>
          {formErr && <p className="error">{formErr}</p>}
          <button
            type="submit" className="btn btn--primary"
            disabled={submitting}
            data-testid="payroll-schedule-submit"
          >
            {submitting ? 'Saving…' : 'Create schedule'}
          </button>
        </form>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {!loading && schedules.length === 0 && (
        <p className="empty-state">No schedules yet. Create one to start running payroll.</p>
      )}

      {schedules.length > 0 && (
        <table className="data-table" data-testid="payroll-schedules-table">
          <thead>
            <tr><th>Name</th><th>Frequency</th><th>Period 1 anchor</th><th>Pay offset</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            {schedules.map((s) => (
              <tr key={s.id}>
                <td>{s.name}</td>
                <td>{s.frequency}</td>
                <td>{s.period_start_anchor}</td>
                <td>+{s.pay_date_offset_days} days</td>
                <td>
                  <span className={`badge badge--${s.active ? 'active' : 'inactive'}`}>
                    {s.active ? 'active' : 'inactive'}
                  </span>
                </td>
                <td>
                  <button
                    className="btn btn--ghost"
                    onClick={() => toggleActive(s)}
                    data-testid={`payroll-schedule-toggle-${s.id}`}
                  >
                    {s.active ? 'Disable' : 'Enable'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <hr style={{ margin: '32px 0', border: 0, borderTop: '1px solid #eef0f4' }} />
      <PayCyclesPanel />
    </section>
  );
}
