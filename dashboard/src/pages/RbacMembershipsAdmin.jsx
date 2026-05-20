import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Users, Shield, Plus, X, Copy, Save, Trash2, RefreshCw } from 'lucide-react';
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

function MembershipForm({ initial, users, onSave, onCancel }) {
  const [form, setForm] = useState(initial || {
    user_id: '', persona_label: 'Primary', persona_type: 'employee',
    status: 'active', is_primary: false,
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

function AccessGrid({ membership, allMemberships, onClose }) {
  const [access, setAccess] = useState([]);
  const [loading, setLoading] = useState(true);
  const [copyFrom, setCopyFrom] = useState('');

  const reload = async () => {
    setLoading(true);
    try {
      const r = await api.get(`/api/admin/membership_access.php?membership_id=${membership.id}`);
      setAccess(r?.access || []);
    } finally { setLoading(false); }
  };

  useEffect(() => { reload(); /* eslint-disable-next-line */ }, [membership.id]);

  const levelFor = (module_key) => {
    const row = access.find(r => r.module_key === module_key);
    return row?.access_level || 'none';
  };

  const grant = async (module_key, level) => {
    if (level === 'none') {
      try { await api.post('/api/admin/membership_access.php', { op: 'revoke', membership_id: membership.id, module_key }); }
      catch (e) { alert(e.message || 'Revoke failed'); return; }
    } else {
      try { await api.post('/api/admin/membership_access.php', { op: 'grant', membership_id: membership.id, module_key, access_level: level }); }
      catch (e) { alert(e.message || 'Grant failed'); return; }
    }
    reload();
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

      {loading && <div>Loading…</div>}
      {!loading && (
        <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid="access-grid-table">
          <thead>
            <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              <th style={{ padding: '6px 4px' }}>Module</th>
              <th style={{ padding: '6px 4px' }}>Access</th>
            </tr>
          </thead>
          <tbody>
            {MODULES.map(m => (
              <tr key={m} style={{ borderTop: '1px solid var(--cf-border)' }}>
                <td style={{ padding: '8px 4px', fontWeight: 500 }}>{m}</td>
                <td style={{ padding: '8px 4px' }}>
                  <select
                    value={levelFor(m)}
                    onChange={(e) => grant(m, e.target.value)}
                    data-testid={`access-level-${m}`}
                  >
                    {LEVELS.map(l => <option key={l} value={l}>{l}</option>)}
                  </select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
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
