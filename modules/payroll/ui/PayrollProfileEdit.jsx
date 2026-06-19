import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

export default function PayrollProfileEdit() {
  const { employeeId } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [schedules, setSchedules] = useState([]);
  const [form, setForm] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let mounted = true;
    Promise.all([
      api.get(`/api/v1/payroll/profiles?employee_id=${employeeId}`),
      api.get('/api/v1/payroll/pay-schedules'),
    ]).then(([d, s]) => {
      if (!mounted) return;
      setData(d);
      setSchedules(s.schedules || []);
      setForm({
        schedule_id: d.profile?.schedule_id || (s.schedules?.[0]?.id ?? ''),
        work_state: d.profile?.work_state || 'CA',
        payment_method: d.profile?.payment_method || 'direct_deposit',
        default_hours_per_period: d.profile?.default_hours_per_period ?? 80,
        retirement_pretax_bps: d.profile?.retirement_pretax_bps ?? 0,
        health_premium_cents: d.profile?.health_premium_cents ?? 0,
        hsa_pretax_cents: d.profile?.hsa_pretax_cents ?? 0,
        extra_post_tax_cents: d.profile?.extra_post_tax_cents ?? 0,
        enabled: d.profile?.enabled ?? 1,
      });
    }).catch((e) => setError(e.message));
    return () => { mounted = false; };
  }, [employeeId]);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      await api.post('/api/v1/payroll/profiles', {
        employee_id: parseInt(employeeId, 10),
        ...form,
        schedule_id: form.schedule_id ? parseInt(form.schedule_id, 10) : null,
        retirement_pretax_bps: parseInt(form.retirement_pretax_bps, 10) || 0,
        health_premium_cents:  parseInt(form.health_premium_cents, 10) || 0,
        hsa_pretax_cents:      parseInt(form.hsa_pretax_cents, 10) || 0,
        extra_post_tax_cents:  parseInt(form.extra_post_tax_cents, 10) || 0,
      });
      navigate('../profiles');
    } catch (err) {
      setError(err.message);
    } finally { setBusy(false); }
  };

  if (!data || !form) return <p>Loading…</p>;
  const emp = data.employee;
  const gaps = data.gaps || [];

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
              onChange={(e) => setForm({ ...form, schedule_id: e.target.value })}
              data-testid="payroll-profile-schedule"
            >
              <option value="">— Select —</option>
              {schedules.map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.frequency})</option>
              ))}
            </select>
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
            <span>401(k) % (basis points; 500 = 5.00%)</span>
            <input
              type="number" min="0" max="10000"
              value={form.retirement_pretax_bps}
              onChange={(e) => setForm({ ...form, retirement_pretax_bps: e.target.value })}
              data-testid="payroll-profile-401k"
            />
          </label>
          <label>
            <span>Health premium (cents)</span>
            <input
              type="number" min="0"
              value={form.health_premium_cents}
              onChange={(e) => setForm({ ...form, health_premium_cents: e.target.value })}
              data-testid="payroll-profile-health"
            />
          </label>
          <label>
            <span>HSA contribution (cents)</span>
            <input
              type="number" min="0"
              value={form.hsa_pretax_cents}
              onChange={(e) => setForm({ ...form, hsa_pretax_cents: e.target.value })}
              data-testid="payroll-profile-hsa"
            />
          </label>
        </fieldset>

        <fieldset>
          <legend>Post-tax deductions</legend>
          <label>
            <span>Other post-tax (cents)</span>
            <input
              type="number" min="0"
              value={form.extra_post_tax_cents}
              onChange={(e) => setForm({ ...form, extra_post_tax_cents: e.target.value })}
              data-testid="payroll-profile-posttax"
            />
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
