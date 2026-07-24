import React, { useEffect, useState } from 'react';
import { useParams, useNavigate, NavLink, Routes, Route, Navigate, Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';
import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel';
import SyncHistoryDrawer from '../../../dashboard/src/components/SyncHistoryDrawer';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import PlacementTimesheetsTab from './PlacementTimesheetsTab';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

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
  const commissions = data?.commissions ?? [];
  const referrals   = data?.referrals ?? [];
  const documents   = data?.documents ?? [];

  if (loading) return <p data-testid="placement-detail-loading">Loading…</p>;
  if (error)   return <p className="error" data-testid="placement-detail-error">Error: {error.message}</p>;
  if (!placement) return <p data-testid="placement-detail-empty">Placement not found.</p>;

  const TABS = [
    { slug: 'overview',    label: 'Overview' },
    { slug: 'economics',   label: 'Economics' },
    { slug: 'rates',       label: 'Rates' },
    { slug: 'timesheets',  label: 'Timesheets' },
    { slug: 'documents',   label: 'Documents' },
    { slug: 'approval',    label: 'Approval' },
  ];

  return (
    <section className="person-detail" data-testid="placement-detail">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--cf-space-3)' }}>
        <div>
          <button onClick={() => nav('..')} className="btn btn--ghost" data-testid="placement-detail-back">← Placements</button>
          <Link
            to="graphql"
            className="btn btn--ghost"
            data-testid="placement-detail-switch-gql"
            style={{ marginLeft: 'var(--cf-space-2)' }}
          >⚡ GraphQL pilot</Link>
          <h2 data-testid="placement-detail-title" style={{ marginTop: 'var(--cf-space-2)', display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <span>{placement.title}</span>
            <IdBadge id={placement.id} prefix="PL" title={`Placement ID ${placement.id} — click to copy for CSV imports`} />
            {placement.person_id && (
              <span style={{ fontSize: 14, color: '#334155', fontWeight: 500, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                ·{' '}
                <a
                  href={`/modules/people/${placement.person_id}`}
                  data-testid="placement-detail-person-link"
                  style={{ color: '#1d4ed8', textDecoration: 'none' }}
                  title="Open person profile"
                >
                  {placement.person_first_name || placement.person_last_name
                    ? `${placement.person_first_name || ''} ${placement.person_last_name || ''}`.trim()
                    : `Person #${placement.person_id}`}
                </a>
                <IdBadge id={placement.person_id} prefix="P" />
              </span>
            )}
          </h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            <span className={`badge badge--${placement.status}`} data-testid="placement-detail-status">{placement.status}</span>{' '}
            <span className={`badge badge--${placement.engagement_type}`} data-testid="placement-detail-etype">{placement.engagement_type}</span>{' · '}
            <span data-testid="placement-detail-client">{placement.end_client_name || '(no end client)'}</span>{' · '}
            <span data-testid="placement-detail-dates">{placement.start_date} → {placement.end_date || '∞'}</span>
            {placement.person_email_primary && (
              <>
                {' · '}
                <a href={`mailto:${placement.person_email_primary}`} data-testid="placement-detail-person-email" style={{ color: '#1d4ed8' }}>
                  {placement.person_email_primary}
                </a>
              </>
            )}
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
        <Route path="economics"  element={<EconomicsTab   placement={placement} chain={chain} commissions={commissions} referrals={referrals} reload={reload} />} />
        <Route path="chain"      element={<Navigate to="../economics" replace />} />
        <Route path="rates"      element={<RatesTab       pid={placement.id} rates={rates} reload={reload} />} />
        <Route path="commissions"element={<Navigate to="../economics" replace />} />
        <Route path="referrals"  element={<Navigate to="../economics" replace />} />
        <Route path="corp"       element={<Navigate to="../economics" replace />} />
        <Route path="cycles"     element={<Navigate to="../economics" replace />} />
        <Route path="timesheets" element={<PlacementTimesheetsTab pid={placement.id} placement={placement} />} />
        <Route path="documents"  element={<DocumentsTab   pid={placement.id} rows={documents} reload={reload} />} />
        <Route path="approval"   element={<ApprovalTab    pid={placement.id} placement={placement} reload={reload} />} />
        <Route path="margin"     element={<Navigate to="../economics" replace />} />
      </Routes>
    </section>
  );
}

// ── Overview ────────────────────────────────────────────────
/**
 * Parse the placement's `coreflux_overridden_fields` JSON column into
 * a Set of field names. Returns an empty Set when the column is null,
 * empty, or malformed — so callers can always `.has(fieldName)`.
 */
function parseOverrides(placement) {
  const raw = placement?.coreflux_overridden_fields;
  if (!raw) return new Set();
  try {
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
    return new Set(Array.isArray(parsed) ? parsed.map(String) : []);
  } catch {
    return new Set();
  }
}

/**
 * True when the placement was pulled in from JobDiva. The override
 * affordances only render for these — direct-CoreFlux placements don't
 * have anything to "revert to".
 */
function isJobDivaSourced(placement) {
  const ext = placement?.external_id;
  return typeof ext === 'string' && ext.startsWith('jd:');
}

/** Pill shown next to fields that have been edited inside CoreFlux. */
function OverridePill({ field }) {
  return (
    <span
      data-testid={`override-pill-${field.replace(/_/g, '-')}`}
      title={`This field was edited in CoreFlux and is no longer synced from JobDiva. Click the field in Edit mode to revert.`}
      style={{
        marginLeft:    '6px',
        padding:       '1px 6px',
        fontSize:      '0.7em',
        fontWeight:    600,
        letterSpacing: '0.02em',
        textTransform: 'uppercase',
        color:         '#7c3a00',
        background:    '#ffe7c2',
        border:        '1px solid #ffb766',
        borderRadius:  '999px',
        verticalAlign: '2px',
      }}
    >
      overridden
    </span>
  );
}

function OverviewTab({ placement, reload }) {
  const [editing, setEditing] = useState(false);
  if (editing) return <OverviewEdit placement={placement} onClose={() => { setEditing(false); reload(); }} />;
  const overrides = parseOverrides(placement);
  const fromJD    = isJobDivaSourced(placement);
  const Item = ({ k, v, t, field, span }) => (
    <div style={span ? { gridColumn: `span ${span}` } : undefined}>
      <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em', display: 'block', marginBottom: 2 }}>
        {k}
        {fromJD && field && overrides.has(field) ? <OverridePill field={field} /> : null}
      </span>
      <span data-testid={t}>{v != null && v !== '' ? v : '—'}</span>
    </div>
  );

  // Person fields surfaced from the new placementGet() JOIN. Operator
  // complaint was that the detail page "doesn't even have the NAME?!"
  // — we now expose name, email, phone, classification, work auth,
  // plus a clickable link back to the person profile.
  const personName = [placement.person_first_name, placement.person_last_name].filter(Boolean).join(' ').trim();
  const personLink = placement.person_id ? `/modules/people/${placement.person_id}` : null;

  return (
    <div data-testid="tab-overview">
      <header style={{ display: 'flex', justifyContent: 'space-between' }}><h3>Overview</h3>
        <button className="btn" onClick={() => setEditing(true)} data-testid="placement-overview-edit">Edit</button>
      </header>

      {/* Section 1: Person on this placement — was missing entirely. */}
      <section data-testid="tab-overview-section-person" style={{ marginBottom: 'var(--cf-space-4)' }}>
        <h4 style={{ marginBottom: 'var(--cf-space-2)', color: '#475569', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Person</h4>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
          <Item k="Name"             v={personLink && personName
              ? <a href={personLink} data-testid="overview-person-name-link" style={{ color: '#1d4ed8', textDecoration: 'none' }}>{personName}</a>
              : (personName || (placement.person_id ? `Person #${placement.person_id}` : '—'))}
            t="overview-person-name" />
          <Item k="Email"            v={placement.person_email_primary
              ? <a href={`mailto:${placement.person_email_primary}`} style={{ color: '#1d4ed8' }}>{placement.person_email_primary}</a>
              : '—'}
            t="overview-person-email" />
          <Item k="Phone"            v={placement.person_phone_primary}              t="overview-person-phone" />
          <Item k="Work auth"        v={placement.person_work_auth_status}            t="overview-person-work-auth" />
          <Item k="Work auth expiry" v={placement.person_work_auth_expiry}            t="overview-person-work-auth-expiry" />
        </div>
      </section>

      {/* Section 2: Engagement (the original Overview) */}
      <section data-testid="tab-overview-section-engagement" style={{ marginBottom: 'var(--cf-space-4)' }}>
        <h4 style={{ marginBottom: 'var(--cf-space-2)', color: '#475569', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Engagement</h4>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
          <Item k="Title"            v={placement.title}            t="overview-title"       field="title" />
          <Item k="Worker classification" v={placement.engagement_type} t="overview-etype" field="engagement_type" />
          <Item k="Status"           v={placement.status}           t="overview-status"      field="status" />
          <Item k="Start"            v={placement.start_date}       t="overview-start"       field="start_date" />
          <Item k="End (planned)"    v={placement.end_date}         t="overview-end"         field="end_date" />
          <Item k="Actual end"       v={placement.actual_end_date}  t="overview-actual-end"  field="actual_end_date" />
          <Item k="Due"              v={placement.due_date}         t="overview-due"         field="due_date" />
          <Item k="Worksite"         v={[placement.worksite_state, placement.worksite_country].filter(Boolean).join(', ') || null} t="overview-site" />
          <Item k="Remote policy"    v={placement.remote_policy}    t="overview-remote"      field="remote_policy" />
          <Item k="External ID"      v={placement.external_id}      t="overview-external" />
        </div>
      </section>

      {/* Section 3: End client */}
      <section data-testid="tab-overview-section-client" style={{ marginBottom: 'var(--cf-space-4)' }}>
        <h4 style={{ marginBottom: 'var(--cf-space-2)', color: '#475569', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.05em' }}>End client &amp; approver</h4>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
          <Item k="End client"        v={placement.end_client_company_id
              ? <a href={`/modules/people/companies/${placement.end_client_company_id}`} data-testid="overview-end-client-link" style={{ color: '#1d4ed8', textDecoration: 'none' }}>
                  {placement.end_client_company_name || placement.end_client_name}
                </a>
              : placement.end_client_name}
            t="overview-client" field="end_client_name" />
          <Item k="Client website"    v={placement.end_client_company_website
              ? <a href={placement.end_client_company_website} target="_blank" rel="noreferrer" style={{ color: '#1d4ed8' }}>{placement.end_client_company_website}</a>
              : null}
            t="overview-client-website" />
          <Item k="Approver name"     v={placement.client_approver_name}  t="overview-approver-name" />
          <Item k="Approver email"    v={placement.client_approver_email
              ? <a href={`mailto:${placement.client_approver_email}`} style={{ color: '#1d4ed8' }}>{placement.client_approver_email}</a>
              : null}
            t="overview-approver-email" />
          <Item k="Tokenised email approvals"
            v={<span style={{ color: placement.tokenized_email_approval_enabled ? '#15803d' : '#94a3b8' }}>
                  {placement.tokenized_email_approval_enabled ? 'Enabled' : 'Off'}
               </span>}
            t="overview-token-email" />
          <Item k="Bulk pre-approval"
            v={<span style={{ color: placement.bulk_uploads_can_be_pre_approved ? '#15803d' : '#94a3b8' }}>
                  {placement.bulk_uploads_can_be_pre_approved ? 'Allowed' : 'Off'}
               </span>}
            t="overview-bulk-preapprove" />
        </div>
      </section>

      {/* Section 4: JobDiva metadata — only when sourced from JobDiva.
          Surfaces the assignment id, recruiter, account manager so the
          ops team doesn't have to flip back to JobDiva to see who owns
          this placement. */}
      {(fromJD || placement.jobdiva_job_id || placement.recruiter_name || placement.account_manager_name) && (
        <section data-testid="tab-overview-section-jobdiva" style={{ marginBottom: 'var(--cf-space-4)' }}>
          <h4 style={{ marginBottom: 'var(--cf-space-2)', color: '#475569', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
            JobDiva metadata
          </h4>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
            <Item k="JobDiva job ID"     v={placement.jobdiva_job_id}        t="overview-jd-job-id" field="jobdiva_job_id" />
            <Item k="Recruiter"          v={placement.recruiter_name}        t="overview-recruiter-name" field="recruiter_name" />
            <Item k="Recruiter email"    v={placement.recruiter_email
                ? <a href={`mailto:${placement.recruiter_email}`} style={{ color: '#1d4ed8' }}>{placement.recruiter_email}</a>
                : null}
              t="overview-recruiter-email" field="recruiter_email" />
            <Item k="Account manager"    v={placement.account_manager_name}  t="overview-am-name" field="account_manager_name" />
            <Item k="AM email"           v={placement.account_manager_email
                ? <a href={`mailto:${placement.account_manager_email}`} style={{ color: '#1d4ed8' }}>{placement.account_manager_email}</a>
                : null}
              t="overview-am-email" field="account_manager_email" />
          </div>
        </section>
      )}

      {/* Section 5: Notes — full width, multi-line. */}
      <section data-testid="tab-overview-section-notes">
        <h4 style={{ marginBottom: 'var(--cf-space-2)', color: '#475569', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Notes</h4>
        <div style={{ whiteSpace: 'pre-wrap', color: placement.notes ? 'inherit' : '#94a3b8', fontSize: 14 }} data-testid="overview-notes">
          {placement.notes || '— no notes —'}
        </div>
      </section>
    </div>
  );
}
function OverviewEdit({ placement, onClose }) {
  const [form, setForm] = useState(placement);
  const [saving, setSaving] = useState(false);
  const [reverting, setReverting] = useState(null);   // current field being reverted, for spinner
  const [error, setError] = useState(null);
  // Local copy of the override set — kept in sync with the server after
  // every clear_override call so the UI updates without a full reload.
  const [overrides, setOverrides] = useState(() => parseOverrides(placement));
  const fromJD = isJobDivaSourced(placement);
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
  /**
   * Drop one field from the placement's coreflux_overridden_fields list.
   * The next JobDiva sync will then refresh that column from upstream.
   */
  const revert = async (field) => {
    setReverting(field); setError(null);
    try {
      const resp = await api.post(
        `/modules/placements/api/placements.php?id=${placement.id}&action=clear_override`,
        { fields: [field] }
      );
      // Server returns the updated placement; re-parse the new override list.
      setOverrides(parseOverrides(resp?.placement ?? {}));
    } catch (e) {
      setError(e);
    } finally {
      setReverting(null);
    }
  };
  /** Renders a "Revert to JobDiva" pill under a field when it's overridden. */
  const RevertControl = ({ field }) => {
    if (!fromJD || !overrides.has(field)) return null;
    const isBusy = reverting === field;
    return (
      <button
        type="button"
        onClick={() => revert(field)}
        disabled={isBusy}
        data-testid={`revert-${field.replace(/_/g, '-')}`}
        title="Drop the CoreFlux override on this field — next JobDiva sync will refresh it from upstream."
        style={{
          marginTop:    '4px',
          alignSelf:    'flex-start',
          padding:      '2px 8px',
          fontSize:     '0.72em',
          fontWeight:   500,
          color:        '#7c3a00',
          background:   'transparent',
          border:       '1px solid #ffb766',
          borderRadius: '999px',
          cursor:       isBusy ? 'wait' : 'pointer',
          opacity:      isBusy ? 0.6 : 1,
        }}
      >
        {isBusy ? 'Reverting…' : '↻ Revert to JobDiva'}
      </button>
    );
  };
  return (
    <div data-testid="tab-overview-edit">
      <h3>Edit overview</h3>
      {fromJD && (
        <p style={{ fontSize: '0.85em', color: 'var(--cf-text-secondary)', marginBottom: 'var(--cf-space-3)' }} data-testid="overview-edit-jd-banner">
          This placement was pulled from JobDiva. Fields you edit here will be
          marked <em>overridden</em> and skipped by future JobDiva syncs. Use
          the <strong>Revert</strong> button under any overridden field to give
          control back to JobDiva.
        </p>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 'var(--cf-space-3)' }}>
        {[['title','Title'],['end_client_name','End client'],['external_id','External ID'],
          ['worksite_state','State'],['worksite_country','Country (2)'],['notes','Notes']].map(([k, l]) => (
          <label key={k} style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>
              {l}
              {fromJD && overrides.has(k) ? <OverridePill field={k} /> : null}
            </span>
            <input className="input" value={form[k] ?? ''} onChange={set(k)} data-testid={`overview-edit-${k.replace(/_/g,'-')}`} />
            <RevertControl field={k} />
          </label>
        ))}
        {[['start_date','Start'],['end_date','End'],['due_date','Due']].map(([k, l]) => (
          <label key={k} style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>
              {l}
              {fromJD && overrides.has(k) ? <OverridePill field={k} /> : null}
            </span>
            <input className="input" type="date" value={form[k] ?? ''} onChange={set(k)} data-testid={`overview-edit-${k.replace(/_/g,'-')}`} />
            <RevertControl field={k} />
          </label>
        ))}
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>
            Status
            {fromJD && overrides.has('status') ? <OverridePill field="status" /> : null}
          </span>
          <select className="input" value={form.status} onChange={set('status')} data-testid="overview-edit-status">
            {['draft','pending_start','active','on_hold','ended','cancelled'].map(s => <option key={s} value={s}>{s}</option>)}
          </select>
          <RevertControl field="status" />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>
            Engagement type
            {fromJD && overrides.has('engagement_type') ? <OverridePill field="engagement_type" /> : null}
          </span>
          <select className="input" value={form.engagement_type} onChange={set('engagement_type')} data-testid="overview-edit-etype">
            {['w2','1099','c2c','temp_to_perm','direct_hire'].map(s => <option key={s} value={s}>{s}</option>)}
          </select>
          <RevertControl field="engagement_type" />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>
            Remote
            {fromJD && overrides.has('remote_policy') ? <OverridePill field="remote_policy" /> : null}
          </span>
          <select className="input" value={form.remote_policy ?? ''} onChange={set('remote_policy')} data-testid="overview-edit-remote">
            <option value="">—</option><option value="onsite">onsite</option><option value="hybrid">hybrid</option><option value="remote">remote</option>
          </select>
          <RevertControl field="remote_policy" />
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
function EconomicsTab({ placement, chain, commissions, referrals, reload }) {
  const path = `/modules/placements/api/economics.php?placement_id=${placement.id}`;
  const { data, loading, error, reload: reloadEconomics } = useApi(path);
  const parties = data?.parties || [];
  const readiness = data?.readiness || {};
  const model = data?.model || {};
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [partyCompany, setPartyCompany] = useState(null);
  const [partyRecipientType, setPartyRecipientType] = useState('company');
  const [partyPersonSearch, setPartyPersonSearch] = useState('');
  const usersLookup = useApi('/api/users.php');
  const tenantUsers = usersLookup.data?.users || usersLookup.data?.rows || [];
  const [partyForm, setPartyForm] = useState({ role: 'vendor', settlement_channel: 'ap', fee_basis: 'none', fee_pct: '', fee_flat: '', cadence: 'biweekly', payment_terms: 'NET30', pwp_enabled: false });
  const partyPersonLookup = useApi(partyRecipientType === 'person' && partyPersonSearch.length >= 2 && !partyForm.person_id
    ? `/modules/people/api/people.php?q=${encodeURIComponent(partyPersonSearch)}&per_page=10`
    : null);

  const refreshAll = () => { reloadEconomics(); reload(); };
  const patchParty = async (id, changes) => {
    setBusy(true); setMessage('');
    try {
      await api.patch(`/modules/placements/api/economics.php?id=${id}`, changes);
      setMessage('Economic terms saved.'); refreshAll();
    } catch (e) { setMessage(`Save failed: ${e.message}`); }
    finally { setBusy(false); }
  };
  const removeParty = async (party) => {
    if (!window.confirm(`Remove ${party.display_name} from this placement's economics?`)) return;
    setBusy(true); setMessage('');
    try {
      await api.delete(`/modules/placements/api/economics.php?id=${party.id}`);
      setMessage('Participant removed.'); refreshAll();
    } catch (e) { setMessage(`Remove failed: ${e.message}`); }
    finally { setBusy(false); }
  };
  const addParty = async (e) => {
    e.preventDefault();
    const selectedUser = tenantUsers.find((user) => Number(user.id) === Number(partyForm.user_id));
    if (partyRecipientType === 'company' && !partyCompany?.name) { setMessage('Choose or create a company first.'); return; }
    if (partyRecipientType === 'user' && !selectedUser) { setMessage('Choose an internal user first.'); return; }
    if (partyRecipientType === 'person' && !partyForm.person_id) { setMessage('Choose a person first.'); return; }
    setBusy(true); setMessage('');
    try {
      await api.post(`${path}&action=party`, {
        ...partyForm,
        company_id: partyRecipientType === 'company' ? (partyCompany?.id || null) : null,
        person_id: partyRecipientType === 'person' ? Number(partyForm.person_id) : null,
        user_id: partyRecipientType === 'user' ? Number(partyForm.user_id) : null,
        display_name: partyRecipientType === 'company'
          ? partyCompany.name
          : partyRecipientType === 'user'
            ? (selectedUser.name || selectedUser.email)
            : partyPersonSearch,
        fee_pct: partyForm.fee_pct === '' ? null : Number(partyForm.fee_pct),
        fee_flat: partyForm.fee_flat === '' ? null : Number(partyForm.fee_flat),
      });
      setPartyCompany(null);
      setPartyRecipientType('company');
      setPartyPersonSearch('');
      setPartyForm({ role: 'vendor', settlement_channel: 'ap', fee_basis: 'none', fee_pct: '', fee_flat: '', cadence: 'biweekly', payment_terms: 'NET30', pwp_enabled: false });
      setMessage('Participant added and normalized to the shared company/vendor graph.'); refreshAll();
    } catch (e) { setMessage(`Add failed: ${e.message}`); }
    finally { setBusy(false); }
  };
  const arTermsOptions = ['DUE_ON_RECEIPT','NET7','NET10','NET15','NET30','NET45','NET60','NET90'];
  const apTermsOptions = [...arTermsOptions, 'PWP','PWP_NET7','PWP_NET10','PWP_NET15','PWP_NET30','PWP_NET45','PWP_NET60','PWP_NET90'];
  const frequencyOptions = ['weekly','biweekly','semimonthly','monthly','adhoc'];
  const frequencyLabel = { weekly: 'Weekly', biweekly: 'Bi-weekly', semimonthly: 'Semi-monthly', monthly: 'Monthly', adhoc: 'As needed' };
  const termsLabel = (term) => term === 'DUE_ON_RECEIPT' ? 'Due on receipt' : term === 'PWP' ? 'Paid when paid' : term.startsWith('PWP_NET') ? `Paid when paid + ${term.slice(7)} days` : term.replace('NET', 'Net ');
  const partyRoleOptions = partyForm.settlement_channel === 'ar'
    ? ['end_client', 'client']
    : partyForm.settlement_channel === 'payroll'
      ? ['worker', 'commission_recipient', 'recruiter', 'account_manager', 'referrer', 'other']
      : ['vendor', ...(placement.engagement_type === 'c2c' ? ['c2c_vendor'] : []), 'worker', 'msp', 'prime_vendor', 'sub_vendor', 'referrer', 'other'];
  const readinessProblems = [
    readiness.missing_receivable_party && 'Client billing recipient',
    readiness.multiple_receivable_parties && 'Choose one bill-to client',
    readiness.missing_approved_rate && 'Approved rate',
    readiness.missing_payable_party && 'Worker or vendor payee',
    readiness.missing_labor_payee && 'Primary labor payee',
    readiness.multiple_labor_payees && 'Resolve multiple primary labor payees',
    readiness.missing_c2c_vendor && 'C2C corporate vendor',
    readiness.missing_billing_cycle && 'Client billing frequency', readiness.missing_ap_cycle && 'Vendor payment frequency',
    readiness.missing_payroll_cycle && 'Payroll frequency',
    readiness.missing_ar_payment_terms && 'Client payment terms',
    readiness.missing_ap_payment_terms && 'Vendor payment terms',
    readiness.unresolved_parties > 0 && `${readiness.unresolved_parties} unresolved recipient(s)`,
  ].filter(Boolean);
  if (loading) return <p>Loading placement economics...</p>;
  if (error) return <p className="error">Error: {error.message}</p>;

  return (
    <div data-testid="tab-economics">
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 16, alignItems: 'baseline', flexWrap: 'wrap' }}>
        <div><h3 style={{ margin: 0 }}>Placement economics</h3><p style={{ color: 'var(--cf-text-secondary)', margin: '4px 0 0' }}>Every client, worker, vendor, referrer, and commission recipient involved in this engagement.</p></div>
        <span className={`badge badge--${readiness.ready ? 'active' : 'candidate'}`} data-testid="economics-readiness">{readiness.ready ? 'Ready for settlement' : `${readinessProblems.length} setup item${readinessProblems.length === 1 ? '' : 's'}`}</span>
      </header>
      {!readiness.ready && <div className="alert alert--warn" style={{ marginTop: 12 }}>Complete: {readinessProblems.join(', ') || 'economic setup'}.</div>}
      {message && <div className={message.includes('failed') ? 'alert alert--err' : 'alert alert--ok'} style={{ marginTop: 12 }}>{message}</div>}

      {model.available && <section style={{ marginTop: 24 }} data-testid="economics-model-summary">
        <h4>Modeled hourly economics</h4>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
          {[['Bill rate', model.bill_rate], ['All hourly costs', model.modeled_hourly_cost], ['Modeled margin', model.modeled_hourly_margin]].map(([label, value]) => <div key={label}><span style={{ display: 'block', fontSize: 12, color: 'var(--cf-text-secondary)' }}>{label}</span><strong>{model.currency} {Number(value).toFixed(2)} / hour</strong></div>)}
          <div><span style={{ display: 'block', fontSize: 12, color: 'var(--cf-text-secondary)' }}>Margin</span><strong>{(Number(model.modeled_margin_pct) * 100).toFixed(2)}%</strong></div>
          <div><span style={{ display: 'block', fontSize: 12, color: 'var(--cf-text-secondary)' }}>Fixed obligations</span><strong>{model.currency} {Number(model.fixed_obligations).toFixed(2)}</strong></div>
        </div>
        <details style={{ marginTop: 10 }}><summary style={{ cursor: 'pointer' }}>Cost breakdown</summary><table className="data-table" style={{ marginTop: 8 }}><thead><tr><th>Recipient or cost</th><th>Basis</th><th>Channel</th><th>Amount</th></tr></thead><tbody>{[...(model.hourly_lines || []), ...(model.fixed_lines || [])].map((line, index) => <tr key={`${line.role}-${index}`}><td>{line.name}</td><td>{line.basis.replace(/_/g, ' ')}</td><td>{line.settlement_channel}</td><td>{model.currency} {Number(line.amount).toFixed(2)}{(model.hourly_lines || []).includes(line) ? ' / hour' : ''}</td></tr>)}</tbody></table></details>
      </section>}

      <section style={{ marginTop: 24 }}>
        <h4>Commercial terms by participant</h4>
        <table className="data-table" data-testid="economics-parties-table">
          <thead><tr><th>Participant</th><th>Role</th><th>Flow</th><th>Calculation</th><th>Billing / payment frequency</th><th>Payment terms</th><th aria-label="Actions" /></tr></thead>
          <tbody>
            {parties.length === 0 && <tr><td colSpan={7} className="empty">No participants resolved.</td></tr>}
            {parties.map((party) => <tr key={party.id} data-testid={`economics-party-${party.id}`}>
              <td><strong>{party.display_name}</strong><div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{party.company_id ? `Company #${party.company_id}${party.ap_vendor_id ? ` / Vendor #${party.ap_vendor_id}` : ''}` : party.person_id ? `Person #${party.person_id}` : party.source_type}</div></td>
              <td>{party.role.replace(/_/g, ' ')}</td>
              <td>{party.settlement_channel === 'ar' ? 'Receivable' : party.settlement_channel === 'ap' ? 'Accounts payable' : party.settlement_channel === 'payroll' ? 'Payroll' : 'Informational'}</td>
              <td>{party.fee_basis.replace(/_/g, ' ')}{party.fee_pct ? ` (${(Number(party.fee_pct) * 100).toFixed(2)}%)` : ''}{party.fee_flat ? ` ($${Number(party.fee_flat).toFixed(2)})` : ''}</td>
              <td>{party.settlement_channel !== 'none' ? <select className="input" value={party.cycle_cadence || ''} disabled={busy} onChange={(e) => patchParty(party.id, { cadence: e.target.value })} aria-label={`Frequency for ${party.display_name}`}><option value="" disabled>Choose frequency</option>{frequencyOptions.map((value) => <option key={value} value={value}>{frequencyLabel[value]}</option>)}</select> : '-'}</td>
              <td>{['ar','ap'].includes(party.settlement_channel) ? <select className="input" value={party.payment_terms || party.vendor_default_terms || 'NET30'} disabled={busy} onChange={(e) => patchParty(party.id, { payment_terms: e.target.value, pwp_enabled: e.target.value.startsWith('PWP') })} aria-label={`Payment terms for ${party.display_name}`}>{(party.settlement_channel === 'ar' ? arTermsOptions : apTermsOptions).map((term) => <option key={term} value={term}>{termsLabel(term)}</option>)}</select> : party.settlement_channel === 'payroll' ? 'Paid through payroll' : '-'}</td>
              <td>{!Number(party.source_managed) && <button type="button" className="btn btn--sm" disabled={busy} onClick={() => removeParty(party)}>Remove</button>}</td>
            </tr>)}
          </tbody>
        </table>
      </section>

      <section style={{ marginTop: 28 }}>
        <h4>Add participant</h4>
        <form onSubmit={addParty} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 8, alignItems: 'end' }}>
          <label><span style={{ display: 'block', fontSize: 12 }}>Recipient type</span><select className="input" value={partyRecipientType} onChange={(e) => {
            const type = e.target.value;
            const settlementChannel = type === 'user' ? 'payroll' : 'ap';
            setPartyRecipientType(type);
            setPartyCompany(null);
            setPartyPersonSearch('');
            setPartyForm({
              ...partyForm,
              company_id: '', person_id: '', user_id: '',
              settlement_channel: settlementChannel,
              role: type === 'company' ? 'vendor' : type === 'person' ? 'worker' : 'commission_recipient',
              cadence: 'biweekly', payment_terms: 'NET30', pwp_enabled: false,
            });
          }}><option value="company">Company or vendor</option><option value="person">Person</option><option value="user">Internal user</option></select></label>
          {partyRecipientType === 'company' && <div><span style={{ display: 'block', fontSize: 12 }}>Company</span><CompanyTypeahead role={partyForm.settlement_channel === 'ar' ? 'client' : 'vendor'} value={partyCompany} onChange={setPartyCompany} placeholder="Search or create company" testId="economics-company" /></div>}
          {partyRecipientType === 'user' && <label><span style={{ display: 'block', fontSize: 12 }}>Internal user</span><select className="input" required value={partyForm.user_id || ''} onChange={(e) => setPartyForm({ ...partyForm, user_id: e.target.value })}><option value="">Choose user</option>{tenantUsers.map((user) => <option key={user.id} value={user.id}>{user.name || user.email}</option>)}</select></label>}
          {partyRecipientType === 'person' && <div style={{ position: 'relative' }}><span style={{ display: 'block', fontSize: 12 }}>Person</span><input className="input" required value={partyPersonSearch} onChange={(e) => { setPartyPersonSearch(e.target.value); setPartyForm({ ...partyForm, person_id: '' }); }} placeholder="Search people" />{!partyForm.person_id && (partyPersonLookup.data?.rows || []).length > 0 && <div style={{ position: 'absolute', zIndex: 20, top: '100%', left: 0, right: 0, background: 'white', border: '1px solid var(--cf-border)', maxHeight: 180, overflowY: 'auto' }}>{(partyPersonLookup.data?.rows || []).map((person) => <button type="button" key={person.id} onClick={() => { setPartyForm({ ...partyForm, person_id: person.id }); setPartyPersonSearch(`${person.first_name} ${person.last_name}`); }} style={{ display: 'block', width: '100%', padding: 8, textAlign: 'left', border: 0, background: 'white', cursor: 'pointer' }}>{person.first_name} {person.last_name} ({person.email_primary})</button>)}</div>}</div>}
          <label><span style={{ display: 'block', fontSize: 12 }}>Role</span><select className="input" value={partyForm.role} onChange={(e) => setPartyForm({ ...partyForm, role: e.target.value, fee_basis: e.target.value === 'c2c_vendor' ? 'pay_rate' : partyForm.fee_basis })}>{partyRoleOptions.map((value) => <option key={value} value={value}>{value.replace(/_/g, ' ')}</option>)}</select></label>
          <label><span style={{ display: 'block', fontSize: 12 }}>Money flow</span><select className="input" value={partyForm.settlement_channel} onChange={(e) => {
            const settlementChannel = e.target.value;
            const role = settlementChannel === 'ar'
              ? 'end_client'
              : settlementChannel === 'payroll'
                ? (partyRecipientType === 'user' ? 'commission_recipient' : 'worker')
                : ['end_client', 'client'].includes(partyForm.role) ? 'vendor' : partyForm.role;
            setPartyForm({
              ...partyForm,
              settlement_channel: settlementChannel,
              role,
              cadence: settlementChannel === 'ar' ? 'monthly' : 'biweekly',
              payment_terms: 'NET30', pwp_enabled: false,
            });
          }}>{partyRecipientType === 'company' && Number(readiness.receivable_parties || 0) === 0 && <option value="ar">Client receivable</option>}{partyRecipientType !== 'user' && <option value="ap">Vendor payable</option>}{partyRecipientType !== 'company' && <option value="payroll">Employee payroll</option>}<option value="none">Informational only</option></select></label>
          <label><span style={{ display: 'block', fontSize: 12 }}>Calculation</span><select className="input" value={partyForm.fee_basis} onChange={(e) => setPartyForm({ ...partyForm, fee_basis: e.target.value })}>{['none','pay_rate','per_hour','per_invoice','one_time','pct_bill','pct_margin','flat'].map((value) => <option key={value}>{value.replace(/_/g, ' ')}</option>)}</select></label>
          <label><span style={{ display: 'block', fontSize: 12 }}>Percent (decimal)</span><input className="input" type="number" step="0.0001" value={partyForm.fee_pct} onChange={(e) => setPartyForm({ ...partyForm, fee_pct: e.target.value })} placeholder="0.10 = 10%" /></label>
          <label><span style={{ display: 'block', fontSize: 12 }}>Flat or hourly amount</span><input className="input" type="number" step="0.01" value={partyForm.fee_flat} onChange={(e) => setPartyForm({ ...partyForm, fee_flat: e.target.value })} placeholder="0.00" /></label>
          {partyForm.settlement_channel !== 'none' && <label><span style={{ display: 'block', fontSize: 12 }}>{partyForm.settlement_channel === 'ar' ? 'Client billing frequency' : partyForm.settlement_channel === 'ap' ? 'Vendor payment frequency' : 'Payroll frequency'}</span><select className="input" value={partyForm.cadence} onChange={(e) => setPartyForm({ ...partyForm, cadence: e.target.value })}>{frequencyOptions.map((value) => <option key={value} value={value}>{frequencyLabel[value]}</option>)}</select></label>}
          {['ar','ap'].includes(partyForm.settlement_channel) && <label><span style={{ display: 'block', fontSize: 12 }}>Payment terms</span><select className="input" value={partyForm.payment_terms} onChange={(e) => setPartyForm({ ...partyForm, payment_terms: e.target.value, pwp_enabled: e.target.value.startsWith('PWP') })}>{(partyForm.settlement_channel === 'ar' ? arTermsOptions : apTermsOptions).map((term) => <option key={term} value={term}>{termsLabel(term)}</option>)}</select></label>}
          <button className="btn" disabled={busy}>Add participant</button>
        </form>
      </section>

      <details style={{ marginTop: 28 }}><summary style={{ cursor: 'pointer', fontWeight: 600 }}>Source details and documents</summary>
        <div style={{ marginTop: 20 }}><ChainTab pid={placement.id} chain={chain} reload={refreshAll} /></div>
        <div style={{ marginTop: 28 }}><ReferralsTab pid={placement.id} rows={referrals} reload={refreshAll} /></div>
        <div style={{ marginTop: 28 }}><CommissionsTab pid={placement.id} rows={commissions} reload={refreshAll} /></div>
        {placement.engagement_type === 'c2c' && <div style={{ marginTop: 28 }}><CorpTab pid={placement.id} /></div>}
      </details>
    </div>
  );
}

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
    // No more "Is this a correction?" popup. The server auto-detects
    // a correction (any prior approved row on this placement) and
    // generates a default reason. Operators who genuinely need a
    // custom reason can still PATCH the audit row afterwards — UI
    // doesn't gate that on a confirm dialog.
    try {
      const res = await api.post(`/modules/placements/api/rates.php?action=approve&id=${rateId}`, {});
      if (res?.auto_correction) {
        // Quiet inline confirmation rather than another modal.
        console.info(`Rate ${rateId} approved as a correction (auto-detected supersede).`);
      }
      reload();
    } catch (e) { alert(`Approve failed: ${e.message}`); }
  };

  const approveAllDrafts = async () => {
    // Catch-up button for the (frequent) case where a placement was
    // promoted from draft BEFORE the auto-approve side effect shipped
    // — or where the operator at promotion time didn't have the
    // financials.approve permission. One click here re-runs the same
    // server-side helper and approves every draft rate on the
    // placement at once.
    try {
      const res = await api.post(`/modules/placements/api/rates.php?action=approve_all_for_placement&placement_id=${pid}`, {});
      console.info(`Approved ${res?.approved ?? 0} draft rate(s) on placement ${pid}`);
      reload();
    } catch (e) { alert(`Approve all failed: ${e.message}`); }
  };

  const draftCount = rates.filter(r => !r.approved_at).length;

  return (
    <div data-testid="tab-rates">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', flexWrap: 'wrap', gap: 'var(--cf-space-2)' }}>
        <div>
          <h3 style={{ margin: 0 }}>Rates</h3>
          <p style={{ color: 'var(--cf-text-secondary)', margin: '4px 0 0' }}>
            Drafts can be edited; approved rates are locked (snapshot).
          </p>
        </div>
        {draftCount > 0 && (
          <button
            type="button"
            className="btn btn--primary"
            onClick={approveAllDrafts}
            data-testid="rates-approve-all-drafts"
            title="Approve every draft rate on this placement in one click. Uses the same chain-based margin snapshot + audit as per-row approval."
          >
            Approve all {draftCount} draft{draftCount === 1 ? '' : 's'}
          </button>
        )}
      </header>
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
  const usersLookup = useApi('/api/users.php');
  const tenantUsers = usersLookup.data?.users || usersLookup.data?.rows || [];
  const emptyForm = () => ({ role: 'recruiter', user_id: '', split_pct: '', basis: 'net_margin', effective_from: new Date().toISOString().slice(0,10) });
  const [form, setForm] = useState(emptyForm);
  const [error, setError] = useState(null);
  const add = async (e) => {
    e.preventDefault(); setError(null);
    try {
      await api.post(`/modules/placements/api/commissions.php?placement_id=${pid}`, {
        ...form, split_pct: form.split_pct ? parseFloat(form.split_pct) : null,
      });
      setForm(emptyForm());
      reload();
    } catch (e) { setError(e); }
  };
  const patchRecipient = async (id, userId) => {
    setError(null);
    try { await api.patch(`/modules/placements/api/commissions.php?id=${id}`, { user_id: Number(userId) }); reload(); }
    catch (e) { setError(e); }
  };
  const del = async (id) => { if (!confirm('Remove split?')) return; await api.delete(`/modules/placements/api/commissions.php?id=${id}`); reload(); };
  return (
    <div data-testid="tab-commissions">
      <h3>Commission splits</h3>
      <table className="data-table" data-testid="commissions-table">
        <thead><tr><th>Recipient</th><th>Role</th><th>Split</th><th>Basis</th><th>From</th><th>To</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={7} className="empty" data-testid="commissions-empty">No splits.</td></tr>}
          {rows.map(c => (
            <tr key={c.id} data-testid={`commission-row-${c.id}`}>
              <td>
                <select className="input" value={c.user_id || ''} onChange={e => patchRecipient(c.id, e.target.value)} aria-label={`Commission recipient for ${c.role}`}>
                  <option value="">Choose recipient</option>
                  {tenantUsers.map(u => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}
                </select>
              </td>
              <td>{c.role}</td><td>{c.split_pct ? `${(c.split_pct * 100).toFixed(2)}%` : c.flat_amount ? `$${c.flat_amount}` : '—'}</td>
              <td>{c.basis}</td><td>{c.effective_from}</td><td>{c.effective_to || '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => del(c.id)} data-testid={`commission-delete-${c.id}`}>Remove</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <form onSubmit={add} style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }} data-testid="commissions-add-form">
        <select className="input" required value={form.user_id} onChange={e => setForm({ ...form, user_id: e.target.value })} data-testid="commission-recipient">
          <option value="">Choose recipient</option>
          {tenantUsers.map(u => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}
        </select>
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
  const usersLookup = useApi('/api/users.php');
  const tenantUsers = usersLookup.data?.users || usersLookup.data?.rows || [];
  const emptyForm = () => ({ referrer_type: 'vendor', referrer_vendor_name: '', referrer_company_id: '', referrer_person_id: '', referrer_user_id: '', fee_basis: 'pct_bill', fee_pct: '', fee_flat: '', start_date: new Date().toISOString().slice(0,10), duration_months: '', payment_terms_override: 'NET30', pwp_enabled: false });
  const [form, setForm] = useState(emptyForm);
  const [referrerCompany, setReferrerCompany] = useState(null);
  const [personSearch, setPersonSearch] = useState('');
  const personLookup = useApi(form.referrer_type === 'person' && personSearch.length >= 2 && !form.referrer_person_id
    ? `/modules/people/api/people.php?q=${encodeURIComponent(personSearch)}&per_page=10`
    : null);
  const [error, setError] = useState(null);
  const add = async (e) => {
    e.preventDefault(); setError(null);
    try {
      const payload = {
        ...form,
        referrer_company_id: referrerCompany?.id || null,
        referrer_vendor_name: referrerCompany?.name || form.referrer_vendor_name || null,
        fee_pct: form.fee_pct ? parseFloat(form.fee_pct) : null,
        fee_flat: form.fee_flat ? parseFloat(form.fee_flat) : null,
        duration_months: form.duration_months ? parseInt(form.duration_months, 10) : null,
      };
      await api.post(`/modules/placements/api/referrals.php?placement_id=${pid}`, payload);
      setForm(emptyForm());
      setReferrerCompany(null);
      setPersonSearch('');
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
              <td>{r.referrer_name || r.referrer_vendor_name || `#${r.referrer_person_id || r.referrer_user_id}`}</td>
              <td>{r.fee_pct ? `${(r.fee_pct * 100).toFixed(2)}%` : r.fee_flat ? `$${r.fee_flat}` : '—'}</td>
              <td>{r.fee_basis}</td><td>{r.start_date}</td><td>{r.duration_months ? `${r.duration_months}mo` : '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => del(r.id)} data-testid={`referral-delete-${r.id}`}>Remove</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <form onSubmit={add} style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }} data-testid="referrals-add-form">
        <select className="input" value={form.referrer_type} onChange={e => { setForm({ ...emptyForm(), referrer_type: e.target.value }); setReferrerCompany(null); setPersonSearch(''); }} data-testid="referral-type">
          <option value="vendor">vendor</option><option value="person">person</option><option value="user">user</option>
        </select>
        {form.referrer_type === 'vendor' && <div style={{ minWidth: 260 }}><CompanyTypeahead role="referrer" value={referrerCompany} onChange={(company) => { setReferrerCompany(company); setForm({ ...form, referrer_company_id: company?.id || '', referrer_vendor_name: company?.name || '' }); }} placeholder="Search or create vendor" testId="referral-company" /></div>}
        {form.referrer_type === 'user' && <select className="input" required value={form.referrer_user_id} onChange={e => setForm({ ...form, referrer_user_id: e.target.value })} data-testid="referral-user"><option value="">Choose user</option>{tenantUsers.map(u => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}</select>}
        {form.referrer_type === 'person' && <div style={{ position: 'relative', minWidth: 260 }}>
          <input className="input" required value={personSearch} onChange={e => { setPersonSearch(e.target.value); setForm({ ...form, referrer_person_id: '' }); }} placeholder="Search people" data-testid="referral-person-search" />
          {!form.referrer_person_id && (personLookup.data?.rows || []).length > 0 && <div style={{ position: 'absolute', zIndex: 20, top: '100%', left: 0, right: 0, background: 'white', border: '1px solid var(--cf-border)', maxHeight: 180, overflowY: 'auto' }}>
            {(personLookup.data?.rows || []).map(person => <button type="button" key={person.id} onClick={() => { setForm({ ...form, referrer_person_id: person.id }); setPersonSearch(`${person.first_name} ${person.last_name}`); }} style={{ display: 'block', width: '100%', padding: 8, textAlign: 'left', border: 0, background: 'white', cursor: 'pointer' }}>{person.first_name} {person.last_name} ({person.email_primary})</button>)}
          </div>}
        </div>}
        <select className="input" value={form.fee_basis} onChange={e => setForm({ ...form, fee_basis: e.target.value })} data-testid="referral-basis">
          {['per_hour','per_invoice','one_time','pct_bill','pct_margin'].map(b => <option key={b} value={b}>{b}</option>)}
        </select>
        <input className="input" type="number" step="0.0001" placeholder="0.10 = 10%" value={form.fee_pct} onChange={e => setForm({ ...form, fee_pct: e.target.value })} data-testid="referral-fee" />
        <input className="input" type="number" step="0.01" placeholder="Flat amount" value={form.fee_flat} onChange={e => setForm({ ...form, fee_flat: e.target.value })} data-testid="referral-flat" />
        <input className="input" type="number" placeholder="Months" value={form.duration_months} onChange={e => setForm({ ...form, duration_months: e.target.value })} style={{ width: '90px' }} data-testid="referral-months" />
        <input className="input" type="date" value={form.start_date} onChange={e => setForm({ ...form, start_date: e.target.value })} data-testid="referral-start" />
        <select className="input" value={form.payment_terms_override} onChange={e => setForm({ ...form, payment_terms_override: e.target.value, pwp_enabled: e.target.value.startsWith('PWP') })} data-testid="referral-terms">{['DUE_ON_RECEIPT','NET7','NET10','NET15','NET30','NET45','NET60','NET90','PWP','PWP_NET7','PWP_NET10','PWP_NET15','PWP_NET30','PWP_NET45','PWP_NET60','PWP_NET90'].map(term => <option key={term} value={term}>{term === 'PWP' ? 'Paid when paid' : term.startsWith('PWP_NET') ? `Paid when paid + ${term.slice(7)} days` : term.replace('DUE_ON_RECEIPT', 'Due on receipt').replace('NET', 'Net ')}</option>)}</select>
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
