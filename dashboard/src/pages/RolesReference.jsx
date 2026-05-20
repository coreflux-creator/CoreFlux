import React, { useMemo, useState } from 'react';
import { Shield, Globe2, Building2, User2, Wrench, ExternalLink, Info } from 'lucide-react';
import { useApi } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * RolesReference — read-only documentation page for `/admin/roles`.
 *
 * Answers "if I pick this persona_type, what am I authorising?" without
 * forcing admins to grep the codebase or chase support links. Fetches
 * /api/admin/roles_reference.php and renders each persona as a card
 * with: scope, default access level, wildcard module grants, specific
 * permissions, notes, and how it maps onto the legacy dual-check bridge.
 */

const SCOPE_META = {
  platform: { icon: Globe2,    label: 'Platform-wide',    tone: '#7c3aed' },
  tenant:   { icon: Building2, label: 'Tenant-scoped',    tone: '#2563eb' },
};

const ACCESS_TONE = {
  none:  '#94a3b8',
  read:  '#0ea5e9',
  write: '#f59e0b',
  admin: '#dc2626',
};

export default function RolesReference({ session }) {
  const { data, loading, error } = useApi('/api/admin/roles_reference.php');
  const [filter, setFilter] = useState('');

  const roles = useMemo(() => {
    const rows = data?.roles || [];
    const q = filter.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter(r =>
      (r.label || '').toLowerCase().includes(q) ||
      (r.key   || '').toLowerCase().includes(q) ||
      (r.summary || '').toLowerCase().includes(q)
    );
  }, [data, filter]);

  return (
    <div data-testid="roles-reference">
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
          <Shield size={22} />
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700 }}>Roles reference</h1>
        </div>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14, maxWidth: 720 }}>
          What each persona_type grants. Picking a role on a membership doesn't grant the
          listed permissions outright — it sets the user's <em>baseline</em> in the legacy
          dual-check bridge. Final access is the intersection of this role and the membership's
          per-module access level (read / write / admin).
        </p>
      </div>

      <div style={{ marginBottom: 12 }}>
        <input
          className="input"
          placeholder="Filter roles by name or key…"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          style={{ maxWidth: 320 }}
          data-testid="roles-reference-filter"
        />
      </div>

      {loading && <Card><p>Loading…</p></Card>}
      {error   && <Card><p style={{ color: '#b91c1c' }} data-testid="roles-reference-error">{error.message || String(error)}</p></Card>}

      {!loading && !error && (
        <>
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(380px, 1fr))',
            gap: 'var(--cf-space-4)',
          }} data-testid="roles-reference-grid">
            {roles.map(r => <RoleCard key={r.key} role={r} />)}
            {roles.length === 0 && (
              <Card><p style={{ color: 'var(--cf-text-secondary)' }}>No roles match the filter.</p></Card>
            )}
          </div>

          <div style={{ marginTop: 'var(--cf-space-6)' }}>
            <Legend legend={data?.legend || {}} />
          </div>
        </>
      )}
    </div>
  );
}

function RoleCard({ role }) {
  const scope = SCOPE_META[role.scope] || SCOPE_META.tenant;
  const ScopeIcon = scope.icon;
  return (
    <Card data-testid={`roles-reference-card-${role.key}`}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 8, marginBottom: 8 }}>
        <div>
          <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', fontFamily: 'monospace' }}
               data-testid={`roles-reference-key-${role.key}`}>{role.key}</div>
          <div style={{ fontSize: 18, fontWeight: 600 }}>{role.label}</div>
        </div>
        <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
          <Pill icon={ScopeIcon} tone={scope.tone}>{scope.label}</Pill>
          <Pill tone={ACCESS_TONE[role.default_access_level] || '#64748b'}
                data-testid={`roles-reference-default-${role.key}`}>
            {role.default_access_level}
          </Pill>
        </div>
      </div>

      <p style={{ color: 'var(--cf-text-primary)', fontSize: 14, marginBottom: 12 }}>
        {role.summary}
      </p>

      {role.grants_wildcard_modules?.length > 0 && (
        <Block title="Grants full access to (legacy bridge)" testid={`roles-reference-wildcards-${role.key}`}>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
            {role.grants_wildcard_modules.map(m => (
              <code key={m} style={{
                background: '#f1f5f9', padding: '2px 6px', borderRadius: 4, fontSize: 12,
              }}>{m === '*' ? '* (everything)' : `${m}.*`}</code>
            ))}
          </div>
        </Block>
      )}

      {role.grants_specific_perms?.length > 0 && (
        <Block title={`Specific permissions (${role.grants_specific_perms.length})`}
               testid={`roles-reference-specifics-${role.key}`}>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, maxHeight: 96, overflowY: 'auto' }}>
            {role.grants_specific_perms.map(p => (
              <code key={p} style={{
                background: '#f8fafc', padding: '2px 6px', borderRadius: 4, fontSize: 12,
                border: '1px solid #e2e8f0',
              }}>{p}</code>
            ))}
          </div>
        </Block>
      )}

      {role.notes?.length > 0 && (
        <Block title="Notes" testid={`roles-reference-notes-${role.key}`}>
          <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            {role.notes.map((n, i) => <li key={i} style={{ marginBottom: 4 }}>{n}</li>)}
          </ul>
        </Block>
      )}

      <div style={{
        marginTop: 12, paddingTop: 8, borderTop: '1px dashed #e2e8f0',
        display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--cf-text-secondary)',
      }}>
        <Wrench size={12} />
        Legacy bridge falls back to: <code style={{ fontFamily: 'monospace' }}>{role.legacy_role_mapping}</code>
      </div>
    </Card>
  );
}

function Block({ title, testid, children }) {
  return (
    <div style={{ marginBottom: 10 }} data-testid={testid}>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--cf-text-secondary)', marginBottom: 4, textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {title}
      </div>
      {children}
    </div>
  );
}

function Pill({ icon: Icon, tone, children, ...rest }) {
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      padding: '2px 8px', borderRadius: 999, fontSize: 12,
      background: tone + '20', color: tone, fontWeight: 600,
    }} {...rest}>
      {Icon ? <Icon size={12} /> : null}
      {children}
    </span>
  );
}

function Legend({ legend }) {
  const entries = Object.entries(legend);
  if (!entries.length) return null;
  return (
    <Card data-testid="roles-reference-legend">
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8 }}>
        <Info size={16} />
        <h2 style={{ fontSize: 16, fontWeight: 600 }}>Glossary</h2>
      </div>
      <dl style={{ margin: 0, display: 'grid', gridTemplateColumns: 'minmax(180px, max-content) 1fr', gap: '4px 16px' }}>
        {entries.map(([k, v]) => (
          <React.Fragment key={k}>
            <dt style={{ fontFamily: 'monospace', fontSize: 12, color: '#0f172a' }}>{k}</dt>
            <dd style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: 0 }}>{v}</dd>
          </React.Fragment>
        ))}
      </dl>
    </Card>
  );
}
