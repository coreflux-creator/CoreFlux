import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

export default function VendorsList() {
  const [q, setQ] = useState('');
  const path = q ? `/modules/ap/api/vendors.php?q=${encodeURIComponent(q)}` : '/modules/ap/api/vendors.php';
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];
  const [showCreate, setShowCreate] = useState(false);
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ template_id: String(tplId) });
    return `/api/v1/ap/csv-export?${params.toString()}`;
  };

  return (
    <section data-testid="ap-vendors-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 8, flexWrap: 'wrap' }}>
        <input className="input" placeholder="Search vendors…" value={q} onChange={(e) => setQ(e.target.value)} data-testid="ap-vendors-search" style={{ maxWidth: 320 }} />
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Link to="csv_import" className="btn" data-testid="ap-vendors-import-csv">Import CSV</Link>
          <a className="btn" href="/api/v1/ap/csv-export" data-testid="ap-vendors-export-csv">Export CSV</a>
          <ExportTemplatePicker
            dataset="ap_vendors"
            buildHref={buildTemplateExportHref}
            label="Export via template"
            testid="ap-vendors-export-template"
          />
          <button className="btn btn--primary" onClick={() => setShowCreate(true)} data-testid="ap-vendor-new">New vendor</button>
        </div>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-vendors-table">
        <thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Terms</th><th>PWP?</th><th>Tax ID</th><th>1099?</th><th>Last bill</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={9} className="empty" data-testid="ap-vendors-empty">No vendors yet.</td></tr>}
          {rows.map(v => (
            <tr key={v.id} data-testid={`ap-vendor-row-${v.id}`}>
              <td><IdBadge id={v.id} prefix="V" /></td>
              <td>{v.vendor_name}</td>
              <td><span className="badge">{v.vendor_type}</span></td>
              <td>{v.default_terms}</td>
              <td><VendorPwpToggle vendor={v} onChanged={reload} /></td>
              <td>{v.tax_id_last4 ? `••${v.tax_id_last4}` : '—'}</td>
              <td>{Number(v.requires_1099) ? 'Yes' : 'No'}</td>
              <td>{v.last_bill_at || '—'}</td>
              <td>
                <PortalInviteButton vendor={v} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {showCreate && <VendorCreateModal onClose={() => setShowCreate(false)} onCreated={() => { setShowCreate(false); reload(); }} />}
    </section>
  );
}

function VendorPwpToggle({ vendor, onChanged }) {
  const [busy, setBusy] = useState(false);
  const isOn = Number(vendor.default_pwp) === 1;
  const flip = async () => {
    setBusy(true);
    try {
      await api.post(`/modules/ap/api/vendors.php?action=toggle_pwp&id=${vendor.id}`, { default_pwp: isOn ? 0 : 1 });
      onChanged?.();
    } catch (e) { alert(`Could not update: ${e.message}`); }
    finally { setBusy(false); }
  };
  return (
    <label style={{ display: 'inline-flex', alignItems: 'center', gap: 4, cursor: busy ? 'wait' : 'pointer' }} title="When ON, new bills for this vendor default to Pay-When-Paid (NET90, accelerates when AR clears)">
      <input
        type="checkbox"
        checked={isOn}
        disabled={busy}
        onChange={flip}
        data-testid={`ap-vendor-pwp-${vendor.id}`}
      />
      <span style={{ fontSize: 11, color: isOn ? '#0891b2' : 'var(--cf-text-secondary)', fontWeight: isOn ? 600 : 400 }}>
        {isOn ? 'PWP' : '—'}
      </span>
    </label>
  );
}

function PortalInviteButton({ vendor }) {
  const [busy, setBusy] = useState(false);
  const [link, setLink] = useState(null);
  const [err, setErr]   = useState(null);
  const invite = async () => {
    const email = prompt(`Send vendor portal invite for "${vendor.vendor_name}". Email:`, vendor.contact_email || '');
    if (!email) return;
    setBusy(true); setErr(null); setLink(null);
    try {
      const res = await api.post(
        '/modules/ap/api/vendor_portal.php?action=invite',
        { vendor_id: vendor.id, email }
      );
      setLink(res.magic_link);
    } catch (e) { setErr(e.message); }
    finally     { setBusy(false); }
  };
  return (
    <>
      <button
        type="button"
        className="btn btn--ghost"
        onClick={invite}
        disabled={busy}
        data-testid={`ap-vendor-portal-invite-${vendor.id}`}
        style={{ padding: '2px 8px', fontSize: 11 }}
      >
        {busy ? '…' : 'Portal invite'}
      </button>
      {link && (
        <div data-testid={`ap-vendor-portal-link-${vendor.id}`} style={{ fontSize: 10, marginTop: 4 }}>
          <input
            value={link}
            readOnly
            onClick={(e) => e.target.select()}
            style={{ width: '100%', fontSize: 10, padding: 2 }}
          />
        </div>
      )}
      {err && <div style={{ color: '#b91c1c', fontSize: 10 }}>{err}</div>}
    </>
  );
}

function VendorCreateModal({ onClose, onCreated }) {
  const [form, setForm] = useState({ vendor_name: '', vendor_type: '1099_individual', default_terms: 'NET30', tax_id_full: '', requires_1099: true });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const submit = async () => {
    setBusy(true); setError(null);
    try { await api.post('/modules/ap/api/vendors.php', form); onCreated?.(); }
    catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <div style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()} data-testid="ap-vendor-modal">
      <div style={modalBox}>
        <header style={modalHeader}><h3 style={{ margin: 0 }}>New vendor</h3></header>
        <div style={{ padding: 20, display: 'grid', gap: 12 }}>
          <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Name</span>
            <input className="input" value={form.vendor_name} onChange={(e) => setForm({ ...form, vendor_name: e.target.value })} data-testid="ap-vendor-name" style={{ marginTop: 4 }} />
          </label>
          <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Type</span>
            <select className="input" value={form.vendor_type} onChange={(e) => setForm({ ...form, vendor_type: e.target.value })} data-testid="ap-vendor-type" style={{ marginTop: 4 }}>
              <option value="1099_individual">1099 Individual</option>
              <option value="c2c_corp">C2C Corp</option>
              <option value="w9_business">W9 Business</option>
              <option value="utility">Utility</option>
              <option value="other">Other</option>
            </select>
          </label>
          <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Default terms</span>
            <input className="input" value={form.default_terms} onChange={(e) => setForm({ ...form, default_terms: e.target.value })} data-testid="ap-vendor-terms" style={{ marginTop: 4 }} />
          </label>
          <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Tax ID (EIN / SSN)</span>
            <input className="input" value={form.tax_id_full} onChange={(e) => setForm({ ...form, tax_id_full: e.target.value })} data-testid="ap-vendor-taxid" placeholder="Encrypted at rest" style={{ marginTop: 4 }} />
          </label>
          <label style={{ display: 'inline-flex', gap: 6, fontSize: 14 }}>
            <input type="checkbox" checked={form.requires_1099} onChange={(e) => setForm({ ...form, requires_1099: e.target.checked })} data-testid="ap-vendor-requires-1099" />
            Requires 1099-NEC
          </label>
          {error && <p className="error">Error: {error.message}</p>}
        </div>
        <footer style={modalFooter}>
          <button className="btn btn--ghost" onClick={onClose} data-testid="ap-vendor-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !form.vendor_name} data-testid="ap-vendor-save">{busy ? 'Saving…' : 'Save'}</button>
        </footer>
      </div>
    </div>
  );
}

const modalOverlay = { position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 };
const modalBox = { background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(480px, 100%)', maxHeight: '90vh', display: 'flex', flexDirection: 'column' };
const modalHeader = { padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' };
const modalFooter = { padding: 16, borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'flex-end', gap: 8, background: 'var(--cf-surface-alt, #f9fafb)' };
