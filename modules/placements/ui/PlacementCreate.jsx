import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

const ETYPES = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];
const VENDOR_ROLES = ['msp','prime_vendor','sub_vendor','vendor'];

/**
 * PlacementCreate — wizard-style form.
 *
 * Fields are grouped into Required / Client / Vendor chain / Initial rate /
 * C2C corp details (only when engagement_type=c2c). Companies are picked via
 * a typeahead against /api/people/companies — never free-text — so the same
 * "Acme Inc" record is reused across placements, AR, AP, referrals.
 *
 * Accepts ?person_id=N in the URL (e.g. from PersonDetail's "+ New placement"
 * button) so the person picker is pre-filled.
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
  const [endClient, setEndClient] = useState(null);   // { id, name }
  const [chain, setChain]         = useState([]);     // [{ company:{id,name}, party_role, vendor_portal_id, portal_fee_pct, portal_fee_flat }]
  const [rate, setRate]           = useState({ effective_from: '', bill_rate: '', pay_rate: '', rate_unit: 'hour', overtime_multiplier: '1.5', doubletime_multiplier: '2.0' });
  const [corp, setCorp]           = useState({ corp_legal_name: '', corp_ein: '' });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  // Person typeahead (separate concern — uses people.php directly)
  const [personSearch, setPersonSearch] = useState('');
  const personLookup = useApi(personSearch.length >= 2
    ? `/modules/people/api/people.php?q=${encodeURIComponent(personSearch)}&per_page=10`
    : null);

  // If pre-filled with person_id, fetch their name to display
  const prefilled = useApi(prefilledPersonId ? `/modules/people/api/people.php?id=${prefilledPersonId}` : null);
  useEffect(() => {
    const p = prefilled.data?.person;
    if (p) setPersonSearch(`${p.first_name} ${p.last_name} (${p.email_primary})`);
  }, [prefilled.data?.person?.id]);

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const setRateF = (k) => (e) => setRate({ ...rate, [k]: e.target.value });
  const setCorpF = (k) => (e) => setCorp({ ...corp, [k]: e.target.value });

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setError(null);
    try {
      // 1) Create placement
      const payload = { ...form };
      ['end_date', 'due_date'].forEach(k => { if (!payload[k]) delete payload[k]; });
      payload.person_id = parseInt(payload.person_id, 10);
      if (endClient) {
        payload.end_client_company_id = endClient.id || undefined;
        payload.end_client_name = endClient.name;
      }
      const created = await api.post('/modules/placements/api/placements.php', payload);
      const placementId = created.placement.id;

      // 2) Vendor chain rows
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

      // 3) Initial rate row
      if (rate.bill_rate || rate.pay_rate) {
        await api.post(`/modules/placements/api/rates.php?placement_id=${placementId}`, {
          effective_from: rate.effective_from || form.start_date,
          bill_rate:  rate.bill_rate  ? Number(rate.bill_rate)  : 0,
          pay_rate:   rate.pay_rate   ? Number(rate.pay_rate)   : 0,
          bill_rate_unit: rate.rate_unit || 'hour',
          pay_rate_unit:  rate.rate_unit || 'hour',
          overtime_multiplier:   Number(rate.overtime_multiplier   || 1.5),
          doubletime_multiplier: Number(rate.doubletime_multiplier || 2.0),
        });
      }

      // 4) C2C corp details
      if (form.engagement_type === 'c2c' && (corp.corp_legal_name || corp.corp_ein)) {
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

      <form onSubmit={submit} className="person-create__form" data-testid="placement-create-form" style={{ maxWidth: 900 }}>
        <fieldset disabled={submitting} style={{ border: 0, padding: 0 }}>
          <SectionTitle>1. Person + role</SectionTitle>
          <Field label="Person *">
            <input className="input" placeholder="Type to search People…" value={personSearch}
                   onChange={e => setPersonSearch(e.target.value)} data-testid="placement-create-person-search" />
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

          <SectionTitle>4. Initial rate (optional but recommended)</SectionTitle>
          <Row>
            <Field label="Bill rate ($/hr)"><input className="input" type="number" step="0.01" value={rate.bill_rate} onChange={setRateF('bill_rate')} data-testid="placement-create-rate-bill" placeholder="125.00" /></Field>
            <Field label="Pay rate ($/hr)"><input className="input" type="number" step="0.01" value={rate.pay_rate} onChange={setRateF('pay_rate')} data-testid="placement-create-rate-pay" placeholder="75.00" /></Field>
            <Field label="Effective from"><input className="input" type="date" value={rate.effective_from} onChange={setRateF('effective_from')} data-testid="placement-create-rate-effective" /></Field>
            <Field label="OT mult"><input className="input" type="number" step="0.01" value={rate.overtime_multiplier} onChange={setRateF('overtime_multiplier')} data-testid="placement-create-rate-ot" /></Field>
            <Field label="DT mult"><input className="input" type="number" step="0.01" value={rate.doubletime_multiplier} onChange={setRateF('doubletime_multiplier')} data-testid="placement-create-rate-dt" /></Field>
          </Row>

          {form.engagement_type === 'c2c' && (
            <>
              <SectionTitle>5. Corp details (C2C)</SectionTitle>
              <Row>
                <Field label="Corp legal name *"><input className="input" value={corp.corp_legal_name} onChange={setCorpF('corp_legal_name')} data-testid="placement-create-corp-name" /></Field>
                <Field label="EIN"><input className="input" value={corp.corp_ein} onChange={setCorpF('corp_ein')} data-testid="placement-create-corp-ein" placeholder="XX-XXXXXXX (encrypted at rest)" /></Field>
              </Row>
            </>
          )}

          <SectionTitle>Notes</SectionTitle>
          <Field label="Internal notes (not shared with client)">
            <textarea className="input" rows={3} value={form.notes} onChange={set('notes')} data-testid="placement-create-notes" />
          </Field>

          {error && <p className="error" data-testid="placement-create-error">Error: {error.message} {error.data?.fields ? `(missing: ${error.data.fields.join(', ')})` : ''}</p>}

          <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)' }}>
            <button type="submit" className="btn btn--primary" data-testid="placement-create-submit" disabled={submitting || !form.person_id || !form.title}>
              {submitting ? 'Saving…' : 'Create placement'}
            </button>
            <Link to=".." className="btn btn--ghost" data-testid="placement-create-cancel">Cancel</Link>
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
