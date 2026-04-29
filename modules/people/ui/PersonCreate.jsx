import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

const CLASSIFICATIONS = ['candidate', 'w2', '1099', 'c2c', 'temp', 'perm', 'alumni'];
const WORK_AUTH = ['unknown', 'citizen', 'green_card', 'h1b', 'opt', 'cpt', 'tn', 'other'];

export default function PersonCreate() {
  const nav = useNavigate();
  const [form, setForm] = useState({
    first_name: '', middle_name: '', last_name: '', preferred_name: '',
    email_primary: '', email_secondary: '',
    phone_primary: '', phone_secondary: '',
    classification: 'candidate', status: 'active',
    work_auth_status: 'unknown', work_auth_expiry: '', requires_sponsorship: false,
    linkedin_url: '', source: '', external_id: '', recruiter_notes: '',
  });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError]           = useState(null);

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setError(null);
    try {
      const cleaned = { ...form };
      if (!cleaned.work_auth_expiry) delete cleaned.work_auth_expiry;
      const result = await api.post('/modules/people/api/people.php', cleaned);
      nav(`../${result.person.id}`);
    } catch (err) {
      setError(err);
      setSubmitting(false);
    }
  };

  return (
    <section className="person-create" data-testid="person-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
        <h2>Add person</h2>
        <Link to=".." className="btn btn--ghost" data-testid="person-create-back">← Back to directory</Link>
      </header>

      <form onSubmit={submit} className="person-create__form" data-testid="person-create-form" style={{ maxWidth: '720px' }}>
        <fieldset disabled={submitting}>
          <Row>
            <Field label="First name *">
              <input data-testid="person-create-first-name" required value={form.first_name} onChange={set('first_name')} className="input" />
            </Field>
            <Field label="Middle name">
              <input data-testid="person-create-middle-name" value={form.middle_name} onChange={set('middle_name')} className="input" />
            </Field>
            <Field label="Last name *">
              <input data-testid="person-create-last-name" required value={form.last_name} onChange={set('last_name')} className="input" />
            </Field>
          </Row>

          <Row>
            <Field label="Preferred name">
              <input data-testid="person-create-preferred-name" value={form.preferred_name} onChange={set('preferred_name')} className="input" />
            </Field>
            <Field label="External ID">
              <input data-testid="person-create-external-id" value={form.external_id} onChange={set('external_id')} className="input" placeholder="ATS / payroll id" />
            </Field>
          </Row>

          <Row>
            <Field label="Primary email *">
              <input data-testid="person-create-email-primary" type="email" required value={form.email_primary} onChange={set('email_primary')} className="input" />
            </Field>
            <Field label="Secondary email">
              <input data-testid="person-create-email-secondary" type="email" value={form.email_secondary} onChange={set('email_secondary')} className="input" />
            </Field>
          </Row>

          <Row>
            <Field label="Primary phone">
              <input data-testid="person-create-phone-primary" value={form.phone_primary} onChange={set('phone_primary')} className="input" placeholder="E.164 (+15551234567)" />
            </Field>
            <Field label="Secondary phone">
              <input data-testid="person-create-phone-secondary" value={form.phone_secondary} onChange={set('phone_secondary')} className="input" />
            </Field>
          </Row>

          <Row>
            <Field label="Classification *">
              <select data-testid="person-create-classification" required value={form.classification} onChange={set('classification')} className="input">
                {CLASSIFICATIONS.map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </Field>
            <Field label="Status">
              <select data-testid="person-create-status" value={form.status} onChange={set('status')} className="input">
                <option value="active">active</option>
                <option value="bench">bench</option>
                <option value="inactive">inactive</option>
              </select>
            </Field>
          </Row>

          <Row>
            <Field label="Work auth status">
              <select data-testid="person-create-work-auth-status" value={form.work_auth_status} onChange={set('work_auth_status')} className="input">
                {WORK_AUTH.map(w => <option key={w} value={w}>{w}</option>)}
              </select>
            </Field>
            <Field label="Work auth expiry">
              <input data-testid="person-create-work-auth-expiry" type="date" value={form.work_auth_expiry} onChange={set('work_auth_expiry')} className="input" />
            </Field>
            <Field label="">
              <label data-testid="person-create-requires-sponsorship-label" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <input data-testid="person-create-requires-sponsorship" type="checkbox" checked={form.requires_sponsorship} onChange={set('requires_sponsorship')} />
                Requires sponsorship
              </label>
            </Field>
          </Row>

          <Row>
            <Field label="LinkedIn URL">
              <input data-testid="person-create-linkedin" value={form.linkedin_url} onChange={set('linkedin_url')} className="input" placeholder="https://linkedin.com/in/…" />
            </Field>
            <Field label="Source">
              <input data-testid="person-create-source" value={form.source} onChange={set('source')} className="input" placeholder="LinkedIn, Referral: Jane, …" />
            </Field>
          </Row>

          <Field label="Recruiter notes">
            <textarea data-testid="person-create-notes" value={form.recruiter_notes} onChange={set('recruiter_notes')} className="input" rows={3} />
          </Field>

          {error && (
            <p className="error" data-testid="person-create-error" style={{ color: '#c0392b' }}>
              Error: {error.message} {error.data?.fields ? `(missing: ${error.data.fields.join(', ')})` : ''}
            </p>
          )}

          <div style={{ marginTop: '1rem', display: 'flex', gap: '0.5rem' }}>
            <button type="submit" className="btn btn--primary" data-testid="person-create-submit" disabled={submitting}>
              {submitting ? 'Saving…' : 'Create person'}
            </button>
            <Link to=".." className="btn btn--ghost" data-testid="person-create-cancel">Cancel</Link>
          </div>
        </fieldset>
      </form>
    </section>
  );
}

const Row   = ({ children }) => <div style={{ display: 'flex', gap: '0.75rem', marginBottom: '0.75rem', flexWrap: 'wrap' }}>{children}</div>;
const Field = ({ label, children }) => (
  <label style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: '180px' }}>
    {label && <span style={{ fontSize: '0.85em', color: '#555', marginBottom: '0.25rem' }}>{label}</span>}
    {children}
  </label>
);
