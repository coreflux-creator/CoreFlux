import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Shield, Plus, X, Save, Trash2, RefreshCw } from 'lucide-react';

/**
 * PermissionProfileBuilder — tenant-private RBAC profile editor (B6).
 *
 * What an operator can do here:
 *   - Browse every profile visible to this tenant (system + global custom
 *     + tenant-private). System rows are read-only; tenant-private rows
 *     are editable + deletable.
 *   - Author new tenant-private profiles ("Firm-standard senior",
 *     "Industry overlay", etc.) by typing a profile_key + label + grants
 *     matrix.
 *   - Save changes (POST /api/admin/permission_profiles.php?action=save).
 *   - Delete tenant-private profiles (DELETE …?id=N).
 *
 * Profiles authored here surface automatically in the ProfilePicker on
 * the Memberships admin page (filtered by `applies_to_persona`), so
 * the next onboarding can pick "Acme — Senior Bookkeeper" in one click.
 *
 * Mounted at /admin/permission-profiles.
 */

const PERSONA_TYPES = [
  '',                 // "Any persona" — generic profile, surfaced for every persona type
  'admin', 'manager', 'employee', 'contractor',
  'cpa', 'cpa_partner', 'cpa_staff',
  'bookkeeper', 'client_advisor', 'external_auditor',
];

const ACCESS_LEVELS = ['none', 'read', 'write', 'admin'];

// Mirrors RbacMembershipsAdmin.MODULES — keep these two arrays in sync.
const MODULES = [
  'people', 'placements', 'time', 'billing', 'ap', 'ar',
  'accounting', 'payroll', 'treasury', 'cfo', 'reports',
  'staffing', 'integrations', 'rbac',
];

function ScopeBadge({ scope, isSystem }) {
  if (isSystem) {
    return (
      <span data-testid="profile-badge-system"
            style={{ background: '#2f7a3b22', color: '#2f7a3b', fontSize: 10,
                     padding: '2px 6px', borderRadius: 6, fontWeight: 600 }}>
        SYSTEM
      </span>
    );
  }
  if (scope === 'global') {
    return (
      <span data-testid="profile-badge-global"
            style={{ background: '#0ea5e922', color: '#0c4a6e', fontSize: 10,
                     padding: '2px 6px', borderRadius: 6, fontWeight: 600 }}>
        GLOBAL
      </span>
    );
  }
  return (
    <span data-testid="profile-badge-tenant"
          style={{ background: '#a06a0022', color: '#a06a00', fontSize: 10,
                   padding: '2px 6px', borderRadius: 6, fontWeight: 600 }}>
      TENANT
    </span>
  );
}

function emptyDraft() {
  return {
    id: null,
    profile_key: '',
    label: '',
    description: '',
    applies_to_persona: '',
    grants: MODULES.map((m) => ({ module_key: m, access_level: 'none' })),
  };
}

function GrantsMatrix({ value, onChange, readOnly }) {
  // Ensure every known module has a row even if the saved profile only
  // referenced a subset — operators expect to see every module they can
  // grant access to, with the unsaved ones defaulted to "none".
  const byKey = useMemo(() => {
    const m = new Map();
    (value || []).forEach((g) => m.set(g.module_key, g.access_level));
    return m;
  }, [value]);

  const setLevel = (mk, lvl) => {
    if (readOnly) return;
    const next = MODULES.map((m) => ({
      module_key: m,
      access_level: m === mk ? lvl : (byKey.get(m) || 'none'),
    }));
    onChange(next);
  };

  return (
    <table data-testid="profile-grants-matrix" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
      <thead>
        <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)' }}>
          <th style={{ padding: '6px 4px' }}>Module</th>
          <th style={{ padding: '6px 4px' }}>Access</th>
        </tr>
      </thead>
      <tbody>
        {MODULES.map((m) => (
          <tr key={m} style={{ borderTop: '1px solid var(--cf-border, #eee)' }}>
            <td style={{ padding: '6px 4px', fontWeight: 500 }}>{m}</td>
            <td style={{ padding: '6px 4px' }}>
              <select
                value={byKey.get(m) || 'none'}
                onChange={(e) => setLevel(m, e.target.value)}
                disabled={readOnly}
                data-testid={`profile-grant-${m}`}
              >
                {ACCESS_LEVELS.map((lvl) => <option key={lvl} value={lvl}>{lvl}</option>)}
              </select>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function ProfileEditor({ initial, onSave, onCancel, onDelete }) {
  const [draft, setDraft] = useState(initial);
  const isSystem = !!initial?.is_system;
  const isNew    = !initial?.id;
  const readOnly = isSystem;

  const submit = async () => {
    if (!draft.profile_key.trim()) return alert('profile_key is required');
    if (!draft.label.trim())       return alert('label is required');
    const grants = (draft.grants || []).filter((g) => g.access_level !== 'none');
    if (!grants.length) return alert('Pick at least one module with access > none');
    try {
      const payload = {
        ...(draft.id ? { id: draft.id } : {}),
        profile_key: draft.profile_key.trim(),
        label:        draft.label.trim(),
        description:  draft.description || null,
        applies_to_persona: draft.applies_to_persona || null,
        grants,
      };
      await api.post('/api/admin/permission_profiles.php?action=save', payload);
      onSave();
    } catch (e) { alert(e.message || 'Save failed'); }
  };

  return (
    <Card data-testid="profile-editor">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div>
          <strong>{isSystem ? 'System profile (read-only)' : (isNew ? 'New tenant profile' : `Edit profile #${draft.id}`)}</strong>
          <div style={{ marginTop: 4, display: 'flex', gap: 6 }}>
            <ScopeBadge scope={initial?.scope || 'tenant'} isSystem={isSystem} />
            {draft.applies_to_persona && (
              <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                applies to <code>{draft.applies_to_persona}</code>
              </span>
            )}
          </div>
        </div>
        <button onClick={onCancel} className="btn btn--ghost btn--sm" aria-label="Close" data-testid="profile-editor-close">
          <X size={14} />
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Profile key</span>
          <input
            value={draft.profile_key}
            onChange={(e) => setDraft({ ...draft, profile_key: e.target.value })}
            placeholder="e.g. firm.senior_bookkeeper"
            disabled={readOnly || !isNew}
            data-testid="profile-editor-key"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Label</span>
          <input
            value={draft.label}
            onChange={(e) => setDraft({ ...draft, label: e.target.value })}
            placeholder="Senior Bookkeeper — full books + AR/AP"
            disabled={readOnly}
            data-testid="profile-editor-label"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, gridColumn: '1 / -1' }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Description (optional)</span>
          <textarea
            value={draft.description || ''}
            onChange={(e) => setDraft({ ...draft, description: e.target.value })}
            rows={2}
            placeholder="Surfaced under the picker when admins onboard new members."
            disabled={readOnly}
            data-testid="profile-editor-description"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Applies to persona</span>
          <select
            value={draft.applies_to_persona || ''}
            onChange={(e) => setDraft({ ...draft, applies_to_persona: e.target.value })}
            disabled={readOnly}
            data-testid="profile-editor-persona"
          >
            {PERSONA_TYPES.map((p) => (
              <option key={p} value={p}>{p === '' ? '(any persona)' : p}</option>
            ))}
          </select>
        </label>
      </div>

      <div style={{ marginTop: 16 }}>
        <strong style={{ fontSize: 13 }}>Module grants</strong>
        <p style={{ marginTop: 4, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          Pick the access level per module. <code>none</code> rows are dropped on save.
        </p>
        <GrantsMatrix
          value={draft.grants}
          onChange={(next) => setDraft({ ...draft, grants: next })}
          readOnly={readOnly}
        />
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 16 }}>
        <div>
          {!isNew && !isSystem && onDelete && (
            <button
              onClick={onDelete}
              className="btn btn--ghost btn--sm"
              style={{ color: '#b94a4a' }}
              data-testid="profile-editor-delete"
            >
              <Trash2 size={14} style={{ marginRight: 4 }} />Delete profile
            </button>
          )}
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={onCancel} className="btn btn--ghost" data-testid="profile-editor-cancel">Cancel</button>
          {!readOnly && (
            <button onClick={submit} className="btn btn--primary" data-testid="profile-editor-save">
              <Save size={14} style={{ marginRight: 6 }} />Save
            </button>
          )}
        </div>
      </div>
    </Card>
  );
}

export default function PermissionProfileBuilder() {
  const [profiles, setProfiles] = useState(null);
  const [error, setError]       = useState(null);
  const [editing, setEditing]   = useState(null); // null = list view, {} = new, row = edit existing

  const reload = async () => {
    try {
      setError(null);
      const r = await api.get('/api/admin/permission_profiles.php');
      setProfiles(Array.isArray(r?.profiles) ? r.profiles : []);
    } catch (e) {
      setProfiles([]);
      setError(e?.message || 'Failed to load profiles');
    }
  };

  useEffect(() => { reload(); }, []);

  const startEdit = (p) => {
    setEditing({
      id: p.id,
      profile_key: p.profile_key,
      label: p.label,
      description: p.description || '',
      applies_to_persona: p.applies_to_persona || '',
      grants: MODULES.map((m) => {
        const g = (p.grants || []).find((x) => x.module_key === m);
        return { module_key: m, access_level: g ? g.access_level : 'none' };
      }),
      is_system: p.is_system,
      scope:     p.scope,
    });
  };

  const removeProfile = async (id) => {
    if (!confirm('Delete this tenant profile? Memberships that already have its grants applied are not affected.')) return;
    try {
      await api.delete(`/api/admin/permission_profiles.php?id=${id}`);
      setEditing(null);
      reload();
    } catch (e) { alert(e.message || 'Delete failed'); }
  };

  return (
    <div data-testid="permission-profile-builder">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <Shield size={20} /> Permission profiles
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Named bundles of module grants that can be applied to a membership in one click.
            System profiles are built-in; tenant profiles are private to this firm.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={reload} className="btn btn--ghost" data-testid="profile-builder-refresh">
            <RefreshCw size={14} style={{ marginRight: 6 }} />Refresh
          </button>
          <button
            onClick={() => setEditing({ ...emptyDraft(), is_system: false, scope: 'tenant' })}
            className="btn btn--primary"
            data-testid="profile-builder-new"
          >
            <Plus size={14} style={{ marginRight: 6 }} />New profile
          </button>
        </div>
      </div>

      {error && (
        <Card data-testid="profile-builder-error" style={{ background: '#fff1f0', border: '1px solid #ffccc7' }}>
          {error.includes('100_rbac')
            ? <>Migration 100 hasn't been applied yet — apply it from the Cloudways admin panel and refresh.</>
            : error}
        </Card>
      )}

      {editing !== null && (
        <ProfileEditor
          initial={editing}
          onSave={() => { setEditing(null); reload(); }}
          onCancel={() => setEditing(null)}
          onDelete={editing.id ? () => removeProfile(editing.id) : null}
        />
      )}

      {editing === null && (
        <Card data-testid="profile-builder-list">
          {!profiles && <div data-testid="profile-builder-loading">Loading…</div>}
          {profiles && profiles.length === 0 && (
            <div data-testid="profile-builder-empty" style={{ padding: 20, color: 'var(--cf-text-secondary)' }}>
              No profiles yet. Click <strong>New profile</strong> to author a tenant-private bundle.
            </div>
          )}
          {profiles && profiles.length > 0 && (
            <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid="profile-builder-table">
              <thead>
                <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  <th style={{ padding: '6px 4px' }}>Key</th>
                  <th style={{ padding: '6px 4px' }}>Label</th>
                  <th style={{ padding: '6px 4px' }}>Persona</th>
                  <th style={{ padding: '6px 4px' }}>Scope</th>
                  <th style={{ padding: '6px 4px' }}>Modules</th>
                  <th style={{ padding: '6px 4px' }}></th>
                </tr>
              </thead>
              <tbody>
                {profiles.map((p) => (
                  <tr key={p.id} style={{ borderTop: '1px solid var(--cf-border)' }} data-testid={`profile-row-${p.profile_key}`}>
                    <td style={{ padding: '8px 4px', fontFamily: 'monospace', fontSize: 12 }}>{p.profile_key}</td>
                    <td style={{ padding: '8px 4px' }}>{p.label}</td>
                    <td style={{ padding: '8px 4px', fontSize: 12 }}>
                      {p.applies_to_persona || <em style={{ color: 'var(--cf-text-secondary)' }}>(any)</em>}
                    </td>
                    <td style={{ padding: '8px 4px' }}>
                      <ScopeBadge scope={p.scope} isSystem={p.is_system} />
                    </td>
                    <td style={{ padding: '8px 4px', fontSize: 12 }}>{p.grants.length}</td>
                    <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                      <button
                        onClick={() => startEdit(p)}
                        className="btn btn--ghost btn--sm"
                        data-testid={`profile-edit-${p.profile_key}`}
                      >
                        {p.is_system ? 'View' : 'Edit'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}
    </div>
  );
}
