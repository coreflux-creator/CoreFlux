import React, { useState, useEffect, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { Building2, Plus, Power, Save, RefreshCw, ArrowLeft, Settings2 } from 'lucide-react';
import { api, useApi } from '../lib/api';
import { Section, Card } from '../components/UIComponents';

const MODULES_FOR_SCOPE = [
  'people', 'placements', 'companies', 'crm',
  'billing', 'ap', 'accounting', 'payroll', 'treasury', 'time', 'tax',
];

const SCOPE_HELP = {
  shared: 'Reads/writes parent tenant rows (master controls, sub-tenants reuse).',
  isolated: 'Each sub-tenant gets its own data partition.',
};

const SubTenantsAdmin = ({ session }) => {
  const { data, error, loading, reload } = useApi('/api/sub_tenants.php');
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState(null);

  const subs = data?.sub_tenants || [];
  const parentId = data?.parent_tenant_id;

  return (
    <div data-testid="sub-tenants-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            <Building2 size={22} style={{ display: 'inline', marginRight: 8 }} />
            Sub-Tenants
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Provision and manage isolated business units under your master tenant.
            Each sub-tenant has its own financial data; shared catalogs (people,
            placements, companies) live on the master.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={reload} className="btn btn--ghost" data-testid="sub-tenants-refresh-btn">
            <RefreshCw size={16} /> Refresh
          </button>
          <Link
            to="/admin/sub-tenants/new"
            className="btn btn--primary"
            data-testid="sub-tenants-new-btn"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <Plus size={16} /> New sub-tenant
          </Link>
        </div>
      </div>

      {error && (
        <div className="alert alert--err" data-testid="sub-tenants-error">
          {error.message || 'Failed to load sub-tenants'}
          {error.status === 403 && (
            <div style={{ marginTop: 6, fontSize: 12 }}>
              Only master_admin or the master tenant's tenant_admin can manage sub-tenants.
            </div>
          )}
        </div>
      )}

      <Card>
        {loading ? (
          <div style={{ padding: 24, color: 'var(--cf-text-secondary)' }}>Loading…</div>
        ) : subs.length === 0 ? (
          <div style={{ padding: 32, textAlign: 'center', color: 'var(--cf-text-secondary)' }} data-testid="sub-tenants-empty">
            No sub-tenants yet. Click <strong>New sub-tenant</strong> to provision one.
          </div>
        ) : (
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr>
                <th style={{ textAlign: 'left' }}>Name</th>
                <th style={{ textAlign: 'left' }}>Slug</th>
                <th style={{ textAlign: 'left' }}>Status</th>
                <th style={{ textAlign: 'left' }}>Created</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {subs.map((s) => (
                <tr key={s.id} data-testid={`sub-tenant-row-${s.id}`}>
                  <td style={{ fontWeight: 500 }}>{s.name}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>{s.slug || '—'}</td>
                  <td>
                    {s.is_active === 1 || s.is_active === '1'
                      ? <span className="badge badge--ok">active</span>
                      : <span className="badge badge--muted">deactivated</span>}
                  </td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>
                    {(s.created_at || '').split(' ')[0] || ''}
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <button
                      onClick={() => setEditing(s)}
                      className="btn btn--ghost"
                      data-testid={`sub-tenant-edit-${s.id}`}
                      style={{ padding: '4px 10px', fontSize: 13 }}
                    >
                      <Settings2 size={14} /> Configure
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {showCreate && (
        <CreateModal
          parentId={parentId}
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); reload(); }}
        />
      )}

      {editing && (
        <EditPanel
          sub={editing}
          onClose={() => setEditing(null)}
          onChanged={() => { setEditing(null); reload(); }}
        />
      )}
    </div>
  );
};

const CreateModal = ({ parentId, onClose, onCreated }) => {
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/api/sub_tenants.php', { name, slug: slug || undefined });
      onCreated();
    } catch (e) {
      setErr(e.message || 'Failed to create sub-tenant');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="modal-backdrop" data-testid="sub-tenant-create-modal">
      <div className="modal" style={{ maxWidth: 480 }}>
        <div className="modal-header">
          <h3>New sub-tenant</h3>
          <button onClick={onClose} className="btn btn--ghost">×</button>
        </div>
        <form onSubmit={submit} className="modal-body" style={{ display: 'grid', gap: 12 }}>
          <div>
            <label className="form-label">Name</label>
            <input
              className="input"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Acme East Coast"
              data-testid="sub-tenant-name-input"
              required
            />
          </div>
          <div>
            <label className="form-label">Slug (optional)</label>
            <input
              className="input"
              value={slug}
              onChange={(e) => setSlug(e.target.value)}
              placeholder="acme-east-coast (auto-generated if empty)"
              data-testid="sub-tenant-slug-input"
            />
          </div>
          {err && <div className="alert alert--err">{err}</div>}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button type="button" onClick={onClose} className="btn btn--ghost">Cancel</button>
            <button
              type="submit"
              disabled={busy || !name.trim()}
              className="btn btn--primary"
              data-testid="sub-tenant-create-submit"
            >
              {busy ? 'Creating…' : 'Create sub-tenant'}
            </button>
          </div>
          <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Default scope: shared catalogs (people, placements, companies),
            isolated financials. You can override per module after creation.
          </div>
        </form>
      </div>
    </div>
  );
};

const EditPanel = ({ sub, onClose, onChanged }) => {
  const [scope, setScope] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  useEffect(() => {
    let alive = true;
    api.get(`/api/sub_tenants.php?action=scope&id=${sub.id}`)
      .then((d) => { if (alive) setScope(d.scope || {}); })
      .catch((e) => { if (alive) setErr(e.message); });
    return () => { alive = false; };
  }, [sub.id]);

  const updateScope = async (moduleKey, mode) => {
    setBusy(true); setErr(null);
    try {
      const result = await api.patch(
        `/api/sub_tenants.php?action=scope&id=${sub.id}`,
        { module: moduleKey, mode }
      );
      setScope(result.scope || {});
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };

  const deactivate = async () => {
    if (!confirm(`Deactivate ${sub.name}? Data is preserved; access is blocked.`)) return;
    setBusy(true); setErr(null);
    try {
      await api.delete(`/api/sub_tenants.php?id=${sub.id}`);
      onChanged();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="modal-backdrop" data-testid="sub-tenant-edit-modal">
      <div className="modal" style={{ maxWidth: 600 }}>
        <div className="modal-header">
          <h3>{sub.name} — Module Scope</h3>
          <button onClick={onClose} className="btn btn--ghost">×</button>
        </div>
        <div className="modal-body">
          {err && <div className="alert alert--err">{err}</div>}
          {!scope ? (
            <div style={{ color: 'var(--cf-text-secondary)' }}>Loading scope…</div>
          ) : (
            <table className="data-table" style={{ width: '100%' }}>
              <thead>
                <tr>
                  <th style={{ textAlign: 'left' }}>Module</th>
                  <th style={{ textAlign: 'left' }}>Mode</th>
                  <th style={{ textAlign: 'left' }}>Effect</th>
                </tr>
              </thead>
              <tbody>
                {MODULES_FOR_SCOPE.map((m) => {
                  const mode = scope[m] || 'isolated';
                  return (
                    <tr key={m}>
                      <td style={{ fontWeight: 500 }}>{m}</td>
                      <td>
                        <select
                          value={mode}
                          onChange={(e) => updateScope(m, e.target.value)}
                          disabled={busy}
                          className="input"
                          data-testid={`sub-tenant-scope-${m}`}
                          style={{ width: 130 }}
                        >
                          <option value="shared">shared</option>
                          <option value="isolated">isolated</option>
                        </select>
                      </td>
                      <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                        {SCOPE_HELP[mode]}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}

          <div style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--cf-border)', display: 'flex', justifyContent: 'space-between' }}>
            <button
              onClick={deactivate}
              disabled={busy}
              className="btn btn--danger"
              data-testid="sub-tenant-deactivate-btn"
              style={{ background: '#dc2626', color: '#fff' }}
            >
              <Power size={14} /> Deactivate
            </button>
            <button onClick={onClose} className="btn btn--primary">Done</button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default SubTenantsAdmin;
