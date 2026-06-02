import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Users, Shield, Plus, X, Copy, Save, Trash2, RefreshCw, Mail } from 'lucide-react';
import RecentAccessChangesPanel from './RecentAccessChangesPanel';
import RbacBridgeHealthPanel from './RbacBridgeHealthPanel';

/**
 * RbacMembershipsAdmin — RBAC Phase B3 admin surface.
 *
 * Lets tenant admins:
 *   - See every membership in the active tenant (with module count + status)
 *   - Add a new membership for an existing user (persona label/type)
 *   - Edit persona type, status, is_primary
 *   - Revoke (soft delete → status='revoked')
 *   - Drill into per-membership module access (read/write/admin/none)
 *   - "Copy permissions from…" — clone every module grant from another
 *     membership in the same tenant in one click
 *
 * Reads the new tenant_memberships grid; writes via /api/admin/memberships.php
 * and /api/admin/membership_access.php.  The Recent Access Changes panel at
 * the top is fed by membership_audit.
 */

const PERSONA_TYPES = [
  'tenant_admin', 'admin', 'manager', 'employee',
  'contractor', 'client', 'vendor', 'platform_staff', 'custom',
  // CPA-firm-side personas (migration 100 / RBAC B6).
  'cpa', 'cpa_partner', 'cpa_staff',
  'bookkeeper', 'client_advisor', 'external_auditor',
];
const STATUSES = ['active', 'pending', 'suspended', 'revoked'];
const LEVELS   = ['none', 'read', 'write', 'admin'];
const MODULES  = ['people','placements','time','billing','ap','accounting','payroll','treasury','reports'];

function StatusBadge({ status }) {
  const tone = status === 'active' ? '#2f7a3b'
             : status === 'pending' ? '#a06a00'
             : status === 'suspended' ? '#a64a00'
             : '#7a2a2a';
  return (
    <span data-testid={`status-${status}`}
          style={{
            background: tone + '22', color: tone, fontSize: 11, padding: '2px 8px',
            borderRadius: 10, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.3,
          }}>{status}</span>
  );
}

/**
 * Inline picker for an RBAC permission profile (migration 100 / RBAC B6).
 *
 *  - Pulls profiles from /api/admin/permission_profiles.php on mount.
 *  - Filters to ones whose `applies_to_persona` matches `personaType`,
 *    plus any profile with applies_to_persona === null (generic).
 *  - Surfaces system + tenant-private profiles with a small "system" /
 *    "tenant" badge so the admin knows what they're picking.
 *  - Calls `onChange(profileKey | '')` so the parent can stamp it into
 *    the form payload it POSTs.
 *
 * The picker is intentionally OPTIONAL — leaving it blank just skips
 * the bulk-apply step, preserving the pre-B6 flow.
 */
function ProfilePicker({ personaType, value, onChange, testIdPrefix }) {
  const [profiles, setProfiles] = useState(null); // null = loading
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const r = await api.get('/api/admin/permission_profiles.php');
        if (cancelled) return;
        setProfiles(Array.isArray(r?.profiles) ? r.profiles : []);
      } catch (e) {
        if (cancelled) return;
        // 503 when migration 100 hasn't run yet — degrade gracefully.
        setProfiles([]);
        setError(e?.message || 'profiles unavailable');
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const visible = useMemo(() => {
    if (!Array.isArray(profiles)) return [];
    const persona = (personaType || '').toLowerCase();
    return profiles.filter(p => !p.applies_to_persona || p.applies_to_persona === persona);
  }, [profiles, personaType]);

  if (profiles === null) {
    return (
      <span data-testid={`${testIdPrefix}-loading`} style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
        Loading profiles…
      </span>
    );
  }
  if (visible.length === 0) {
    return (
      <span data-testid={`${testIdPrefix}-empty`} style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
        {error ? `(${error})` : 'No profiles available for this persona'}
      </span>
    );
  }
  return (
    <select
      value={value || ''}
      onChange={(e) => onChange(e.target.value)}
      data-testid={testIdPrefix}
    >
      <option value="">— skip / configure modules manually —</option>
      {visible.map(p => (
        <option
          key={p.id}
          value={p.profile_key}
          data-testid={`${testIdPrefix}-opt-${p.profile_key}`}
        >
          {p.label} {p.is_system ? '· system' : '· tenant'} ({p.grants.length} modules)
        </option>
      ))}
    </select>
  );
}

function MembershipForm({ initial, users, onSave, onCancel }) {
  const [form, setForm] = useState(initial || {
    user_id: '', persona_label: 'Primary', persona_type: 'employee',
    status: 'active', is_primary: false, profile_key: '',
  });
  const isNew = !initial?.id;
  const submit = async () => {
    if (!form.user_id) return alert('Pick a user');
    try {
      if (isNew) {
        await api.post('/api/admin/memberships.php', form);
      } else {
        await api.patch(`/api/admin/memberships.php?id=${initial.id}`, form);
      }
      onSave();
    } catch (e) { alert(e.message || 'Save failed'); }
  };
  return (
    <Card data-testid="membership-form">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <strong>{isNew ? 'New membership' : `Edit membership #${initial.id}`}</strong>
        <button onClick={onCancel} className="btn btn--ghost btn--sm" aria-label="Close" data-testid="membership-form-close">
          <X size={14} />
        </button>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>User</span>
          {isNew ? (
            <select
              value={form.user_id || ''}
              onChange={(e) => setForm({ ...form, user_id: e.target.value })}
              data-testid="membership-user-select"
            >
              <option value="">— pick a user —</option>
              {users.map(u => (
                <option key={u.id} value={u.id}>{u.name || u.email} ({u.email})</option>
              ))}
            </select>
          ) : (
            <input value={initial.user_name || initial.email || ''} disabled />
          )}
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Persona label</span>
          <input
            value={form.persona_label || ''}
            onChange={(e) => setForm({ ...form, persona_label: e.target.value })}
            placeholder="Primary, Recruiter, Controller…"
            data-testid="membership-persona-label"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Persona type</span>
          <select
            value={form.persona_type || 'employee'}
            onChange={(e) => setForm({ ...form, persona_type: e.target.value })}
            data-testid="membership-persona-type"
          >
            {PERSONA_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Status</span>
          <select
            value={form.status || 'active'}
            onChange={(e) => setForm({ ...form, status: e.target.value })}
            data-testid="membership-status"
          >
            {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, gridColumn: '1 / -1' }}>
          <input
            type="checkbox"
            checked={!!form.is_primary}
            onChange={(e) => setForm({ ...form, is_primary: e.target.checked })}
            data-testid="membership-is-primary"
          />
          <span style={{ fontSize: 13 }}>Primary persona for this user in this tenant</span>
        </label>
        {isNew && (
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, gridColumn: '1 / -1' }}>
            <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              Apply permission profile <em style={{ opacity: 0.7 }}>(optional — pre-seeds module access)</em>
            </span>
            <ProfilePicker
              personaType={form.persona_type}
              value={form.profile_key || ''}
              onChange={(v) => setForm({ ...form, profile_key: v })}
              testIdPrefix="membership-profile-picker"
            />
          </label>
        )}
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
        <button onClick={onCancel} className="btn btn--ghost" data-testid="membership-form-cancel">Cancel</button>
        <button onClick={submit} className="btn btn--primary" data-testid="membership-form-save">
          <Save size={14} style={{ marginRight: 6 }} />Save
        </button>
      </div>
    </Card>
  );
}

function ScopePicker({ subTenants, value, onSave, onCancel, testIdPrefix }) {
  // `value` is null (all) or an array of sub_tenant IDs.
  const allMode = value === null;
  const [draft, setDraft] = useState(() => allMode ? [] : value.slice());
  const [draftAll, setDraftAll] = useState(allMode);

  const toggle = (id) => {
    if (draftAll) setDraftAll(false);
    setDraft(d => d.includes(id) ? d.filter(x => x !== id) : [...d, id]);
  };

  const save = () => {
    if (draftAll) onSave(null);
    else if (draft.length === 0) {
      alert('Pick at least one entity, or choose "All entities".');
      return;
    }
    else onSave(draft.map(Number));
  };

  return (
    <div
      data-testid={`${testIdPrefix}-picker`}
      style={{
        position: 'absolute', top: '100%', left: 0, zIndex: 5,
        background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #ddd)',
        padding: 10, borderRadius: 6, boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
        minWidth: 220, marginTop: 4,
      }}
    >
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6, fontSize: 13 }}>
        <input
          type="checkbox"
          checked={draftAll}
          onChange={(e) => { setDraftAll(e.target.checked); if (e.target.checked) setDraft([]); }}
          data-testid={`${testIdPrefix}-all`}
        />
        <strong>All entities</strong>
      </label>
      <div style={{ maxHeight: 180, overflowY: 'auto', borderTop: '1px solid var(--cf-border, #eee)', paddingTop: 6 }}>
        {subTenants.map(st => (
          <label key={st.id} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '2px 0', fontSize: 12 }}>
            <input
              type="checkbox"
              checked={!draftAll && draft.includes(st.id)}
              onChange={() => toggle(st.id)}
              disabled={draftAll}
              data-testid={`${testIdPrefix}-st-${st.id}`}
            />
            <span style={{ color: st.is_active ? 'inherit' : 'var(--cf-text-secondary)' }}>
              {st.name}
              {st.kind === 'parent' && <em style={{ color: '#0369a1', marginLeft: 4 }}> (parent)</em>}
              {!st.is_active && ' (inactive)'}
            </span>
          </label>
        ))}
        {subTenants.length === 0 && (
          <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>No entities yet.</div>
        )}
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 6, marginTop: 8 }}>
        <button onClick={onCancel} className="btn btn--ghost btn--sm" data-testid={`${testIdPrefix}-cancel`}>Cancel</button>
        <button onClick={save} className="btn btn--primary btn--sm" data-testid={`${testIdPrefix}-save`}>Save</button>
      </div>
    </div>
  );
}

function AccessGrid({ membership, allMemberships, onClose }) {
  const [access, setAccess] = useState([]);
  const [loading, setLoading] = useState(true);
  const [copyFrom, setCopyFrom] = useState('');
  // RBAC B6 — apply a named permission profile (system or tenant-private)
  // to bulk-write module grants without round-tripping the per-module
  // selects. Empty string = no profile picked; once chosen + applied we
  // reload() to surface the new rows in the table below.
  const [profileKey, setProfileKey] = useState('');
  const [overwrite, setOverwrite]   = useState(false);
  const [profileBusy, setProfileBusy] = useState(false);
  // RBAC B3 — sub-tenant scope picker.
  // Backend supports a per-grant `sub_tenant_scope` JSON array (NULL = all
  // sub-tenants under the parent tenant). We surface that here so a
  // controller can be granted `accounting:write` for only the EAST division
  // and not WEST. Loaded once per tenant; suppressed when the tenant has
  // no sub-tenants (single-tenant case stays uncluttered).
  const [subTenants, setSubTenants]   = useState([]);
  const [scopePickerFor, setScopePickerFor] = useState(null); // module_key or null

  const reload = async () => {
    setLoading(true);
    try {
      const r = await api.get(`/api/admin/membership_access.php?membership_id=${membership.id}`);
      setAccess(r?.access || []);
    } finally { setLoading(false); }
  };

  useEffect(() => { reload(); /* eslint-disable-next-line */ }, [membership.id]);
  useEffect(() => {
    // Best-effort — endpoint 403's for non-master-tenant callers; we just
    // don't show the picker in that case. Includes the parent tenant as a
    // selectable scope entry because the parent keeps its own books too —
    // parent-as-entity applies everywhere, not just integrations.
    api.get('/api/sub_tenants.php')
      .then(r => {
        const subs = Array.isArray(r?.sub_tenants) ? r.sub_tenants : [];
        const parent = r?.parent || null;
        const parentId = r?.parent_tenant_id ?? parent?.id ?? null;
        const list = [];
        if (parent && parentId) list.push({ id: parentId, name: parent.name || `Tenant ${parentId}`, is_active: 1, kind: 'parent' });
        for (const st of subs) list.push({ ...st, kind: 'sub' });
        setSubTenants(list);
      })
      .catch(() => setSubTenants([]));
  }, []);

  const rowFor = (module_key) => access.find(r => r.module_key === module_key);
  const levelFor = (module_key) => rowFor(module_key)?.access_level || 'none';
  const scopeFor = (module_key) => {
    const s = rowFor(module_key)?.sub_tenant_scope;
    return Array.isArray(s) ? s : null; // null = all sub-tenants
  };

  const grant = async (module_key, level, scope) => {
    if (level === 'none') {
      try { await api.post('/api/admin/membership_access.php', { op: 'revoke', membership_id: membership.id, module_key }); }
      catch (e) { alert(e.message || 'Revoke failed'); return; }
    } else {
      // `sub_tenant_scope === undefined` → backend leaves existing scope
      // untouched. `null` → reset to "all sub-tenants". Array → restrict.
      const body = { op: 'grant', membership_id: membership.id, module_key, access_level: level };
      if (scope !== undefined) body.sub_tenant_scope = scope;
      try { await api.post('/api/admin/membership_access.php', body); }
      catch (e) { alert(e.message || 'Grant failed'); return; }
    }
    reload();
  };

  const setScope = async (module_key, scope) => {
    // Only meaningful when the module is already granted at something
    // other than 'none'. We re-issue grant with the current level + new
    // scope; backend ON DUPLICATE KEY UPDATE makes this an upsert.
    const level = levelFor(module_key);
    if (level === 'none') { alert('Grant access first, then choose scope.'); return; }
    await grant(module_key, level, scope);
    setScopePickerFor(null);
  };

  const copy = async () => {
    if (!copyFrom) return alert('Pick a membership to copy from');
    try {
      const r = await api.post('/api/admin/membership_access.php', {
        op: 'copy', from_membership_id: Number(copyFrom), to_membership_id: membership.id,
      });
      alert(`Copied ${r?.copied || 0} grants`);
      reload();
    } catch (e) { alert(e.message || 'Copy failed'); }
  };

  // RBAC B6 — apply a named profile to this membership. Uses
  // /api/admin/permission_profiles.php?action=apply. When `overwrite=1` is
  // checked, the server-side service revokes every existing module grant
  // that is NOT in the profile before re-applying.
  const applyProfile = async () => {
    if (!profileKey) return alert('Pick a profile first');
    setProfileBusy(true);
    try {
      // Resolve profile_key → profile_id via the list endpoint. We do
      // this here rather than in the picker so the apply button can
      // remain a clean single-click action with no extra state.
      const r1 = await api.get('/api/admin/permission_profiles.php');
      const match = (r1?.profiles || []).find(p => p.profile_key === profileKey);
      if (!match) { alert('Profile not found'); return; }
      const r2 = await api.post('/api/admin/permission_profiles.php?action=apply', {
        profile_id: match.id,
        membership_id: membership.id,
        overwrite: overwrite ? 1 : 0,
      });
      alert(`Applied ${r2?.applied || 0} module grants from "${match.label}"`);
      setProfileKey('');
      setOverwrite(false);
      reload();
    } catch (e) {
      alert(e.message || 'Apply failed');
    } finally {
      setProfileBusy(false);
    }
  };

  const copyCandidates = allMemberships.filter(m => m.id !== membership.id && m.status === 'active');

  return (
    <Card data-testid={`access-grid-${membership.id}`}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div>
          <strong>Module access</strong>
          <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            {membership.user_name || membership.email} · {membership.persona_label} ({membership.persona_type})
          </div>
        </div>
        <button onClick={onClose} className="btn btn--ghost btn--sm" data-testid="access-grid-close" aria-label="Close">
          <X size={14} />
        </button>
      </div>

      <div style={{
        display: 'flex', gap: 8, alignItems: 'center',
        padding: 10, background: 'var(--cf-surface-alt, #fafafa)',
        borderRadius: 6, marginBottom: 12,
      }}>
        <Copy size={14} />
        <span style={{ fontSize: 13 }}>Copy permissions from</span>
        <select
          value={copyFrom}
          onChange={(e) => setCopyFrom(e.target.value)}
          data-testid="access-copy-from-select"
          style={{ flex: 1, maxWidth: 320 }}
        >
          <option value="">— pick a membership —</option>
          {copyCandidates.map(m => (
            <option key={m.id} value={m.id}>
              {m.user_name || m.email} · {m.persona_label} ({m.modules_count} grants)
            </option>
          ))}
        </select>
        <button onClick={copy} disabled={!copyFrom} className="btn btn--primary btn--sm" data-testid="access-copy-btn">
          Copy
        </button>
      </div>

      {/* RBAC B6 — apply a named permission profile (CPA bundle, etc.). */}
      <div
        data-testid="access-apply-profile-card"
        style={{
          display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap',
          padding: 10, background: 'var(--cf-surface-alt, #fafafa)',
          borderRadius: 6, marginBottom: 12,
        }}
      >
        <Shield size={14} />
        <span style={{ fontSize: 13 }}>Apply profile</span>
        <div style={{ flex: 1, minWidth: 240, maxWidth: 360 }}>
          <ProfilePicker
            personaType={membership.persona_type}
            value={profileKey}
            onChange={setProfileKey}
            testIdPrefix="access-apply-profile-picker"
          />
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12 }}>
          <input
            type="checkbox"
            checked={overwrite}
            onChange={(e) => setOverwrite(e.target.checked)}
            data-testid="access-apply-profile-overwrite"
          />
          <span>Overwrite other modules</span>
        </label>
        <button
          onClick={applyProfile}
          disabled={!profileKey || profileBusy}
          className="btn btn--primary btn--sm"
          data-testid="access-apply-profile-btn"
        >
          {profileBusy ? 'Applying…' : 'Apply'}
        </button>
      </div>

      {loading && <div>Loading…</div>}
      {!loading && (
        <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid="access-grid-table">
          <thead>
            <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              <th style={{ padding: '6px 4px' }}>Module</th>
              <th style={{ padding: '6px 4px' }}>Access</th>
              {subTenants.length > 0 && <th style={{ padding: '6px 4px' }}>Entity scope</th>}
            </tr>
          </thead>
          <tbody>
            {MODULES.map(m => {
              const level = levelFor(m);
              const scope = scopeFor(m);
              const allScope = scope === null;
              return (
                <tr key={m} style={{ borderTop: '1px solid var(--cf-border)' }}>
                  <td style={{ padding: '8px 4px', fontWeight: 500 }}>{m}</td>
                  <td style={{ padding: '8px 4px' }}>
                    <select
                      value={level}
                      onChange={(e) => grant(m, e.target.value)}
                      data-testid={`access-level-${m}`}
                    >
                      {LEVELS.map(l => <option key={l} value={l}>{l}</option>)}
                    </select>
                  </td>
                  {subTenants.length > 0 && (
                    <td style={{ padding: '8px 4px', position: 'relative' }}>
                      {level === 'none' ? (
                        <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>—</span>
                      ) : (
                        <>
                          <button
                            type="button"
                            onClick={() => setScopePickerFor(scopePickerFor === m ? null : m)}
                            className="btn btn--ghost btn--sm"
                            data-testid={`access-scope-toggle-${m}`}
                            style={{ fontSize: 12, padding: '2px 8px' }}
                          >
                            {allScope
                              ? 'All entities'
                              : `${scope.length} of ${subTenants.length}`}
                          </button>
                          {scopePickerFor === m && (
                            <ScopePicker
                              subTenants={subTenants}
                              value={scope}
                              onSave={(next) => setScope(m, next)}
                              onCancel={() => setScopePickerFor(null)}
                              testIdPrefix={`access-scope-${m}`}
                            />
                          )}
                        </>
                      )}
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </Card>
  );
}

function InviteForm({ onSent, onCancel }) {
  const [form, setForm] = useState({
    email: '', name: '', persona_label: 'Primary',
    persona_type: 'employee', ttl_minutes: 60 * 24 * 7, // 7 days
    profile_key: '',
  });
  const [sending, setSending] = useState(false);
  const [result,  setResult]  = useState(null); // { ok, mailer:{driver,...}, magic_link_url? }
  const [error,   setError]   = useState(null);

  const submit = async () => {
    setError(null); setResult(null);
    if (!form.email.includes('@')) { setError('A valid email is required'); return; }
    setSending(true);
    try {
      const r = await api.post('/api/admin/memberships.php?action=invite', form);
      setResult(r);
    } catch (e) { setError(e.message || 'Invite failed'); }
    finally { setSending(false); }
  };

  // Once sent, show a success card with the resolved mailer outcome.
  if (result?.ok) {
    const driver = result?.mailer?.driver || 'unknown';
    const mailedOk = !!result?.mailer?.ok;
    return (
      <Card data-testid="invite-result">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <strong><Mail size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} /> Invite sent</strong>
          <button onClick={() => { onSent(); }} className="btn btn--ghost btn--sm" data-testid="invite-result-close">
            <X size={14} />
          </button>
        </div>
        <p style={{ fontSize: 13, margin: '4px 0' }} data-testid="invite-result-summary">
          {mailedOk
            ? <>Email sent via <strong>{driver}</strong> to <code>{result.email}</code>. Link expires <strong>{result.expires_at}</strong> UTC.</>
            : <>Membership created but email delivery returned an error via <strong>{driver}</strong>: {result?.mailer?.error || 'unknown'}.</>}
        </p>
        {result.magic_link_url && (
          <div data-testid="invite-result-magic-link" style={{
            marginTop: 8, padding: 8, background: 'var(--cf-surface-alt, #fafafa)',
            borderRadius: 6, fontSize: 12, wordBreak: 'break-all',
          }}>
            <strong>Recovery link (admin-only):</strong><br />
            <code>{result.magic_link_url}</code>
          </div>
        )}
      </Card>
    );
  }

  return (
    <Card data-testid="invite-form">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <strong><Mail size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} /> Invite by email</strong>
        <button onClick={onCancel} className="btn btn--ghost btn--sm" aria-label="Close" data-testid="invite-form-close">
          <X size={14} />
        </button>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Email</span>
          <input
            type="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            placeholder="jane@acme.com"
            data-testid="invite-email"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Name (optional)</span>
          <input
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder="Jane Doe"
            data-testid="invite-name"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Persona label</span>
          <input
            value={form.persona_label}
            onChange={(e) => setForm({ ...form, persona_label: e.target.value })}
            placeholder="Primary, Controller, Recruiter…"
            data-testid="invite-persona-label"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Persona type</span>
          <select
            value={form.persona_type}
            onChange={(e) => setForm({ ...form, persona_type: e.target.value })}
            data-testid="invite-persona-type"
          >
            {PERSONA_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Link valid for</span>
          <select
            value={form.ttl_minutes}
            onChange={(e) => setForm({ ...form, ttl_minutes: Number(e.target.value) })}
            data-testid="invite-ttl"
          >
            <option value={60 * 24}>24 hours</option>
            <option value={60 * 24 * 3}>3 days</option>
            <option value={60 * 24 * 7}>7 days</option>
            <option value={60 * 24 * 14}>14 days</option>
            <option value={60 * 24 * 30}>30 days</option>
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, gridColumn: '1 / -1' }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Apply permission profile <em style={{ opacity: 0.7 }}>(optional — pre-seeds module access on invite)</em>
          </span>
          <ProfilePicker
            personaType={form.persona_type}
            value={form.profile_key || ''}
            onChange={(v) => setForm({ ...form, profile_key: v })}
            testIdPrefix="invite-profile-picker"
          />
        </label>
      </div>
      {error && <div style={{ marginTop: 10, color: '#b94a4a', fontSize: 13 }} data-testid="invite-form-error">{error}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
        <button onClick={onCancel} className="btn btn--ghost" data-testid="invite-form-cancel" disabled={sending}>Cancel</button>
        <button onClick={submit} className="btn btn--primary" data-testid="invite-form-send" disabled={sending}>
          <Mail size={14} style={{ marginRight: 6 }} />{sending ? 'Sending…' : 'Send invite'}
        </button>
      </div>
    </Card>
  );
}

export default function RbacMembershipsAdmin() {
  const [memberships, setMemberships]   = useState([]);
  const [users,       setUsers]         = useState([]);
  const [loading,     setLoading]       = useState(true);
  const [error,       setError]         = useState(null);
  const [editing,     setEditing]       = useState(null);
  const [grid,        setGrid]          = useState(null);
  const [inviting,    setInviting]      = useState(false);
  const [includeInactive, setIncludeInactive] = useState(false);
  const [filter,      setFilter]        = useState('');

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const [m, u] = await Promise.all([
        api.get(`/api/admin/memberships.php${includeInactive ? '?include_inactive=1' : ''}`),
        api.get('/api/users.php'),
      ]);
      setMemberships(m?.memberships || []);
      setUsers(u?.users || []);
    } catch (e) {
      setError(e.message || 'Failed to load memberships');
    } finally { setLoading(false); }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [includeInactive]);

  const visible = useMemo(() => {
    if (!filter) return memberships;
    const q = filter.toLowerCase();
    return memberships.filter(m =>
      (m.user_name || '').toLowerCase().includes(q) ||
      (m.email     || '').toLowerCase().includes(q) ||
      (m.persona_label || '').toLowerCase().includes(q) ||
      (m.persona_type  || '').toLowerCase().includes(q)
    );
  }, [memberships, filter]);

  const revoke = async (m) => {
    if (!confirm(`Revoke ${m.user_name || m.email} (${m.persona_label})? They lose access immediately.`)) return;
    try { await api.delete(`/api/admin/memberships.php?id=${m.id}`); load(); }
    catch (e) { alert(e.message || 'Revoke failed'); }
  };

  return (
    <div data-testid="rbac-memberships-admin">
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4, display: 'flex', alignItems: 'center', gap: 8 }}>
          <Shield size={20} /> Memberships & access
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
          Granular per-tenant, per-module access. Powered by the RBAC B2 resolver.
        </p>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 'var(--cf-space-4)' }}>
        <Card data-testid="memberships-card">
          <div style={{ display: 'flex', gap: 8, marginBottom: 12, alignItems: 'center', flexWrap: 'wrap' }}>
            <input
              placeholder="Filter by name, email, persona…"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              data-testid="memberships-filter"
              style={{ flex: 1, minWidth: 200 }}
            />
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 13 }}>
              <input type="checkbox" checked={includeInactive}
                     onChange={(e) => setIncludeInactive(e.target.checked)}
                     data-testid="memberships-include-inactive" />
              Show inactive
            </label>
            <button onClick={load} className="btn btn--ghost btn--sm" data-testid="memberships-refresh">
              <RefreshCw size={14} />
            </button>
            <button onClick={() => setInviting(true)} className="btn btn--ghost btn--sm" data-testid="memberships-invite-btn">
              <Mail size={14} style={{ marginRight: 4 }} /> Invite by email
            </button>
            <button onClick={() => setEditing({ _new: true })} className="btn btn--primary btn--sm" data-testid="memberships-new-btn">
              <Plus size={14} style={{ marginRight: 4 }} /> New membership
            </button>
          </div>

          {loading && <div>Loading…</div>}
          {error && !loading && <div style={{ color: '#b94a4a' }} data-testid="memberships-error">{error}</div>}

          {!loading && !error && (
            <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid="memberships-table">
              <thead>
                <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  <th style={{ padding: '6px 4px' }}>User</th>
                  <th style={{ padding: '6px 4px' }}>Persona</th>
                  <th style={{ padding: '6px 4px' }}>Type</th>
                  <th style={{ padding: '6px 4px' }}>Status</th>
                  <th style={{ padding: '6px 4px' }}>Modules</th>
                  <th style={{ padding: '6px 4px', textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {visible.length === 0 && (
                  <tr><td colSpan={6} style={{ padding: 12, color: 'var(--cf-text-secondary)' }}
                          data-testid="memberships-empty">
                    No memberships yet. Add one to get started.
                  </td></tr>
                )}
                {visible.map(m => (
                  <tr key={m.id} style={{ borderTop: '1px solid var(--cf-border)' }}
                      data-testid={`membership-row-${m.id}`}>
                    <td style={{ padding: '8px 4px' }}>
                      <div style={{ fontWeight: 500 }}>{m.user_name || '(no name)'}</div>
                      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{m.email}</div>
                    </td>
                    <td style={{ padding: '8px 4px' }}>
                      {m.persona_label}
                      {m.is_primary && (
                        <span style={{ marginLeft: 6, fontSize: 10, fontWeight: 600,
                                       background: '#2f7a3b22', color: '#2f7a3b', padding: '1px 6px', borderRadius: 8 }}>
                          PRIMARY
                        </span>
                      )}
                    </td>
                    <td style={{ padding: '8px 4px', fontSize: 12 }}>{m.persona_type}</td>
                    <td style={{ padding: '8px 4px' }}><StatusBadge status={m.status} /></td>
                    <td style={{ padding: '8px 4px' }}>
                      <button className="btn btn--ghost btn--sm"
                              onClick={() => setGrid(m)}
                              data-testid={`open-access-grid-${m.id}`}>
                        {m.modules_count} modules
                      </button>
                    </td>
                    <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                      <button className="btn btn--ghost btn--sm"
                              onClick={() => setEditing(m)}
                              data-testid={`edit-membership-${m.id}`}
                              style={{ marginRight: 4 }}>
                        Edit
                      </button>
                      <button className="btn btn--ghost btn--sm"
                              onClick={() => revoke(m)}
                              data-testid={`revoke-membership-${m.id}`}
                              style={{ color: '#b94a4a' }}>
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--cf-space-4)' }}>
          <RecentAccessChangesPanel limit={10} showSubTenantFilter={true} />
          <RbacBridgeHealthPanel windowHours={24} />
          <Card data-testid="memberships-help">
            <strong style={{ display: 'block', marginBottom: 6 }}>
              <Users size={14} style={{ verticalAlign: 'middle' }} /> Tips
            </strong>
            <ul style={{ paddingLeft: 18, margin: 0, fontSize: 13, color: 'var(--cf-text-secondary)' }}>
              <li>One user can hold multiple personas in the same tenant (e.g. Admin + Employee).</li>
              <li>Use <em>Copy permissions from…</em> to onboard a new hire in one click.</li>
              <li>Setting a persona to <em>Primary</em> makes it the default on tenant switch.</li>
            </ul>
          </Card>
        </div>
      </div>

      {inviting && (
        <div style={{ marginTop: 'var(--cf-space-4)' }}>
          <InviteForm
            onSent={() => { setInviting(false); load(); }}
            onCancel={() => setInviting(false)}
          />
        </div>
      )}

      {editing && (
        <div style={{ marginTop: 'var(--cf-space-4)' }}>
          <MembershipForm
            initial={editing._new ? null : editing}
            users={users}
            onSave={() => { setEditing(null); load(); }}
            onCancel={() => setEditing(null)}
          />
        </div>
      )}

      {grid && (
        <div style={{ marginTop: 'var(--cf-space-4)' }}>
          <AccessGrid
            membership={grid}
            allMemberships={memberships}
            onClose={() => { setGrid(null); load(); }}
          />
        </div>
      )}
    </div>
  );
}
