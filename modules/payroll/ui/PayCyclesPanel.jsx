import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

/**
 * Cycles panel — renders inside PaySchedules and the standalone Cycles route.
 *
 * Pay groups are optional cohort layers above pay schedules. They are useful
 * only when employees on the same schedule need independent calendars.
 */
export default function PayCyclesPanel() {
  const cyclesApi    = useApi('/modules/payroll/api/cycles.php');
  const schedulesApi = useApi('/modules/payroll/api/pay_schedules.php');
  const cycles       = cyclesApi.data?.cycles ?? [];
  const schedules    = schedulesApi.data?.schedules ?? [];
  const activeSchedules = schedules.filter((schedule) => schedule.active);
  const canCreateCycle = activeSchedules.length > 0;
  const canAutoAdvance = cycles.some((cycle) => cycle.active);

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
  const [notice, setNotice] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setErr(null); setNotice(null);
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
      setNotice('Pay group created. Open its first period when you are ready to prepare payroll.');
    } catch (e2) { setErr(e2.message); } finally { setSubmitting(false); }
  };

  const advance = async (cycleId) => {
    setBusy('advance-' + cycleId); setErr(null); setNotice(null);
    try {
      const result = await api.post('/modules/payroll/api/cycles.php?action=advance', { cycle_id: cycleId });
      cyclesApi.reload();
      setNotice(`Opened ${result.window?.period_start || 'the next period'} through ${result.window?.period_end || 'its end date'} and created draft pay run #${result.run_id}.`);
    } catch (e2) { setErr(e2.message); } finally { setBusy(null); }
  };

  const autoAdvance = async () => {
    setBusy('auto'); setErr(null); setNotice(null);
    try {
      const result = await api.post('/modules/payroll/api/cycles.php?action=auto_advance', {});
      cyclesApi.reload();
      setNotice(result.advanced
        ? `Opened ${result.advanced} due pay ${result.advanced === 1 ? 'period' : 'periods'}.`
        : 'All active pay groups already have a current period.');
    } catch (e2) { setErr(e2.message); } finally { setBusy(null); }
  };

  const toggleActive = async (c) => {
    setBusy(`toggle-${c.id}`); setErr(null); setNotice(null);
    try {
      if (c.active) await api.delete(`/modules/payroll/api/cycles.php?id=${c.id}`);
      else          await api.put(`/modules/payroll/api/cycles.php?id=${c.id}`, { active: 1 });
      cyclesApi.reload();
      setNotice(`${c.name} ${c.active ? 'disabled' : 'enabled'}.`);
    } catch (e2) { setErr(e2.message); } finally { setBusy(null); }
  };

  return (
    <section className="payroll-cycles" data-testid="payroll-cycles">
      <header className="payroll-schedules__header">
        <div>
          <h2>Pay groups</h2>
          <p>
            Optional calendars for teams that share a pay schedule but process payroll on different dates.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {cycles.length > 0 && (
            <button
              className="btn btn--ghost"
              onClick={autoAdvance}
              disabled={busy === 'auto' || !canAutoAdvance}
              data-testid="payroll-cycles-auto-advance-btn"
              title={canAutoAdvance ? 'Create the next period for every cycle that is due' : 'Enable a cycle first'}
            >
              {busy === 'auto' ? 'Opening…' : 'Open all due periods'}
            </button>
          )}
          <button
            className="btn btn--primary"
            onClick={() => setShowForm((v) => !v)}
            disabled={!canCreateCycle}
            title={canCreateCycle ? 'Create a pay group' : 'Create an active pay schedule first'}
            data-testid="payroll-cycles-new-btn"
          >
            {showForm ? 'Cancel' : 'New pay group'}
          </button>
        </div>
      </header>

      {err && <p className="error" data-testid="payroll-cycles-error">{err}</p>}
      {notice && <div className="alert alert--ok" data-testid="payroll-cycles-notice">{notice}</div>}

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
              {activeSchedules.map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.frequency})</option>
              ))}
            </select>
          </label>
          <label>
            <span>Notes (optional)</span>
            <input
              type="text"
              value={form.notes}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
              placeholder="Who belongs in this group?"
              data-testid="payroll-cycle-notes"
            />
          </label>
          <details style={{ gridColumn: '1 / -1' }} data-testid="payroll-cycle-advanced">
            <summary style={{ cursor: 'pointer', fontWeight: 600 }}>Advanced date and routing overrides</summary>
            <p className="muted" style={{ margin: '6px 0 12px' }}>
              Leave these blank to inherit the selected schedule. Routing rules are intended for administrators.
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
              <label>
                <span>Routing rule (JSON)</span>
                <input
                  type="text"
                  value={form.cohort_filter_json}
                  onChange={(e) => setForm({ ...form, cohort_filter_json: e.target.value })}
                  placeholder='{"work_state":"NY"}'
                  data-testid="payroll-cycle-cohort"
                />
              </label>
              <label>
                <span>First period starts on</span>
                <input
                  type="date"
                  value={form.anchor_date_override}
                  onChange={(e) => setForm({ ...form, anchor_date_override: e.target.value })}
                  data-testid="payroll-cycle-anchor"
                />
              </label>
              <label>
                <span>Days after period end to pay</span>
                <input
                  type="number" min="0" max="30"
                  value={form.pay_date_offset_days_override}
                  onChange={(e) => setForm({ ...form, pay_date_offset_days_override: e.target.value })}
                  data-testid="payroll-cycle-offset"
                />
              </label>
            </div>
          </details>
          <button
            type="submit" className="btn btn--primary"
            disabled={submitting}
            data-testid="payroll-cycle-submit"
          >
            {submitting ? 'Saving…' : 'Create pay group'}
          </button>
        </form>
      )}

      {cyclesApi.loading && <p>Loading…</p>}
      {!cyclesApi.loading && cycles.length === 0 && (
        <p className="empty-state">
          {canCreateCycle
            ? 'No pay groups yet. That is fine for teams that use one calendar per schedule.'
            : 'Create an active pay schedule before adding pay groups.'}
        </p>
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
                    disabled={!c.active || busy !== null}
                    data-testid={`payroll-cycle-advance-${c.id}`}
                  >
                    {busy === 'advance-' + c.id ? 'Opening…' : 'Open next period'}
                  </button>
                  <button
                    className="btn btn--ghost"
                    onClick={() => toggleActive(c)}
                    disabled={busy !== null}
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
