import React from 'react';
import { Link } from 'react-router-dom';
import { Building2, ArrowUpRight, TrendingUp } from 'lucide-react';
import { useApi } from '../lib/api';

const fmt$ = (cents) => {
  if (typeof cents !== 'number') return '$0';
  const dollars = cents / 100;
  return '$' + dollars.toLocaleString(undefined, {
    minimumFractionDigits: dollars > 9999 ? 0 : 2,
    maximumFractionDigits: dollars > 9999 ? 0 : 2,
  });
};

/**
 * Master-tenant fleet view. Renders only when the current tenant is a
 * `master` (i.e. the analytics endpoint succeeds) — sub-tenants and
 * single-tenant accounts get a 400 and the card stays hidden.
 */
const SubTenantSummaryCard = ({ session }) => {
  const { data, error, loading } = useApi('/api/sub_tenant_analytics.php');

  if (loading) return null;
  if (error) return null;       // not a master, or no permission — stay quiet
  if (!data) return null;
  if ((data.total_sub_tenants || 0) === 0) return null;

  const lastSub = data.last_active_sub;

  return (
    <section data-testid="sub-tenant-summary-card" style={{ marginBottom: 'var(--cf-space-8)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 12 }}>
        <h2 style={{ fontSize: 18, fontWeight: 600, margin: 0 }}>
          <Building2 size={18} style={{ display: 'inline', marginRight: 6, marginBottom: -3 }} />
          Sub-tenant fleet
        </h2>
        <Link
          to="/admin/sub-tenants"
          style={{ fontSize: 13, color: 'var(--cf-accent)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 4 }}
          data-testid="sub-tenant-summary-manage-link"
        >
          Manage <ArrowUpRight size={14} />
        </Link>
      </div>

      <div style={{
        display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12,
        background: 'var(--cf-bg-elev)', border: '1px solid var(--cf-border)', borderRadius: 8, padding: 16,
      }}>
        <Stat
          label="Active sub-tenants"
          value={`${data.active_sub_tenants}/${data.total_sub_tenants}`}
          testid="sub-tenant-stat-active"
        />
        <Stat
          label="Posted this month"
          value={fmt$(data.posted_this_month_cents)}
          icon={<TrendingUp size={14} />}
          testid="sub-tenant-stat-posted"
        />
        <Stat
          label="AR outstanding"
          value={fmt$(data.ar_outstanding_cents)}
          testid="sub-tenant-stat-ar"
        />
        <Stat
          label="Last active"
          value={lastSub ? lastSub.name : '—'}
          sub={lastSub?.last_active_at?.split(' ')[0] || ''}
          testid="sub-tenant-stat-last-active"
        />
      </div>

      {data.by_sub && data.by_sub.length > 0 && (
        <div style={{ marginTop: 12, fontSize: 13 }}>
          <table className="data-table" style={{ width: '100%' }} data-testid="sub-tenant-summary-table">
            <thead>
              <tr>
                <th style={{ textAlign: 'left' }}>Sub-tenant</th>
                <th style={{ textAlign: 'right' }}>JEs (mo)</th>
                <th style={{ textAlign: 'right' }}>$ posted (mo)</th>
                <th style={{ textAlign: 'left' }}>Last entry</th>
              </tr>
            </thead>
            <tbody>
              {data.by_sub.map((s) => (
                <tr key={s.id} data-testid={`sub-tenant-summary-row-${s.id}`}
                    style={{ opacity: s.is_active ? 1 : 0.5 }}>
                  <td>{s.name}</td>
                  <td style={{ textAlign: 'right' }}>{s.je_count}</td>
                  <td style={{ textAlign: 'right' }}>{fmt$(s.posted_cents)}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>
                    {(s.last_je_at || '').split(' ')[0] || '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
};

const Stat = ({ label, value, sub, icon, testid }) => (
  <div data-testid={testid}>
    <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 4 }}>
      {label}
    </div>
    <div style={{ fontSize: 22, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
      {icon}{value}
    </div>
    {sub && <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{sub}</div>}
  </div>
);

export default SubTenantSummaryCard;
