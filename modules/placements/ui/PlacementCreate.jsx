import React, { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

const ETYPES = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire', 'internal'];
const RATE_UNITS = ['hour', 'day', 'week', 'month', 'project'];
const COMMISSION_ROLES = ['account_manager', 'lead', 'recruiter', 'team', 'other'];
const COMMISSION_BASIS = ['net_margin', 'gross_margin', 'bill_rate', 'flat'];
const REFERRER_TYPES = ['vendor', 'person', 'user'];
const FEE_BASIS = ['per_hour', 'per_invoice', 'one_time', 'pct_bill', 'pct_margin'];
const COUNTRIES = ['US', 'CA', 'IN', 'GB', 'MX']; // common — not exhaustive
const REQUIRED_FIELDS = ['Person', 'Title', 'Start date', 'Engagement type'];

/**
 * PlacementCreate — full SPEC §3 coverage form.
 *
 * Sections:
 *   1. Person + role (with Internal-hire toggle)
 *   2. End client (hidden for internal hires)
 *   3. Vendor chain (hidden for internal hires)
 *   4. Initial rate (currency / unit / adder / background fee)
 *   5. Commissions (inline rows)
 *   6. Referral (optional single)
 *   7. C2C corp details (only when engagement_type='c2c')
 *   8. Notes
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
    client_approver_name: '', client_approver_email: '',
  });
  const [internalHire, setInternalHire] = useState(false);
  const [endClient, setEndClient] = useState(null);
  const [chain, setChain]         = useState([]);
  const [rate, setRate]           = useState({
    effective_from: '', bill_rate: '', pay_rate: '',
    bill_rate_unit: 'hour', pay_rate_unit: 'hour',
    currency: 'USD',
    overtime_multiplier: '1.5', doubletime_multiplier: '2.0',
    adder_pct: '', background_fee_total: '',
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

  // Person typeahead
  const [personSearch, setPersonSearch] = useState('');
  const personLookup = useApi(personSearch.length >= 2 && !form.person_id
    ? `/modules/people/api/people.php?q=${encodeURIComponent(personSearch)}&per_page=10`
    : null);
  const prefilled = useApi(prefilledPersonId ? `/modules/people/api/people.php?id=${prefilledPersonId}` : null);
  useEffect(() => {
    const p = prefilled.data?.person;
    if (p) setPersonSearch(`${p.first_name} ${p.last_name} (${p.email_primary})`);
  }, [prefilled.data?.person?.id]);

  // Tenant user list (for commission row "user_id" picker)
  const usersLookup = useApi('/api/users.php');
  const tenantUsers = usersLookup.data?.users || usersLookup.data?.rows || [];

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const setRateF = (k) => (e) => setRate({ ...rate, [k]: e.target.value });
  const setCorpF = (k) => (e) => setCorp({ ...corp, [k]: e.target.value });

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
    if (!form.person_id)        m.push('Person');
    if (!form.title.trim())     m.push('Title');
    if (!form.start_date)       m.push('Start date');
    if (!form.engagement_type)  m.push('Engagement type');
    return m;
  }, [form.person_id, form.title, form.start_date, form.engagement_type]);

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setError(null);
    try {
      // 1) Create placement
      const payload = { ...form };
      ['end_date', 'due_date'].forEach(k => { if (!payload[k]) delete payload[k]; });
      payload.person_id = parseInt(payload.person_id, 10);
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
      if (rate.bill_rate || rate.pay_rate) {
        await api.post(`/modules/placements/api/rates.php?placement_id=${placementId}`, {
          effective_from: rate.effective_from || form.start_date,
          bill_rate:  rate.bill_rate  ? Number(rate.bill_rate)  : 0,
          pay_rate:   rate.pay_rate   ? Number(rate.pay_rate)   : 0,
          bill_rate_unit: rate.bill_rate_unit || 'hour',
          pay_rate_unit:  rate.pay_rate_unit  || 'hour',
          currency: rate.currency || 'USD',
          overtime_multiplier:   Number(rate.overtime_multiplier   || 1.5),
          doubletime_multiplier: Number(rate.doubletime_multiplier || 2.0),
          adder_pct:            rate.adder_pct ? Number(rate.adder_pct) / 100 : null,
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
          fee_basis: referral.fee_basis,
          duration_months: referral.duration_months ? parseInt(referral.duration_months, 10) : null,
          start_date: referral.start_date || form.start_date,
          notes: referral.notes || null,
        });
      }

      // 6) C2C corp details (only when engagement_type='c2c')
      if (form.engagement_type === 'c2c' && corp.corp_legal_name) {
        await api.post(`/modules/placements/api/corp.php?placement_id=${placementId}`, corp);
      }

      nav(`../${placementId}`);
    } catch (e) { setError(e); setSubmitting(false); }
  };

  return (
    <section className="person-create" data-testid="placement-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2 style={{ margin: 0 }}>New placement</h2>
          {prefilledPersonId && <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }} data-testid="placement-create-prefilled">For person #{prefilledPersonId}</p>}
        </div>
        <Link to=".." className="btn btn--ghost" data-testid="placement-create-back">← Back</Link>
      </header>

      {/* Required-fields hint banner */}
      <div data-testid="placement-create-required-hint"
           style={{ padding: 10, background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, marginBottom: 16, fontSize: 13, color: '#1e40af' }}>
        <strong>Required fields:</strong> {REQUIRED_FIELDS.join(' · ')}. Everything else is optional and editable later.
        <span style={{ marginLeft: 8, color: '#475569' }}>
          Documents (MSA / COI / W-9 / contracts) upload from the placement detail page after creation.
        </span>
      </div>

      <form onSubmit={submit} className="person-create__form" data-testid="placement-create-form" style={{ maxWidth: 920 }}>
        <fieldset disabled={submitting} style={{ border: 0, padding: 0 }}>

          {/* Internal-hire toggle */}
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '8px 12px', background: '#f1f5f9', borderRadius: 8, fontSize: 13, marginBottom: 16, cursor: 'pointer' }}>
            <input type="checkbox" checked={internalHire} onChange={onToggleInternal} data-testid="placement-create-internal-toggle" />
            <strong>This is an internal hire</strong>
            <span style={{ color: '#64748b' }}>· our own employee (admin / recruiter / accountant) — no end client, no vendor chain</span>
          </label>

          <SectionTitle>1. Person + role</SectionTitle>
          <Field label="Person *">
            <input className="input" placeholder="Type to search People…" value={personSearch}
                   onChange={e => { setPersonSearch(e.target.value); if (form.person_id && !prefilledPersonId) setForm({ ...form, person_id: '' }); }}
                   data-testid="placement-create-person-search" />
            {!form.person_id && personLookup.data?.rows?.length > 0 && (
              <ul style={listStyle} data-testid="placement-create-person-results">
                {personLookup.data.rows.map(p => (
                  <li key={p.id}>
                    <button type="button" onClick={() => { setForm({ ...form, person_id: p.id }); setPersonSearch(`${p.first_name} ${p.last_name} (${p.email_primary})`); }}
                            data-testid={`placement-create-pick-person-${p.id}`} style={pickBtnStyle}>
                      {p.first_name} {p.last_name} <span style={{ color: 'var(--cf-text-secondary)' }}>· {p.email_primary} · {p.classification}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <input type="hidden" value={form.person_id} data-testid="placement-create-person-id" readOnly />
          </Field>

          <Row>
            <Field label="Title *"><input className="input" required value={form.title} onChange={set('title')} data-testid="placement-create-title" placeholder="Senior Software Engineer" /></Field>
            <Field label="Engagement type *">
              <select className="input" required value={form.engagement_type} onChange={set('engagement_type')} data-testid="placement-create-etype">
                {ETYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </Field>
            <Field label="External ID"><input className="input" value={form.external_id} onChange={set('external_id')} data-testid="placement-create-external" placeholder="ATS / VMS reference" /></Field>
          </Row>

          <Row>
            <Field label="Start date *"><input className="input" type="date" required value={form.start_date} onChange={set('start_date')} data-testid="placement-create-start" /></Field>
            <Field label="End date"><input className="input" type="date" value={form.end_date} onChange={set('end_date')} data-testid="placement-create-end" /></Field>
            <Field label="Due date"><input className="input" type="date" value={form.due_date} onChange={set('due_date')} data-testid="placement-create-due" /></Field>
          </Row>

          {!internalHire && (
            <>
              <SectionTitle>2. End client</SectionTitle>
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
                    <option value="onsite">onsite</option>
                    <option value="hybrid">hybrid</option>
                    <option value="remote">remote</option>
                  </select>
                </Field>
              </Row>

              <SectionTitle>3. Vendor chain (optional — between us and the end client)</SectionTitle>
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
                      <option value="msp">msp</option>
                      <option value="prime_vendor">prime_vendor</option>
                      <option value="sub_vendor">sub_vendor</option>
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

          <SectionTitle>{internalHire ? '2. Initial rate' : '4. Initial rate'} (optional but recommended)</SectionTitle>
          <Row>
            <Field label="Bill rate"><input className="input" type="number" step="0.01" value={rate.bill_rate} onChange={setRateF('bill_rate')} data-testid="placement-create-rate-bill" placeholder="125.00" /></Field>
            <Field label="Bill unit">
              <select className="input" value={rate.bill_rate_unit} onChange={setRateF('bill_rate_unit')} data-testid="placement-create-rate-bill-unit">
                {RATE_UNITS.map(u => <option key={u} value={u}>{u}</option>)}
              </select>
            </Field>
            <Field label="Pay rate"><input className="input" type="number" step="0.01" value={rate.pay_rate} onChange={setRateF('pay_rate')} data-testid="placement-create-rate-pay" placeholder="75.00" /></Field>
            <Field label="Pay unit">
              <select className="input" value={rate.pay_rate_unit} onChange={setRateF('pay_rate_unit')} data-testid="placement-create-rate-pay-unit">
                {RATE_UNITS.map(u => <option key={u} value={u}>{u}</option>)}
              </select>
            </Field>
            <Field label="Currency">
              <select className="input" value={rate.currency} onChange={setRateF('currency')} data-testid="placement-create-rate-currency">
                <option value="USD">USD</option><option value="CAD">CAD</option><option value="GBP">GBP</option>
                <option value="EUR">EUR</option><option value="INR">INR</option>
              </select>
            </Field>
          </Row>
          <Row>
            <Field label="Effective from"><input className="input" type="date" value={rate.effective_from} onChange={setRateF('effective_from')} data-testid="placement-create-rate-effective" placeholder={form.start_date} /></Field>
            <Field label="OT mult"><input className="input" type="number" step="0.01" value={rate.overtime_multiplier} onChange={setRateF('overtime_multiplier')} data-testid="placement-create-rate-ot" /></Field>
            <Field label="DT mult"><input className="input" type="number" step="0.01" value={rate.doubletime_multiplier} onChange={setRateF('doubletime_multiplier')} data-testid="placement-create-rate-dt" /></Field>
            <Field label="Adder %"><input className="input" type="number" step="0.01" value={rate.adder_pct} onChange={setRateF('adder_pct')} data-testid="placement-create-rate-adder" placeholder="e.g. 22 for 22% employer burden" /></Field>
            <Field label="Background fee ($)"><input className="input" type="number" step="0.01" value={rate.background_fee_total} onChange={setRateF('background_fee_total')} data-testid="placement-create-rate-bgfee" placeholder="one-time" /></Field>
          </Row>

          <button type="button" onClick={() => setShowAdvanced(!showAdvanced)} className="btn btn--ghost"
                  data-testid="placement-create-toggle-advanced" style={{ marginTop: 8, fontSize: 13 }}>
            {showAdvanced ? '− Hide' : '+ Show'} commissions, referral{form.engagement_type === 'c2c' ? ', corp details' : ''}
          </button>

          {showAdvanced && (
            <>
              <SectionTitle>{internalHire ? '3' : '5'}. Commissions (optional)</SectionTitle>
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

              <SectionTitle>{internalHire ? '4' : '6'}. Referral (optional)</SectionTitle>
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
              )}

              {form.engagement_type === 'c2c' && (
                <>
                  <SectionTitle>{internalHire ? '5' : '7'}. C2C corp details</SectionTitle>
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
                    title={missing.length ? `Fill required: ${missing.join(', ')}` : 'Create placement'}>
              {submitting ? 'Saving…' : 'Create placement'}
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
  <h3 style={{ marginTop: 24, marginBottom: 8, fontSize: 14, textTransform: 'uppercase', letterSpacing: 0.5, color: 'var(--cf-text-secondary)' }}>{children}</h3>
);
const listStyle = { listStyle: 'none', padding: 0, margin: 'var(--cf-space-2) 0', maxHeight: '180px', overflow: 'auto', border: '1px solid var(--cf-border)', borderRadius: 'var(--cf-radius-md)' };
const pickBtnStyle = { width: '100%', textAlign: 'left', padding: 'var(--cf-space-2)', background: 'transparent', border: 'none', cursor: 'pointer' };
