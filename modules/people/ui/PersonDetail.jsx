import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate, NavLink, Routes, Route, Navigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Person Detail — 7 tabs per SPEC §6:
 *   1. Overview, 2. Placements, 3. Documents, 4. Skills,
 *   5. Pipeline history, 6. Compliance, 7. PII (gated)
 */
export default function PersonDetail({ session }) {
  const { personId } = useParams();
  const nav = useNavigate();
  const path = `/modules/people/api/people.php?id=${personId}`;
  const { data, loading, error, reload } = useApi(path);
  const person = data?.person;

  const role = session?.user?.global_role || session?.user?.role;
  const canSeePII = role === 'master_admin' || role === 'tenant_admin' || role === 'admin';

  if (loading) return <p data-testid="person-detail-loading">Loading…</p>;
  if (error)   return <p className="error" data-testid="person-detail-error">Error: {error.message}</p>;
  if (!person) return <p data-testid="person-detail-empty">Person not found.</p>;

  const TABS = [
    { slug: 'overview',   label: 'Overview' },
    { slug: 'placements', label: 'Placements' },
    { slug: 'documents',  label: 'Documents' },
    { slug: 'skills',     label: 'Skills' },
    { slug: 'pipeline',   label: 'Pipeline' },
    { slug: 'compliance', label: 'Compliance' },
    ...(canSeePII ? [{ slug: 'pii', label: 'PII' }] : []),
  ];

  return (
    <section className="person-detail" data-testid="person-detail">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
        <div>
          <button onClick={() => nav('..')} className="btn btn--ghost" data-testid="person-detail-back">← Directory</button>
          <h2 data-testid="person-detail-name" style={{ marginTop: '0.5rem' }}>
            {person.preferred_name || person.first_name} {person.last_name}
          </h2>
          <p style={{ color: '#666' }}>
            <span data-testid="person-detail-classification" className={`badge badge--${person.classification}`}>{person.classification}</span>
            {' '}
            <span data-testid="person-detail-status" className={`badge badge--${person.status}`}>{person.status}</span>
            {' · '}
            <span data-testid="person-detail-email">{person.email_primary}</span>
          </p>
        </div>
      </header>

      <nav className="person-detail__tabs" data-testid="person-detail-tabs" style={{ display: 'flex', gap: '0.5rem', borderBottom: '1px solid #ddd', marginBottom: '1rem' }}>
        {TABS.map(t => (
          <NavLink
            key={t.slug}
            to={t.slug}
            className={({ isActive }) => `tab ${isActive ? 'tab--active' : ''}`}
            data-testid={`person-detail-tab-${t.slug}`}
            style={({ isActive }) => ({
              padding: '0.5rem 1rem',
              borderBottom: isActive ? '2px solid #2D62E0' : '2px solid transparent',
              textDecoration: 'none',
              color: isActive ? '#2D62E0' : '#444',
              fontWeight: isActive ? 600 : 400,
            })}
          >
            {t.label}
          </NavLink>
        ))}
      </nav>

      <Routes>
        <Route index            element={<Navigate to="overview" replace />} />
        <Route path="overview"  element={<OverviewTab person={person} reload={reload} />} />
        <Route path="placements"element={<PlacementsTab personId={person.id} />} />
        <Route path="documents" element={<DocumentsTab  personId={person.id} />} />
        <Route path="skills"    element={<SkillsTab     personId={person.id} />} />
        <Route path="pipeline"  element={<PipelineTab   personId={person.id} />} />
        <Route path="compliance"element={<ComplianceTab person={person} reload={reload} />} />
        {canSeePII && <Route path="pii" element={<PIITab person={person} reload={reload} />} />}
      </Routes>
    </section>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 1 — Overview
// ─────────────────────────────────────────────────────────────────────────
function OverviewTab({ person, reload }) {
  const [editing, setEditing] = useState(false);
  return (
    <div data-testid="tab-overview">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
        <h3>Overview</h3>
        {!editing && <button className="btn" data-testid="tab-overview-edit" onClick={() => setEditing(true)}>Edit</button>}
      </header>
      {editing
        ? <OverviewEdit person={person} onClose={() => { setEditing(false); reload(); }} />
        : <OverviewView person={person} />}
    </div>
  );
}

function OverviewView({ person }) {
  const Item = ({ k, v, testId }) => (
    <div style={{ marginBottom: '0.5rem' }}>
      <span style={{ color: '#888', fontSize: '0.85em', display: 'block' }}>{k}</span>
      <span data-testid={testId}>{v ?? '—'}</span>
    </div>
  );
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '0.75rem' }}>
      <Item k="First name"     v={person.first_name}     testId="overview-first-name" />
      <Item k="Last name"      v={person.last_name}      testId="overview-last-name" />
      <Item k="Preferred name" v={person.preferred_name} testId="overview-preferred-name" />
      <Item k="Email"          v={person.email_primary}  testId="overview-email" />
      <Item k="Email (alt)"    v={person.email_secondary} testId="overview-email-alt" />
      <Item k="Phone"          v={person.phone_primary}  testId="overview-phone" />
      <Item k="Classification" v={person.classification} testId="overview-classification" />
      <Item k="Status"         v={person.status}         testId="overview-status" />
      <Item k="Source"         v={person.source}         testId="overview-source" />
      <Item k="External ID"    v={person.external_id}    testId="overview-external-id" />
      <Item k="LinkedIn"       v={person.linkedin_url}   testId="overview-linkedin" />
      <Item k="Created"        v={(person.created_at || '').slice(0, 10)} testId="overview-created" />
    </div>
  );
}

function OverviewEdit({ person, onClose }) {
  const [form, setForm]         = useState(person);
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState(null);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const save = async () => {
    setSaving(true); setError(null);
    try {
      const fields = ['first_name','middle_name','last_name','preferred_name','email_primary','email_secondary','phone_primary','phone_secondary','classification','status','source','external_id','linkedin_url','recruiter_notes'];
      const patch = {};
      for (const f of fields) if (form[f] !== person[f]) patch[f] = form[f];
      if (Object.keys(patch).length === 0) { onClose(); return; }
      await api.patch(`/modules/people/api/people.php?id=${person.id}`, patch);
      onClose();
    } catch (e) { setError(e); setSaving(false); }
  };

  return (
    <div data-testid="overview-edit-form">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '0.75rem' }}>
        {[['first_name','First name'],['middle_name','Middle name'],['last_name','Last name'],
          ['preferred_name','Preferred name'],['email_primary','Email'],['email_secondary','Email (alt)'],
          ['phone_primary','Phone'],['phone_secondary','Phone (alt)'],['linkedin_url','LinkedIn'],
          ['source','Source'],['external_id','External ID']].map(([k, label]) => (
          <label key={k} style={{ display: 'flex', flexDirection: 'column' }}>
            <span style={{ color: '#888', fontSize: '0.85em' }}>{label}</span>
            <input data-testid={`overview-edit-${k.replace(/_/g, '-')}`} value={form[k] ?? ''} onChange={set(k)} className="input" />
          </label>
        ))}
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: '#888', fontSize: '0.85em' }}>Classification</span>
          <select data-testid="overview-edit-classification" value={form.classification} onChange={set('classification')} className="input">
            {['w2','1099','c2c','temp','perm','candidate','alumni'].map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: '#888', fontSize: '0.85em' }}>Status</span>
          <select data-testid="overview-edit-status" value={form.status} onChange={set('status')} className="input">
            {['active','bench','inactive','do_not_rehire'].map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
      </div>
      {error && <p className="error" data-testid="overview-edit-error">Error: {error.message}</p>}
      <div style={{ marginTop: '1rem', display: 'flex', gap: '0.5rem' }}>
        <button className="btn btn--primary" data-testid="overview-edit-save" onClick={save} disabled={saving}>
          {saving ? 'Saving…' : 'Save'}
        </button>
        <button className="btn btn--ghost" data-testid="overview-edit-cancel" onClick={onClose} disabled={saving}>Cancel</button>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 2 — Placements (read-only proxy, will fill once Placements module ships)
// ─────────────────────────────────────────────────────────────────────────
function PlacementsTab({ personId }) {
  return (
    <div data-testid="tab-placements">
      <h3>Placements</h3>
      <p style={{ color: '#666' }}>
        Cross-module read from <code>placements/</code> (filter by <code>person_id={personId}</code>).
        Renders here once Placements module ships.
      </p>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 3 — Documents
// ─────────────────────────────────────────────────────────────────────────
function DocumentsTab({ personId }) {
  const path = `/modules/people/api/documents.php?person_id=${personId}`;
  const { data, loading, error, reload } = useApi(path);
  const docs = data?.documents ?? [];

  return (
    <div data-testid="tab-documents">
      <header style={{ display: 'flex', justifyContent: 'space-between' }}>
        <h3>Documents</h3>
        <button className="btn btn--ghost" onClick={reload} data-testid="tab-documents-refresh">Refresh</button>
      </header>
      <p style={{ color: '#666' }}>Documents stored via Core StorageService (S3 in prod).</p>
      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      <table className="data-table" data-testid="tab-documents-table" style={{ width: '100%' }}>
        <thead><tr><th>Type</th><th>File</th><th>Signed</th><th>Expires</th><th>Uploaded</th></tr></thead>
        <tbody>
          {docs.length === 0 && <tr><td colSpan={5} className="empty" data-testid="tab-documents-empty">No documents yet.</td></tr>}
          {docs.map(d => (
            <tr key={d.id} data-testid={`document-row-${d.id}`}>
              <td>{d.doc_type}</td>
              <td>{d.file_name || `#${d.storage_object_id}`}</td>
              <td>{d.signed ? `✓ ${(d.signed_at || '').slice(0, 10)}` : '—'}</td>
              <td>{d.expires_at?.slice(0, 10) || '—'}</td>
              <td>{(d.created_at || '').slice(0, 10)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 4 — Skills
// ─────────────────────────────────────────────────────────────────────────
function SkillsTab({ personId }) {
  const path = `/modules/people/api/skills.php?person_id=${personId}`;
  const { data, loading, error, reload } = useApi(path);
  const skills = data?.skills ?? [];

  const [skill, setSkill]       = useState('');
  const [years, setYears]       = useState('');
  const [proficiency, setProf]  = useState('');
  const [adding, setAdding]     = useState(false);
  const [addError, setAddError] = useState(null);

  const add = async (e) => {
    e.preventDefault();
    if (!skill) return;
    setAdding(true); setAddError(null);
    try {
      await api.post(`/modules/people/api/skills.php?person_id=${personId}`, {
        skill, years_experience: years || null, proficiency: proficiency || null,
      });
      setSkill(''); setYears(''); setProf(''); reload();
    } catch (e) { setAddError(e); }
    finally     { setAdding(false); }
  };

  const del = async (id) => {
    if (!confirm('Remove this skill?')) return;
    await api.delete(`/modules/people/api/skills.php?id=${id}`);
    reload();
  };

  return (
    <div data-testid="tab-skills">
      <h3>Skills</h3>
      <form onSubmit={add} style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem' }} data-testid="tab-skills-add-form">
        <input className="input" placeholder="Skill (e.g. React)" value={skill} onChange={e => setSkill(e.target.value)} data-testid="tab-skills-input-name" />
        <input className="input" placeholder="Years" type="number" step="0.5" value={years} onChange={e => setYears(e.target.value)} data-testid="tab-skills-input-years" style={{ width: '100px' }} />
        <select className="input" value={proficiency} onChange={e => setProf(e.target.value)} data-testid="tab-skills-input-proficiency">
          <option value="">— level —</option>
          <option value="beginner">beginner</option>
          <option value="intermediate">intermediate</option>
          <option value="advanced">advanced</option>
          <option value="expert">expert</option>
        </select>
        <button className="btn btn--primary" data-testid="tab-skills-add-btn" disabled={adding || !skill}>{adding ? '…' : 'Add'}</button>
      </form>
      {addError && <p className="error" data-testid="tab-skills-add-error">Error: {addError.message}</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      <ul data-testid="tab-skills-list">
        {skills.length === 0 && <li className="empty" data-testid="tab-skills-empty">No skills logged.</li>}
        {skills.map(s => (
          <li key={s.id} data-testid={`skill-row-${s.id}`} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0.4rem 0', borderBottom: '1px solid #eee' }}>
            <span>
              <strong>{s.skill}</strong>
              {s.years_experience && <span style={{ color: '#666' }}> · {s.years_experience}y</span>}
              {s.proficiency       && <span style={{ color: '#666' }}> · {s.proficiency}</span>}
            </span>
            <button onClick={() => del(s.id)} className="btn btn--ghost" data-testid={`skill-delete-${s.id}`}>Remove</button>
          </li>
        ))}
      </ul>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 5 — Pipeline
// ─────────────────────────────────────────────────────────────────────────
const STAGES = ['sourced','screened','submitted','interview','offer','placed','bench','terminated','rejected'];

function PipelineTab({ personId }) {
  const path = `/modules/people/api/pipeline.php?person_id=${personId}`;
  const { data, loading, error, reload } = useApi(path);
  const stages = data?.stages ?? [];

  const [stage, setStage] = useState('sourced');
  const [note, setNote]   = useState('');
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState(null);

  const add = async (e) => {
    e.preventDefault();
    setAdding(true); setAddError(null);
    try {
      await api.post(`/modules/people/api/pipeline.php?person_id=${personId}`, { stage, note });
      setNote(''); reload();
    } catch (e) { setAddError(e); }
    finally     { setAdding(false); }
  };

  return (
    <div data-testid="tab-pipeline">
      <h3>Pipeline history</h3>
      <form onSubmit={add} style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem' }} data-testid="tab-pipeline-add-form">
        <select className="input" value={stage} onChange={e => setStage(e.target.value)} data-testid="tab-pipeline-stage">
          {STAGES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <input className="input" placeholder="Note (optional)" value={note} onChange={e => setNote(e.target.value)} data-testid="tab-pipeline-note" style={{ flex: 1 }} />
        <button className="btn btn--primary" data-testid="tab-pipeline-add-btn" disabled={adding}>{adding ? '…' : 'Append'}</button>
      </form>
      {addError && <p className="error" data-testid="tab-pipeline-add-error">Error: {addError.message}</p>}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      <ol data-testid="tab-pipeline-list" style={{ paddingLeft: '1rem' }}>
        {stages.length === 0 && <li className="empty" data-testid="tab-pipeline-empty">No pipeline entries yet.</li>}
        {stages.map(s => (
          <li key={s.id} data-testid={`pipeline-row-${s.id}`} style={{ marginBottom: '0.5rem' }}>
            <strong>{s.stage}</strong>
            {s.substage_label && <span style={{ color: '#888' }}> / {s.substage_label}</span>}
            <span style={{ color: '#666' }}> · {(s.entered_at || '').replace('T', ' ').slice(0, 16)}</span>
            {s.note && <div style={{ color: '#444' }}>{s.note}</div>}
          </li>
        ))}
      </ol>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 6 — Compliance (work auth)
// ─────────────────────────────────────────────────────────────────────────
function ComplianceTab({ person, reload }) {
  const [form, setForm] = useState({
    work_auth_status:     person.work_auth_status,
    work_auth_expiry:     person.work_auth_expiry || '',
    requires_sponsorship: !!person.requires_sponsorship,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError]   = useState(null);

  const save = async () => {
    setSaving(true); setError(null);
    try {
      await api.patch(`/modules/people/api/people.php?id=${person.id}`, {
        ...form,
        work_auth_expiry: form.work_auth_expiry || null,
      });
      reload();
    } catch (e) { setError(e); }
    finally     { setSaving(false); }
  };

  return (
    <div data-testid="tab-compliance">
      <h3>Compliance</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '0.75rem' }}>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: '#888', fontSize: '0.85em' }}>Work auth status</span>
          <select data-testid="compliance-work-auth-status" value={form.work_auth_status} onChange={e => setForm({ ...form, work_auth_status: e.target.value })} className="input">
            {['unknown','citizen','green_card','h1b','opt','cpt','tn','other'].map(w => <option key={w} value={w}>{w}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column' }}>
          <span style={{ color: '#888', fontSize: '0.85em' }}>Expiry</span>
          <input data-testid="compliance-work-auth-expiry" type="date" value={form.work_auth_expiry || ''} onChange={e => setForm({ ...form, work_auth_expiry: e.target.value })} className="input" />
        </label>
        <label data-testid="compliance-requires-sponsorship-label" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <input data-testid="compliance-requires-sponsorship" type="checkbox" checked={form.requires_sponsorship} onChange={e => setForm({ ...form, requires_sponsorship: e.target.checked })} />
          Requires sponsorship
        </label>
      </div>
      {error && <p className="error" data-testid="compliance-error">Error: {error.message}</p>}
      <button className="btn btn--primary" onClick={save} disabled={saving} data-testid="compliance-save" style={{ marginTop: '1rem' }}>
        {saving ? 'Saving…' : 'Save'}
      </button>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Tab 7 — PII (gated, audit-logged)
// ─────────────────────────────────────────────────────────────────────────
function PIITab({ person }) {
  const [revealing, setRevealing] = useState(false);
  const [pii, setPII]             = useState(null);
  const [error, setError]         = useState(null);

  const reveal = useCallback(async () => {
    setRevealing(true); setError(null);
    try {
      const result = await api.get(`/modules/people/api/people.php?id=${person.id}&include_pii=1`);
      setPII(result.person);
    } catch (e) { setError(e); }
    finally     { setRevealing(false); }
  }, [person.id]);

  return (
    <div data-testid="tab-pii">
      <h3>PII (audit-logged)</h3>
      <p style={{ color: '#888' }}>
        Every reveal writes a row to <code>people_pii_access_log</code> visible to tenant_admin.
      </p>
      {!pii && (
        <button onClick={reveal} className="btn btn--primary" data-testid="tab-pii-reveal" disabled={revealing}>
          {revealing ? 'Revealing…' : 'Reveal PII'}
        </button>
      )}
      {error && <p className="error" data-testid="tab-pii-error">Error: {error.message}</p>}
      {pii && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '0.75rem', marginTop: '1rem' }} data-testid="tab-pii-fields">
          <Item k="DOB"          v={pii.dob}                testId="pii-dob" />
          <Item k="SSN last 4"   v={pii.ssn_last4}          testId="pii-ssn-last4" />
          <Item k="Address"      v={pii.home_address_line1} testId="pii-address" />
          <Item k="Address 2"    v={pii.home_address_line2} testId="pii-address-2" />
          <Item k="City"         v={pii.home_city}          testId="pii-city" />
          <Item k="State"        v={pii.home_state}         testId="pii-state" />
          <Item k="Postal"       v={pii.home_postal_code}   testId="pii-postal" />
          <Item k="Country"      v={pii.home_country}       testId="pii-country" />
        </div>
      )}
    </div>
  );
}

const Item = ({ k, v, testId }) => (
  <div>
    <span style={{ color: '#888', fontSize: '0.85em', display: 'block' }}>{k}</span>
    <span data-testid={testId}>{v ?? '—'}</span>
  </div>
);
