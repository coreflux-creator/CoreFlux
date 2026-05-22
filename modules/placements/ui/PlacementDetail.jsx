import React, { useState } from 'react';
import { useParams, useNavigate, NavLink, Routes, Route, Navigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';
import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel';
import SyncHistoryDrawer from '../../../dashboard/src/components/SyncHistoryDrawer';

/**
 * Placement Detail — SPEC §7 tabs.
 * Phase A tabs: Overview, Chain, Rates, Commissions, Referrals, Corp (C2C),
 * Documents, Approval, Margin. (Time tab is read-only; will populate from
 * time/ when that module ships.)
 */
export default function PlacementDetail({ session }) {
  const { pid } = useParams();
  const nav = useNavigate();
  const { data, loading, error, reload } = useApi(`/modules/placements/api/placements.php?id=${pid}`);
  const placement = data?.placement;
  const chain     = data?.chain ?? [];
  const rates     = data?.rates ?? [];
  const currentRate = data?.current_rate;
  const commissions = data?.commissions ?? [];
  const referrals   = data?.referrals ?? [];
  const documents   = data?.documents ?? [];

  if (loading) return <p data-testid="placement-detail-loading">Loading…</p>;
  if (error)   return <p className="error" data-testid="placement-detail-error">Error: {error.message}</p>;
  if (!placement) return <p data-testid="placement-detail-empty">Placement not found.</p>;

  const TABS = [
    { slug: 'overview',    label: 'Overview' },
    { slug: 'chain',       label: 'Chain' },
    { slug: 'rates',       label: 'Rates' },
    { slug: 'commissions', label: 'Commissions' },
    { slug: 'referrals',   label: 'Referrals' },
    ...(placement.engagement_type === 'c2c' ? [{ slug: 'corp', label: 'Corp (C2C)' }] : []),
    { slug: 'cycles',      label: 'Cycles' },
    { slug: 'documents',   label: 'Documents' },
    { slug: 'approval',    label: 'Approval' },
    { slug: 'margin',      label: 'Margin' },
  ];

  return (
    <section className="person-detail" data-testid="placement-detail">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--cf-space-3)' }}>
        <div>
          <button onClick={() => nav('..')} className="btn btn--ghost" data-testid="placement-detail-back">← Placements</button>
          <h2 data-testid="placement-detail-title" style={{ marginTop: 'var(--cf-space-2)' }}>{placement.title}</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            <span className={`badge badge--${placement.status}`} data-testid="placement-detail-status">{placement.status}</span>{' '}
            <span className={`badge badge--${placement.engagement_type}`} data-testid="placement-detail-etype">{placement.engagement_type}</span>{' · '}
            <span data-testid="placement-detail-client">{placement.end_client_name || '(no end client)'}</span>{' · '}
            <span data-testid="placement-detail-dates">{placement.start_date} → {placement.end_date || '∞'}</span>
          </p>
        </div>
      </header>

      <LinkedExternalSystemsPanel entityType="placement" internalId={placement.id} />
      <div style={{ marginTop: 8, display: 'flex', justifyContent: 'flex-end' }}>
        <SyncHistoryDrawer entityType="placement" internalId={placement.id} />
      </div>

      <nav className="person-detail__tabs" data-testid="placement-detail-tabs" style={{ display: 'flex', gap: 'var(--cf-space-1)' }}>
        {TABS.map(t => (
          <NavLink key={t.slug} to={t.slug} className={({ isActive }) => `tab ${isActive ? 'tab--active' : ''}`} data-testid={`placement-tab-${t.slug}`}>
            {t.label}
          </NavLink>
        ))}
      </nav>

      <Routes>
        <Route index             element={<Navigate to="overview" replace />} />
        <Route path="overview"   element={<OverviewTab    placement={placement} reload={reload} />} />
        <Route path="chain"      element={<ChainTab       pid={placement.id} chain={chain} reload={reload} />} />
        <Route path="rates"      element={<RatesTab       pid={placement.id} rates={rates} reload={reload} />} />
        <Route path="commissions"element={<CommissionsTab pid={placement.id} rows={commissions} reload={reload} />} />
        <Route path="referrals"  element={<ReferralsTab   pid={placement.id} rows={referrals} reload={reload} />} />
        <Route path="corp"       element={<CorpTab        pid={placement.id} />} />
        <Route path="cycles"     element={<CyclesTab      placement={placement} reload={reload} />} />
        <Route path="documents"  element={<DocumentsTab   pid={placement.id} rows={documents} reload={reload} />} />
        <Route path="approval"   element={<ApprovalTab    pid={placement.id} placement={placement} reload={reload} />} />
        <Route path="margin"     element={<MarginTab      currentRate={currentRate} chain={chain} />} />
      </Routes>
    </section>
  );
}

// ── Overview ────────────────────────────────────────────────
function OverviewTab({ placement, reload }) {
  const [editing, setEditing] = useState(false);
  if (editing) return <OverviewEdit placement={placement} onClose={() => { setEditing(false); reload(); }} />;
  const Item = ({ k, v, t }) => (
    <div><span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em', display: 'block' }}>{k}</span><span data-testid={t}>{v ?? '—'}</span></div>
  );
  return (
    <div data-testid="tab-overview">
      <header style={{ display: 'flex', justifyContent: 'space-between' }}><h3>Overview</h3>
        <button className="btn" onClick={() => setEditing(true)} data-testid="placement-overview-edit">Edit</button>
      </header>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
        <Item k="Title"            v={placement.title}            t="overview-title" />
        <Item k="Engagement type"  v={placement.engagement_type}  t="overview-etype" />
        <Item k="Status"           v={placement.status}           t="overview-status" />
        <Item k="Start"            v={placement.start_date}       t="overview-start" />
        <Item k="End (planned)"    v={placement.end_date}         t="overview-end" />
        <Item k="Actual end"       v={placement.actual_end_date}  t="overview-actual-end" />
        <Item k="Due"              v={placement.due_date}         t="overview-due" />
        <Item k="End client"       v={placement.end_client_name}  t="overview-client" />
        <Item k="Worksite"         v={[placement.worksite_state, placement.worksite_country].filter(Boolean).join(', ') || '—'} t="overview-site" />
        <Item k="Remote policy"    v={placement.remote_policy}    t="overview-remote" />
        <Item k="External ID"      v={placement.external_id}      t="overview-external" />
      </div>
    </div>
  );
}
function OverviewEdit({ placement, onClose }) {
  const [form, setForm] = useState(placement);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const save = async () => {
    setSaving(true); setError(null);
    try {
      const fields = ['title','status','start_date','end_date','due_date','end_client_name','worksite_state','worksite_country','remote_policy','engagement_type','notes','external_id'];
      const patch = {};
      for (const f of fields) if (form[f] !== placement[f]) patch[f] = form[f];
      if (!Object.keys(patch).length) { onClose(); return; }
      await api.patch(`/modules/placements/api/placements.php?id=${placement.id}`, patch);
      onClose();
    } catch (e) { setError(e); setSaving(false); }
  };
  return (
    <div data-testid="tab-overview-edit">
      <h3>Edit overview</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
        {[['title','Title'],['end_client_name','End client'],['external_id','External ID'],
          ['worksite_state','State'],['worksite_country','Country (2)'],['notes','Notes']].map(([k, l]) => (
          <label key={k} style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>{l}</span>
            <input className="input" value={form[k] ?? ''} onChange={set(k)} data-testid={`overview-edit-${k.replace(/_/g,'-')}`} />
          </label>
        ))}
        {[['start_date','Start'],['end_date','End'],['due_date','Due']].map(([k, l]) => (
          <label key={k} style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>{l}</span>
            <input className="input" type="date" value={form[k] ?? ''} onChange={set(k)} data-testid={`overview-edit-${k.replace(/_/g,'-')}`} />
          </label>
        ))}
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>Status</span>
          <select className="input" value={form.status} onChange={set('status')} data-testid="overview-edit-status">
            {['draft','pending_start','active','on_hold','ended','cancelled'].map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>Engagement type</span>
          <select className="input" value={form.engagement_type} onChange={set('engagement_type')} data-testid="overview-edit-etype">
            {['w2','1099','c2c','temp_to_perm','direct_hire'].map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>Remote</span>
          <select className="input" value={form.remote_policy ?? ''} onChange={set('remote_policy')} data-testid="overview-edit-remote">
            <option value="">—</option><option value="onsite">onsite</option><option value="hybrid">hybrid</option><option value="remote">remote</option>
          </select>
        </label>
      </div>
      {error && <p className="error" data-testid="overview-edit-error">Error: {error.message}</p>}
      <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)' }}>
        <button className="btn btn--primary" onClick={save} disabled={saving} data-testid="overview-edit-save">{saving ? 'Saving…' : 'Save'}</button>
        <button className="btn btn--ghost" onClick={onClose} data-testid="overview-edit-cancel">Cancel</button>
      </div>
    </div>
  );
}

// ── Chain ────────────────────────────────────────────────
function ChainTab({ pid, chain, reload }) {
  const [form, setForm] = useState({ position: 0, party_name: '', party_role: 'end_client', portal_fee_pct: '', submittal_id: '', vms_job_id: '' });
  const [adding, setAdding] = useState(false);
  const [error, setError]   = useState(null);
  const [portalFor, setPortalFor] = useState(null); // chain row currently editing portal creds

  const add = async (e) => {
    e.preventDefault(); setAdding(true); setError(null);
    try {
      await api.post(`/modules/placements/api/chain.php?placement_id=${pid}`, {
        ...form, position: parseInt(form.position, 10),
        portal_fee_pct: form.portal_fee_pct ? parseFloat(form.portal_fee_pct) : null,
        submittal_id: form.submittal_id || null,
        vms_job_id: form.vms_job_id || null,
      });
      setForm({ position: chain.length, party_name: '', party_role: 'sub_vendor', portal_fee_pct: '', submittal_id: '', vms_job_id: '' });
      reload();
    } catch (e) { setError(e); }
    finally     { setAdding(false); }
  };
  const del = async (id) => { if (!confirm('Remove tier?')) return; await api.delete(`/modules/placements/api/chain.php?id=${id}`); reload(); };

  // Inline patch helper (used by submittal_id / vms_job_id cells).
  const patchField = async (id, field, value) => {
    try {
      await api.patch(`/modules/placements/api/chain.php?id=${id}`, { [field]: value || null });
      reload();
    } catch (e) { alert(`Save failed: ${e.message}`); }
  };

  return (
    <div data-testid="tab-chain">
      <h3>Vendor chain</h3>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Position 0 = end client. Higher numbers = layers between us and them. Fees stack additively.</p>
      <table className="data-table" data-testid="chain-table">
        <thead><tr><th>#</th><th>Name</th><th>Role</th><th>Portal fee %</th><th>Submittal #</th><th>VMS Job #</th><th>Portal creds</th><th>Contract</th><th></th></tr></thead>
        <tbody>
          {chain.length === 0 && <tr><td colSpan={9} className="empty" data-testid="chain-empty">No chain rows yet.</td></tr>}
          {chain.map(c => (
            <tr key={c.id} data-testid={`chain-row-${c.id}`}>
              <td>{c.position}</td>
              <td>{c.party_name}</td>
              <td>{c.party_role}</td>
              <td>{c.portal_fee_pct ? `${(c.portal_fee_pct * 100).toFixed(2)}%` : '—'}</td>
              <td>
                <InlineEdit value={c.submittal_id} onSave={(v) => patchField(c.id, 'submittal_id', v)} testId={`chain-submittal-${c.id}`} placeholder="—" />
              </td>
              <td>
                <InlineEdit value={c.vms_job_id} onSave={(v) => patchField(c.id, 'vms_job_id', v)} testId={`chain-vms-${c.id}`} placeholder="—" />
              </td>
              <td>
                <button
                  type="button"
                  className="btn btn--ghost"
                  data-testid={`chain-portal-btn-${c.id}`}
                  onClick={() => setPortalFor(c)}
                  style={{ fontSize: 12 }}
                >
                  {c.has_portal_credentials ? '🔒 Manage' : '+ Set'}
                </button>
              </td>
              <td><ContractCell row={c} /></td>
              <td><button className="btn btn--ghost" onClick={() => del(c.id)} data-testid={`chain-delete-${c.id}`}>Remove</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <form onSubmit={add} style={{ display: 'flex', gap: 'var(--cf-space-2)', marginTop: 'var(--cf-space-3)', flexWrap: 'wrap' }} data-testid="chain-add-form">
        <input className="input" type="number" placeholder="#" value={form.position} onChange={e => setForm({ ...form, position: e.target.value })} style={{ width: '60px' }} data-testid="chain-position" />
        <input className="input" placeholder="Party name" value={form.party_name} onChange={e => setForm({ ...form, party_name: e.target.value })} data-testid="chain-name" required />
        <select className="input" value={form.party_role} onChange={e => setForm({ ...form, party_role: e.target.value })} data-testid="chain-role">
          {['end_client','msp','prime_vendor','sub_vendor','direct'].map(r => <option key={r} value={r}>{r}</option>)}
        </select>
        <input className="input" type="number" step="0.0001" placeholder="0.02 = 2%" value={form.portal_fee_pct} onChange={e => setForm({ ...form, portal_fee_pct: e.target.value })} style={{ width: '140px' }} data-testid="chain-fee" />
        <input className="input" placeholder="Submittal #" value={form.submittal_id} onChange={e => setForm({ ...form, submittal_id: e.target.value })} style={{ width: '140px' }} data-testid="chain-submittal" />
        <input className="input" placeholder="VMS Job #" value={form.vms_job_id} onChange={e => setForm({ ...form, vms_job_id: e.target.value })} style={{ width: '140px' }} data-testid="chain-vms" />
        <button className="btn btn--primary" disabled={adding} data-testid="chain-add-btn">{adding ? '…' : 'Add tier'}</button>
      </form>
      {error && <p className="error" data-testid="chain-error">Error: {error.message}</p>}

      {portalFor && (
        <PortalCredsDialog
          row={portalFor}
          onClose={() => setPortalFor(null)}
          onSaved={() => { setPortalFor(null); reload(); }}
        />
      )}
    </div>
  );
}

function InlineEdit({ value, onSave, testId, placeholder }) {
  const [editing, setEditing] = useState(false);
  const [v, setV] = useState(value || '');
  if (!editing) {
    return (
      <span
        data-testid={testId}
        onClick={() => { setV(value || ''); setEditing(true); }}
        style={{ cursor: 'pointer', color: value ? 'inherit' : '#999' }}
      >
        {value || placeholder}
      </span>
    );
  }
  return (
    <span style={{ display: 'inline-flex', gap: 4 }}>
      <input
        className="input"
        autoFocus
        value={v}
        onChange={(e) => setV(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Escape') setEditing(false); if (e.key === 'Enter') { onSave(v); setEditing(false); } }}
        data-testid={`${testId}-input`}
        style={{ width: 120, fontSize: 12 }}
      />
      <button type="button" className="btn btn--ghost" data-testid={`${testId}-save`} onClick={() => { onSave(v); setEditing(false); }} style={{ fontSize: 11 }}>✓</button>
      <button type="button" className="btn btn--ghost" data-testid={`${testId}-cancel`} onClick={() => setEditing(false)} style={{ fontSize: 11 }}>✕</button>
    </span>
  );
}

function PortalCredsDialog({ row, onClose, onSaved }) {
  const [revealed, setRevealed] = useState(null);     // shown plaintext (from reveal_portal)
  const [revealing, setRevealing] = useState(false);
  const [revealError, setRevealError] = useState(null);
  const [draft, setDraft] = useState({ url: '', username: '', password: '', notes: '' });
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState(null);

  const reveal = async () => {
    if (!confirm('Reveal vendor portal credentials? This action is logged to the audit trail.')) return;
    setRevealing(true); setRevealError(null);
    try {
      const res = await api.get(`/modules/placements/api/chain.php?action=reveal_portal&id=${row.id}`);
      const c = res.credentials || {};
      setRevealed(c);
      setDraft({ url: c.url || '', username: c.username || '', password: c.password || '', notes: c.notes || '' });
    } catch (e) { setRevealError(e); }
    finally     { setRevealing(false); }
  };

  const save = async () => {
    setSaving(true); setSaveError(null);
    try {
      const payload = {};
      ['url','username','password','notes'].forEach((k) => { if (draft[k]) payload[k] = draft[k]; });
      if (Object.keys(payload).length === 0) { setSaveError({ message: 'At least one field required' }); setSaving(false); return; }
      await api.post(`/modules/placements/api/chain.php?action=set_portal&id=${row.id}`, payload);
      onSaved();
    } catch (e) { setSaveError(e); }
    finally     { setSaving(false); }
  };

  const clear = async () => {
    if (!confirm('Clear stored portal credentials?')) return;
    setSaving(true); setSaveError(null);
    try {
      await api.post(`/modules/placements/api/chain.php?action=clear_portal&id=${row.id}`, {});
      onSaved();
    } catch (e) { setSaveError(e); }
    finally     { setSaving(false); }
  };

  return (
    <div
      data-testid="chain-portal-dialog"
      onClick={onClose}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50 }}
    >
      <div onClick={(e) => e.stopPropagation()} style={{ background: '#fff', padding: 24, borderRadius: 8, width: 'min(520px, 95vw)' }}>
        <h3 style={{ margin: '0 0 4px' }}>Portal credentials — {row.party_name}</h3>
        <p style={{ margin: '0 0 16px', fontSize: 13, color: '#666' }}>
          Encrypted at rest. Reveals are audit-logged. Storage is one record per chain tier.
        </p>
        {row.has_portal_credentials && !revealed && (
          <div style={{ marginBottom: 12 }}>
            <button type="button" className="btn btn--ghost" data-testid="chain-portal-reveal" onClick={reveal} disabled={revealing}>
              {revealing ? 'Revealing…' : '👁 Reveal stored credentials'}
            </button>
            {revealError && <p className="error" data-testid="chain-portal-reveal-error">Error: {revealError.message}</p>}
          </div>
        )}
        <Field label="Portal URL"><input className="input" value={draft.url} onChange={(e) => setDraft({ ...draft, url: e.target.value })} data-testid="chain-portal-url" placeholder="https://vendor-portal.example.com" /></Field>
        <Field label="Username"><input className="input" value={draft.username} onChange={(e) => setDraft({ ...draft, username: e.target.value })} data-testid="chain-portal-username" /></Field>
        <Field label="Password"><input className="input" type="password" value={draft.password} onChange={(e) => setDraft({ ...draft, password: e.target.value })} data-testid="chain-portal-password" /></Field>
        <Field label="Notes"><textarea className="input" rows={2} value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} data-testid="chain-portal-notes" /></Field>
        {saveError && <p className="error" data-testid="chain-portal-save-error">Error: {saveError.message}</p>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          {row.has_portal_credentials && (
            <button type="button" className="btn btn--ghost" data-testid="chain-portal-clear" onClick={clear} disabled={saving}>Clear</button>
          )}
          <button type="button" className="btn btn--ghost" data-testid="chain-portal-cancel" onClick={onClose}>Cancel</button>
          <button type="button" className="btn btn--primary" data-testid="chain-portal-save" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : (row.has_portal_credentials ? 'Update' : 'Save')}
          </button>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', marginBottom: 10 }}>
      <span style={{ fontSize: '0.85em', color: '#555', marginBottom: 4 }}>{label}</span>
      {children}
    </label>
  );
}

// ── Rates ────────────────────────────────────────────────
function RatesTab({ pid, rates, reload }) {
  const [form, setForm] = useState({ effective_from: new Date().toISOString().slice(0,10), bill_rate: '', pay_rate: '' });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const draft = async (e) => {
    e.preventDefault(); setBusy(true); setError(null);
    try {
      await api.post(`/modules/placements/api/rates.php?placement_id=${pid}`, {
        ...form, bill_rate: parseFloat(form.bill_rate), pay_rate: parseFloat(form.pay_rate),
      });
      setForm({ effective_from: new Date().toISOString().slice(0,10), bill_rate: '', pay_rate: '' });
      reload();
    } catch (e) { setError(e); }
    finally     { setBusy(false); }
  };

  const approve = async (rateId) => {
    const isCorrection = confirm('Is this a correction to a prior approved rate? OK = yes, Cancel = no');
    let reason = null;
    if (isCorrection) {
      reason = prompt('Correction reason (required for audit log):');
      if (!reason) { alert('Correction reason required.'); return; }
    }
    try {
      await api.post(`/modules/placements/api/rates.php?action=approve&id=${rateId}`, { is_correction: isCorrection, correction_reason: reason });
      reload();
    } catch (e) { alert(`Approve failed: ${e.message}`); }
  };

  return (
    <div data-testid="tab-rates">
      <h3>Rates</h3>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Drafts can be edited; approved rates are locked (snapshot).</p>
      <table className="data-table" data-testid="rates-table">
        <thead><tr><th>From</th><th>To</th><th>Bill</th><th>Pay</th><th>Adjusted</th><th>Net</th><th>State</th><th></th></tr></thead>
        <tbody>
          {rates.length === 0 && <tr><td colSpan={8} className="empty" data-testid="rates-empty">No rate rows yet.</td></tr>}
          {rates.map(r => (
            <tr key={r.id} data-testid={`rate-row-${r.id}`}>
              <td>{r.effective_from}</td>
              <td>{r.effective_to || '—'}</td>
              <td>${parseFloat(r.bill_rate).toFixed(2)}</td>
              <td>${parseFloat(r.pay_rate).toFixed(2)}</td>
              <td>{r.adjusted_bill_rate ? `$${parseFloat(r.adjusted_bill_rate).toFixed(2)}` : '—'}</td>
              <td>{r.net_to_vendor ? `$${parseFloat(r.net_to_vendor).toFixed(2)}` : '—'}</td>
              <td>{r.approved_at ? <span className="badge badge--active" data-testid={`rate-state-${r.id}`}>approved</span>
                                  : <span className="badge badge--candidate" data-testid={`rate-state-${r.id}`}>draft</span>}
                  {r.is_correction ? ' (correction)' : ''}
              </td>
              <td>{!r.approved_at && <button className="btn btn--primary" onClick={() => approve(r.id)} data-testid={`rate-approve-${r.id}`}>Approve</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <form onSubmit={draft} style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }} data-testid="rates-draft-form">
        <input className="input" type="date" value={form.effective_from} onChange={e => setForm({ ...form, effective_from: e.target.value })} data-testid="rates-effective-from" required />
        <input className="input" type="number" step="0.01" placeholder="Bill ($/hr)" value={form.bill_rate} onChange={e => setForm({ ...form, bill_rate: e.target.value })} data-testid="rates-bill" required />
        <input className="input" type="number" step="0.01" placeholder="Pay ($/hr)" value={form.pay_rate} onChange={e => setForm({ ...form, pay_rate: e.target.value })} data-testid="rates-pay" required />
        <button className="btn btn--primary" disabled={busy} data-testid="rates-draft-btn">{busy ? '…' : 'Draft new rate'}</button>
      </form>
      {error && <p className="error" data-testid="rates-error">Error: {error.message}</p>}
    </div>
  );
}

// ── Commissions ────────────────────────────────────────────
function CommissionsTab({ pid, rows, reload }) {
  const [form, setForm] = useState({ role: 'recruiter', split_pct: '', basis: 'net_margin', effective_from: new Date().toISOString().slice(0,10) });
  const [error, setError] = useState(null);
  const add = async (e) => {
    e.preventDefault(); setError(null);
    try {
      await api.post(`/modules/placements/api/commissions.php?placement_id=${pid}`, {
        ...form, split_pct: form.split_pct ? parseFloat(form.split_pct) : null,
      });
      setForm({ role: 'recruiter', split_pct: '', basis: 'net_margin', effective_from: new Date().toISOString().slice(0,10) });
      reload();
    } catch (e) { setError(e); }
  };
  const del = async (id) => { if (!confirm('Remove split?')) return; await api.delete(`/modules/placements/api/commissions.php?id=${id}`); reload(); };
  return (
    <div data-testid="tab-commissions">
      <h3>Commission splits</h3>
      <table className="data-table" data-testid="commissions-table">
        <thead><tr><th>Role</th><th>Split</th><th>Basis</th><th>From</th><th>To</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="commissions-empty">No splits.</td></tr>}
          {rows.map(c => (
            <tr key={c.id} data-testid={`commission-row-${c.id}`}>
              <td>{c.role}</td><td>{c.split_pct ? `${(c.split_pct * 100).toFixed(2)}%` : c.flat_amount ? `$${c.flat_amount}` : '—'}</td>
              <td>{c.basis}</td><td>{c.effective_from}</td><td>{c.effective_to || '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => del(c.id)} data-testid={`commission-delete-${c.id}`}>Remove</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <form onSubmit={add} style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }} data-testid="commissions-add-form">
        <select className="input" value={form.role} onChange={e => setForm({ ...form, role: e.target.value })} data-testid="commission-role">
          {['account_manager','lead','recruiter','team','other'].map(r => <option key={r} value={r}>{r}</option>)}
        </select>
        <input className="input" type="number" step="0.0001" placeholder="0.30 = 30%" value={form.split_pct} onChange={e => setForm({ ...form, split_pct: e.target.value })} data-testid="commission-split" />
        <select className="input" value={form.basis} onChange={e => setForm({ ...form, basis: e.target.value })} data-testid="commission-basis">
          {['net_margin','gross_margin','bill_rate','flat'].map(b => <option key={b} value={b}>{b}</option>)}
        </select>
        <input className="input" type="date" value={form.effective_from} onChange={e => setForm({ ...form, effective_from: e.target.value })} data-testid="commission-from" />
        <button className="btn btn--primary" data-testid="commission-add-btn">Add</button>
      </form>
      {error && <p className="error" data-testid="commissions-error">Error: {error.message}</p>}
    </div>
  );
}

// ── Referrals ────────────────────────────────────────────
function ReferralsTab({ pid, rows, reload }) {
  const [form, setForm] = useState({ referrer_type: 'vendor', referrer_vendor_name: '', fee_basis: 'pct_bill', fee_pct: '', start_date: new Date().toISOString().slice(0,10), duration_months: '' });
  const [error, setError] = useState(null);
  const add = async (e) => {
    e.preventDefault(); setError(null);
    try {
      const payload = { ...form, fee_pct: form.fee_pct ? parseFloat(form.fee_pct) : null, duration_months: form.duration_months ? parseInt(form.duration_months, 10) : null };
      await api.post(`/modules/placements/api/referrals.php?placement_id=${pid}`, payload);
      reload();
    } catch (e) { setError(e); }
  };
  const del = async (id) => { if (!confirm('Remove referral?')) return; await api.delete(`/modules/placements/api/referrals.php?id=${id}`); reload(); };
  return (
    <div data-testid="tab-referrals">
      <h3>Referral fees</h3>
      <table className="data-table" data-testid="referrals-table">
        <thead><tr><th>Referrer</th><th>Fee</th><th>Basis</th><th>Start</th><th>Duration</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="referrals-empty">No referrals.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`referral-row-${r.id}`}>
              <td>{r.referrer_vendor_name || `#${r.referrer_person_id || r.referrer_user_id}`}</td>
              <td>{r.fee_pct ? `${(r.fee_pct * 100).toFixed(2)}%` : r.fee_flat ? `$${r.fee_flat}` : '—'}</td>
              <td>{r.fee_basis}</td><td>{r.start_date}</td><td>{r.duration_months ? `${r.duration_months}mo` : '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => del(r.id)} data-testid={`referral-delete-${r.id}`}>Remove</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <form onSubmit={add} style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }} data-testid="referrals-add-form">
        <select className="input" value={form.referrer_type} onChange={e => setForm({ ...form, referrer_type: e.target.value })} data-testid="referral-type">
          <option value="vendor">vendor</option><option value="person">person</option><option value="user">user</option>
        </select>
        <input className="input" placeholder="Vendor name (if vendor)" value={form.referrer_vendor_name} onChange={e => setForm({ ...form, referrer_vendor_name: e.target.value })} data-testid="referral-name" />
        <select className="input" value={form.fee_basis} onChange={e => setForm({ ...form, fee_basis: e.target.value })} data-testid="referral-basis">
          {['per_hour','per_invoice','one_time','pct_bill','pct_margin'].map(b => <option key={b} value={b}>{b}</option>)}
        </select>
        <input className="input" type="number" step="0.0001" placeholder="0.10 = 10%" value={form.fee_pct} onChange={e => setForm({ ...form, fee_pct: e.target.value })} data-testid="referral-fee" />
        <input className="input" type="number" placeholder="Months" value={form.duration_months} onChange={e => setForm({ ...form, duration_months: e.target.value })} style={{ width: '90px' }} data-testid="referral-months" />
        <input className="input" type="date" value={form.start_date} onChange={e => setForm({ ...form, start_date: e.target.value })} data-testid="referral-start" />
        <button className="btn btn--primary" data-testid="referral-add-btn">Add</button>
      </form>
      {error && <p className="error" data-testid="referrals-error">Error: {error.message}</p>}
    </div>
  );
}

// ── Corp (C2C only) ────────────────────────────────────────
function CorpTab({ pid }) {
  const path = `/modules/placements/api/corp.php?placement_id=${pid}`;
  const { data, loading, error, reload } = useApi(path);
  const corp = data?.corp;
  const [form, setForm] = useState({});
  const [saving, setSaving] = useState(false); const [saveError, setSaveError] = useState(null);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const save = async () => {
    setSaving(true); setSaveError(null);
    try {
      await api.put(path, { ...corp, ...form });
      setForm({}); reload();
    } catch (e) { setSaveError(e); }
    finally     { setSaving(false); }
  };
  return (
    <div data-testid="tab-corp">
      <h3>C2C corp details (encrypted EIN)</h3>
      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="corp-error">Error: {error.message}</p>}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
        {[['corp_legal_name','Legal name'],['corp_ein','EIN (will be encrypted)'],
          ['corp_address_line1','Address 1'],['corp_address_line2','Address 2'],
          ['corp_city','City'],['corp_state','State'],['corp_postal_code','Postal'],['corp_country','Country (2)'],
          ['corp_contact_name','Contact name'],['corp_contact_email','Contact email'],['corp_contact_phone','Contact phone']].map(([k, l]) => (
          <label key={k} style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>{l}{k === 'corp_ein' && corp?.corp_ein_last4 ? ` (current: •••${corp.corp_ein_last4})` : ''}</span>
            <input className="input" value={form[k] ?? (k === 'corp_ein' ? '' : (corp?.[k] ?? ''))} onChange={set(k)} data-testid={`corp-${k.replace(/_/g, '-')}`} />
          </label>
        ))}
      </div>
      {saveError && <p className="error" data-testid="corp-save-error">Error: {saveError.message}</p>}
      <button className="btn btn--primary" style={{ marginTop: 'var(--cf-space-3)' }} onClick={save} disabled={saving} data-testid="corp-save">
        {saving ? 'Saving…' : 'Save'}
      </button>
    </div>
  );
}

// ── Documents ────────────────────────────────────────────
function DocumentsTab({ rows }) {
  return (
    <div data-testid="tab-documents">
      <h3>Documents</h3>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Stored via Core StorageService (S3). Upload UI ships in the next pass — for now this view shows already-uploaded docs.</p>
      <table className="data-table" data-testid="documents-table">
        <thead><tr><th>Type</th><th>File</th><th>Effective</th><th>Uploaded</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={4} className="empty" data-testid="documents-empty">No documents yet.</td></tr>}
          {rows.map(d => (
            <tr key={d.id} data-testid={`document-row-${d.id}`}>
              <td>{d.doc_type}</td><td>{d.file_name || `#${d.storage_object_id}`}</td>
              <td>{d.effective_from || '—'}{d.effective_to ? ` → ${d.effective_to}` : ''}</td>
              <td>{(d.created_at || '').slice(0, 10)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ── Approval contact ────────────────────────────────────────
function ApprovalTab({ pid, placement, reload }) {
  const [form, setForm] = useState({
    client_approver_name:  placement.client_approver_name  || '',
    client_approver_email: placement.client_approver_email || '',
    tokenized_email_approval_enabled: !!placement.tokenized_email_approval_enabled,
    bulk_uploads_can_be_pre_approved: !!placement.bulk_uploads_can_be_pre_approved,
  });
  const [saving, setSaving] = useState(false); const [error, setError] = useState(null);
  const save = async () => {
    setSaving(true); setError(null);
    try { await api.put(`/modules/placements/api/approval_contact.php?placement_id=${pid}`, form); reload(); }
    catch (e) { setError(e); }
    finally   { setSaving(false); }
  };
  return (
    <div data-testid="tab-approval">
      <h3>Approval contact</h3>
      <p style={{ color: 'var(--cf-text-secondary)' }}>Used by Time module for tokenized weekly approvals. Default: OFF.</p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>Approver name</span>
          <input className="input" value={form.client_approver_name} onChange={e => setForm({ ...form, client_approver_name: e.target.value })} data-testid="approval-name" />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>Approver email</span>
          <input className="input" type="email" value={form.client_approver_email} onChange={e => setForm({ ...form, client_approver_email: e.target.value })} data-testid="approval-email" />
        </label>
        <label data-testid="approval-tokenized-label" style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
          <input type="checkbox" checked={form.tokenized_email_approval_enabled} onChange={e => setForm({ ...form, tokenized_email_approval_enabled: e.target.checked })} data-testid="approval-tokenized" />
          Tokenized email approvals enabled
        </label>
        <label data-testid="approval-bulk-label" style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
          <input type="checkbox" checked={form.bulk_uploads_can_be_pre_approved} onChange={e => setForm({ ...form, bulk_uploads_can_be_pre_approved: e.target.checked })} data-testid="approval-bulk" />
          Bulk uploads can be pre-approved
        </label>
      </div>
      {error && <p className="error" data-testid="approval-error">Error: {error.message}</p>}
      <button className="btn btn--primary" style={{ marginTop: 'var(--cf-space-3)' }} onClick={save} disabled={saving} data-testid="approval-save">
        {saving ? 'Saving…' : 'Save'}
      </button>
    </div>
  );
}

// ── Margin ────────────────────────────────────────────────
function MarginTab({ currentRate, chain }) {
  if (!currentRate) return <div data-testid="tab-margin"><h3>Margin</h3><p className="empty" data-testid="margin-empty">No approved rate yet — draft and approve a rate row first.</p></div>;
  const totalPct = chain.reduce((sum, c) => sum + (parseFloat(c.portal_fee_pct || 0)), 0);
  const bill = parseFloat(currentRate.bill_rate);
  const pay  = parseFloat(currentRate.pay_rate);
  const adjusted = parseFloat(currentRate.adjusted_bill_rate ?? bill * (1 - totalPct));
  const net      = parseFloat(currentRate.net_to_vendor      ?? adjusted - pay);
  const Item = ({ k, v, t }) => (<div><span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em', display: 'block' }}>{k}</span><strong data-testid={t}>{v}</strong></div>);
  return (
    <div data-testid="tab-margin">
      <h3>Net margin (current approved rate)</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 'var(--cf-space-3)' }}>
        <Item k="Bill rate"           v={`$${bill.toFixed(2)} /hr`}              t="margin-bill" />
        <Item k="Total portal fee %"  v={`${(totalPct * 100).toFixed(2)}%`}      t="margin-fee-pct" />
        <Item k="Adjusted bill rate"  v={`$${adjusted.toFixed(2)} /hr`}          t="margin-adjusted" />
        <Item k="Pay rate"            v={`$${pay.toFixed(2)} /hr`}               t="margin-pay" />
        <Item k="Net to vendor"       v={`$${net.toFixed(2)} /hr`}               t="margin-net" />
        <Item k="Margin %"            v={`${((net / bill) * 100).toFixed(2)}%`}  t="margin-pct" />
      </div>
    </div>
  );
}

function ContractCell({ row }) {
  const [state, setState] = useState({ status: 'idle', error: null, draft: null });

  const onPick = async (file) => {
    if (!file) return;
    setState({ status: 'uploading', error: null, draft: null });
    try {
      const uploaded = await uploadFileViaPresignedPost(
        `/modules/placements/api/chain.php?action=contract_upload_url&id=${row.id}&file_name=${encodeURIComponent(file.name)}`,
        file
      );
      setState({ status: 'extracting', error: null, draft: null });
      const ex = await api.post(`/modules/placements/api/chain.php?action=extract_contract&id=${row.id}`, { storage_key: uploaded.storage_key });
      setState({ status: 'extracted', error: null, draft: ex.draft });
    } catch (e) { setState({ status: 'error', error: e, draft: null }); }
  };

  return (
    <div data-testid={`chain-contract-${row.id}`} style={{ minWidth: 140 }}>
      {state.status === 'idle' && (
        <label className="btn btn--ghost" style={{ cursor: 'pointer', fontSize: 12 }} data-testid={`chain-contract-${row.id}-pick-label`}>
          ✨ Extract MSA/SOW
          <input
            type="file"
            accept="application/pdf,image/*"
            onChange={(e) => onPick(e.target.files?.[0] || null)}
            data-testid={`chain-contract-${row.id}-input`}
            style={{ display: 'none' }}
          />
        </label>
      )}
      {state.status === 'uploading'  && <span style={{ fontSize: 12, color: '#6b7280' }}>Uploading…</span>}
      {state.status === 'extracting' && <span style={{ fontSize: 12, color: '#6b7280' }}>Extracting…</span>}
      {state.status === 'extracted'  && (
        <button
          type="button"
          className="btn btn--ghost"
          data-testid={`chain-contract-${row.id}-summary-btn`}
          onClick={() => alert(JSON.stringify(state.draft, null, 2))}
          style={{ fontSize: 12, color: '#065f46' }}
        >
          ✨ {state.draft?.agreement_type || 'contract'} · view summary
        </button>
      )}
      {state.status === 'error' && <span style={{ fontSize: 12, color: '#991b1b' }} data-testid={`chain-contract-${row.id}-error`}>{state.error?.message || 'Failed'}</span>}
    </div>
  );
}



// ── Cycles ────────────────────────────────────────────────
// Each placement can be on a different cadence for billing, AP, and payroll.
// Time settlement walks these pointers when generating downstream bundles.
function CyclesTab({ placement, reload }) {
  const { data: cyclesData, loading: cyclesLoading } = useApi('/modules/payroll/api/cycles.php');
  const cycles = cyclesData?.rows || cyclesData?.cycles || [];
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [draft, setDraft] = useState({
    billing_cycle_id: placement.billing_cycle_id || '',
    ap_cycle_id:      placement.ap_cycle_id      || '',
    payroll_cycle_id: placement.payroll_cycle_id || '',
  });

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      const patch = {};
      ['billing_cycle_id','ap_cycle_id','payroll_cycle_id'].forEach((k) => {
        patch[k] = draft[k] === '' ? null : Number(draft[k]);
      });
      await api.patch(`/modules/placements/api/placements.php?id=${placement.id}`, patch);
      reload();
    } catch (e) {
      setErr(e.message || 'Save failed');
    } finally {
      setBusy(false);
    }
  };

  const Picker = ({ field, label, hint }) => (
    <div style={{ marginBottom: 16 }}>
      <label style={{ display: 'block', fontWeight: 500, marginBottom: 4 }}>{label}</label>
      <select
        className="input"
        value={draft[field]}
        onChange={(e) => setDraft({ ...draft, [field]: e.target.value })}
        data-testid={`placement-cycle-${field}`}
        style={{ width: 320 }}
      >
        <option value="">— None (placement excluded) —</option>
        {cycles.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name} ({c.cadence || c.frequency || 'cycle'})
          </option>
        ))}
      </select>
      {hint && <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 4 }}>{hint}</div>}
    </div>
  );

  return (
    <div data-testid="tab-cycles" style={{ maxWidth: 640 }}>
      <h3 style={{ marginTop: 0 }}>Cycle assignment</h3>
      <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        Decoupled cadences. A single engagement can bill bi-weekly, pay vendors
        monthly, and run W-2 payroll semi-monthly. Time settlement walks these
        pointers when generating AR / AP / Payroll bundles for the period.
      </p>

      {cyclesLoading
        ? <p>Loading cycles…</p>
        : (
          <>
            <Picker
              field="billing_cycle_id"
              label="Billing cycle (AR)"
              hint="Cadence on which this placement's hours invoice the client."
            />
            <Picker
              field="ap_cycle_id"
              label="AP cycle (vendor pay)"
              hint="When 1099 / C2C vendor payments cut for hours on this placement."
            />
            <Picker
              field="payroll_cycle_id"
              label="Payroll cycle (W-2)"
              hint="When W-2 employee paychecks cut for hours on this placement."
            />

            {err && <div className="alert alert--err" data-testid="placement-cycles-error">{err}</div>}

            <button
              className="btn btn--primary"
              onClick={save}
              disabled={busy}
              data-testid="placement-cycles-save"
            >
              {busy ? 'Saving…' : 'Save cycle assignment'}
            </button>
          </>
        )}
    </div>
  );
}
