import React, { useState } from 'react';
import { Building2, Plus, Edit2, Power, X, Save, Layers } from 'lucide-react';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * Master-tenant CRUD — replaces the legacy /core/views/admin/tenant_edit.php
 * PHP form. Strictly master_admin gated by /api/tenants.php.
 *
 * Sub-tenants are managed at /admin/sub-tenants (this page is parents only).
 */
export default function MasterTenantsAdmin({ session }) {
  const { data, loading, error, reload } = useApi('/api/tenants.php');
  const [editing, setEditing] = useState(null);  // row or { _new: true }

  const tenants = data?.tenants || [];

  const onDeactivate = async (t) => {
    if (!confirm(`Deactivate ${t.name}? Data is preserved; access is blocked.`)) return;
    try {
      await api.delete(`/api/tenants.php?id=${t.id}`);
      reload();
    } catch (e) { alert(e.message || 'Failed'); }
  };

  return (
    <div data-testid="master-tenants-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            <Building2 size={22} style={{ display: 'inline', marginRight: 8 }} />
            Master tenants
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Top-level customer organizations. Each has its own user pool, modules,
            and (optionally) sub-tenants. Manage sub-tenants under{' '}
            <a href="/spa.php#/admin/sub-tenants">Sub-Tenants</a>.
          </p>
        </div>
        <button
          onClick={() => setEditing({ _new: true })}
          className="btn btn--primary"
          data-testid="master-tenants-new-btn"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
        >
          <Plus size={16} /> New tenant
        </button>
      </div>

      {error && (
        <div className="alert alert--err" data-testid="master-tenants-error">
          {error.message || 'Failed to load'}
          {error.status === 403 && (
            <div style={{ marginTop: 6, fontSize: 12 }}>master_admin only.</div>
          )}
        </div>
      )}

      <Card>
        {loading ? (
          <div style={{ padding: 24, color: 'var(--cf-text-secondary)' }}>Loading…</div>
        ) : tenants.length === 0 ? (
          <div style={{ padding: 32, textAlign: 'center', color: 'var(--cf-text-secondary)' }} data-testid="master-tenants-empty">
            No tenants yet. Click <strong>New tenant</strong> to create one.
          </div>
        ) : (
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr>
                <th style={{ textAlign: 'left' }}>Name</th>
                <th style={{ textAlign: 'left' }}>Slug</th>
                <th style={{ textAlign: 'left' }}>Domain</th>
                <th style={{ textAlign: 'right' }}>Users</th>
                <th style={{ textAlign: 'right' }}>Sub-tenants</th>
                <th style={{ textAlign: 'left' }}>Status</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {tenants.map((t) => (
                <tr key={t.id} data-testid={`master-tenant-row-${t.id}`}>
                  <td style={{ fontWeight: 500 }}>{t.name}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>{t.slug || '—'}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>{t.domain || '—'}</td>
                  <td style={{ textAlign: 'right' }}>{t.user_count}</td>
                  <td style={{ textAlign: 'right' }}>
                    {t.sub_count > 0 && (
                      <a href="/spa.php#/admin/sub-tenants"
                         style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: 'var(--cf-accent)' }}>
                        <Layers size={12} /> {t.sub_count}
                      </a>
                    )}
                    {t.sub_count === 0 && '—'}
                  </td>
                  <td>
                    {t.is_active === 1 || t.is_active === '1'
                      ? <span className="badge badge--ok">active</span>
                      : <span className="badge badge--muted">deactivated</span>}
                  </td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button onClick={() => setEditing(t)} className="btn btn--ghost"
                            data-testid={`master-tenant-edit-${t.id}`}
                            style={{ padding: '4px 10px', fontSize: 13 }}>
                      <Edit2 size={12} /> Edit
                    </button>
                    {(t.is_active === 1 || t.is_active === '1') && (
                      <button onClick={() => onDeactivate(t)} className="btn btn--ghost"
                              data-testid={`master-tenant-deactivate-${t.id}`}
                              style={{ padding: '4px 10px', fontSize: 13, marginLeft: 4, color: '#dc2626' }}>
                        <Power size={12} />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {editing && (
        <Editor
          tenant={editing}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </div>
  );
}

function Editor({ tenant, onClose, onSaved }) {
  const isNew = !!tenant._new;
  const [form, setForm] = useState({
    name:            tenant.name            || '',
    slug:            tenant.slug            || '',
    domain:          tenant.domain          || '',
    subdomain:       tenant.subdomain       || '',
    logo_url:        tenant.logo_url        || '',
    primary_color:   tenant.primary_color   || '',
    hero_title:      tenant.hero_title      || '',
    hero_subtitle:   tenant.hero_subtitle   || '',
    login_cta:       tenant.login_cta       || '',
    landing_enabled: tenant.landing_enabled === undefined ? 1 : tenant.landing_enabled,
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const save = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      if (isNew) {
        await api.post('/api/tenants.php', form);
      } else {
        await api.patch(`/api/tenants.php?id=${tenant.id}`, form);
      }
      onSaved();
    } catch (e) {
      setErr(e.message || 'Save failed');
    } finally { setBusy(false); }
  };

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const Field = ({ k, label, placeholder, hint }) => (
    <div>
      <label className="form-label">{label}</label>
      <input className="input" value={form[k] || ''} placeholder={placeholder}
             onChange={(e) => set(k, e.target.value)}
             data-testid={`master-tenant-${k}`} />
      {hint && <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>{hint}</div>}
    </div>
  );

  return (
    <div className="modal-backdrop" data-testid="master-tenant-editor">
      <div className="modal" style={{ maxWidth: 720 }}>
        <div className="modal-header">
          <h3>{isNew ? 'New master tenant' : `Edit: ${tenant.name}`}</h3>
          <button onClick={onClose} className="btn btn--ghost"><X size={18} /></button>
        </div>
        <form onSubmit={save} className="modal-body" style={{ display: 'grid', gap: 18 }}>
          <fieldset style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 12 }}>
            <legend style={{ padding: '0 6px', fontSize: 13, fontWeight: 600 }}>Basic</legend>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <Field k="name"      label="Name *"       placeholder="Acme Corp" />
              <Field k="slug"      label="Slug"         placeholder="acme-corp" hint="URL-friendly identifier (auto if blank)" />
              <Field k="domain"    label="Domain"       placeholder="acme.com" />
              <Field k="subdomain" label="Subdomain"    placeholder="acme" />
            </div>
          </fieldset>

          <fieldset style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 12 }}>
            <legend style={{ padding: '0 6px', fontSize: 13, fontWeight: 600 }}>Branding</legend>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <Field k="logo_url"      label="Logo URL"       placeholder="https://…" />
              <Field k="primary_color" label="Primary color"  placeholder="#002c70" />
              <Field k="hero_title"    label="Hero title"     placeholder="" />
              <Field k="hero_subtitle" label="Hero subtitle"  placeholder="" />
              <Field k="login_cta"     label="Login CTA"      placeholder="Sign In" />
              <label style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 24, fontSize: 13 }}>
                <input type="checkbox"
                       checked={!!form.landing_enabled}
                       onChange={(e) => set('landing_enabled', e.target.checked ? 1 : 0)}
                       data-testid="master-tenant-landing_enabled" />
                Enable landing page
              </label>
            </div>
          </fieldset>

          {err && <div className="alert alert--err" data-testid="master-tenant-error">{err}</div>}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button type="button" onClick={onClose} className="btn btn--ghost">Cancel</button>
            <button type="submit" disabled={busy || !form.name?.trim()}
                    className="btn btn--primary" data-testid="master-tenant-save">
              <Save size={14} /> {busy ? 'Saving…' : (isNew ? 'Create tenant' : 'Save changes')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
