import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Building2, Check } from 'lucide-react';

/**
 * Post-login tenant picker. Lists user's accessible tenants and routes to
 * /switch_tenant.php?tenant_id=X&next=/spa.php on click. If `?auto=1` is set
 * (or there is exactly one membership), auto-redirects after 200ms.
 */
const TenantPicker = ({ session }) => {
  const navigate = useNavigate();
  const tenants = session?.tenants || [];
  const currentId = session?.tenant_id;
  const [redirecting, setRedirecting] = useState(false);

  useEffect(() => {
    if (tenants.length === 1) {
      setRedirecting(true);
      window.location.href = `/switch_tenant.php?tenant_id=${tenants[0].id}&next=/spa.php`;
    }
  }, [tenants]);

  if (tenants.length === 0) {
    return (
      <div className="page-wrap" style={{ padding: 48, textAlign: 'center' }} data-testid="tenant-picker-empty">
        <h2>No tenants assigned</h2>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Contact your administrator to grant access.</p>
      </div>
    );
  }

  return (
    <div className="page-wrap" data-testid="tenant-picker">
      <div style={{ maxWidth: 560, margin: '64px auto', padding: 24 }}>
        <h1 style={{ fontSize: 24, fontWeight: 700, marginBottom: 8 }}>
          <Building2 size={22} style={{ display: 'inline', marginRight: 8 }} />
          Choose a workspace
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)', marginBottom: 24 }}>
          You have access to {tenants.length} workspace{tenants.length === 1 ? '' : 's'}.
          Pick one to continue.
        </p>

        {redirecting && (
          <div className="alert alert--ok" data-testid="tenant-picker-redirecting">
            Redirecting to your workspace…
          </div>
        )}

        <div style={{ display: 'grid', gap: 12 }}>
          {tenants.map((t) => (
            <a
              key={t.id || t.name}
              href={`/switch_tenant.php?tenant_id=${t.id}&next=/spa.php`}
              className="card card--interactive"
              data-testid={`tenant-picker-option-${t.id}`}
              style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '16px 20px', textDecoration: 'none', color: 'inherit',
                border: '1px solid var(--cf-border)', borderRadius: 8,
              }}
            >
              <div>
                <div style={{ fontWeight: 600, fontSize: 16 }}>{t.name}</div>
                <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  Role: {t.role || 'member'}
                </div>
              </div>
              {(t.id === currentId) && (
                <span className="badge badge--ok">
                  <Check size={12} /> active
                </span>
              )}
            </a>
          ))}
        </div>
      </div>
    </div>
  );
};

export default TenantPicker;
