import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Filter, ChevronRight, RefreshCw, ExternalLink } from 'lucide-react';
import { useApi } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * CrossTenantAuditAdmin — chronological feed of every save that crossed
 * tenant boundaries on Consolidation / Intercompany surfaces.
 *
 * Why this surface exists: parent-tenant admins can now wire consolidation
 * edges and intercompany mappings that span sub-tenant boundaries. SOX-style
 * attestations need a single feed answering "every cross-tenant accounting
 * decision and who made it." This is that feed.
 */
export default function CrossTenantAuditAdmin() {
  const [action, setAction] = useState('');
  const [since, setSince]   = useState('');
  const qs = new URLSearchParams();
  if (action) qs.set('action', action);
  if (since)  qs.set('since',  since);
  const { data, loading, error, reload } =
    useApi(`/api/admin/cross_tenant_audit.php?${qs.toString()}`);

  const rows    = data?.rows || [];
  const actions = data?.actions || [];

  const fmtTime = (iso) => iso?.replace('T', ' ').slice(0, 16) || '';

  return (
    <div data-testid="cross-tenant-audit">
      <div style={{ display: 'flex', justifyContent: 'space-between',
                    alignItems: 'flex-end', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            Cross-tenant audit trail
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Every consolidation edge and intercompany mapping that spans
            tenant boundaries lands here. Use it as your SOX evidence trail
            and as a one-stop rollback signal when an edge was wired wrong.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 4,
                          fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            <Filter size={12} /> Action
            <select className="input" value={action}
                    onChange={e => setAction(e.target.value)}
                    data-testid="xt-audit-action-filter"
                    style={{ marginLeft: 4, padding: '2px 6px' }}>
              <option value="">All</option>
              {actions.map(a => <option key={a} value={a}>{a}</option>)}
            </select>
          </label>
          <label style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Since
            <input type="date" className="input" value={since}
                   onChange={e => setSince(e.target.value)}
                   data-testid="xt-audit-since-filter"
                   style={{ marginLeft: 4, padding: '2px 6px' }} />
          </label>
          <button className="btn btn--ghost" onClick={reload}
                  data-testid="xt-audit-refresh" title="Refresh">
            <RefreshCw size={14} />
          </button>
        </div>
      </div>

      {loading && <Card><p>Loading audit trail…</p></Card>}
      {error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {!loading && !error && (
        <Card>
          <table className="data-table" data-testid="xt-audit-table">
            <thead>
              <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Acting tenant</th>
                <th>Action</th>
                <th>Edge</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={6} style={{ textAlign: 'center', padding: 28,
                                              color: 'var(--cf-text-secondary)' }}>
                  No cross-tenant accounting saves recorded yet.
                </td></tr>
              )}
              {rows.map(r => (
                <tr key={r.id} data-testid={`xt-audit-row-${r.id}`}>
                  <td style={{ fontSize: 12, fontVariantNumeric: 'tabular-nums',
                               color: 'var(--cf-text-secondary)' }}>
                    {fmtTime(r.occurred_at)}
                  </td>
                  <td>
                    <div style={{ fontWeight: 500 }}>
                      {r.actor_label || `User #${r.actor_user_id || '—'}`}
                    </div>
                    <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                      {r.ip || ''}
                    </div>
                  </td>
                  <td style={{ fontSize: 13 }}>{r.acting_tenant_name || `#${r.acting_tenant_id}`}</td>
                  <td>
                    <span style={{ padding: '2px 8px', borderRadius: 4,
                                   fontSize: 11, fontWeight: 600,
                                   background: r.action.startsWith('consolidation')
                                     ? '#dbeafe' : '#fef3c7',
                                   color: r.action.startsWith('consolidation')
                                     ? '#1d4ed8' : '#92400e' }}>
                      {r.action}
                    </span>
                  </td>
                  <td style={{ fontSize: 12 }}>
                    <strong>{r.left_tenant_name || `#${r.left_tenant_id}`}</strong>
                    <span style={{ display: 'inline-flex', alignItems: 'center',
                                   margin: '0 6px', color: '#6b7280' }}>
                      <ChevronRight size={12} />
                    </span>
                    <strong>{r.right_tenant_name || `#${r.right_tenant_id}`}</strong>
                    {(r.left_entity_id || r.right_entity_id) && (
                      <div style={{ fontSize: 10, color: '#6b7280',
                                    fontFamily: 'monospace' }}>
                        entities #{r.left_entity_id || '—'} → #{r.right_entity_id || '—'}
                      </div>
                    )}
                  </td>
                  <td style={{ maxWidth: 280 }}>
                    {r.payload && (
                      <details>
                        <summary style={{ fontSize: 11, cursor: 'pointer',
                                          color: '#6b7280' }}>payload</summary>
                        <pre style={{ fontSize: 10, lineHeight: 1.4,
                                       margin: '4px 0 0', whiteSpace: 'pre-wrap',
                                       maxHeight: 160, overflowY: 'auto',
                                       padding: 6, background: '#f9fafb',
                                       borderRadius: 4 }}>
                          {JSON.stringify(r.payload, null, 2)}
                        </pre>
                      </details>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {rows.length >= (data?.limit || 200) && (
            <div style={{ marginTop: 12, fontSize: 12, color: '#92400e' }}>
              Showing {data.limit} most-recent rows. Refine the filters to see older events.
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
