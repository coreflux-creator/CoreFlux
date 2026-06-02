import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Briefcase, ExternalLink, RefreshCw, Users } from 'lucide-react';

/**
 * CpaPortfolio — "My CPA clients" landing page.
 *
 * Surfaces every client tenant the current user can access via any
 * firm tenant they're a member of (master_admin / tenant_admin / cpa /
 * cpa_partner / cpa_staff / bookkeeper / client_advisor membership on
 * the firm tenant). Groups by firm so a user who works across multiple
 * CPA firms sees each portfolio separately.
 *
 * Each row exposes a "Jump in" button that calls
 * /api/sub_tenants.php?action=switch to flip the session's active tenant
 * to the client and reloads the SPA so the new tenant context propagates.
 *
 * Tenant-leak posture: portfolio data comes from
 * /api/admin/cpa_firms.php?action=portfolio which filters by the caller's
 * memberships server-side. This component renders whatever the API
 * returns and does no cross-tenant lookups of its own.
 *
 * Mounted at /cpa (top-level) and /admin/cpa-portfolio (admin nav).
 */

function StatusPill({ status }) {
  const tones = {
    active:  { bg: '#2f7a3b22', fg: '#2f7a3b' },
    paused:  { bg: '#a06a0022', fg: '#a06a00' },
    pending: { bg: '#0c4a6e22', fg: '#0c4a6e' },
    ended:   { bg: '#7a2a2a22', fg: '#7a2a2a' },
  };
  const t = tones[status] || tones.active;
  return (
    <span data-testid={`portfolio-status-${status}`}
          style={{
            background: t.bg, color: t.fg, fontSize: 11, padding: '2px 8px',
            borderRadius: 10, fontWeight: 600, textTransform: 'uppercase',
          }}>{status}</span>
  );
}

function RelationshipPill({ rt }) {
  const label = ({
    books_full:       'Books — full',
    books_review_only:'Books — review',
    tax_only:         'Tax only',
    advisory_only:    'Advisory',
    custom:           'Custom',
  })[rt] || rt;
  return (
    <span data-testid={`portfolio-relationship-${rt}`}
          style={{
            background: 'var(--cf-surface-alt, #f1f5f9)',
            color: 'var(--cf-text-secondary, #475569)',
            fontSize: 11, padding: '2px 8px', borderRadius: 10, fontWeight: 500,
          }}>{label}</span>
  );
}

export default function CpaPortfolio() {
  const [firms, setFirms]   = useState(null);
  const [error, setError]   = useState(null);
  const [jumpingTo, setJumpingTo] = useState(null);

  const reload = async () => {
    try {
      setError(null);
      const r = await api.get('/api/admin/cpa_firms.php?action=portfolio');
      setFirms(Array.isArray(r?.firms) ? r.firms : []);
    } catch (e) {
      setFirms([]);
      setError(e?.message || 'Failed to load portfolio');
    }
  };

  useEffect(() => { reload(); }, []);

  const totalClients = useMemo(
    () => (firms || []).reduce((acc, f) => acc + (f.clients || []).length, 0),
    [firms]
  );

  const jumpIn = async (clientTenantId, hasMembership) => {
    if (!hasMembership) {
      alert(
        'You do not yet have a direct membership on this client tenant. '
      + 'Ask the firm admin to add you to the membership grid before jumping in.'
      );
      return;
    }
    setJumpingTo(clientTenantId);
    try {
      await api.post('/api/sub_tenants.php?action=switch', { tenant_id: clientTenantId });
      // Full reload so App.jsx re-bootstraps with the new active tenant.
      window.location.href = '/';
    } catch (e) {
      alert(e.message || 'Switch failed');
      setJumpingTo(null);
    }
  };

  return (
    <div data-testid="cpa-portfolio">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <Briefcase size={20} /> My CPA clients
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Every client this firm manages. Click <strong>Jump in</strong> to flip your session
            into the client's books — your existing membership on that client decides what you can do once you're there.
          </p>
        </div>
        <button onClick={reload} className="btn btn--ghost" data-testid="cpa-portfolio-refresh">
          <RefreshCw size={14} style={{ marginRight: 6 }} />Refresh
        </button>
      </div>

      {error && (
        <Card data-testid="cpa-portfolio-error" style={{ background: '#fff1f0', border: '1px solid #ffccc7' }}>
          {error}
        </Card>
      )}

      {firms !== null && firms.length === 0 && (
        <Card data-testid="cpa-portfolio-empty">
          <div style={{ padding: 24, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>
            <Briefcase size={28} style={{ opacity: 0.4, marginBottom: 8 }} />
            <p style={{ margin: 0 }}>
              No client tenants linked to any firm you belong to yet.
            </p>
            <p style={{ margin: '4px 0 0', fontSize: 12 }}>
              A firm admin can wire client tenants via Admin → CPA firm → Clients.
            </p>
          </div>
        </Card>
      )}

      {firms && firms.length > 0 && (
        <>
          <div style={{ display: 'flex', gap: 12, marginBottom: 16 }}>
            <Card style={{ flex: 1 }} data-testid="cpa-portfolio-summary">
              <div style={{ display: 'flex', gap: 16 }}>
                <div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Firms</div>
                  <div style={{ fontSize: 24, fontWeight: 700 }} data-testid="cpa-portfolio-firms-count">{firms.length}</div>
                </div>
                <div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Clients</div>
                  <div style={{ fontSize: 24, fontWeight: 700 }} data-testid="cpa-portfolio-clients-count">{totalClients}</div>
                </div>
              </div>
            </Card>
          </div>

          {firms.map((firm) => (
            <Card key={firm.firm_tenant_id} data-testid={`cpa-portfolio-firm-${firm.firm_tenant_id}`} style={{ marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                <div>
                  <strong style={{ fontSize: 15 }}>{firm.firm_name}</strong>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                    <Users size={11} style={{ verticalAlign: 'middle', marginRight: 4 }} />
                    Your persona at this firm: <code>{firm.firm_persona || 'unknown'}</code>
                  </div>
                </div>
                <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  {(firm.clients || []).length} client{(firm.clients || []).length === 1 ? '' : 's'}
                </span>
              </div>

              <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid={`cpa-portfolio-table-${firm.firm_tenant_id}`}>
                <thead>
                  <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                    <th style={{ padding: '6px 4px' }}>Client</th>
                    <th style={{ padding: '6px 4px' }}>Status</th>
                    <th style={{ padding: '6px 4px' }}>Relationship</th>
                    <th style={{ padding: '6px 4px' }}>Your access</th>
                    <th style={{ padding: '6px 4px' }}></th>
                  </tr>
                </thead>
                <tbody>
                  {(firm.clients || []).map((c) => (
                    <tr key={c.link_id} style={{ borderTop: '1px solid var(--cf-border)' }} data-testid={`cpa-portfolio-row-${c.link_id}`}>
                      <td style={{ padding: '8px 4px', fontWeight: 500 }}>
                        {c.client_name}
                        {!c.client_is_active && (
                          <span style={{ marginLeft: 6, fontSize: 11, color: '#b94a4a' }}>(inactive)</span>
                        )}
                      </td>
                      <td style={{ padding: '8px 4px' }}><StatusPill status={c.status} /></td>
                      <td style={{ padding: '8px 4px' }}><RelationshipPill rt={c.relationship_type} /></td>
                      <td style={{ padding: '8px 4px', fontSize: 12 }}>
                        {c.has_client_membership
                          ? <code>{c.client_persona}</code>
                          : <em style={{ color: '#b94a4a' }}>no membership</em>}
                      </td>
                      <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                        <button
                          onClick={() => jumpIn(c.client_tenant_id, c.has_client_membership)}
                          className="btn btn--primary btn--sm"
                          disabled={jumpingTo === c.client_tenant_id || !c.has_client_membership}
                          data-testid={`cpa-portfolio-jump-${c.client_tenant_id}`}
                          title={c.has_client_membership ? 'Switch the active tenant to this client' : 'Ask the firm admin to seat you on this client first'}
                        >
                          <ExternalLink size={12} style={{ marginRight: 4 }} />
                          {jumpingTo === c.client_tenant_id ? 'Switching…' : 'Jump in'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          ))}
        </>
      )}
    </div>
  );
}
