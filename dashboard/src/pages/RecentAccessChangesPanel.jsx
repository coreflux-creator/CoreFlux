import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { History, RefreshCw } from 'lucide-react';

/**
 * RecentAccessChangesPanel — last N rows from membership_audit.
 *
 * Tiny SoD compliance receipt that we already audit-log on every grant/
 * revoke/persona-switch via RBACResolver::auditMembership(). Rendering it
 * costs almost nothing because the rows are already there.
 *
 * Renders compactly so it can sit on the AdminOverview alongside other
 * cards, or full-width on the RbacMembershipsAdmin page.
 */
const ACTION_LABEL = {
  created:              'created membership for',
  updated:              'updated membership for',
  revoked:              'revoked membership of',
  module_grant:         'granted module access to',
  module_revoke:        'revoked module access from',
  persona_switched:     'switched persona for',
  permissions_copied:   'copied permissions to',
  invited:              'invited',
  accepted:             'accepted membership of',
  suspended:            'suspended',
};

function actionTone(action) {
  if (action === 'revoked' || action === 'module_revoke' || action === 'suspended') return '#b94a4a';
  if (action === 'permissions_copied' || action === 'module_grant' || action === 'created') return '#2f7a3b';
  return 'var(--cf-text-secondary)';
}

function describeDetail(action, detail) {
  if (!detail) return null;
  if (action === 'module_grant') {
    const scope = Array.isArray(detail.sub_tenant_scope)
      ? ` (sub-tenants: ${detail.sub_tenant_scope.join(', ')})`
      : '';
    return `${detail.module}: ${detail.level}${scope}`;
  }
  if (action === 'module_revoke') return `${detail.module || ''}`;
  if (action === 'permissions_copied') {
    return `from membership #${detail.from_membership_id} — ${detail.grants_copied} grants`;
  }
  if (action === 'created' || action === 'updated') {
    const bits = [];
    if (detail.persona_label) bits.push(detail.persona_label);
    if (detail.persona_type)  bits.push(detail.persona_type);
    if (detail.status)        bits.push(`status=${detail.status}`);
    return bits.join(' · ');
  }
  return null;
}

function fmtWho(p) {
  if (!p) return 'someone';
  return p.name || p.email || (p.user_id ? `user #${p.user_id}` : 'someone');
}

function fmtTime(iso) {
  if (!iso) return '';
  const d = new Date(iso.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return iso;
  return d.toLocaleString();
}

export default function RecentAccessChangesPanel({ limit = 10, compact = false }) {
  const [entries, setEntries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error,   setError]   = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const res = await api.get(`/api/admin/membership_audit.php?limit=${limit}`);
      setEntries(res?.entries || []);
    } catch (e) {
      setError(e.message || 'Failed to load access changes');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [limit]);

  return (
    <Card data-testid="recent-access-changes">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 'var(--cf-space-3)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <History size={16} />
          <strong style={{ fontSize: 14 }}>Recent access changes</strong>
        </div>
        <button
          onClick={load}
          className="btn btn--ghost btn--sm"
          aria-label="Refresh"
          data-testid="recent-access-refresh"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
        >
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {loading && <div style={{ color: 'var(--cf-text-secondary)' }}>Loading…</div>}
      {error && !loading && <div style={{ color: '#b94a4a' }} data-testid="recent-access-error">{error}</div>}
      {!loading && !error && entries.length === 0 && (
        <div style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }} data-testid="recent-access-empty">
          No access changes yet. Grant or revoke a module to see entries here.
        </div>
      )}

      {!loading && !error && entries.length > 0 && (
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: compact ? 6 : 10 }}
            data-testid="recent-access-list">
          {entries.map((e) => (
            <li key={e.id} data-testid={`recent-access-row-${e.id}`}
                style={{ borderLeft: `3px solid ${actionTone(e.action)}`, paddingLeft: 10 }}>
              <div style={{ fontSize: 13, lineHeight: 1.45 }}>
                <strong>{fmtWho(e.actor)}</strong>{' '}
                <span style={{ color: actionTone(e.action) }}>
                  {ACTION_LABEL[e.action] || e.action}
                </span>{' '}
                <strong>{fmtWho(e.target)}</strong>
                {e.persona_label && (
                  <span style={{ color: 'var(--cf-text-secondary)' }}> · {e.persona_label}</span>
                )}
                {describeDetail(e.action, e.detail) && (
                  <span style={{ color: 'var(--cf-text-secondary)' }}>
                    {' '}— {describeDetail(e.action, e.detail)}
                  </span>
                )}
              </div>
              <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                {fmtTime(e.occurred_at)}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
