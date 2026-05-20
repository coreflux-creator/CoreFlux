import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Shield, ShieldCheck, ShieldAlert, X, Filter, RefreshCw } from 'lucide-react';

/**
 * UserEffectivePermissionsModal — "View permissions" drawer for /admin/users.
 *
 * Answers "why can/can't this user do X?" without DB access. Renders:
 *   1. User card (name, email, role, is_global_admin badge)
 *   2. Per-tenant table of memberships + module-access matrix
 *   3. The dual-check can() verdict for every permission in the B4 map,
 *      filterable, with a per-row legacy/new split so the admin can see
 *      exactly which layer denied.
 */

const LEVEL_TONE = {
  admin: '#16a34a',
  write: '#0284c7',
  read:  '#7c3aed',
  none:  '#94a3b8',
};

function Pill({ children, tone = '#94a3b8', filled = false }) {
  return (
    <span style={{
      display: 'inline-block', fontSize: 10, fontWeight: 700,
      padding: '2px 7px', borderRadius: 8, letterSpacing: 0.3,
      textTransform: 'uppercase',
      color: filled ? '#fff' : tone,
      background: filled ? tone : tone + '22',
    }}>{children}</span>
  );
}

export default function UserEffectivePermissionsModal({ userId, onClose }) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [filter, setFilter]   = useState('');
  const [onlyDenied, setOnlyDenied] = useState(false);
  const [onlyDisagreement, setOnlyDisagreement] = useState(false);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/admin/user_effective_permissions.php?user_id=${userId}`);
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load permissions'); }
    finally { setLoading(false); }
  };

  useEffect(() => { if (userId) load(); /* eslint-disable-next-line */ }, [userId]);

  if (!userId) return null;

  const matrixRows = data ? Object.entries(data.can_matrix || {}) : [];
  const visibleRows = matrixRows.filter(([perm, info]) => {
    if (filter && !perm.toLowerCase().includes(filter.toLowerCase())) return false;
    if (onlyDenied && info.allowed) return false;
    if (onlyDisagreement && !info.parked && info.legacy_ok === info.new_ok) return false;
    return true;
  });

  return (
    <div data-testid="user-effective-permissions-modal"
         onClick={onClose}
         style={{
           position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)',
           display: 'flex', alignItems: 'center', justifyContent: 'center',
           zIndex: 1000, padding: 24,
         }}>
      <div onClick={(e) => e.stopPropagation()}
           style={{
             background: 'var(--cf-bg, #fff)', borderRadius: 10, maxWidth: 1000,
             width: '100%', maxHeight: '90vh', overflow: 'auto',
             boxShadow: '0 20px 60px rgba(0,0,0,0.3)',
           }}>
        <div style={{ position: 'sticky', top: 0, background: 'var(--cf-bg, #fff)',
                      borderBottom: '1px solid var(--cf-border)', padding: 'var(--cf-space-4)',
                      display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Shield size={18} />
            <strong style={{ fontSize: 15 }}>Effective permissions</strong>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <button onClick={load} className="btn btn--ghost btn--sm" aria-label="Refresh"
                    data-testid="user-permissions-refresh">
              <RefreshCw size={14} />
            </button>
            <button onClick={onClose} className="btn btn--ghost btn--sm" aria-label="Close"
                    data-testid="user-permissions-close">
              <X size={14} />
            </button>
          </div>
        </div>

        <div style={{ padding: 'var(--cf-space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--cf-space-4)' }}>
          {loading && <div style={{ color: 'var(--cf-text-secondary)' }}>Loading…</div>}
          {error && <div style={{ color: '#b91c1c' }} data-testid="user-permissions-error">{error}</div>}

          {!loading && !error && data && (
            <>
              {/* User card */}
              <Card data-testid="user-permissions-user">
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                  <div style={{ flex: 1, minWidth: 200 }}>
                    <div style={{ fontSize: 16, fontWeight: 600 }}>{data.user.name || '(no name)'}</div>
                    <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{data.user.email}</div>
                  </div>
                  <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <Pill tone="#0f172a">{data.user.role}</Pill>
                    {data.user.is_global_admin && (
                      <span data-testid="user-permissions-global-admin"
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                                     fontSize: 11, fontWeight: 700, padding: '4px 8px',
                                     background: '#16a34a22', color: '#15803d', borderRadius: 8 }}>
                        <ShieldCheck size={12} /> GLOBAL ADMIN
                      </span>
                    )}
                  </div>
                </div>
              </Card>

              {/* Tenants + memberships */}
              {data.tenants.length === 0 && (
                <Card data-testid="user-permissions-no-tenants">
                  <div style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                    No tenant memberships found. This user can log in but the dual-check bridge
                    will deny every gated action until a membership is created in
                    /admin/memberships.
                  </div>
                </Card>
              )}
              {data.tenants.map((t) => (
                <Card key={t.tenant_id} data-testid={`user-permissions-tenant-${t.tenant_id}`}>
                  <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 8 }}>
                    <strong style={{ fontSize: 14 }}>{t.tenant_name}</strong>
                    <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                      tenant #{t.tenant_id}
                    </span>
                    {t.legacy_role && (
                      <span style={{ marginLeft: 'auto' }}>
                        <Pill tone="#475569">legacy: {t.legacy_role}</Pill>
                      </span>
                    )}
                  </div>
                  {t.memberships.length === 0 && (
                    <div style={{ fontSize: 12, color: '#b91c1c' }}>
                      No tenant_memberships row — user is "orphaned" on the new RBAC model for this tenant.
                    </div>
                  )}
                  {t.memberships.map((m) => (
                    <div key={m.id}
                         data-testid={`user-permissions-membership-${m.id}`}
                         style={{ borderTop: '1px dashed var(--cf-border)', paddingTop: 8, marginTop: 8 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap', marginBottom: 6 }}>
                        <strong style={{ fontSize: 13 }}>{m.persona_label}</strong>
                        <Pill tone="#0f172a">{m.persona_type}</Pill>
                        {m.is_primary && <Pill tone="#16a34a">PRIMARY</Pill>}
                        <Pill tone={m.status === 'active' ? '#16a34a' : '#a16207'}>{m.status}</Pill>
                      </div>
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 6 }}>
                        {Object.entries(m.module_access || {}).length === 0 && (
                          <span style={{ fontSize: 12, color: '#b91c1c' }}>No module grants on this membership.</span>
                        )}
                        {Object.entries(m.module_access || {}).map(([mod, lvl]) => (
                          <div key={mod}
                               data-testid={`user-permissions-module-${m.id}-${mod}`}
                               style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                                        padding: '4px 8px', background: 'var(--cf-surface-alt, #fafafa)',
                                        borderRadius: 6, fontSize: 12 }}>
                            <span style={{ fontFamily: 'monospace' }}>{mod}</span>
                            <Pill tone={LEVEL_TONE[lvl] || '#64748b'}>{lvl}</Pill>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </Card>
              ))}

              {/* Permission matrix */}
              <Card data-testid="user-permissions-matrix-card">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8, flexWrap: 'wrap' }}>
                  <strong style={{ fontSize: 14 }}>Permission matrix</strong>
                  <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                    {data.summary.total_perms_checked} permissions evaluated for the primary tenant
                  </span>
                  <div style={{ marginLeft: 'auto', display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                    <Filter size={12} />
                    <input value={filter} onChange={(e) => setFilter(e.target.value)}
                           placeholder="Filter…"
                           data-testid="user-permissions-filter"
                           style={{ fontSize: 12, padding: '2px 6px', minWidth: 120 }} />
                    <label style={{ fontSize: 11, display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                      <input type="checkbox" checked={onlyDenied}
                             onChange={(e) => setOnlyDenied(e.target.checked)}
                             data-testid="user-permissions-only-denied" />
                      denied
                    </label>
                    <label style={{ fontSize: 11, display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                      <input type="checkbox" checked={onlyDisagreement}
                             onChange={(e) => setOnlyDisagreement(e.target.checked)}
                             data-testid="user-permissions-only-disagreement" />
                      disagreements
                    </label>
                  </div>
                </div>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}
                       data-testid="user-permissions-matrix">
                  <thead>
                    <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)' }}>
                      <th style={{ padding: '4px 6px' }}>Permission</th>
                      <th>Module</th>
                      <th>Action</th>
                      <th>Legacy</th>
                      <th>New</th>
                      <th>Verdict</th>
                    </tr>
                  </thead>
                  <tbody>
                    {visibleRows.map(([perm, info]) => (
                      <tr key={perm}
                          data-testid={`user-permissions-row-${perm}`}
                          style={{ borderTop: '1px solid var(--cf-border)' }}>
                        <td style={{ padding: '4px 6px', fontFamily: 'monospace' }}>{perm}</td>
                        <td><Pill tone="#0f172a">{info.module}</Pill></td>
                        <td><Pill tone={LEVEL_TONE[info.action] || '#64748b'}>{info.action}</Pill></td>
                        <td style={{ color: info.legacy_ok ? '#16a34a' : '#b91c1c' }}>
                          {info.legacy_ok ? '✓' : '✗'}
                        </td>
                        <td style={{ color: info.parked ? '#94a3b8' : (info.new_ok ? '#16a34a' : '#b91c1c') }}>
                          {info.parked ? '—' : (info.new_ok ? '✓' : '✗')}
                        </td>
                        <td>
                          {info.allowed
                            ? <span style={{ color: '#16a34a', fontWeight: 600 }}>ALLOW</span>
                            : <span style={{ color: '#b91c1c', fontWeight: 600 }}>DENY</span>}
                          {info.parked && <Pill tone="#a16207">PARKED</Pill>}
                        </td>
                      </tr>
                    ))}
                    {visibleRows.length === 0 && (
                      <tr><td colSpan={6} style={{ padding: 10, color: 'var(--cf-text-secondary)' }}
                              data-testid="user-permissions-matrix-empty">
                        No rows match the current filter.
                      </td></tr>
                    )}
                  </tbody>
                </table>
              </Card>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
