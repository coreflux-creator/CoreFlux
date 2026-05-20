import React from 'react';
import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';

/**
 * CFOGuard — client-side gate matching api_require_cfo() on the backend.
 *
 * Allowed:
 *   - user.global_role === 'master_admin'  OR  is_global_admin === 1
 *   - user.role in ('tenant_admin','admin') at the active tenant
 *
 * Anyone else who deep-links to /cfo lands on a friendly Forbidden card
 * rather than seeing a broken dashboard riddled with 403s from every API
 * call the dashboard makes on mount.
 */
export default function CFOGuard({ session, children }) {
  const user        = session?.user || {};
  const role        = user.role        || '';
  const globalRole  = user.global_role || '';
  const isGlobalAdm = !!user.is_global_admin;

  const allowed = globalRole === 'master_admin'
              || isGlobalAdm
              || ['tenant_admin', 'admin'].includes(role);

  if (allowed) return children;

  return (
    <div data-testid="cfo-forbidden"
         style={{ maxWidth: 540, margin: '8vh auto', padding: 32,
                  borderRadius: 12, border: '1px solid var(--cf-border)',
                  background: 'var(--cf-surface, #fff)', textAlign: 'center' }}>
      <ShieldAlert size={42} color="#b45309" style={{ marginBottom: 16 }} />
      <h2 style={{ fontSize: 20, fontWeight: 700, marginBottom: 8 }}>
        CFO Dashboard is restricted
      </h2>
      <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14, lineHeight: 1.6 }}>
        Access to this surface requires a <strong>master_admin</strong>,
        <strong> tenant_admin</strong>, or an explicit CFO grant on your
        membership. If you should have access, ask your administrator to
        grant the <code>cfo</code> module on your tenant membership.
      </p>
      <Link to="/" className="btn btn--primary"
            style={{ marginTop: 18, display: 'inline-block' }}
            data-testid="cfo-forbidden-home">
        Back to Dashboard
      </Link>
    </div>
  );
}
