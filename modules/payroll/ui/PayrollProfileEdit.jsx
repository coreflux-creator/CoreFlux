import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

export default function PayrollProfileEdit() {
  const { employeeId } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [schedules, setSchedules] = useState([]);
  const [cycles, setCycles] = useState([]);
  const [form, setForm] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let mounted = true;
    Promise.all([
      api.get(`/modules/payroll/api/profiles.php?employee_id=${employeeId}`),
      api.get('/modules/payroll/api/pay_schedules.php'),
      api.get('/modules/payroll/api/cycles.php'),
    ]).then(([d, s, c]) => {
      if (!mounted) return;
      setData(d);
      setSchedules(s.schedules || []);
      setCycles(c.cycles || []);
      const scheduleId = d.profile?.schedule_id || (s.schedules?.find((item) => item.active)?.id ?? '');
      const matchingCycles = (c.cycles || []).filter((item) => (
        Number(item.schedule_id) === Number(scheduleId) && Number(item.active) === 1
      ));
      setForm({
        schedule_id: scheduleId,
        cycle_id: d.profile?.cycle_id || (matchingCycles.length === 1 ? matchingCycles[0].id : ''),
        work_state: d.profile?.work_state || 'CA',
        payment_method: d.profile?.payment_method || 'direct_deposit',
        default_hours_per_period: d.profile?.default_hours_per_period ?? 80,
        retirement_percent: (d.profile?.retirement_pretax_bps ?? 0) / 100,
        health_premium: (d.profile?.health_premium_cents ?? 0) / 100,
        hsa_pretax: (d.profile?.hsa_pretax_cents ?? 0) / 100,
        extra_post_tax: (d.profile?.extra_post_tax_cents ?? 0) / 100,
        enabled: d.profile?.enabled ?? 1,
        notes: d.profile?.notes ?? '',
      });
    }).catch((e) => setError(e.message));
    return () => { mounted = false; };
  }, [employeeId]);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      await api.post('/modules/payroll/api/profiles.php', {
        employee_id: parseInt(employeeId, 10),
        ...form,
        schedule_id: form.schedule_id ? parseInt(form.schedule_id, 10) : null,
        cycle_id: form.cycle_id ? parseInt(form.cycle_id, 10) : null,
        retirement_pretax_bps: Math.round((parseFloat(form.retirement_percent) || 0) * 100),
        health_premium_cents: Math.round((parseFloat(form.health_premium) || 0) * 100),
        hsa_pretax_cents: Math.round((parseFloat(form.hsa_pretax) || 0) * 100),
        extra_post_tax_cents: Math.round((parseFloat(form.extra_post_tax) || 0) * 100),
      });
      navigate('../profiles');
    } catch (err) {
      setError(err.message);
    } finally { setBusy(false); }
  };

  if (!data || !form) return <p>Loading…</p>;
  const emp = data.employee;
  const gaps = data.gaps || [];
  const availableCycles = cycles.filter((cycle) => (
    Number(cycle.schedule_id) === Number(form.schedule_id) && Number(cycle.active) === 1
  ));

  return (
    <section className="payroll-profile-edit" data-testid="payroll-profile-edit">
      <header>
        <h2>{emp.preferred_name || emp.legal_first_name} {emp.legal_last_name}</h2>
        <p className="muted">Employee #{emp.employee_number} · {emp.department || 'No department'}</p>
      </header>

      {gaps.length > 0 && (
        <div className="alert alert--warn" data-testid="payroll-profile-gaps">
          <strong>People-module data needed before payroll can run:</strong>
          <ul>
            {gaps.map((g) => <li key={g}>{g}</li>)}
          </ul>
          <p className="muted">Update these in the People module — Payroll only manages the profile fields below.</p>
        </div>
      )}

      <form onSubmit={submit} className="payroll-profile-edit__form">
        <fieldset>
          <legend>Schedule & state</legend>
          <label>
            <span>Pay schedule</span>
            <select
              value={form.schedule_id || ''}
              onChange={(e) => {
                const scheduleId = e.target.value;
                const matches = cycles.filter((cycle) => (
                  Number(cycle.schedule_id) === Number(scheduleId) && Number(cycle.active) === 1
                ));
                setForm({
                  ...form,
                  schedule_id: scheduleId,
                  cycle_id: matches.length === 1 ? matches[0].id : '',
                });
              }}
              data-testid="payroll-profile-schedule"
            >
              <option value="">— Select —</option>
              {schedules.filter((s) => s.active || Number(s.id) === Number(form.schedule_id)).map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.frequency})</option>
              ))}
            </select>
          </label>
          <label>
            <span>Pay cycle</span>
            <select
              value={form.cycle_id || ''}
              onChange={(e) => setForm({ ...form, cycle_id: e.target.value })}
              data-testid="payroll-profile-cycle"
              required={availableCycles.length > 1}
            >
              <option value="">{availableCycles.length ? '— Select —' : 'No cycle on this schedule'}</option>
              {availableCycles.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>{cycle.name}</option>
              ))}
            </select>
            {availableCycles.length > 1 && !form.cycle_id && (
              <small className="muted">Choose the cohort that should include this employee.</small>
            )}
          </label>
          <label>
            <span>Work state</span>
            <input
              type="text" maxLength="2"
              value={form.work_state}
              onChange={(e) => setForm({ ...form, work_state: e.target.value.toUpperCase() })}
              data-testid="payroll-profile-state"
            />
          </label>
          <label>
            <span>Payment method</span>
            <select
              value={form.payment_method}
              onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
              data-testid="payroll-profile-method"
            >
              <option value="direct_deposit">Direct deposit</option>
              <option value="check">Paper check</option>
            </select>
          </label>
          <label>
            <span>Default hours per period (hourly only)</span>
            <input
              type="number" step="0.01"
              value={form.default_hours_per_period}
              onChange={(e) => setForm({ ...form, default_hours_per_period: parseFloat(e.target.value) })}
              data-testid="payroll-profile-default-hours"
            />
          </label>
        </fieldset>

        <fieldset>
          <legend>Pre-tax deductions (per pay period)</legend>
          <label>
            <span>401(k) contribution (%)</span>
            <input
              type="number" min="0" max="100" step="0.01"
              value={form.retirement_percent}
              onChange={(e) => setForm({ ...form, retirement_percent: e.target.value })}
              data-testid="payroll-profile-401k"
            />
          </label>
          <label>
            <span>Health premium per pay period ($)</span>
            <input
              type="number" min="0" step="0.01"
              value={form.health_premium}
              onChange={(e) => setForm({ ...form, health_premium: e.target.value })}
              data-testid="payroll-profile-health"
            />
          </label>
          <label>
            <span>HSA contribution per pay period ($)</span>
            <input
              type="number" min="0" step="0.01"
              value={form.hsa_pretax}
              onChange={(e) => setForm({ ...form, hsa_pretax: e.target.value })}
              data-testid="payroll-profile-hsa"
            />
          </label>
        </fieldset>

        <fieldset>
          <legend>Post-tax deductions</legend>
          <label>
            <span>Other post-tax per pay period ($)</span>
            <input
              type="number" min="0" step="0.01"
              value={form.extra_post_tax}
              onChange={(e) => setForm({ ...form, extra_post_tax: e.target.value })}
              data-testid="payroll-profile-posttax"
            />
          </label>
        </fieldset>

        <fieldset>
          <legend>Internal note</legend>
          <label>
            <span>Notes</span>
            <textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })}
                      rows={3} data-testid="payroll-profile-notes" />
          </label>
        </fieldset>

        <fieldset>
          <label className="checkbox">
            <input
              type="checkbox"
              checked={!!form.enabled}
              onChange={(e) => setForm({ ...form, enabled: e.target.checked ? 1 : 0 })}
              data-testid="payroll-profile-enabled"
            />
            <span>Include this employee in payroll runs</span>
          </label>
        </fieldset>

        {error && <p className="error">{error}</p>}
        <div className="payroll-profile-edit__actions">
          <button type="button" className="btn btn--ghost" onClick={() => navigate('../profiles')}>
            Cancel
          </button>
          <button type="submit" className="btn btn--primary" disabled={busy} data-testid="payroll-profile-save">
            {busy ? 'Saving…' : 'Save profile'}
          </button>
        </div>
      </form>
    </section>
  );
}
