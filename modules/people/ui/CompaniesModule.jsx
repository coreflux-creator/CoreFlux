import React, { useState } from 'react';
import { Routes, Route, NavLink, Navigate, useNavigate, useParams, Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Companies module sub-router. Mounted under /modules/people/companies.
 *   index → list
 *   /new  → create
 *   /:id  → detail
 */
const ROLES = ['client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner'];

export default function CompaniesModule() {
  return (
    <section data-testid="companies-module">
      <Routes>
        <Route index           element={<CompaniesList />} />
        <Route path="new"      element={<CompanyCreate />} />
        <Route path=":id/*"    element={<CompanyDetail />} />
      </Routes>
    </section>
  );
}

function CompaniesList() {
  const [q, setQ] = useState('');
  const [role, setRole] = useState('');
  const params = new URLSearchParams();
  if (q) params.set('q', q);
  if (role) params.set('role', role);
  const { data, loading, error } = useApi('/modules/people/api/companies.php' + (params.toString() ? '?' + params.toString() : ''));
  const rows = data?.rows ?? [];

  return (
    <div data-testid="companies-list">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 8, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0 }}>Companies</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Single source of truth for clients, vendors, referrers, MSPs. Placements + AP + Billing reference these by FK.
          </p>
        </div>
        <Link to="new" className="btn btn--primary" data-testid="companies-new">+ New company</Link>
      </header>

      <div style={{ display: 'flex', gap: 8, marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input className="input" placeholder="Search by name…" value={q} onChange={(e) => setQ(e.target.value)} data-testid="companies-search" style={{ maxWidth: 320 }} />
        <select className="input" value={role} onChange={(e) => setRole(e.target.value)} data-testid="companies-role-filter">
          <option value="">All roles</option>
          {ROLES.map(r => <option key={r} value={r}>{r}</option>)}
        </select>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="companies-table">
        <thead><tr><th>Name</th><th>Roles</th><th>Primary contact</th><th>Location</th><th style={{textAlign:'right'}}>Used</th><th>Last used</th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={6} className="empty" data-testid="companies-empty">No companies yet — they're auto-created when you build placements, or click "New company".</td></tr>}
          {rows.map(c => (
            <tr key={c.id} data-testid={`company-row-${c.id}`}>
              <td><Link to={String(c.id)} data-testid={`company-link-${c.id}`}>{c.name}</Link>{c.legal_name && c.legal_name !== c.name && <div style={{ fontSize: 11, color: '#6b7280' }}>{c.legal_name}</div>}</td>
              <td>{(c.roles || []).map(r => <span key={r} className="badge" style={{ marginRight: 4 }}>{r}</span>)}</td>
              <td>{c.primary_contact_name || '—'}{c.primary_contact_email && <div style={{ fontSize: 11, color: '#6b7280' }}>{c.primary_contact_email}</div>}</td>
              <td>{c.city ? `${c.city}, ${c.state || c.country || ''}` : '—'}</td>
              <td style={{ textAlign: 'right' }}>{c.use_count}</td>
              <td>{(c.last_used_at || '').slice(0, 10) || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CompanyCreate() {
  const nav = useNavigate();
  const [form, setForm] = useState({
    name: '', legal_name: '', website: '', phone: '',
    primary_contact_name: '', primary_contact_email: '', primary_contact_phone: '',
    address_line1: '', address_line2: '', city: '', state: '', postal_code: '', country: 'US',
    notes: '', roles: ['client'],
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const toggleRole = (role) => setForm({
    ...form,
    roles: form.roles.includes(role) ? form.roles.filter(r => r !== role) : [...form.roles, role],
  });

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post('/modules/people/api/companies.php', form);
      nav(`../${res.id}`);
    } catch (e) { setError(e); } finally { setBusy(false); }
  };

  return (
    <div data-testid="company-create">
      <Link to=".." style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Companies</Link>
      <h2 style={{ marginTop: 8 }}>New company</h2>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Name *"><input className="input" required value={form.name} onChange={set('name')} data-testid="company-create-name" /></Field>
        <Field label="Legal name"><input className="input" value={form.legal_name} onChange={set('legal_name')} data-testid="company-create-legal" /></Field>
        <Field label="Website"><input className="input" value={form.website} onChange={set('website')} data-testid="company-create-website" placeholder="https://…" /></Field>
        <Field label="Phone"><input className="input" value={form.phone} onChange={set('phone')} data-testid="company-create-phone" /></Field>
      </div>

      <fieldset style={{ marginTop: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 12 }}>
        <legend style={{ fontSize: 12, color: 'var(--cf-text-secondary)', padding: '0 8px' }}>Roles (a company can be many things)</legend>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {ROLES.map(r => (
            <label key={r} style={{ display: 'inline-flex', gap: 4, fontSize: 13 }}>
              <input type="checkbox" checked={form.roles.includes(r)} onChange={() => toggleRole(r)} data-testid={`company-create-role-${r}`} /> {r}
            </label>
          ))}
        </div>
      </fieldset>

      <h4 style={{ marginTop: 24 }}>Primary contact</h4>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Name"><input className="input" value={form.primary_contact_name} onChange={set('primary_contact_name')} data-testid="company-create-contact-name" /></Field>
        <Field label="Email"><input className="input" type="email" value={form.primary_contact_email} onChange={set('primary_contact_email')} data-testid="company-create-contact-email" /></Field>
        <Field label="Phone"><input className="input" value={form.primary_contact_phone} onChange={set('primary_contact_phone')} data-testid="company-create-contact-phone" /></Field>
      </div>

      <h4 style={{ marginTop: 24 }}>Address</h4>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <Field label="Line 1"><input className="input" value={form.address_line1} onChange={set('address_line1')} data-testid="company-create-line1" /></Field>
        <Field label="Line 2"><input className="input" value={form.address_line2} onChange={set('address_line2')} data-testid="company-create-line2" /></Field>
        <Field label="City"><input className="input" value={form.city} onChange={set('city')} data-testid="company-create-city" /></Field>
        <Field label="State"><input className="input" value={form.state} onChange={set('state')} data-testid="company-create-state" /></Field>
        <Field label="Postal code"><input className="input" value={form.postal_code} onChange={set('postal_code')} data-testid="company-create-postal" /></Field>
        <Field label="Country"><input className="input" maxLength={2} value={form.country} onChange={set('country')} data-testid="company-create-country" /></Field>
      </div>

      <Field label="Notes" style={{ marginTop: 12, maxWidth: 900 }}>
        <textarea className="input" rows={3} value={form.notes} onChange={set('notes')} data-testid="company-create-notes" />
      </Field>

      {error && <p className="error" data-testid="company-create-error">Error: {error.message}</p>}

      <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
        <button className="btn btn--primary" onClick={submit} disabled={busy || !form.name} data-testid="company-create-save">{busy ? 'Saving…' : 'Save company'}</button>
        <Link to=".." className="btn btn--ghost" data-testid="company-create-cancel">Cancel</Link>
      </div>
    </div>
  );
}

function CompanyDetail() {
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
    <div data-testid="company-detail">
      <Link to=".." style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Companies</Link>
      <h2 style={{ marginTop: 8 }} data-testid="company-detail-name">{c.name}</h2>
      <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 14 }}>
        {c.legal_name && c.legal_name !== c.name ? `${c.legal_name} · ` : ''}
        {c.city ? `${c.city}, ${c.state || c.country || ''}` : ''}
        {' · used '}{c.use_count}{' times'}
      </p>

      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 16 }} data-testid="company-detail-roles">
        {ROLES.map(r => (
          <button
            key={r}
            type="button"
            onClick={() => toggleRole(r)}
            data-testid={`company-detail-role-${r}`}
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

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }} data-testid="company-detail-fields">
        <Box label="Website" v={c.website ? <a href={c.website} target="_blank" rel="noreferrer">{c.website}</a> : '—'} />
        <Box label="Phone"   v={c.phone || '—'} />
        <Box label="Primary contact" v={c.primary_contact_name || '—'} />
        <Box label="Contact email"   v={c.primary_contact_email || '—'} />
        <Box label="Contact phone"   v={c.primary_contact_phone || '—'} />
        <Box label="Address"         v={c.address_line1 ? `${c.address_line1}, ${c.city || ''} ${c.state || ''} ${c.postal_code || ''}` : '—'} />
        <Box label="MSA signed"      v={c.msa_signed_at || '—'} />
      </div>

      <h3 style={{ marginTop: 24, fontSize: 14 }}>Contacts</h3>
      <table className="data-table" data-testid="company-detail-contacts">
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
