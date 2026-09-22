import React, { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Search, UserRoundPlus } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import EntityPicker from '../../../dashboard/src/components/EntityPicker';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

const ETYPES = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire', 'referral', 'internal'];
const ETYPE_LABELS = {
  w2: 'W-2 employee',
  1099: '1099 contractor',
  c2c: 'C2C contractor',
  temp_to_perm: 'Temp-to-perm',
  direct_hire: 'Direct hire',
  referral: 'Referral placement',
  internal: 'Internal employee',
};
const RATE_UNITS = ['hour', 'day', 'week', 'month', 'project'];
const COMMISSION_ROLES = ['account_manager', 'lead', 'recruiter', 'team', 'other'];
const COMMISSION_BASIS = ['net_margin', 'gross_margin', 'bill_rate', 'flat'];
const REFERRER_TYPES = ['vendor', 'person', 'user'];
const FEE_BASIS = ['per_hour', 'per_invoice', 'one_time', 'pct_bill', 'pct_margin'];
const COUNTRIES = ['US', 'CA', 'IN', 'GB', 'MX']; // common — not exhaustive
const PERSON_CLASSIFICATION_LABELS = {
  w2: 'W-2',
  '1099': '1099 contractor',
  c2c: 'C2C contractor',
  temp: 'Temporary worker',
  perm: 'Permanent employee',
  candidate: 'Candidate / referred person',
};

/**
 * PlacementCreate — full SPEC §3 coverage form.
 *
 * Sections:
 *   1. New or existing person
 *   2. Placement role and dates (with Internal-hire toggle)
 *   3. End client (hidden for internal hires)
 *   4. Vendor chain (hidden for internal hires)
 *   5. Initial rate (currency / unit / adder / background fee)
 *   6. Commissions (inline rows)
 *   7. Referral (optional single)
 *   8. C2C corp details (only when engagement_type='c2c')
 *   9. Notes
 *
 * Documents (MSA / COI / W-9 / chain contracts) are uploaded after creation
 * from the placement detail page so we don't have to multi-upload before the
 * placement_id exists.
 *
 * Accepts ?person_id=N in the URL so the person picker is pre-filled.
 */
export default function PlacementCreate() {
  const nav = useNavigate();
  const [search] = useSearchParams();
  const prefilledPersonId = search.get('person_id');

  const [form, setForm] = useState({
    person_id: prefilledPersonId || '',
    title: '', engagement_type: 'w2',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '', due_date: '',
    worksite_state: '', worksite_country: 'US',
    remote_policy: '', external_id: '', notes: '',
    staffing_job_id: '', branch: '', service_line: '', workers_comp_class: '',
    department: '', cost_center: '', accounting_entity_id: '',
    recruiter_name: '', recruiter_email: '',
    account_manager_name: '', account_manager_email: '',
    client_approver_name: '', client_approver_email: '',
  });
  const [personMode, setPersonMode] = useState(prefilledPersonId ? 'existing' : 'new');
  const [newPerson, setNewPerson] = useState({
    first_name: '', last_name: '', email_primary: '', phone_primary: '',
  });
  const [personConflict, setPersonConflict] = useState(null);
  const [internalHire, setInternalHire] = useState(false);
  const [endClient, setEndClient] = useState(null);
  const [chain, setChain]         = useState([]);
  const [rate, setRate]           = useState({
    effective_from: '', bill_rate: '', pay_rate: '',
    bill_rate_unit: 'hour', pay_rate_unit: 'hour',
    currency: 'USD',
    overtime_multiplier: '1.5', doubletime_multiplier: '2.0',
    adder_pct: '', c2c_overhead_pct: '', background_fee_total: '',
  });
  const [commissions, setCommissions] = useState([]);
  const [referral, setReferral] = useState(null);
  const [corp, setCorp] = useState({
    corp_legal_name: '', corp_ein: '',
    corp_address_line1: '', corp_address_line2: '',
    corp_city: '', corp_state: '', corp_postal_code: '', corp_country: 'US',
    corp_contact_name: '', corp_contact_email: '', corp_contact_phone: '',
  });
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);
  const isReferral = form.engagement_type === 'referral';

  // Person typeahead
  const [personSearch, setPersonSearch] = useState('');
  const personLookup = useApi(personSearch.length >= 2 && !form.person_id
    ? `/modules/people/api/people.php?q=${encodeURIComponent(personSearch)}&per_page=10`
    : null);
  const prefilled = useApi(prefilledPersonId ? `/modules/people/api/people.php?id=${prefilledPersonId}` : null);
  const prefilledPerson = prefilled.data?.person;
  useEffect(() => {
    const p = prefilledPerson;
    if (p) setPersonSearch(`${p.first_name} ${p.last_name} (${p.email_primary})`);
  }, [prefilledPerson]);

  // Tenant user list (for commission row "user_id" picker)
  const usersLookup = useApi('/api/users.php');
  const tenantUsers = usersLookup.data?.users || usersLookup.data?.rows || [];
  const jobsLookup = useApi('/modules/staffing/api/jobs.php?action=list&limit=500');
  const staffingJobs = jobsLookup.data?.rows || [];
  const ownerUsers = tenantUsers.filter(user => Number(user.is_active ?? 1) !== 0);

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const setNewPersonF = (k) => (e) => setNewPerson({ ...newPerson, [k]: e.target.value });
  const setRateF = (k) => (e) => setRate({ ...rate, [k]: e.target.value });
  const setCorpF = (k) => (e) => setCorp({ ...corp, [k]: e.target.value });
  const setOwner = (prefix) => (e) => {
    const selected = ownerUsers.find(user => String(user.id) === e.target.value);
    setForm(current => ({
      ...current,
      [`${prefix}_name`]: selected?.name || '',
      [`${prefix}_email`]: selected?.email || '',
    }));
  };

  useEffect(() => {
    if (!isReferral) return;
    setRate(current => ({
      ...current,
      pay_rate: '0',
      bill_rate_unit: 'hour',
      pay_rate_unit: 'hour',
    }));
    setReferral(current => current ? {
      ...current,
      referrer_type: 'vendor',
      fee_basis: 'per_hour',
    } : {
      referrer_type: 'vendor',
      fee_basis: 'per_hour',
      payment_terms_override: 'NET30',
      pwp_enabled: false,
    });
  }, [isReferral]);

  // Internal-hire toggle: short-circuit end-client / vendor-chain noise.
  const onToggleInternal = (e) => {
    const v = e.target.checked;
    setInternalHire(v);
    if (v) {
      setForm(f => ({ ...f, engagement_type: 'internal', client_approver_name: '', client_approver_email: '' }));
      setEndClient(null);
      setChain([]);
    } else if (form.engagement_type === 'internal') {
      setForm(f => ({ ...f, engagement_type: 'w2' }));
    }
  };

  // What's missing for the disabled button → show as inline hint.
  const missing = useMemo(() => {
    const m = [];
    if (personMode === 'existing' && !form.person_id) m.push('Person');
    if (personMode === 'new' && !newPerson.first_name.trim()) m.push('First name');
    if (personMode === 'new' && !newPerson.last_name.trim()) m.push('Last name');
    if (personMode === 'new' && !newPerson.email_primary.trim()) m.push('Work email');
    if (!form.title.trim())     m.push('Title');
    if (!form.start_date)       m.push('Start date');
    if (!form.engagement_type)  m.push('Engagement type');
    if (form.engagement_type === 'referral') {
      if (!endClient?.name) m.push('End client');
      if (!(Number(rate.bill_rate) > 0)) m.push('Client referral fee');
      if (!referral?.referrer_company?.name && !referral?.referrer_vendor_name) m.push('Referral vendor');
      if (!(Number(referral?.fee_flat) > 0)) m.push('Vendor referral payout');
    }
    return m;
  }, [personMode, newPerson, form.person_id, form.title, form.start_date, form.engagement_type, endClient, rate.bill_rate, referral]);

  const personClassification = classificationForEngagement(form.engagement_type);

  const choosePersonMode = (mode) => {
    setPersonMode(mode);
    setPersonConflict(null);
    setError(null);
    if (mode === 'new') {
      setForm(f => ({ ...f, person_id: '' }));
      setPersonSearch('');
    }
  };

  const useConflictingPerson = () => {
    if (!personConflict?.id) return;
    setPersonMode('existing');
    setForm(f => ({ ...f, person_id: String(personConflict.id) }));
    setPersonSearch(personDisplayName(personConflict));
    setPersonConflict(null);
    setError(null);
  };

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setError(null); setPersonConflict(null);
    try {
      // 1) Create placement
      const payload = { ...form };
      ['end_date', 'due_date'].forEach(k => { if (!payload[k]) delete payload[k]; });
      if (personMode === 'new') {
        delete payload.person_id;
        payload.new_person = {
          first_name: newPerson.first_name.trim(),
          last_name: newPerson.last_name.trim(),
          email_primary: newPerson.email_primary.trim(),
          phone_primary: newPerson.phone_primary.trim() || null,
        };
      } else {
        payload.person_id = parseInt(payload.person_id, 10);
      }
      if (!internalHire && endClient) {
        payload.end_client_company_id = endClient.id || undefined;
        payload.end_client_name = endClient.name;
      }
      const created = await api.post('/modules/placements/api/placements.php', payload);
      const placementId = created.placement.id;

      // 2) Vendor chain rows (skipped for internal hires)
      if (!internalHire) {
        for (let i = 0; i < chain.length; i++) {
          const c = chain[i];
          if (!c.company?.name) continue;
          await api.post(`/modules/placements/api/chain.php?placement_id=${placementId}`, {
            position: i + 1,
            company_id: c.company.id || undefined,
            party_name: c.company.id ? undefined : c.company.name,
            party_role: c.party_role,
            vendor_portal_id: c.vendor_portal_id || undefined,
            portal_fee_pct:  c.portal_fee_pct  ? Number(c.portal_fee_pct)  : undefined,
            portal_fee_flat: c.portal_fee_flat ? Number(c.portal_fee_flat) : undefined,
          });
        }
      }

      // 3) Initial rate row
      if (rate.bill_rate || rate.pay_rate || isReferral) {
        await api.post(`/modules/placements/api/rates.php?placement_id=${placementId}`, {
          effective_from: rate.effective_from || form.start_date,
          bill_rate:  rate.bill_rate  ? Number(rate.bill_rate)  : 0,
          pay_rate:   isReferral ? 0 : (rate.pay_rate ? Number(rate.pay_rate) : 0),
          bill_rate_unit: rate.bill_rate_unit || 'hour',
          pay_rate_unit:  rate.pay_rate_unit  || 'hour',
          currency: rate.currency || 'USD',
          overtime_multiplier:   Number(rate.overtime_multiplier   || 1.5),
          doubletime_multiplier: Number(rate.doubletime_multiplier || 2.0),
          adder_pct:            rate.adder_pct ? Number(rate.adder_pct) / 100 : null,
          c2c_overhead_pct:     rate.c2c_overhead_pct === '' ? null : Number(rate.c2c_overhead_pct) / 100,
          background_fee_total: rate.background_fee_total ? Number(rate.background_fee_total) : null,
        });
      }

      // 4) Commission rows
      for (const c of commissions) {
        if (!c.role || !(c.split_pct || c.flat_amount)) continue;
        await api.post(`/modules/placements/api/commissions.php?placement_id=${placementId}`, {
          role: c.role,
          user_id: c.user_id ? parseInt(c.user_id, 10) : null,
          split_pct: c.split_pct ? Number(c.split_pct) / 100 : null,
          flat_amount: c.flat_amount ? Number(c.flat_amount) : null,
          basis: c.basis || 'net_margin',
          effective_from: c.effective_from || form.start_date,
          notes: c.notes || null,
        });
      }

      // 5) Referral
      if (referral && referral.referrer_type && referral.fee_basis) {
        await api.post(`/modules/placements/api/referrals.php?placement_id=${placementId}`, {
          referrer_type: referral.referrer_type,
          referrer_vendor_name: referral.referrer_vendor_name || null,
          referrer_company_id:  referral.referrer_company?.id || null,
          referrer_person_id:   referral.referrer_person_id ? parseInt(referral.referrer_person_id, 10) : null,
          referrer_user_id:     referral.referrer_user_id   ? parseInt(referral.referrer_user_id, 10)   : null,
          fee_pct:  referral.fee_pct  ? Number(referral.fee_pct) / 100 : null,
          fee_flat: referral.fee_flat ? Number(referral.fee_flat)      : null,
          fee_basis: isReferral ? 'per_hour' : referral.fee_basis,
          payment_terms_override: referral.payment_terms_override || null,
          pwp_enabled: Boolean(referral.pwp_enabled),
          duration_months: referral.duration_months ? parseInt(referral.duration_months, 10) : null,
          start_date: referral.start_date || form.start_date,
          end_date: referral.end_date || form.end_date || null,
          notes: referral.notes || null,
        });
      }

      // 6) C2C corp details (only when engagement_type='c2c')
      if (form.engagement_type === 'c2c' && corp.corp_legal_name) {
        await api.post(`/modules/placements/api/corp.php?placement_id=${placementId}`, corp);
      }

      nav(`../${placementId}`);
    } catch (e) {
      if (e.status === 409 && e.data?.conflict_id) {
        setPersonConflict(e.data.conflict || {
          id: e.data.conflict_id,
          first_name: '',
          last_name: '',
          email_primary: newPerson.email_primary.trim(),
        });
      } else {
        setError(e);
      }
      setSubmitting(false);
    }
  };

  return (
    <section className="person-create" data-testid="placement-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2 style={{ margin: 0 }}>New placement</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            {prefilledPersonId ? <span data-testid="placement-create-prefilled">For person #{prefilledPersonId}</span> : 'Add the person and engagement in one pass.'}
          </p>
        </div>
        <Link to=".." className="btn btn--ghost" data-testid="placement-create-back">← Back</Link>
      </header>

      <div data-testid="placement-create-required-hint"
           style={{ padding: '8px 12px', background: 'var(--cf-accent-light, #eaf4ff)', borderLeft: '3px solid var(--cf-accent, #007fff)', marginBottom: 16, fontSize: 13, color: 'var(--cf-text-secondary)' }}>
        Fields marked <strong>*</strong> are required. New people are classified from the engagement type and can be completed later in People.
      </div>

      <form onSubmit={submit} className="person-create__form" data-testid="placement-create-form" style={{ maxWidth: 920 }}>
        <fieldset disabled={submitting} style={{ border: 0, padding: 0 }}>

          {/* Internal-hire toggle */}
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '8px 12px', background: '#f1f5f9', borderRadius: 8, fontSize: 13, marginBottom: 16, cursor: 'pointer' }}>
            <input type="checkbox" checked={internalHire} onChange={onToggleInternal} data-testid="placement-create-internal-toggle" />
            <strong>This is an internal hire</strong>
            <span style={{ color: '#64748b' }}>· our own employee (admin / recruiter / accountant) — no end client, no vendor chain</span>
          </label>

          <SectionTitle>1. Person</SectionTitle>
          <div role="group" aria-label="Person source" style={modeSwitchStyle} data-testid="placement-create-person-mode">
            <button type="button" aria-pressed={personMode === 'new'} onClick={() => choosePersonMode('new')}
                    data-testid="placement-create-person-new" style={{ ...modeButtonStyle, ...(personMode === 'new' ? modeButtonActiveStyle : {}) }}>
              <UserRoundPlus size={16} aria-hidden="true" /> New person
            </button>
            <button type="button" aria-pressed={personMode === 'existing'} onClick={() => choosePersonMode('existing')}
                    data-testid="placement-create-person-existing" style={{ ...modeButtonStyle, ...(personMode === 'existing' ? modeButtonActiveStyle : {}) }}>
              <Search size={16} aria-hidden="true" /> Existing person
            </button>
          </div>

          {personMode === 'new' ? (
            <div data-testid="placement-create-new-person-fields">
              <Row>
                <Field label="First name *"><input className="input" required value={newPerson.first_name} onChange={setNewPersonF('first_name')} data-testid="placement-create-person-first-name" autoComplete="given-name" /></Field>
                <Field label="Last name *"><input className="input" required value={newPerson.last_name} onChange={setNewPersonF('last_name')} data-testid="placement-create-person-last-name" autoComplete="family-name" /></Field>
              </Row>
              <Row>
                <Field label="Work email *"><input className="input" type="email" required value={newPerson.email_primary} onChange={setNewPersonF('email_primary')} data-testid="placement-create-person-email" autoComplete="email" placeholder="name@company.com" /></Field>
                <Field label="Phone"><input className="input" type="tel" value={newPerson.phone_primary} onChange={setNewPersonF('phone_primary')} data-testid="placement-create-person-phone" autoComplete="tel" /></Field>
              </Row>
              <p style={{ margin: '-4px 0 14px', fontSize: 12, color: 'var(--cf-text-secondary)' }} data-testid="placement-create-person-classification">
                People classification: <strong style={{ color: 'var(--cf-text)' }}>{PERSON_CLASSIFICATION_LABELS[personClassification] || personClassification}</strong>
              </p>
            </div>
          ) : (
            <Field label="Person *">
              <input className="input" placeholder="Search by name, email, or ID" value={personSearch}
                     onChange={e => { setPersonSearch(e.target.value); if (form.person_id && !prefilledPersonId) setForm({ ...form, person_id: '' }); }}
                     data-testid="placement-create-person-search" />
              {!form.person_id && personLookup.data?.rows?.length > 0 && (
                <ul style={listStyle} data-testid="placement-create-person-results">
                  {personLookup.data.rows.map(p => (
                    <li key={p.id}>
                      <button type="button" onClick={() => { setForm({ ...form, person_id: p.id }); setPersonSearch(personDisplayName(p)); }}
                              data-testid={`placement-create-pick-person-${p.id}`} style={pickBtnStyle}>
                        {p.first_name} {p.last_name} <span style={{ color: 'var(--cf-text-secondary)' }}>· {p.email_primary} · {ETYPE_LABELS[p.classification] || p.classification}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              <input type="hidden" value={form.person_id} data-testid="placement-create-person-id" readOnly />
            </Field>
          )}

          {personConflict && (
            <div role="alert" data-testid="placement-create-person-conflict"
                 style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, padding: '10px 12px', marginBottom: 16, background: '#fff9eb', border: '1px solid #f5d88a', borderRadius: 6, color: '#7a4d00' }}>
              <span><strong>{personDisplayName(personConflict)}</strong> already uses this email.</span>
              <button type="button" className="btn btn--ghost" onClick={useConflictingPerson} data-testid="placement-create-use-existing-conflict">Use existing person</button>
            </div>
          )}

          <SectionTitle>2. Placement details</SectionTitle>

          <Row>
            <Field label="Title *"><input className="input" required value={form.title} onChange={set('title')} data-testid="placement-create-title" placeholder="Senior Software Engineer" /></Field>
            <Field label="Engagement type *">
              <select className="input" required disabled={internalHire} value={form.engagement_type} onChange={set('engagement_type')} data-testid="placement-create-etype">
                {ETYPES.map(t => <option key={t} value={t}>{ETYPE_LABELS[t]}</option>)}
              </select>
            </Field>
            <Field label="External ID"><input className="input" value={form.external_id} onChange={set('external_id')} data-testid="placement-create-external" placeholder="ATS / VMS reference" /></Field>
          </Row>

          <Row>
            <Field label="Start date *"><input className="input" type="date" required value={form.start_date} onChange={set('start_date')} data-testid="placement-create-start" /></Field>
            <Field label="End date"><input className="input" type="date" value={form.end_date} onChange={set('end_date')} data-testid="placement-create-end" /></Field>
            <Field label="Due date"><input className="input" type="date" value={form.due_date} onChange={set('due_date')} data-testid="placement-create-due" /></Field>
          </Row>

          <SectionTitle>Assignment reporting</SectionTitle>
          <Row>
            <Field label="Job / requisition">
              <select className="input" value={form.staffing_job_id} onChange={set('staffing_job_id')} data-testid="placement-create-job">
                <option value="">— Not linked —</option>
                {staffingJobs.map(job => <option key={job.id} value={job.id}>{job.external_id ? `${job.external_id} · ` : ''}{job.title}{job.client_name ? ` · ${job.client_name}` : ''}</option>)}
              </select>
            </Field>
            <Field label="Branch / business unit"><input className="input" value={form.branch} onChange={set('branch')} data-testid="placement-create-branch" placeholder="Charlotte" /></Field>
            <Field label="Service line"><input className="input" list="placement-service-lines" value={form.service_line} onChange={set('service_line')} data-testid="placement-create-service-line" placeholder="Contract staffing" /></Field>
          </Row>
          <datalist id="placement-service-lines">
            <option value="Contract staffing" /><option value="Direct hire" /><option value="Referral" />
            <option value="EOR / payrolling" /><option value="SOW / project" /><option value="Internal" />
          </datalist>
          <Row>
            <Field label="WC class"><input className="input" value={form.workers_comp_class} onChange={set('workers_comp_class')} data-testid="placement-create-wc-class" placeholder="8810" /></Field>
            <Field label="Department"><input className="input" value={form.department} onChange={set('department')} data-testid="placement-create-department" placeholder="Delivery" /></Field>
            <Field label="Cost center"><input className="input" value={form.cost_center} onChange={set('cost_center')} data-testid="placement-create-cost-center" placeholder="CLT-DEL" /></Field>
          </Row>
          <Row>
            <Field label="Recruiter">
              <select className="input" value={ownerUserId(ownerUsers, form.recruiter_email)} onChange={setOwner('recruiter')} data-testid="placement-create-recruiter">
                <option value="">— Not assigned —</option>
                {ownerUsers.map(user => <option key={user.id} value={user.id}>{ownerUserLabel(user)}</option>)}
              </select>
            </Field>
            <Field label="Account manager">
              <select className="input" value={ownerUserId(ownerUsers, form.account_manager_email)} onChange={setOwner('account_manager')} data-testid="placement-create-account-manager">
                <option value="">— Not assigned —</option>
                {ownerUsers.map(user => <option key={user.id} value={user.id}>{ownerUserLabel(user)}</option>)}
              </select>
            </Field>
          </Row>
          <Row>
            <EntityPicker
              value={form.accounting_entity_id || null}
              onChange={(value) => setForm({ ...form, accounting_entity_id: value || '' })}
              label="Legal entity"
              testId="placement-create-legal-entity"
            />
          </Row>

          {!internalHire && (
            <>
              <SectionTitle>3. End client</SectionTitle>
              <Field label="End client (typeahead — picks from Companies, or creates one)">
                <CompanyTypeahead
                  role="client"
                  value={endClient}
                  onChange={setEndClient}
                  testId="placement-create-end-client"
                  placeholder="e.g. Apple, Acme Inc, Globex…"
                />
              </Field>
              <Row>
                <Field label="Client approver name"><input className="input" value={form.client_approver_name} onChange={set('client_approver_name')} data-testid="placement-create-approver-name" placeholder="John Smith (signs timesheets)" /></Field>
                <Field label="Client approver email"><input className="input" type="email" value={form.client_approver_email} onChange={set('client_approver_email')} data-testid="placement-create-approver-email" placeholder="approver@client.com" /></Field>
              </Row>
              <Row>
                <Field label="Worksite state"><input className="input" value={form.worksite_state} onChange={set('worksite_state')} data-testid="placement-create-state" placeholder="CA" /></Field>
                <Field label="Worksite country"><input className="input" maxLength={2} value={form.worksite_country} onChange={set('worksite_country')} data-testid="placement-create-country" /></Field>
                <Field label="Remote policy">
                  <select className="input" value={form.remote_policy} onChange={set('remote_policy')} data-testid="placement-create-remote">
                    <option value="">—</option>
                    <option value="onsite">On-site</option>
                    <option value="hybrid">Hybrid</option>
                    <option value="remote">Remote</option>
                  </select>
                </Field>
              </Row>

              <SectionTitle>4. Vendor chain (optional — between us and the end client)</SectionTitle>
              <p style={{ margin: '0 0 8px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                Add MSPs, prime vendors, or sub-vendors in order. Skip if direct to client.
              </p>
              {chain.map((c, i) => (
                <div key={i} style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr 1fr 0.8fr 0.8fr 30px', gap: 8, marginBottom: 8, alignItems: 'end' }} data-testid={`placement-create-chain-row-${i}`}>
                  <Field label={`Vendor ${i + 1}`}>
                    <CompanyTypeahead
                      role={c.party_role || 'vendor'}
                      value={c.company}
                      onChange={(co) => updateChain(i, { company: co })}
                      testId={`placement-create-chain-${i}`}
                      placeholder="Vendor name…"
                    />
                  </Field>
                  <Field label="Role">
                    <select className="input" value={c.party_role} onChange={(e) => updateChain(i, { party_role: e.target.value })} data-testid={`placement-create-chain-${i}-role`}>
                      <option value="msp">Managed service provider</option>
                      <option value="prime_vendor">Prime vendor</option>
                      <option value="sub_vendor">Subvendor</option>
                    </select>
                  </Field>
                  <Field label="Portal ID"><input className="input" value={c.vendor_portal_id || ''} onChange={(e) => updateChain(i, { vendor_portal_id: e.target.value })} data-testid={`placement-create-chain-${i}-portal`} /></Field>
                  <Field label="Fee %"><input className="input" type="number" step="0.01" value={c.portal_fee_pct || ''} onChange={(e) => updateChain(i, { portal_fee_pct: e.target.value })} data-testid={`placement-create-chain-${i}-feepct`} /></Field>
                  <Field label="Fee $"><input className="input" type="number" step="0.01" value={c.portal_fee_flat || ''} onChange={(e) => updateChain(i, { portal_fee_flat: e.target.value })} data-testid={`placement-create-chain-${i}-feeflat`} /></Field>
                  <button type="button" onClick={() => setChain(chain.filter((_, j) => j !== i))} data-testid={`placement-create-chain-${i}-remove`} style={{ background: 'transparent', border: 0, fontSize: 18, color: '#ef4444', cursor: 'pointer' }}>×</button>
                </div>
              ))}
              <button type="button" onClick={() => setChain([...chain, { company: null, party_role: 'prime_vendor' }])} className="btn btn--ghost" data-testid="placement-create-chain-add">+ Add vendor</button>
            </>
          )}

          <SectionTitle>{internalHire ? '3. Initial rate' : isReferral ? '5. Referral economics' : '5. Initial rate'} {isReferral ? '' : '(optional but recommended)'}</SectionTitle>
          <Row>
            <Field label={isReferral ? 'Client referral fee / hour *' : 'Bill rate'}><input className="input" type="number" min="0" step="0.01" value={rate.bill_rate} onChange={setRateF('bill_rate')} data-testid="placement-create-rate-bill" placeholder={isReferral ? '4.00' : '125.00'} /></Field>
            {!isReferral && <Field label="Bill unit">
              <select className="input" value={rate.bill_rate_unit} onChange={setRateF('bill_rate_unit')} data-testid="placement-create-rate-bill-unit">
                {RATE_UNITS.map(u => <option key={u} value={u}>{u}</option>)}
              </select>
            </Field>}
            {!isReferral && <Field label="Pay rate"><input className="input" type="number" step="0.01" value={rate.pay_rate} onChange={setRateF('pay_rate')} data-testid="placement-create-rate-pay" placeholder="75.00" /></Field>}
            {!isReferral && <Field label="Pay unit">
              <select className="input" value={rate.pay_rate_unit} onChange={setRateF('pay_rate_unit')} data-testid="placement-create-rate-pay-unit">
                {RATE_UNITS.map(u => <option key={u} value={u}>{u}</option>)}
              </select>
            </Field>}
            {isReferral && <Field label="Referral vendor *">
              <CompanyTypeahead role="referrer" value={referral?.referrer_company || null} onChange={(co) => setReferral({ ...referral, referrer_company: co, referrer_vendor_name: co?.name || '' })} testId="placement-create-referral-company" placeholder="Vendor / agency name..." />
            </Field>}
            {isReferral && <Field label="Vendor referral payout / hour *"><input className="input" type="number" min="0" step="0.01" value={referral?.fee_flat || ''} onChange={(e) => setReferral({ ...referral, fee_flat: e.target.value })} data-testid="placement-create-referral-flat" placeholder="2.00" /></Field>}
            <Field label="Currency">
              <select className="input" value={rate.currency} onChange={setRateF('currency')} data-testid="placement-create-rate-currency">
                <option value="USD">USD</option><option value="CAD">CAD</option><option value="GBP">GBP</option>
                <option value="EUR">EUR</option><option value="INR">INR</option>
              </select>
            </Field>
          </Row>
          {isReferral && <Row>
            <Field label="Referral vendor payment terms">
              <select className="input" value={referral?.payment_terms_override || 'NET30'} onChange={(e) => setReferral({ ...referral, payment_terms_override: e.target.value })} data-testid="placement-create-referral-terms">
                {['DUE_ON_RECEIPT','NET7','NET15','NET30','NET45','NET60','NET90'].map(term => <option key={term} value={term}>{term === 'DUE_ON_RECEIPT' ? 'Due on receipt' : term.replace('NET', 'Net ')}</option>)}
              </select>
            </Field>
            <Field label="Payout ends"><input className="input" type="date" value={referral?.end_date || form.end_date || ''} onChange={(e) => setReferral({ ...referral, end_date: e.target.value })} data-testid="placement-create-referral-end" /></Field>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, minWidth: 220, paddingTop: 22 }}>
              <input type="checkbox" checked={Boolean(referral?.pwp_enabled)} onChange={(e) => setReferral({ ...referral, pwp_enabled: e.target.checked })} data-testid="placement-create-referral-pwp" />
              Pay vendor after client pays
            </label>
          </Row>}
          <Row>
            <Field label="Effective from"><input className="input" type="date" value={rate.effective_from} onChange={setRateF('effective_from')} data-testid="placement-create-rate-effective" placeholder={form.start_date} /></Field>
            <Field label="OT mult"><input className="input" type="number" step="0.01" value={rate.overtime_multiplier} onChange={setRateF('overtime_multiplier')} data-testid="placement-create-rate-ot" /></Field>
            <Field label="DT mult"><input className="input" type="number" step="0.01" value={rate.doubletime_multiplier} onChange={setRateF('doubletime_multiplier')} data-testid="placement-create-rate-dt" /></Field>
            {form.engagement_type === 'w2' && <Field label="Employer load %"><input className="input" type="number" step="0.01" value={rate.adder_pct} onChange={setRateF('adder_pct')} data-testid="placement-create-rate-adder" placeholder="Blank uses tenant default" /></Field>}
            {form.engagement_type === 'c2c' && <Field label="C2C overhead %"><input className="input" type="number" step="0.01" value={rate.c2c_overhead_pct} onChange={setRateF('c2c_overhead_pct')} data-testid="placement-create-rate-c2c-overhead" placeholder="Blank uses tenant default" /></Field>}
            <Field label="Background fee ($)"><input className="input" type="number" step="0.01" value={rate.background_fee_total} onChange={setRateF('background_fee_total')} data-testid="placement-create-rate-bgfee" placeholder="one-time" /></Field>
          </Row>

          <button type="button" onClick={() => setShowAdvanced(!showAdvanced)} className="btn btn--ghost"
                  data-testid="placement-create-toggle-advanced" style={{ marginTop: 8, fontSize: 13 }}>
            {showAdvanced ? '− Hide' : '+ Show'} commissions{!isReferral ? ', referral' : ''}{form.engagement_type === 'c2c' ? ', corp details' : ''}
          </button>

          {showAdvanced && (
            <>
              <SectionTitle>{internalHire ? '4' : '6'}. Commissions (optional)</SectionTitle>
              <p style={{ margin: '0 0 8px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                Each split is one row. Splits within the same role + window must sum to 100%.
              </p>
              {commissions.map((c, i) => (
                <div key={i} data-testid={`placement-create-commission-row-${i}`} style={{ display: 'grid', gridTemplateColumns: '1fr 1.4fr 0.8fr 0.8fr 1fr 1fr 30px', gap: 8, marginBottom: 8, alignItems: 'end' }}>
                  <Field label="Role">
                    <select className="input" value={c.role || ''} onChange={(e) => updateCommission(i, { role: e.target.value })} data-testid={`placement-create-commission-${i}-role`}>
                      <option value="">—</option>
                      {COMMISSION_ROLES.map(r => <option key={r} value={r}>{r}</option>)}
                    </select>
                  </Field>
                  <Field label="User (optional for 'team')">
                    <select className="input" value={c.user_id || ''} onChange={(e) => updateCommission(i, { user_id: e.target.value })} data-testid={`placement-create-commission-${i}-user`}>
                      <option value="">—</option>
                      {tenantUsers.map(u => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}
                    </select>
                  </Field>
                  <Field label="Split %"><input className="input" type="number" step="0.01" value={c.split_pct || ''} onChange={(e) => updateCommission(i, { split_pct: e.target.value })} data-testid={`placement-create-commission-${i}-pct`} placeholder="60" /></Field>
                  <Field label="Flat $"><input className="input" type="number" step="0.01" value={c.flat_amount || ''} onChange={(e) => updateCommission(i, { flat_amount: e.target.value })} data-testid={`placement-create-commission-${i}-flat`} placeholder="when basis=flat" /></Field>
                  <Field label="Basis">
                    <select className="input" value={c.basis || 'net_margin'} onChange={(e) => updateCommission(i, { basis: e.target.value })} data-testid={`placement-create-commission-${i}-basis`}>
                      {COMMISSION_BASIS.map(b => <option key={b} value={b}>{b}</option>)}
                    </select>
                  </Field>
                  <Field label="Effective from"><input className="input" type="date" value={c.effective_from || ''} onChange={(e) => updateCommission(i, { effective_from: e.target.value })} data-testid={`placement-create-commission-${i}-eff`} placeholder={form.start_date} /></Field>
                  <button type="button" onClick={() => setCommissions(commissions.filter((_, j) => j !== i))} data-testid={`placement-create-commission-${i}-remove`} style={{ background: 'transparent', border: 0, fontSize: 18, color: '#ef4444', cursor: 'pointer' }}>×</button>
                </div>
              ))}
              <button type="button" onClick={() => setCommissions([...commissions, { role: 'recruiter', basis: 'net_margin', split_pct: '' }])}
                      className="btn btn--ghost" data-testid="placement-create-commission-add">+ Add commission split</button>

              {!isReferral && <><SectionTitle>{internalHire ? '5' : '7'}. Referral (optional)</SectionTitle>
              {!referral && (
                <button type="button" onClick={() => setReferral({ referrer_type: 'vendor', fee_basis: 'pct_bill' })}
                        className="btn btn--ghost" data-testid="placement-create-referral-add">+ Add referral fee</button>
              )}
              {referral && (
                <div data-testid="placement-create-referral-row">
                  <Row>
                    <Field label="Referrer type">
                      <select className="input" value={referral.referrer_type} onChange={(e) => setReferral({ ...referral, referrer_type: e.target.value })} data-testid="placement-create-referral-type">
                        {REFERRER_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
                      </select>
                    </Field>
                    {referral.referrer_type === 'vendor' && (
                      <Field label="Referrer company">
                        <CompanyTypeahead role="referrer" value={referral.referrer_company} onChange={(co) => setReferral({ ...referral, referrer_company: co, referrer_vendor_name: co?.name })} testId="placement-create-referral-company" placeholder="Vendor / agency name…" />
                      </Field>
                    )}
                    {referral.referrer_type === 'person' && (
                      <Field label="Referrer person id"><input className="input" type="number" value={referral.referrer_person_id || ''} onChange={(e) => setReferral({ ...referral, referrer_person_id: e.target.value })} data-testid="placement-create-referral-person-id" /></Field>
                    )}
                    {referral.referrer_type === 'user' && (
                      <Field label="Referrer user">
                        <select className="input" value={referral.referrer_user_id || ''} onChange={(e) => setReferral({ ...referral, referrer_user_id: e.target.value })} data-testid="placement-create-referral-user-id">
                          <option value="">—</option>
                          {tenantUsers.map(u => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}
                        </select>
                      </Field>
                    )}
                  </Row>
                  <Row>
                    <Field label="Fee basis">
                      <select className="input" value={referral.fee_basis} onChange={(e) => setReferral({ ...referral, fee_basis: e.target.value })} data-testid="placement-create-referral-basis">
                        {FEE_BASIS.map(b => <option key={b} value={b}>{b}</option>)}
                      </select>
                    </Field>
                    <Field label="Fee %"><input className="input" type="number" step="0.01" value={referral.fee_pct || ''} onChange={(e) => setReferral({ ...referral, fee_pct: e.target.value })} data-testid="placement-create-referral-pct" placeholder="e.g. 5" /></Field>
                    <Field label="Fee $"><input className="input" type="number" step="0.01" value={referral.fee_flat || ''} onChange={(e) => setReferral({ ...referral, fee_flat: e.target.value })} data-testid="placement-create-referral-flat" /></Field>
                    <Field label="Duration (months)"><input className="input" type="number" value={referral.duration_months || ''} onChange={(e) => setReferral({ ...referral, duration_months: e.target.value })} data-testid="placement-create-referral-duration" /></Field>
                    <Field label="Start date"><input className="input" type="date" value={referral.start_date || ''} onChange={(e) => setReferral({ ...referral, start_date: e.target.value })} data-testid="placement-create-referral-start" placeholder={form.start_date} /></Field>
                  </Row>
                  <button type="button" onClick={() => setReferral(null)} className="btn btn--ghost" data-testid="placement-create-referral-remove" style={{ fontSize: 12, color: '#ef4444' }}>Remove referral</button>
                </div>
              )}</>}

              {form.engagement_type === 'c2c' && (
                <>
                  <SectionTitle>{internalHire ? '6' : '8'}. C2C corp details</SectionTitle>
                  <Row>
                    <Field label="Corp legal name *"><input className="input" value={corp.corp_legal_name} onChange={setCorpF('corp_legal_name')} data-testid="placement-create-corp-name" /></Field>
                    <Field label="EIN"><input className="input" value={corp.corp_ein} onChange={setCorpF('corp_ein')} data-testid="placement-create-corp-ein" placeholder="XX-XXXXXXX (encrypted at rest)" /></Field>
                  </Row>
                  <Row>
                    <Field label="Address line 1"><input className="input" value={corp.corp_address_line1} onChange={setCorpF('corp_address_line1')} data-testid="placement-create-corp-addr1" /></Field>
                    <Field label="Address line 2"><input className="input" value={corp.corp_address_line2} onChange={setCorpF('corp_address_line2')} data-testid="placement-create-corp-addr2" /></Field>
                  </Row>
                  <Row>
                    <Field label="City"><input className="input" value={corp.corp_city} onChange={setCorpF('corp_city')} data-testid="placement-create-corp-city" /></Field>
                    <Field label="State"><input className="input" value={corp.corp_state} onChange={setCorpF('corp_state')} data-testid="placement-create-corp-state" /></Field>
                    <Field label="Postal"><input className="input" value={corp.corp_postal_code} onChange={setCorpF('corp_postal_code')} data-testid="placement-create-corp-postal" /></Field>
                    <Field label="Country">
                      <select className="input" value={corp.corp_country} onChange={setCorpF('corp_country')} data-testid="placement-create-corp-country">
                        {COUNTRIES.map(c => <option key={c} value={c}>{c}</option>)}
                      </select>
                    </Field>
                  </Row>
                  <Row>
                    <Field label="Contact name"><input className="input" value={corp.corp_contact_name} onChange={setCorpF('corp_contact_name')} data-testid="placement-create-corp-contact-name" /></Field>
                    <Field label="Contact email"><input className="input" type="email" value={corp.corp_contact_email} onChange={setCorpF('corp_contact_email')} data-testid="placement-create-corp-contact-email" /></Field>
                    <Field label="Contact phone"><input className="input" value={corp.corp_contact_phone} onChange={setCorpF('corp_contact_phone')} data-testid="placement-create-corp-contact-phone" /></Field>
                  </Row>
                  <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
                    MSA / COI / W-9 documents upload from the placement detail page → Documents tab once this placement is created.
                  </p>
                </>
              )}
            </>
          )}

          <SectionTitle>Notes</SectionTitle>
          <Field label="Internal notes (not shared with client)">
            <textarea className="input" rows={3} value={form.notes} onChange={set('notes')} data-testid="placement-create-notes" />
          </Field>

          {error && <p className="error" data-testid="placement-create-error">Error: {error.message} {error.data?.fields ? `(missing: ${error.data.fields.join(', ')})` : ''}</p>}

          <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', alignItems: 'center', flexWrap: 'wrap' }}>
            <button type="submit" className="btn btn--primary" data-testid="placement-create-submit"
                    disabled={submitting || missing.length > 0}
                    title={missing.length ? `Fill required: ${missing.join(', ')}` : (personMode === 'new' ? 'Create person and placement' : 'Create placement')}>
              {submitting ? 'Saving…' : (personMode === 'new' ? 'Create person & placement' : 'Create placement')}
            </button>
            <Link to=".." className="btn btn--ghost" data-testid="placement-create-cancel">Cancel</Link>
            {missing.length > 0 && (
              <span data-testid="placement-create-missing-hint" style={{ fontSize: 13, color: '#b45309' }}>
                Fill required: {missing.join(' · ')}
              </span>
            )}
          </div>
        </fieldset>
      </form>
    </section>
  );

  function updateChain(i, patch) {
    const next = [...chain];
    next[i] = { ...next[i], ...patch };
    setChain(next);
  }
  function updateCommission(i, patch) {
    const next = [...commissions];
    next[i] = { ...next[i], ...patch };
    setCommissions(next);
  }
}

const Row = ({ children }) => <div style={{ display: 'flex', gap: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>{children}</div>;
const Field = ({ label, children }) => (
  <label style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: '180px' }}>
    <span style={{ fontSize: '0.85em', color: 'var(--cf-text-secondary)', marginBottom: 'var(--cf-space-1)' }}>{label}</span>
    {children}
  </label>
);
const SectionTitle = ({ children }) => (
  <h3 style={{ marginTop: 24, marginBottom: 8, fontSize: 14, textTransform: 'uppercase', letterSpacing: 0, color: 'var(--cf-text-secondary)' }}>{children}</h3>
);
const listStyle = { listStyle: 'none', padding: 0, margin: 'var(--cf-space-2) 0', maxHeight: '180px', overflow: 'auto', border: '1px solid var(--cf-border)', borderRadius: 'var(--cf-radius-md)' };
const pickBtnStyle = { width: '100%', textAlign: 'left', padding: 'var(--cf-space-2)', background: 'transparent', border: 'none', cursor: 'pointer' };
const modeSwitchStyle = { display: 'inline-flex', gap: 2, padding: 3, marginBottom: 16, background: '#edf4fc', border: '1px solid #d7e5f5', borderRadius: 6 };
const modeButtonStyle = { minHeight: 34, display: 'inline-flex', alignItems: 'center', gap: 7, padding: '6px 12px', border: '1px solid transparent', borderRadius: 4, background: 'transparent', color: 'var(--cf-text-secondary)', fontWeight: 600, cursor: 'pointer' };
const modeButtonActiveStyle = { background: '#fff', borderColor: '#b8d7fb', color: 'var(--cf-accent, #007fff)', boxShadow: 'inset 0 -2px 0 var(--cf-accent, #007fff)' };

function classificationForEngagement(engagementType) {
  if (engagementType === 'c2c') return 'c2c';
  if (engagementType === '1099') return '1099';
  if (engagementType === 'temp_to_perm') return 'temp';
  if (engagementType === 'direct_hire') return 'perm';
  if (engagementType === 'referral') return 'candidate';
  return 'w2';
}

function personDisplayName(person) {
  const name = [person?.first_name, person?.last_name].filter(Boolean).join(' ').trim();
  const email = person?.email_primary || '';
  if (name && email) return `${name} (${email})`;
  return name || email || `Person #${person?.id || ''}`;
}

function ownerUserId(users, email) {
  if (!email) return '';
  const match = users.find(user => String(user.email || '').toLowerCase() === String(email).toLowerCase());
  return match ? String(match.id) : '';
}

function ownerUserLabel(user) {
  const name = String(user?.name || '').trim();
  const email = String(user?.email || '').trim();
  return name && email ? `${name} (${email})` : name || email || `User #${user?.id || ''}`;
}
