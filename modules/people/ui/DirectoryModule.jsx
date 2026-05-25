import React, { useState } from 'react';
import { Routes, Route, NavLink, Navigate, useNavigate, useParams, Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import ConnectedSourcesBadge from '../../../dashboard/src/components/ConnectedSourcesBadge';
import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel';
import IdBadge from '../../../dashboard/src/components/IdBadge';

/**
 * Directory module — shared engine for Clients and Vendors views.
 *
 * Storage is the single `companies` table; views are role-filtered. A single
 * legal entity (Acme Inc) can be both a client AND a vendor; it appears in
 * both lists with a cross-link badge. Importantly we never expose the word
 * "Companies" in the UX — staffing users think Clients (AR) and Vendors (AP),
 * never an abstract Companies entity.
 *
 * Mode-driven config:
 *   mode="clients" → role filter ['client','customer'], terms default NET30, MSA emphasis
 *   mode="vendors" → role filter ['vendor','msp','prime_vendor','sub_vendor','referrer'], W-9 + 1099 emphasis
 */
const CLIENT_ROLES = ['client','customer'];
const VENDOR_ROLES = ['vendor','msp','prime_vendor','sub_vendor','referrer','partner'];
const ALL_ROLES    = ['client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner'];

const MODES = {
  clients: {
    label: 'Clients',
    description: "End clients and direct customers — who you bill, who signs SOWs, who approves time.",
    primaryRole: 'client',
    roleSet: CLIENT_ROLES,
    listLabel: 'client',
    crossRoles: VENDOR_ROLES,
    crossLink: '../vendors',
    crossLabel: 'vendor',
    defaultTerms: 'NET30',
    emphasizeFields: ['msa_signed_at','coi_on_file','default_terms'],
  },
  vendors: {
    label: 'Vendors',
    description: "Prime vendors, MSPs, sub-vendors, referrers — who's in the chain between us and the end client, or who we pay.",
    primaryRole: 'vendor',
    roleSet: VENDOR_ROLES,
    listLabel: 'vendor',
    crossRoles: CLIENT_ROLES,
    crossLink: '../clients',
    crossLabel: 'client',
    defaultTerms: 'NET45',
    emphasizeFields: ['w9_on_file','tax_classification','default_terms'],
  },
};

export default function DirectoryModule({ mode = 'clients' }) {
  const cfg = MODES[mode] || MODES.clients;
  return (
    <section data-testid={`directory-module-${mode}`}>
      <Routes>
        <Route index           element={<DirectoryList   cfg={cfg} mode={mode} />} />
        <Route path="new"      element={<DirectoryCreate cfg={cfg} mode={mode} />} />
        <Route path=":id/*"    element={<DirectoryDetail cfg={cfg} mode={mode} />} />
      </Routes>
    </section>
  );
}

function DirectoryList({ cfg, mode }) {
  const [q, setQ] = useState('');
  const [subRole, setSubRole] = useState('');
  // Build query: hits companies API but filters server-side by role one-of cfg.roleSet.
  // Server takes single role; for "any vendor-flavor" we fetch all-roles client-side
  // when no subRole is set, then filter to cfg.roleSet locally.
  const params = new URLSearchParams();
  if (q) params.set('q', q);
  if (subRole) params.set('role', subRole);
  const { data, loading, error } = useApi('/modules/people/api/companies.php' + (params.toString() ? '?' + params.toString() : ''));
  const allRows = data?.rows ?? [];
  const rows = subRole
    ? allRows
    : allRows.filter(r => (r.roles || []).some(role => cfg.roleSet.includes(role)));

  return (
    <div data-testid={`${mode}-list`}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 8, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0 }}>{cfg.label}</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>{cfg.description}</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="../merge" className="btn btn--ghost" data-testid={`${mode}-merge-link`}>Merge duplicates</Link>
          <Link to="new" className="btn btn--primary" data-testid={`${mode}-new`}>+ New {cfg.listLabel}</Link>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 8, marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input className="input" placeholder={`Search ${cfg.label.toLowerCase()}…`} value={q} onChange={(e) => setQ(e.target.value)} data-testid={`${mode}-search`} style={{ maxWidth: 320 }} />
        <select className="input" value={subRole} onChange={(e) => setSubRole(e.target.value)} data-testid={`${mode}-role-filter`}>
          <option value="">All {cfg.label.toLowerCase()}</option>
          {cfg.roleSet.map(r => <option key={r} value={r}>{r}</option>)}
        </select>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid={`${mode}-table`}>
        <thead><tr><th>ID</th><th>Name</th><th>Roles</th><th>Primary contact</th><th>Location</th><th style={{textAlign:'right'}}>Used</th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={6} className="empty" data-testid={`${mode}-empty`}>No {cfg.label.toLowerCase()} yet — auto-created when placements reference them, or click "+ New {cfg.listLabel}".</td></tr>}
          {rows.map(c => {
            const alsoOther = (c.roles || []).some(role => cfg.crossRoles.includes(role));
            return (
              <tr key={c.id} data-testid={`${mode}-row-${c.id}`}>
                <td><IdBadge id={c.id} prefix="C" /></td>
                <td>
                  <Link to={String(c.id)} data-testid={`${mode}-link-${c.id}`}>{c.name}</Link>
                  {alsoOther && <span title={`Also acts as ${cfg.crossLabel}`} className="badge" style={{ marginLeft: 6, fontSize: 10 }}>also {cfg.crossLabel}</span>}
                </td>
                <td>{(c.roles || []).filter(r => cfg.roleSet.includes(r)).map(r => <span key={r} className="badge" style={{ marginRight: 4 }}>{r}</span>)}</td>
                <td>{c.primary_contact_name || '—'}{c.primary_contact_email && <div style={{ fontSize: 11, color: '#6b7280' }}>{c.primary_contact_email}</div>}</td>
                <td>{c.city ? `${c.city}, ${c.state || c.country || ''}` : '—'}</td>
                <td style={{ textAlign: 'right' }}>{c.use_count}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function DirectoryCreate({ cfg, mode }) {
  const nav = useNavigate();
  const [form, setForm] = useState({
    name: '', legal_name: '', website: '', phone: '',
    primary_contact_name: '', primary_contact_email: '', primary_contact_phone: '',
    address_line1: '', address_line2: '', city: '', state: '', postal_code: '', country: 'US',
    notes: '', roles: [cfg.primaryRole],
    status: 'active',
    default_terms: cfg.defaultTerms,
    currency: 'USD',
    tax_classification: '',
    industry: '',
    employee_size_range: '',
    w9_on_file: false, w9_expires_on: '',
    coi_on_file: false, coi_expires_on: '',
    tags: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const setBool = (k) => (e) => setForm({ ...form, [k]: e.target.checked });
  const toggleRole = (role) => setForm({
    ...form,
    roles: form.roles.includes(role) ? form.roles.filter(r => r !== role) : [...form.roles, role],
  });

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const payload = { ...form };
      payload.tags = form.tags ? form.tags.split(',').map(s => s.trim()).filter(Boolean) : [];
      ['w9_expires_on','coi_expires_on'].forEach(k => { if (!payload[k]) delete payload[k]; });
      ['tax_classification','industry','employee_size_range'].forEach(k => { if (!payload[k]) delete payload[k]; });
      const res = await api.post('/modules/people/api/companies.php', payload);
      nav(`../${res.id}`);
    } catch (e) { setError(e); } finally { setBusy(false); }
  };

  // Role pickers: only show roles relevant to this view (clients sees client/customer; vendors sees vendor flavors).
  const showRoles = cfg.roleSet;

  return (
    <div data-testid={`${mode}-create`}>
      <Link to=".." style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← {cfg.label}</Link>
      <h2 style={{ marginTop: 8 }}>New {cfg.listLabel}</h2>

      <SectionTitle>Identity</SectionTitle>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Name *"><input className="input" required value={form.name} onChange={set('name')} data-testid={`${mode}-create-name`} /></Field>
        <Field label="Legal name"><input className="input" value={form.legal_name} onChange={set('legal_name')} data-testid={`${mode}-create-legal`} /></Field>
        <Field label="Website"><input className="input" value={form.website} onChange={set('website')} data-testid={`${mode}-create-website`} placeholder="https://…" /></Field>
        <Field label="Phone"><input className="input" value={form.phone} onChange={set('phone')} data-testid={`${mode}-create-phone`} /></Field>
      </div>

      <fieldset style={{ marginTop: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 12 }}>
        <legend style={{ fontSize: 12, color: 'var(--cf-text-secondary)', padding: '0 8px' }}>{cfg.label} sub-type</legend>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {showRoles.map(r => (
            <label key={r} style={{ display: 'inline-flex', gap: 4, fontSize: 13 }}>
              <input type="checkbox" checked={form.roles.includes(r)} onChange={() => toggleRole(r)} data-testid={`${mode}-create-role-${r}`} /> {r}
            </label>
          ))}
        </div>
        <p style={{ margin: '8px 0 0', fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          {mode === 'clients'
            ? 'Pick "client" for direct end clients. "customer" if billed under a different label (e.g. SaaS-style).'
            : 'Pick the chain position: prime_vendor (between us and end client), msp (managed-service vendor), sub_vendor (downstream of us), referrer (gets a fee for sending us business).'}
        </p>
      </fieldset>

      <SectionTitle>Business profile</SectionTitle>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Status">
          <select className="input" value={form.status} onChange={set('status')} data-testid={`${mode}-create-status`}>
            <option value="prospect">prospect</option><option value="active">active</option>
            <option value="inactive">inactive</option><option value="blacklisted">blacklisted</option>
          </select>
        </Field>
        <Field label="Default payment terms"><input className="input" value={form.default_terms} onChange={set('default_terms')} data-testid={`${mode}-create-terms`} placeholder="NET30 / 2/10 NET30" /></Field>
        <Field label="Currency"><input className="input" maxLength={3} value={form.currency} onChange={set('currency')} data-testid={`${mode}-create-currency`} /></Field>
        <Field label="Tax classification">
          <select className="input" value={form.tax_classification} onChange={set('tax_classification')} data-testid={`${mode}-create-taxclass`}>
            <option value="">—</option>
            <option value="c_corp">C-Corp</option><option value="s_corp">S-Corp</option>
            <option value="llc">LLC</option><option value="partnership">Partnership</option>
            <option value="sole_prop">Sole proprietorship</option><option value="nonprofit">Nonprofit</option>
            <option value="government">Government</option><option value="other">Other</option>
          </select>
        </Field>
        <Field label="Industry"><input className="input" value={form.industry} onChange={set('industry')} data-testid={`${mode}-create-industry`} placeholder="SaaS, Healthcare, Govt…" /></Field>
        <Field label="Employee size">
          <select className="input" value={form.employee_size_range} onChange={set('employee_size_range')} data-testid={`${mode}-create-size`}>
            <option value="">—</option>
            <option>1-10</option><option>11-50</option><option>51-200</option>
            <option>201-1000</option><option>1001-5000</option><option>5000+</option>
          </select>
        </Field>
      </div>

      <SectionTitle>Compliance docs</SectionTitle>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <label style={{ display: 'inline-flex', gap: 6, fontSize: 14 }}>
          <input type="checkbox" checked={form.w9_on_file} onChange={setBool('w9_on_file')} data-testid={`${mode}-create-w9`} /> W-9 on file
        </label>
        <Field label="W-9 expires"><input className="input" type="date" value={form.w9_expires_on} onChange={set('w9_expires_on')} data-testid={`${mode}-create-w9-exp`} /></Field>
        <label style={{ display: 'inline-flex', gap: 6, fontSize: 14 }}>
          <input type="checkbox" checked={form.coi_on_file} onChange={setBool('coi_on_file')} data-testid={`${mode}-create-coi`} /> COI on file
        </label>
        <Field label="COI expires"><input className="input" type="date" value={form.coi_expires_on} onChange={set('coi_expires_on')} data-testid={`${mode}-create-coi-exp`} /></Field>
      </div>

      <SectionTitle>Primary contact</SectionTitle>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Name"><input className="input" value={form.primary_contact_name} onChange={set('primary_contact_name')} data-testid={`${mode}-create-contact-name`} /></Field>
        <Field label="Email"><input className="input" type="email" value={form.primary_contact_email} onChange={set('primary_contact_email')} data-testid={`${mode}-create-contact-email`} /></Field>
        <Field label="Phone"><input className="input" value={form.primary_contact_phone} onChange={set('primary_contact_phone')} data-testid={`${mode}-create-contact-phone`} /></Field>
      </div>

      <SectionTitle>HQ address (more addresses can be added on the detail page)</SectionTitle>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Line 1"><input className="input" value={form.address_line1} onChange={set('address_line1')} data-testid={`${mode}-create-line1`} /></Field>
        <Field label="Line 2"><input className="input" value={form.address_line2} onChange={set('address_line2')} data-testid={`${mode}-create-line2`} /></Field>
        <Field label="City"><input className="input" value={form.city} onChange={set('city')} data-testid={`${mode}-create-city`} /></Field>
        <Field label="State"><input className="input" value={form.state} onChange={set('state')} data-testid={`${mode}-create-state`} /></Field>
        <Field label="Postal code"><input className="input" value={form.postal_code} onChange={set('postal_code')} data-testid={`${mode}-create-postal`} /></Field>
        <Field label="Country"><input className="input" maxLength={2} value={form.country} onChange={set('country')} data-testid={`${mode}-create-country`} /></Field>
      </div>

      <SectionTitle>Tags + notes</SectionTitle>
      <Field label="Tags (comma-separated)" style={{ maxWidth: 900 }}>
        <input className="input" value={form.tags} onChange={set('tags')} data-testid={`${mode}-create-tags`} placeholder="key-account, q4-target, msp-required" />
      </Field>
      <Field label="Notes" style={{ marginTop: 12, maxWidth: 900 }}>
        <textarea className="input" rows={3} value={form.notes} onChange={set('notes')} data-testid={`${mode}-create-notes`} />
      </Field>

      {error && <p className="error" data-testid={`${mode}-create-error`}>Error: {error.message}</p>}

      <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
        <button className="btn btn--primary" onClick={submit} disabled={busy || !form.name} data-testid={`${mode}-create-save`}>{busy ? 'Saving…' : `Save ${cfg.listLabel}`}</button>
        <Link to=".." className="btn btn--ghost" data-testid={`${mode}-create-cancel`}>Cancel</Link>
      </div>
    </div>
  );
}

const SectionTitle = ({ children }) => (
  <h3 style={{ marginTop: 24, marginBottom: 8, fontSize: 13, textTransform: 'uppercase', letterSpacing: 0.5, color: 'var(--cf-text-secondary)' }}>{children}</h3>
);

function DirectoryDetail({ cfg, mode }) {
  const { id } = useParams();
  const { data, loading, error, reload } = useApi(`/modules/people/api/companies.php?id=${id}`);
  const c = data?.company;

  if (loading) return <p>Loading…</p>;
  if (error || !c) return <p className="error" data-testid="company-detail-error">{error?.message || 'Not found'}</p>;

  const toggleRole = async (role) => {
    const action = c.roles?.includes(role) ? 'remove-role' : 'add-role';
    try { await api.post(`/modules/people/api/companies.php?action=${action}&id=${c.id}`, { role }); reload(); }
    catch (e) { alert(e.message); }
  };

  return (
    <div data-testid={`${mode}-detail`}>
      <Link to=".." style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← {cfg.label}</Link>
      <h2 style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 10 }} data-testid={`${mode}-detail-name`}>
        <span>{c.name}</span>
        <IdBadge id={c.id} prefix="C" title={`Company ID ${c.id} — click to copy for CSV imports`} />
      </h2>
      <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 14 }}>
        {c.legal_name && c.legal_name !== c.name ? `${c.legal_name} · ` : ''}
        {c.city ? `${c.city}, ${c.state || c.country || ''}` : ''}
        {' · used '}{c.use_count}{' times'}
      </p>
      <div style={{ margin: '4px 0 8px' }}>
        <ConnectedSourcesBadge entityType="company" internalId={c.id} />
      </div>

      {/* Cross-link badge: if this record also acts as the OTHER role, show a link to the other directory */}
      {(c.roles || []).some(r => cfg.crossRoles.includes(r)) && (
        <p style={{ margin: '0 0 12px', fontSize: 13 }} data-testid={`${mode}-detail-cross-link`}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>This record also acts as a {cfg.crossLabel} →</span>{' '}
          <Link to={`${cfg.crossLink}/${c.id}`}>view {cfg.crossLabel} profile</Link>
        </p>
      )}

      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 16 }} data-testid={`${mode}-detail-roles`}>
        {ALL_ROLES.map(r => (
          <button
            key={r}
            type="button"
            onClick={() => toggleRole(r)}
            data-testid={`${mode}-detail-role-${r}`}
            className="badge"
            style={{
              padding: '4px 10px', border: '1px solid', borderRadius: 999, cursor: 'pointer',
              background: c.roles?.includes(r) ? 'var(--cf-text, #111827)' : 'transparent',
              color: c.roles?.includes(r) ? '#fff' : 'var(--cf-text-secondary)',
              borderColor: c.roles?.includes(r) ? 'var(--cf-text, #111827)' : 'var(--cf-border, #e5e7eb)',
            }}
          >{r}</button>
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }} data-testid={`${mode}-detail-fields`}>
        <Box label="Status"          v={<span className={`badge badge--${c.status || 'active'}`}>{c.status || 'active'}</span>} />
        <Box label="Default terms"   v={c.default_terms || 'NET30'} />
        <Box label="Currency"        v={c.currency || 'USD'} />
        <Box label="Tax class"       v={c.tax_classification || '—'} />
        <Box label="Industry"        v={c.industry || '—'} />
        <Box label="Employee size"   v={c.employee_size_range || '—'} />
        <Box label="W-9 on file"     v={Number(c.w9_on_file) ? `Yes (expires ${c.w9_expires_on || '—'})` : 'No'} />
        <Box label="COI on file"     v={Number(c.coi_on_file) ? `Yes (expires ${c.coi_expires_on || '—'})` : 'No'} />
        <Box label="Website" v={c.website ? <a href={c.website} target="_blank" rel="noreferrer">{c.website}</a> : '—'} />
        <Box label="Phone"   v={c.phone || '—'} />
        <Box label="Primary contact" v={c.primary_contact_name || '—'} />
        <Box label="Contact email"   v={c.primary_contact_email || '—'} />
        <Box label="Contact phone"   v={c.primary_contact_phone || '—'} />
        <Box label="MSA signed"      v={c.msa_signed_at || '—'} />
        <Box label="Tags"            v={(c.tags || []).length ? (c.tags || []).map(t => <span key={t} className="badge" style={{ marginRight: 4 }}>{t}</span>) : '—'} />
      </div>

      <h3 style={{ marginTop: 24, fontSize: 14 }}>Addresses</h3>
      <table className="data-table" data-testid={`${mode}-detail-addresses`}>
        <thead><tr><th>Kind</th><th>Address</th><th>City / State / ZIP</th><th>Country</th><th>Primary</th></tr></thead>
        <tbody>
          {(c.addresses || []).length === 0 && (
            <tr><td colSpan={5} className="empty">
              {c.address_line1
                ? <>Single address from quick-create: <strong>{c.address_line1}</strong>, {c.city}, {c.state || c.country}. Add more via API for billing/remit-to/worksite.</>
                : 'No addresses captured yet.'}
            </td></tr>
          )}
          {(c.addresses || []).map(a => (
            <tr key={a.id}>
              <td><span className="badge">{a.kind}</span> {a.label && <span style={{ fontSize: 11, color: '#6b7280' }}>· {a.label}</span>}</td>
              <td>{a.line1}{a.line2 ? `, ${a.line2}` : ''}</td>
              <td>{a.city}{a.state ? `, ${a.state}` : ''} {a.postal_code || ''}</td>
              <td>{a.country}</td>
              <td>{Number(a.is_primary) ? '✓' : ''}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h3 style={{ marginTop: 24, fontSize: 14 }}>Contacts</h3>
      <table className="data-table" data-testid={`${mode}-detail-contacts`}>
        <thead><tr><th>Name</th><th>Title</th><th>Role</th><th>Email</th><th>Phone</th><th>Primary</th></tr></thead>
        <tbody>
          {(c.contacts || []).length === 0 && <tr><td colSpan={6} className="empty">No additional contacts.</td></tr>}
          {(c.contacts || []).map(ct => (
            <tr key={ct.id}>
              <td>{ct.name}</td><td>{ct.title || '—'}</td><td><span className="badge">{ct.contact_role}</span></td>
              <td>{ct.email || '—'}</td><td>{ct.phone || '—'}</td><td>{Number(ct.is_primary) ? '✓' : ''}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: 24 }}>
        <LinkedExternalSystemsPanel entityType="company" internalId={c.id} />
      </div>
    </div>
  );
}

const Field = ({ label, children, style }) => (
  <label style={{ display: 'block', fontSize: 13, ...style }}>
    <span style={{ color: 'var(--cf-text-secondary, #6b7280)' }}>{label}</span>
    <div style={{ marginTop: 4 }}>{children}</div>
  </label>
);

const Box = ({ label, v }) => (
  <div>
    <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</div>
    <div style={{ fontSize: 14, marginTop: 2 }}>{v}</div>
  </div>
);
