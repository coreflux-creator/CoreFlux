import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Check, Plus, Trash2 } from 'lucide-react';
import { api } from '../lib/api';

/**
 * Sub-Tenant Onboarding Wizard.
 *
 * Multi-step replacement for the bare "name + slug" form. Walks a master
 * admin through:
 *   1. Identity         — name, slug, primary color, logo
 *   2. Modules          — pick which modules + per-module shared/isolated
 *   3. Defaults         — placeholder for future seeding (CoA / approvals
 *                          / pay schedules). Currently informational only.
 *   4. Invite users     — list emails of existing platform users to attach
 *
 * On finish: POST /api/sub_tenants.php with everything in one transaction
 * and redirect to /admin/sub-tenants.
 */

const ALL_MODULES = [
  { key: 'people',     label: 'People',     defaultScope: 'shared',   defaultEnabled: true  },
  { key: 'placements', label: 'Placements', defaultScope: 'shared',   defaultEnabled: true  },
  { key: 'companies',  label: 'Companies',  defaultScope: 'shared',   defaultEnabled: true  },
  { key: 'crm',        label: 'CRM',        defaultScope: 'shared',   defaultEnabled: false },
  { key: 'time',       label: 'Time',       defaultScope: 'isolated', defaultEnabled: true  },
  { key: 'billing',    label: 'Billing',    defaultScope: 'isolated', defaultEnabled: true  },
  { key: 'ap',         label: 'AP',         defaultScope: 'isolated', defaultEnabled: true  },
  { key: 'accounting', label: 'Accounting', defaultScope: 'isolated', defaultEnabled: true  },
  { key: 'payroll',    label: 'Payroll',    defaultScope: 'isolated', defaultEnabled: false },
  { key: 'treasury',   label: 'Treasury',   defaultScope: 'isolated', defaultEnabled: false },
  { key: 'tax',        label: 'Tax',        defaultScope: 'isolated', defaultEnabled: false },
];

const SLUGIFY = (s) => (s || '')
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/^-+|-+$/g, '')
  .slice(0, 60);

export default function SubTenantWizard() {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);
  const [done, setDone] = useState(null);

  // Step 1: identity
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [primaryColor, setPrimaryColor] = useState('#2563eb');
  const [logoUrl, setLogoUrl] = useState('');

  // Step 2: modules
  const [moduleConfig, setModuleConfig] = useState(
    Object.fromEntries(ALL_MODULES.map(m => [m.key, { enabled: m.defaultEnabled, scope: m.defaultScope }]))
  );

  // Step 3 placeholder

  // Step 4: invites
  const [invites, setInvites] = useState([{ email: '', role: 'user' }]);

  const onNameChange = (v) => {
    setName(v);
    if (!slugTouched) setSlug(SLUGIFY(v));
  };
  const onSlugChange = (v) => { setSlug(SLUGIFY(v)); setSlugTouched(true); };

  const toggleModule = (key) => setModuleConfig(c => ({ ...c, [key]: { ...c[key], enabled: !c[key].enabled } }));
  const setScope    = (key, scope) => setModuleConfig(c => ({ ...c, [key]: { ...c[key], scope } }));

  const addInvite    = () => setInvites(v => [...v, { email: '', role: 'user' }]);
  const removeInvite = (i) => setInvites(v => v.filter((_, idx) => idx !== i));
  const setInvite    = (i, patch) => setInvites(v => v.map((row, idx) => idx === i ? { ...row, ...patch } : row));

  const canNext = step === 1 ? name.trim().length > 0 && slug.length > 0 : true;

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const modules = ALL_MODULES.filter(m => moduleConfig[m.key].enabled).map(m => m.key);
      const scope_overrides = Object.fromEntries(
        Object.entries(moduleConfig).filter(([, v]) => v.enabled).map(([k, v]) => [k, v.scope])
      );
      const cleanInvites = invites
        .filter(i => i.email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(i.email.trim()))
        .map(i => ({ email: i.email.trim().toLowerCase(), role: i.role }));
      const payload = {
        name: name.trim(),
        slug,
        primary_color: primaryColor || null,
        logo_url: logoUrl || null,
        modules,
        scope_overrides,
        invites: cleanInvites,
      };
      const r = await api.post('/api/sub_tenants.php', payload);
      setDone(r);
    } catch (e) {
      setError(e);
    } finally {
      setSubmitting(false);
    }
  };

  if (done) {
    return (
      <section data-testid="sub-tenant-wizard-done" style={{ padding: 24, maxWidth: 720 }}>
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 10, color: '#065f46', marginBottom: 16 }}>
          <Check size={28} />
          <h2 style={{ margin: 0 }}>Sub-tenant provisioned</h2>
        </div>
        <p>"{name}" is live. Tenant id <code>#{done.id}</code>.</p>
        <div style={{ display: 'flex', gap: 8, marginTop: 24, flexWrap: 'wrap' }}>
          <button
            className="btn btn--primary"
            onClick={async () => {
              try {
                await api.post('/api/sub_tenants.php?action=switch', { tenant_id: done.id });
                // Force a full reload so the SPA rehydrates with the new tenant
                // session (modules, manifest, sidebar, etc).
                window.location.href = '/';
              } catch (e) {
                setError(e);
              }
            }}
            data-testid="wizard-switch-into"
          >
            Switch into {name} now <ArrowRight size={14} />
          </button>
          <button className="btn" onClick={() => navigate('/admin/sub-tenants')} data-testid="wizard-back-to-list">
            Back to sub-tenants
          </button>
          <button className="btn btn--ghost" onClick={() => navigate('/admin/consolidated-reports')} data-testid="wizard-view-consolidated">
            View consolidated reports
          </button>
        </div>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 12, marginTop: 16 }}>
          Tip: switching now lets you finish setup (Chart of Accounts, approval policy, pay schedule)
          inside the new tenant. The dashboard will guide you with a Setup Checklist for the first 30 days.
        </p>
      </section>
    );
  }

  return (
    <section data-testid="sub-tenant-wizard" style={{ padding: 24, maxWidth: 880 }}>
      <header style={{ marginBottom: 24 }}>
        <button className="btn btn--ghost" onClick={() => navigate('/admin/sub-tenants')} data-testid="wizard-cancel">
          <ArrowLeft size={14} /> Cancel
        </button>
        <h2 style={{ margin: '12px 0 4px' }}>Provision a sub-tenant</h2>
        <Stepper step={step} />
      </header>

      {error && <div className="alert alert--err" data-testid="wizard-error">{error.message || 'Something went wrong'}</div>}

      {step === 1 && (
        <Step1
          name={name} onName={onNameChange}
          slug={slug} onSlug={onSlugChange}
          primaryColor={primaryColor} onPrimaryColor={setPrimaryColor}
          logoUrl={logoUrl} onLogoUrl={setLogoUrl}
        />
      )}
      {step === 2 && (
        <Step2 moduleConfig={moduleConfig} toggle={toggleModule} setScope={setScope} />
      )}
      {step === 3 && <Step3 />}
      {step === 4 && (
        <Step4 invites={invites} addInvite={addInvite} removeInvite={removeInvite} setInvite={setInvite} />
      )}

      <footer style={{ display: 'flex', justifyContent: 'space-between', marginTop: 32, paddingTop: 16, borderTop: '1px solid #e5e7eb' }}>
        <button className="btn btn--ghost" disabled={step === 1} onClick={() => setStep(s => s - 1)} data-testid="wizard-prev">
          <ArrowLeft size={14} /> Back
        </button>
        {step < 4 ? (
          <button className="btn btn--primary" disabled={!canNext} onClick={() => setStep(s => s + 1)} data-testid="wizard-next">
            Next <ArrowRight size={14} />
          </button>
        ) : (
          <button className="btn btn--primary" disabled={submitting} onClick={submit} data-testid="wizard-finish">
            {submitting ? 'Provisioning…' : 'Finish & provision'}
          </button>
        )}
      </footer>
    </section>
  );
}

function Stepper({ step }) {
  const labels = ['Identity', 'Modules', 'Defaults', 'Invites'];  return (
    <ol style={{ display: 'flex', gap: 8, listStyle: 'none', padding: 0, margin: 0, fontSize: 12, color: 'var(--cf-text-secondary)' }}
        data-testid="wizard-stepper">
      {labels.map((label, i) => {
        const idx = i + 1;
        const active = idx === step;
        const past = idx < step;
        return (
          <li key={label} data-testid={`wizard-step-${idx}`} style={{
            padding: '6px 12px', borderRadius: 999,
            background: active ? '#dbeafe' : past ? '#dcfce7' : '#f3f4f6',
            color: active ? '#1e40af' : past ? '#065f46' : '#6b7280',
            fontWeight: active ? 600 : 400,
          }}>
            {idx}. {label}
          </li>
        );
      })}
    </ol>
  );
}

function Step1({ name, onName, slug, onSlug, primaryColor, onPrimaryColor, logoUrl, onLogoUrl }) {
  return (
    <div data-testid="wizard-step1-body" style={{ display: 'grid', gap: 16 }}>
      <Field label="Sub-tenant name *" hint="Shown on the tenant switcher and login picker.">
        <input className="input" value={name} onChange={(e) => onName(e.target.value)} data-testid="wizard-name" placeholder="e.g. NY Engineering Co" />
      </Field>
      <Field label="Slug *" hint="URL-safe identifier. Auto-derived from name; override if needed.">
        <input className="input" value={slug} onChange={(e) => onSlug(e.target.value)} data-testid="wizard-slug" placeholder="ny-engineering" />
      </Field>
      <Field label="Primary color" hint="Used for buttons and the brand bar in this sub-tenant's UI.">
        <input type="color" value={primaryColor} onChange={(e) => onPrimaryColor(e.target.value)} data-testid="wizard-color"
               style={{ width: 80, height: 36, padding: 2 }} />
      </Field>
      <Field label="Logo URL (optional)">
        <input className="input" value={logoUrl} onChange={(e) => onLogoUrl(e.target.value)} data-testid="wizard-logo" placeholder="https://…/logo.png" />
      </Field>
    </div>
  );
}

function Step2({ moduleConfig, toggle, setScope }) {
  return (
    <div data-testid="wizard-step2-body">
      <p style={{ color: 'var(--cf-text-secondary)', marginBottom: 16, fontSize: 13 }}>
        Pick the modules this sub-tenant should access. <strong>Shared</strong> modules read/write parent tenant data
        (master controls, sub-tenants reuse). <strong>Isolated</strong> modules have their own data partition.
      </p>
      <table className="data-table" data-testid="wizard-modules-table" style={{ width: '100%' }}>
        <thead>
          <tr>
            <th style={{ width: 60 }}>Enable</th>
            <th>Module</th>
            <th>Scope</th>
          </tr>
        </thead>
        <tbody>
          {ALL_MODULES.map(m => {
            const cfg = moduleConfig[m.key];
            return (
              <tr key={m.key} data-testid={`wizard-module-row-${m.key}`}>
                <td>
                  <input type="checkbox" checked={cfg.enabled} onChange={() => toggle(m.key)}
                         data-testid={`wizard-module-toggle-${m.key}`} />
                </td>
                <td><strong>{m.label}</strong></td>
                <td>
                  <select className="input" value={cfg.scope} onChange={(e) => setScope(m.key, e.target.value)}
                          disabled={!cfg.enabled}
                          data-testid={`wizard-module-scope-${m.key}`}>
                    <option value="shared">shared (uses parent data)</option>
                    <option value="isolated">isolated (own data)</option>
                  </select>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function Step3() {
  return (
    <div data-testid="wizard-step3-body">
      <p style={{ color: 'var(--cf-text-secondary)', marginBottom: 16, fontSize: 13 }}>
        Defaults will be seeded after the sub-tenant is created. For now we apply
        sensible platform defaults (default Chart of Accounts, default approval policy,
        default pay schedule). You can customise these from the sub-tenant's
        admin once provisioning completes.
      </p>
      <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 8 }}>
        {[
          'Default US-GAAP chart of accounts (50+ accounts)',
          'Single-step approval policy for AP bills',
          'Bi-weekly pay schedule template',
          'Standard mileage + expense categories',
        ].map((item) => (
          <li key={item} data-testid="wizard-default-item" style={{
            background: '#f9fafb', padding: '10px 14px', borderRadius: 8,
            display: 'flex', gap: 10, alignItems: 'center', fontSize: 13,
          }}>
            <Check size={16} style={{ color: '#059669' }} /> {item}
          </li>
        ))}
      </ul>
      <p style={{ color: 'var(--cf-text-secondary)', marginTop: 16, fontSize: 12 }}>
        <strong>Heads-up:</strong> automated seeding is on the roadmap. Today these
        are applied lazily on first use of each module.
      </p>
    </div>
  );
}

function Step4({ invites, addInvite, removeInvite, setInvite }) {
  return (
    <div data-testid="wizard-step4-body">
      <p style={{ color: 'var(--cf-text-secondary)', marginBottom: 16, fontSize: 13 }}>
        Invite existing platform users to this sub-tenant. They'll see it in their
        tenant switcher right after provisioning. Net-new email invitations
        (people not yet on CoreFlux) will be sent in a follow-up release.
      </p>
      <div style={{ display: 'grid', gap: 8 }}>
        {invites.map((row, i) => (
          <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'center' }} data-testid={`wizard-invite-row-${i}`}>
            <input className="input" placeholder="user@example.com"
                   value={row.email} onChange={(e) => setInvite(i, { email: e.target.value })}
                   data-testid={`wizard-invite-email-${i}`} style={{ flex: 1 }} />
            <select className="input" value={row.role} onChange={(e) => setInvite(i, { role: e.target.value })}
                    data-testid={`wizard-invite-role-${i}`} style={{ width: 160 }}>
              <option value="user">user</option>
              <option value="manager">manager</option>
              <option value="approver">approver</option>
              <option value="admin">admin</option>
              <option value="tenant_admin">tenant_admin</option>
            </select>
            <button className="btn btn--ghost" onClick={() => removeInvite(i)}
                    disabled={invites.length === 1}
                    data-testid={`wizard-invite-remove-${i}`}><Trash2 size={14} /></button>
          </div>
        ))}
      </div>
      <button className="btn" onClick={addInvite} style={{ marginTop: 12 }} data-testid="wizard-invite-add">
        <Plus size={14} /> Add another
      </button>
    </div>
  );
}

function Field({ label, hint, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <span style={{ fontSize: 13, fontWeight: 500 }}>{label}</span>
      {children}
      {hint && <small style={{ color: 'var(--cf-text-secondary)', fontSize: 11 }}>{hint}</small>}
    </label>
  );
}
