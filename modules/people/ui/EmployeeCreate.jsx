import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

/**
 * EmployeeCreate — multi-step wizard (identity → employment → comp → tax → banking → review)
 *
 * For MVP, we ship the identity + employment step (creating the base record)
 * and the UI hints toward the follow-up tabs for comp/tax/banking. Those can
 * be captured either here or inside the detail view.
 */

const blank = {
  legal_first_name: '',
  legal_last_name: '',
  preferred_name: '',
  personal_email: '',
  date_of_birth: '',
  ssn: '',
  employment_type: 'full_time',
  flsa_class: 'non_exempt',
  hire_date: '',
  job_title: '',
  department: '',
  location: '',
  work_email: '',
};

export default function EmployeeCreate() {
  const nav = useNavigate();
  const [form, setForm] = useState(blank);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const update = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await api.post('/modules/people/api/employees.php', form);
      nav(`../${res.employee.id}`);
    } catch (err) {
      setError(err.message || 'Failed to create employee');
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="employee-create" data-testid="people-create-form">
      <h2>New employee</h2>
      <p className="muted">
        Start with identity + employment. Compensation, tax, and banking are captured on
        the employee's detail tabs — each is history-aware and audited.
      </p>

      <form onSubmit={submit} className="form-grid">
        <label>Legal first name *
          <input required value={form.legal_first_name} onChange={update('legal_first_name')}
            data-testid="people-create-first-name" />
        </label>
        <label>Legal last name *
          <input required value={form.legal_last_name} onChange={update('legal_last_name')}
            data-testid="people-create-last-name" />
        </label>
        <label>Preferred name
          <input value={form.preferred_name} onChange={update('preferred_name')}
            data-testid="people-create-preferred-name" />
        </label>
        <label>Personal email
          <input type="email" value={form.personal_email} onChange={update('personal_email')} />
        </label>
        <label>Date of birth
          <input type="date" value={form.date_of_birth} onChange={update('date_of_birth')} />
        </label>
        <label>SSN/SIN <span className="muted">(stored encrypted; only last 4 shown)</span>
          <input value={form.ssn} onChange={update('ssn')} placeholder="123-45-6789"
            data-testid="people-create-ssn" />
        </label>

        <label>Employment type
          <select value={form.employment_type} onChange={update('employment_type')}>
            <option value="full_time">Full time</option>
            <option value="part_time">Part time</option>
            <option value="contractor">Contractor</option>
            <option value="intern">Intern</option>
            <option value="temp">Temp</option>
          </select>
        </label>
        <label>FLSA
          <select value={form.flsa_class} onChange={update('flsa_class')}>
            <option value="non_exempt">Non-exempt</option>
            <option value="exempt">Exempt</option>
          </select>
        </label>
        <label>Hire date
          <input type="date" value={form.hire_date} onChange={update('hire_date')} />
        </label>
        <label>Job title
          <input value={form.job_title} onChange={update('job_title')} />
        </label>
        <label>Department
          <input value={form.department} onChange={update('department')} />
        </label>
        <label>Location
          <input value={form.location} onChange={update('location')} />
        </label>
        <label>Work email
          <input type="email" value={form.work_email} onChange={update('work_email')} />
        </label>

        {error && <p className="error">{error}</p>}

        <div className="form-actions">
          <button type="button" className="btn btn--ghost" onClick={() => nav('../directory')}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving}
            data-testid="people-create-submit">
            {saving ? 'Creating…' : 'Create employee'}
          </button>
        </div>
      </form>
    </section>
  );
}
