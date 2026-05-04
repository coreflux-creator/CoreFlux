import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

/**
 * Cycles panel — renders inside PaySchedules and the standalone Cycles route.
 *
 * Cycles are cohort layers above pay schedules: a single bi-weekly schedule
 * can run multiple cohorts (NY engineers, CA sales, FL contractors) on
 * independent calendars. This panel lets the user create cycles, advance
 * them manually, or trigger a cron-style sweep that auto-advances every
 * cycle whose newest period has ended.
 */
export default function PayCyclesPanel() {
  const cyclesApi    = useApi('/modules/payroll/api/cycles.php');
  const schedulesApi = useApi('/modules/payroll/api/pay_schedules.php');
  const cycles       = cyclesApi.data?.cycles ?? [];
  const schedules    = schedulesApi.data?.schedules ?? [];

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    name: '',
    schedule_id: '',
    cohort_filter_json: '',
    anchor_date_override: '',
    pay_date_offset_days_override: '',
    notes: '',
  });
  const [submitting, setSubmitting] = useState(false);
  const [busy, setBusy] = useState(null);
  const [err, setErr] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setErr(null);
    try {
      const payload = {
        name: form.name,
        schedule_id: parseInt(form.schedule_id, 10),
      };
      if (form.cohort_filter_json) {
        try { payload.cohort_filter_json = JSON.parse(form.cohort_filter_json); }
        catch { throw new Error('Cohort filter must be valid JSON'); }
      }
      if (form.anchor_date_override) payload.anchor_date_override = form.anchor_date_override;
      if (form.pay_date_offset_days_override !== '') {
        payload.pay_date_offset_days_override = parseInt(form.pay_date_offset_days_override, 10);
      }
      if (form.notes) payload.notes = form.notes;
      await api.post('/modules/payroll/api/cycles.php', payload);
      setShowForm(false);
      setForm({ ...form, name: '', cohort_filter_json: '', anchor_date_override: '',
                pay_date_offset_days_override: '', notes: '' });
      cyclesApi.reload();
    } catch (e2) { setErr(e2.message); } finally { setSubmitting(false); }
  };

  const advance = async (cycleId) => {
    setBusy('advance-' + cycleId); setErr(null);
    try {
      await api.post('/modules/payroll/api/cycles.php?action=advance', { cycle_id: cycleId });
      cyclesApi.reload();
    } catch (e2) { setErr(e2.message); } finally { setBusy(null); }
  };

  const autoAdvance = async () => {
    setBusy('auto'); setErr(null);
    try {
      await api.post('/modules/payroll/api/cycles.php?action=auto_advance', {});
      cyclesApi.reload();
    } catch (e2) { setErr(e2.message); } finally { setBusy(null); }
  };

  const toggleActive = async (c) => {
    if (c.active) await api.delete(`/modules/payroll/api/cycles.php?id=${c.id}`);
    else          await api.put(`/modules/payroll/api/cycles.php?id=${c.id}`, { active: 1 });
    cyclesApi.reload();
  };

  return (
    <section className="payroll-cycles" data-testid="payroll-cycles">
      <header className="payroll-schedules__header">
        <div>
          <h3>Pay Cycles</h3>
          <p>
            Cohorts on top of a schedule (e.g. NY engineers vs CA sales). Each cycle has its own
            calendar — advance manually, or let the deploy-time sweep auto-advance once a period ends.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            className="btn btn--ghost"
            onClick={autoAdvance}
            disabled={busy === 'auto'}
            data-testid="payroll-cycles-auto-advance-btn"
          >
            {busy === 'auto' ? 'Sweeping…' : 'Auto-advance all due'}
          </button>
          <button
            className="btn btn--primary"
            onClick={() => setShowForm((v) => !v)}
            data-testid="payroll-cycles-new-btn"
          >
            {showForm ? 'Cancel' : 'New cycle'}
          </button>
        </div>
      </header>

      {err && <p className="error" data-testid="payroll-cycles-error">{err}</p>}

      {showForm && (
        <form className="payroll-schedules__form" onSubmit={submit} data-testid="payroll-cycles-form">
          <label>
            <span>Name</span>
            <input
              type="text" required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. BW – NY Engineering"
              data-testid="payroll-cycle-name"
            />
          </label>
          <label>
            <span>Schedule</span>
            <select
              required
              value={form.schedule_id}
              onChange={(e) => setForm({ ...form, schedule_id: e.target.value })}
              data-testid="payroll-cycle-schedule"
            >
              <option value="">— select —</option>
              {schedules.filter((s) => s.active).map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.frequency})</option>
              ))}
            </select>
          </label>
          <label>
            <span>Cohort filter (optional JSON)</span>
            <input
              type="text"
              value={form.cohort_filter_json}
              onChange={(e) => setForm({ ...form, cohort_filter_json: e.target.value })}
              placeholder='{"work_state":"NY"}'
              data-testid="payroll-cycle-cohort"
            />
          </label>
          <label>
            <span>Anchor date override (optional)</span>
            <input
              type="date"
              value={form.anchor_date_override}
              onChange={(e) => setForm({ ...form, anchor_date_override: e.target.value })}
              data-testid="payroll-cycle-anchor"
            />
          </label>
          <label>
            <span>Pay-date offset override (optional)</span>
            <input
              type="number" min="0" max="30"
              value={form.pay_date_offset_days_override}
              onChange={(e) => setForm({ ...form, pay_date_offset_days_override: e.target.value })}
              data-testid="payroll-cycle-offset"
            />
          </label>
          <button
            type="submit" className="btn btn--primary"
            disabled={submitting}
            data-testid="payroll-cycle-submit"
          >
            {submitting ? 'Saving…' : 'Create cycle'}
          </button>
        </form>
      )}

      {cyclesApi.loading && <p>Loading…</p>}
      {!cyclesApi.loading && cycles.length === 0 && (
        <p className="empty-state">No cycles yet. Create one for each pay cohort.</p>
      )}

      {cycles.length > 0 && (
        <table className="data-table" data-testid="payroll-cycles-table">
          <thead>
            <tr>
              <th>Name</th><th>Schedule</th><th>Frequency</th>
              <th>Next #</th><th>Last advanced</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            {cycles.map((c) => (
              <tr key={c.id} data-testid={`payroll-cycle-row-${c.id}`}>
                <td>{c.name}</td>
                <td>{c.schedule_name}</td>
                <td>{c.frequency}</td>
                <td>{c.next_period_number}</td>
                <td>{c.last_advanced_at ?? '—'}</td>
                <td>
                  <span className={`badge badge--${c.active ? 'active' : 'inactive'}`}>
                    {c.active ? 'active' : 'inactive'}
                  </span>
                </td>
                <td>
                  <button
                    className="btn btn--ghost"
                    onClick={() => advance(c.id)}
                    disabled={!c.active || busy === 'advance-' + c.id}
                    data-testid={`payroll-cycle-advance-${c.id}`}
                  >
                    {busy === 'advance-' + c.id ? 'Advancing…' : 'Advance'}
                  </button>
                  <button
                    className="btn btn--ghost"
                    onClick={() => toggleActive(c)}
                    data-testid={`payroll-cycle-toggle-${c.id}`}
                  >
                    {c.active ? 'Disable' : 'Enable'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
