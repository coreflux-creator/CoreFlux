import React, { useState } from 'react';
import { Plus, Edit2, X, Save, KeyRound, Power, Search, Shield } from 'lucide-react';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';
import UserEffectivePermissionsModal from './UserEffectivePermissionsModal';

/**
 * UsersAdmin — real CRUD against /api/users.php. Replaces the mock array
 * that lived inside AdminModule.jsx prior to Sprint 2 (2026-02 fork).
 *
 * - master_admin sees every user; tenant_admin sees users that share the
 *   active tenant.
 * - Adding a user implicitly assigns them to the active tenant with the
 *   role chosen in the form. Tenant assignment matrix is editable inline
 *   on the row's expanded panel.
 */
const ROLE_OPTIONS = [
  { value: 'tenant_admin', label: 'Tenant Admin' },
  { value: 'admin',        label: 'Admin' },
  { value: 'manager',      label: 'Manager' },
  { value: 'employee',     label: 'Employee' },
  { value: 'approver',     label: 'Approver' },
  { value: 'viewer',       label: 'Viewer' },
  { value: 'user',         label: 'User' },
];

export default function UsersAdmin({ session }) {
  const { data, loading, error, reload } = useApi('/api/users.php');
  const [editing, setEditing] = useState(null);
  const [filter,  setFilter]  = useState('');
  const [pwReset, setPwReset] = useState(null);  // user object
  const [permsFor, setPermsFor] = useState(null); // user object — opens effective-permissions inspector
  const isMaster = session?.user?.global_role === 'master_admin';

  const users = (data?.users || []).filter(u => {
    if (!filter) return true;
    const q = filter.toLowerCase();
    return (u.name || '').toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q);
  });

  const onDeactivate = async (u) => {
    if (!confirm(`Deactivate ${u.name}? They will lose access immediately.`)) return;
    try { await api.delete(`/api/users.php?id=${u.id}`); reload(); }
    catch (e) { alert(e.message || 'Failed'); }
  };

  return (
    <div data-testid="users-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>Users</h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            {isMaster
              ? 'Every user across the platform. Assign roles, reset passwords, deactivate.'
              : `Users that belong to ${session?.tenant || 'the active tenant'}.`}
          </p>
        </div>
        <button
          className="btn btn--primary"
          onClick={() => setEditing({ _new: true, name: '', email: '', role: 'employee', is_active: 1 })}
          data-testid="users-new-btn"
        >
          <Plus size={16} /> New user
        </button>
      </div>

      <div style={{ marginBottom: 12, display: 'flex', alignItems: 'center', gap: 8, color: 'var(--cf-text-secondary)' }}>
        <Search size={16} />
        <input
          className="input"
          placeholder="Search name or email…"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          style={{ maxWidth: 320 }}
          data-testid="users-search"
        />
      </div>

      {loading && <Card><p>Loading…</p></Card>}
      {error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {!loading && !error && (
        <Card>
          <table className="data-table" data-testid="users-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Tenants</th>
                <th>Status</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.map(u => (
                <tr key={u.id} data-testid={`users-row-${u.id}`}>
                  <td style={{ fontWeight: 500 }}>{u.name}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>{u.email}</td>
                  <td>
                    <span className={`badge badge--${u.role === 'master_admin' ? 'critical' : 'info'}`}>{u.role}</span>
                  </td>
                  <td>{u.tenant_count}</td>
                  <td>
                    <span className={`badge badge--${u.is_active ? 'success' : 'muted'}`}>
                      {u.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <button className="btn btn--ghost" onClick={() => setPermsFor(u)}
                            data-testid={`users-perms-${u.id}`} title="View effective permissions"><Shield size={14} /></button>
                    <button className="btn btn--ghost" onClick={() => setEditing(u)}
                            data-testid={`users-edit-${u.id}`}><Edit2 size={14} /></button>
                    <button className="btn btn--ghost" onClick={() => setPwReset(u)}
                            data-testid={`users-pw-${u.id}`} title="Reset password"><KeyRound size={14} /></button>
                    {u.is_active ? (
                      <button className="btn btn--ghost" onClick={() => onDeactivate(u)}
                              data-testid={`users-deactivate-${u.id}`} style={{ color: '#b91c1c' }}>
                        <Power size={14} />
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
              {users.length === 0 && (
                <tr><td colSpan={6} style={{ textAlign: 'center', padding: 24, color: 'var(--cf-text-secondary)' }}>
                  No users found.
                </td></tr>
              )}
            </tbody>
          </table>
        </Card>
      )}

      {editing && (
        <UserEditModal
          user={editing}
          isMaster={isMaster}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
      {pwReset && (
        <PasswordResetModal user={pwReset} onClose={() => setPwReset(null)} />
      )}
      {permsFor && (
        <UserEffectivePermissionsModal
          userId={permsFor.id}
          onClose={() => setPermsFor(null)}
        />
      )}
    </div>
  );
}

function UserEditModal({ user, isMaster, onClose, onSaved }) {
  const [form, setForm] = useState({
    name: user.name || '',
    email: user.email || '',
    role: user.role || 'employee',
    password: '',
    is_active: user.is_active === undefined ? 1 : user.is_active,
  });
  const [busy, setBusy] = useState(false);
  const [err,  setErr]  = useState(null);

  const isNew = !!user._new;

  const onSave = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      if (isNew) {
        await api.post('/api/users.php', form);
      } else {
        const patch = {
          name: form.name, email: form.email, role: form.role, is_active: form.is_active,
        };
        await api.patch(`/api/users.php?id=${user.id}`, patch);
      }
      onSaved();
    } catch (e) { setErr(e.message || 'Save failed'); }
    finally { setBusy(false); }
  };

  return (
    <ModalShell title={isNew ? 'New user' : `Edit ${user.name}`} onClose={onClose}>
      <form onSubmit={onSave} data-testid="users-edit-form">
        <Field label="Name">
          <input className="input" value={form.name}
                 onChange={e => setForm({...form, name: e.target.value})} required
                 data-testid="users-edit-name" />
        </Field>
        <Field label="Email">
          <input type="email" className="input" value={form.email}
                 onChange={e => setForm({...form, email: e.target.value})} required
                 data-testid="users-edit-email" />
        </Field>
        <Field label="Role">
          <select className="input" value={form.role}
                  onChange={e => setForm({...form, role: e.target.value})}
                  data-testid="users-edit-role">
            {ROLE_OPTIONS.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}
            {isMaster && <option value="master_admin">Master Admin</option>}
          </select>
        </Field>
        {isNew && (
          <Field label="Initial password (≥ 8 chars)">
            <input type="password" className="input" value={form.password}
                   onChange={e => setForm({...form, password: e.target.value})}
                   minLength={8} required data-testid="users-edit-password" />
          </Field>
        )}
        {!isNew && (
          <Field label="Status">
            <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <input type="checkbox" checked={!!form.is_active}
                     onChange={e => setForm({...form, is_active: e.target.checked ? 1 : 0})}
                     data-testid="users-edit-active" />
              Active
            </label>
          </Field>
        )}
        {err && <p style={{ color: '#b91c1c', marginBottom: 12 }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy}
                  data-testid="users-edit-save">
            <Save size={14} /> {busy ? 'Saving…' : 'Save'}
          </button>
        </div>
      </form>
    </ModalShell>
  );
}

function PasswordResetModal({ user, onClose }) {
  const [pwd, setPwd] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.patch(`/api/users.php?id=${user.id}&action=password`, { password: pwd });
      alert('Password reset.');
      onClose();
    } catch (e) { setErr(e.message || 'Reset failed'); }
    finally { setBusy(false); }
  };
  return (
    <ModalShell title={`Reset password for ${user.name}`} onClose={onClose}>
      <form onSubmit={submit} data-testid="users-pw-form">
        <Field label="New password (≥ 8 chars)">
          <input type="password" className="input" value={pwd}
                 onChange={e => setPwd(e.target.value)} minLength={8} required
                 data-testid="users-pw-input" />
        </Field>
        {err && <p style={{ color: '#b91c1c' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy}
                  data-testid="users-pw-save">
            {busy ? 'Saving…' : 'Set password'}
          </button>
        </div>
      </form>
    </ModalShell>
  );
}

function ModalShell({ title, onClose, children }) {
  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 1000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
    }} onClick={onClose} data-testid="modal-overlay">
      <div style={{
        background: '#fff', borderRadius: 12, padding: 24, maxWidth: 480, width: '100%',
        boxShadow: '0 10px 40px rgba(0,0,0,0.2)',
      }} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <h2 style={{ fontSize: 18, fontWeight: 600 }}>{title}</h2>
          <button onClick={onClose} className="btn btn--ghost"><X size={16} /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'block', marginBottom: 14 }}>
      <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>{label}</div>
      {children}
    </label>
  );
}
