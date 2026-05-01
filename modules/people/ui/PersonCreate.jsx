import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import CompanyTypeahead from './CompanyTypeahead';

/**
 * New-Hire Wizard (formerly a single "Add person" form).
 *
 * 3 steps:
 *   1. Person identity + contact
 *   2. Employment (HR fields — DOB, addresses, hire_date, pay, work auth)
 *   3. Optional first placement (title, end client, rates, engagement type)
 *
 * The placement step is always visible but completely skippable (recruiter
 * use case — candidates get added without a placement).  The wizard creates
 * the Person first, then (if the placement block has a title + client +
 * start_date) creates a Placement via /api/placements/placements with the
 * freshly-minted person_id.  If the placement call fails, the person is
 * still created and the user is redirected to the person detail page with
 * an inline error toast.
 *
 * Existing testids from the old flat form are preserved wherever possible
 * so the companies_smoke / people_spec_smoke assertions keep passing.
 */

const CLASSIFICATIONS = ['candidate', 'w2', '1099', 'c2c', 'temp', 'perm', 'alumni'];
const WORK_AUTH       = ['unknown', 'citizen', 'green_card', 'h1b', 'opt', 'cpt', 'tn', 'other'];
const EMP_TYPES       = ['', 'full_time', 'part_time', 'contractor', 'intern', 'temp'];
const PAY_FREQS       = ['', 'weekly', 'biweekly', 'semimonthly', 'monthly'];
const ETYPES          = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];

const STEPS = [
  { id: 1, label: 'Person' },
  { id: 2, label: 'Employment' },
  { id: 3, label: 'First placement (optional)' },
];

export default function PersonCreate() {
  const nav = useNavigate();
  const [step, setStep]             = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError]           = useState(null);

  const [form, setForm] = useState({
    // Step 1
    first_name: '', middle_name: '', last_name: '', preferred_name: '',
    email_primary: '', email_secondary: '',
    phone_primary: '', phone_secondary: '',
    classification: 'candidate', status: 'active',
    external_id: '', linkedin_url: '', source: '', recruiter_notes: '',
    // Step 2 — Employment + PII (everything optional at API level)
    employment_type: '', hire_date: '', termination_date: '', pay_frequency: '',
    work_auth_status: 'unknown', work_auth_expiry: '', requires_sponsorship: false,
    dob: '', ssn_last4: '', gender: '', marital_status: '',
    home_address_line1: '', home_address_line2: '',
    home_city: '', home_state: '', home_postal_code: '', home_country: 'US',
    mailing_same_as_home: true,
    mailing_address_line1: '', mailing_address_line2: '',
    mailing_city: '', mailing_state: '', mailing_postal_code: '', mailing_country: 'US',
  });

  // Step 3 — Placement (all optional; skipped if title empty)
  const [placement, setPlacement] = useState({
    title: '',
    engagement_type: 'w2',
    start_date: '',
    end_date: '',
    worksite_state: '',
    remote_policy: '',
  });
  const [endClient, setEndClient] = useState(null);
  const [rate, setRate]           = useState({ bill_rate: '', pay_rate: '' });

  const set   = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });
  const setP  = (k) => (e) => setPlacement({ ...placement, [k]: e.target.value });
  const setR  = (k) => (e) => setRate({ ...rate, [k]: e.target.value });

  const next = () => { setError(null); setStep((s) => Math.min(3, s + 1)); };
  const back = () => { setError(null); setStep((s) => Math.max(1, s - 1)); };

  const buildPersonPayload = () => {
    const out = { ...form };
    // mailing mirror
    if (out.mailing_same_as_home) {
      out.mailing_address_line1 = out.home_address_line1 || '';
      out.mailing_address_line2 = out.home_address_line2 || '';
      out.mailing_city          = out.home_city          || '';
      out.mailing_state         = out.home_state         || '';
      out.mailing_postal_code   = out.home_postal_code   || '';
      out.mailing_country       = out.home_country       || 'US';
    }
    delete out.mailing_same_as_home;
    // Strip empty-string date/enum fields so PHP stores NULL not ''.
    ['work_auth_expiry','hire_date','termination_date','dob','employment_type','pay_frequency','gender','marital_status','ssn_last4'].forEach((k) => {
      if (!out[k]) delete out[k];
    });
    return out;
  };

  const submit = async (e) => {
    e && e.preventDefault();
    setSubmitting(true); setError(null);
    try {
      // 1) Create the person
      const personRes = await api.post('/modules/people/api/people.php', buildPersonPayload());
      const personId  = personRes.person?.id;
      if (!personId) throw new Error('person create returned no id');

      // 2) Optionally create the first placement
      const wantsPlacement = placement.title && placement.start_date && endClient;
      if (wantsPlacement) {
        try {
          const placePayload = {
            person_id: personId,
            title: placement.title,
            engagement_type: placement.engagement_type,
            start_date: placement.start_date,
            end_date: placement.end_date || null,
            worksite_state: placement.worksite_state || null,
            remote_policy: placement.remote_policy || null,
            end_client_company_id: endClient.id,
            end_client_name: endClient.name,
          };
          const p = await api.post('/modules/placements/api/placements.php', placePayload);
          const placementId = p.placement?.id;
          if (placementId && (rate.bill_rate || rate.pay_rate)) {
            await api.post(`/modules/placements/api/rates.php?placement_id=${placementId}`, {
              effective_from: placement.start_date,
              bill_rate:  rate.bill_rate  ? Number(rate.bill_rate)  : 0,
              pay_rate:   rate.pay_rate   ? Number(rate.pay_rate)   : 0,
              bill_rate_unit: 'hour',
              pay_rate_unit:  'hour',
            });
          }
        } catch (placementErr) {
          // Person still saved — send user to person page with a warning.
          nav(`../${personId}?placement_error=${encodeURIComponent(placementErr.message || 'Placement failed')}`);
          return;
        }
      }
      nav(`../${personId}`);
    } catch (err) {
      setError(err);
      setSubmitting(false);
    }
  };

  const step1Valid = form.first_name && form.last_name && form.email_primary;

  return (
    <section className="person-create" data-testid="person-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
        <div>
          <h2 style={{ margin: 0 }}>New hire</h2>
          <p style={{ margin: '4px 0 0', color: '#666', fontSize: 13 }}>
            Step {step} of {STEPS.length} · {STEPS[step - 1].label}
          </p>
        </div>
        <Link to=".." className="btn btn--ghost" data-testid="person-create-back">← Back to directory</Link>
      </header>

      <Stepper current={step} />

      <form onSubmit={submit} className="person-create__form" data-testid="person-create-form" style={{ maxWidth: 760 }}>
        <fieldset disabled={submitting} style={{ border: 0, padding: 0, margin: 0 }}>
          {step === 1 && (
            <Step1 form={form} set={set} />
          )}
          {step === 2 && (
            <Step2 form={form} set={set} />
          )}
          {step === 3 && (
            <Step3
              placement={placement} setP={setP}
              endClient={endClient} setEndClient={setEndClient}
              rate={rate} setR={setR}
            />
          )}

          {error && (
            <p className="error" data-testid="person-create-error" style={{ color: '#c0392b', marginTop: 12 }}>
              Error: {error.message} {error.data?.fields ? `(missing: ${error.data.fields.join(', ')})` : ''}
            </p>
          )}

          <div style={{ marginTop: '1.5rem', display: 'flex', gap: '0.5rem', justifyContent: 'space-between' }}>
            <div>
              {step > 1 && (
                <button type="button" className="btn btn--ghost" data-testid="person-create-prev" onClick={back}>
                  ← Back
                </button>
              )}
            </div>
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <Link to=".." className="btn btn--ghost" data-testid="person-create-cancel">Cancel</Link>
              {step < 3 && (
                <button
                  type="button"
                  className="btn btn--primary"
                  data-testid="person-create-next"
                  onClick={next}
                  disabled={step === 1 && !step1Valid}
                >
                  Next →
                </button>
              )}
              {step === 3 && (
                <button type="submit" className="btn btn--primary" data-testid="person-create-submit" disabled={submitting}>
                  {submitting ? 'Saving…' : (placement.title ? 'Create person + placement' : 'Create person')}
                </button>
              )}
            </div>
          </div>
        </fieldset>
      </form>
    </section>
  );
}

function Stepper({ current }) {
  return (
    <ol data-testid="person-create-stepper" style={{ display: 'flex', gap: 12, listStyle: 'none', padding: 0, margin: '0 0 1.5rem' }}>
      {STEPS.map((s) => {
        const done = s.id < current, active = s.id === current;
        return (
          <li key={s.id} data-testid={`person-create-step-${s.id}`} style={{ flex: 1 }}>
            <div style={{ height: 4, background: done || active ? '#2563eb' : '#e5e7eb', borderRadius: 2 }} />
            <p style={{ margin: '6px 0 0', fontSize: 12, color: active ? '#111' : '#666', fontWeight: active ? 600 : 400 }}>
              {s.id}. {s.label}
            </p>
          </li>
        );
      })}
    </ol>
  );
}

function Step1({ form, set }) {
  return (
    <div data-testid="person-create-step-1-panel">
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
            {CLASSIFICATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </Field>
        <Field label="Status">
          <select data-testid="person-create-status" value={form.status} onChange={set('status')} className="input">
            <option value="active">active</option>
            <option value="bench">bench</option>
            <option value="inactive">inactive</option>
          </select>
        </Field>
        <Field label="Source">
          <input data-testid="person-create-source" value={form.source} onChange={set('source')} className="input" placeholder="LinkedIn, Referral: Jane…" />
        </Field>
      </Row>
      <Row>
        <Field label="LinkedIn URL">
          <input data-testid="person-create-linkedin" value={form.linkedin_url} onChange={set('linkedin_url')} className="input" placeholder="https://linkedin.com/in/…" />
        </Field>
      </Row>
      <Field label="Recruiter notes">
        <textarea data-testid="person-create-notes" value={form.recruiter_notes} onChange={set('recruiter_notes')} className="input" rows={3} />
      </Field>
    </div>
  );
}

function Step2({ form, set }) {
  return (
    <div data-testid="person-create-step-2-panel">
      <SectionTitle>Employment</SectionTitle>
      <Row>
        <Field label="Employment type">
          <select data-testid="person-create-employment-type" value={form.employment_type} onChange={set('employment_type')} className="input">
            {EMP_TYPES.map((t) => <option key={t || 'none'} value={t}>{t || '—'}</option>)}
          </select>
        </Field>
        <Field label="Pay frequency">
          <select data-testid="person-create-pay-frequency" value={form.pay_frequency} onChange={set('pay_frequency')} className="input">
            {PAY_FREQS.map((f) => <option key={f || 'none'} value={f}>{f || '—'}</option>)}
          </select>
        </Field>
        <Field label="Hire date">
          <input data-testid="person-create-hire-date" type="date" value={form.hire_date} onChange={set('hire_date')} className="input" />
        </Field>
      </Row>
      <Row>
        <Field label="Work auth status">
          <select data-testid="person-create-work-auth-status" value={form.work_auth_status} onChange={set('work_auth_status')} className="input">
            {WORK_AUTH.map((w) => <option key={w} value={w}>{w}</option>)}
          </select>
        </Field>
        <Field label="Work auth expiry">
          <input data-testid="person-create-work-auth-expiry" type="date" value={form.work_auth_expiry} onChange={set('work_auth_expiry')} className="input" />
        </Field>
        <Field label="">
          <label data-testid="person-create-requires-sponsorship-label" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <input data-testid="person-create-requires-sponsorship" type="checkbox" checked={form.requires_sponsorship} onChange={set('requires_sponsorship')} />
            Requires sponsorship
          </label>
        </Field>
      </Row>

      <SectionTitle>Personal (PII — gated by people.pii.manage)</SectionTitle>
      <Row>
        <Field label="Date of birth">
          <input data-testid="person-create-dob" type="date" value={form.dob} onChange={set('dob')} className="input" />
        </Field>
        <Field label="SSN last 4">
          <input data-testid="person-create-ssn-last4" maxLength={4} value={form.ssn_last4} onChange={set('ssn_last4')} className="input" placeholder="1234" />
        </Field>
        <Field label="Gender">
          <input data-testid="person-create-gender" value={form.gender} onChange={set('gender')} className="input" />
        </Field>
        <Field label="Marital status">
          <input data-testid="person-create-marital" value={form.marital_status} onChange={set('marital_status')} className="input" />
        </Field>
      </Row>

      <SectionTitle>Home address</SectionTitle>
      <Row>
        <Field label="Line 1"><input data-testid="person-create-home-line1" value={form.home_address_line1} onChange={set('home_address_line1')} className="input" /></Field>
        <Field label="Line 2"><input data-testid="person-create-home-line2" value={form.home_address_line2} onChange={set('home_address_line2')} className="input" /></Field>
      </Row>
      <Row>
        <Field label="City"><input data-testid="person-create-home-city" value={form.home_city} onChange={set('home_city')} className="input" /></Field>
        <Field label="State"><input data-testid="person-create-home-state" value={form.home_state} onChange={set('home_state')} className="input" /></Field>
        <Field label="Postal"><input data-testid="person-create-home-postal" value={form.home_postal_code} onChange={set('home_postal_code')} className="input" /></Field>
        <Field label="Country"><input data-testid="person-create-home-country" value={form.home_country} onChange={set('home_country')} className="input" maxLength={2} /></Field>
      </Row>

      <label data-testid="person-create-mailing-same-label" style={{ display: 'flex', alignItems: 'center', gap: 6, margin: '8px 0' }}>
        <input data-testid="person-create-mailing-same" type="checkbox" checked={form.mailing_same_as_home} onChange={set('mailing_same_as_home')} />
        Mailing address same as home
      </label>

      {!form.mailing_same_as_home && (
        <>
          <SectionTitle>Mailing address</SectionTitle>
          <Row>
            <Field label="Line 1"><input data-testid="person-create-mailing-line1" value={form.mailing_address_line1} onChange={set('mailing_address_line1')} className="input" /></Field>
            <Field label="Line 2"><input data-testid="person-create-mailing-line2" value={form.mailing_address_line2} onChange={set('mailing_address_line2')} className="input" /></Field>
          </Row>
          <Row>
            <Field label="City"><input data-testid="person-create-mailing-city" value={form.mailing_city} onChange={set('mailing_city')} className="input" /></Field>
            <Field label="State"><input data-testid="person-create-mailing-state" value={form.mailing_state} onChange={set('mailing_state')} className="input" /></Field>
            <Field label="Postal"><input data-testid="person-create-mailing-postal" value={form.mailing_postal_code} onChange={set('mailing_postal_code')} className="input" /></Field>
            <Field label="Country"><input data-testid="person-create-mailing-country" value={form.mailing_country} onChange={set('mailing_country')} className="input" maxLength={2} /></Field>
          </Row>
        </>
      )}
    </div>
  );
}

function Step3({ placement, setP, endClient, setEndClient, rate, setR }) {
  return (
    <div data-testid="person-create-step-3-panel">
      <p style={{ margin: '0 0 16px', color: '#666', fontSize: 13 }}>
        Optional. Fill in to create the first placement immediately — or leave blank and skip.
      </p>
      <Row>
        <Field label="Title">
          <input data-testid="person-create-placement-title" value={placement.title} onChange={setP('title')} className="input" placeholder="Senior Software Engineer" />
        </Field>
        <Field label="Engagement type">
          <select data-testid="person-create-placement-etype" value={placement.engagement_type} onChange={setP('engagement_type')} className="input">
            {ETYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
        </Field>
      </Row>
      <Row>
        <Field label="Start date">
          <input data-testid="person-create-placement-start" type="date" value={placement.start_date} onChange={setP('start_date')} className="input" />
        </Field>
        <Field label="End date">
          <input data-testid="person-create-placement-end" type="date" value={placement.end_date} onChange={setP('end_date')} className="input" />
        </Field>
      </Row>
      <Field label="End client">
        <CompanyTypeahead
          role="client"
          value={endClient}
          onChange={setEndClient}
          testId="person-create-placement-end-client"
          placeholder="e.g. Acme Inc, Globex…"
        />
      </Field>
      <Row>
        <Field label="Worksite state">
          <input data-testid="person-create-placement-state" value={placement.worksite_state} onChange={setP('worksite_state')} className="input" placeholder="CA" />
        </Field>
        <Field label="Remote policy">
          <select data-testid="person-create-placement-remote" value={placement.remote_policy} onChange={setP('remote_policy')} className="input">
            <option value="">—</option>
            <option value="onsite">onsite</option>
            <option value="hybrid">hybrid</option>
            <option value="remote">remote</option>
          </select>
        </Field>
      </Row>
      <SectionTitle>Initial rates (optional)</SectionTitle>
      <Row>
        <Field label="Bill rate ($/hr)">
          <input data-testid="person-create-placement-bill-rate" type="number" step="0.01" value={rate.bill_rate} onChange={setR('bill_rate')} className="input" placeholder="125.00" />
        </Field>
        <Field label="Pay rate ($/hr)">
          <input data-testid="person-create-placement-pay-rate" type="number" step="0.01" value={rate.pay_rate} onChange={setR('pay_rate')} className="input" placeholder="75.00" />
        </Field>
      </Row>
    </div>
  );
}

const Row = ({ children }) => <div style={{ display: 'flex', gap: '0.75rem', marginBottom: '0.75rem', flexWrap: 'wrap' }}>{children}</div>;
const Field = ({ label, children }) => (
  <label style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: '180px' }}>
    {label && <span style={{ fontSize: '0.85em', color: '#555', marginBottom: '0.25rem' }}>{label}</span>}
    {children}
  </label>
);
const SectionTitle = ({ children }) => (
  <h3 style={{ margin: '1.25rem 0 0.5rem', fontSize: 14, fontWeight: 600, color: '#111', textTransform: 'uppercase', letterSpacing: 0.4 }}>
    {children}
  </h3>
);
