import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { ScrollText, RefreshCw } from 'lucide-react';

/**
 * CpaAuditPage — CPA-portfolio-scoped audit feed.
 *
 * Pulls /api/admin/cpa_audit.php which unions cross_tenant_accounting_audit
 * rows AND membership_audit rows where any tenant in the user's CPA
 * portfolio is involved (acting or either side). The endpoint scopes by
 * the caller's firm memberships, so any firm-side persona can open this
 * page without admin gating.
 *
 * Mounted at /admin/cpa-audit.
 */

const DATE_INPUT_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

function SourceBadge({ src }) {
  const tone = src === 'accounting'
    ? { bg: '#0c4a6e22', fg: '#0c4a6e' }
    : { bg: '#a06a0022', fg: '#a06a00' };
  return (
    <span data-testid={`cpa-audit-source-${src}`}
          style={{ background: tone.bg, color: tone.fg, fontSize: 10,
                   padding: '2px 6px', borderRadius: 6, fontWeight: 600,
                   textTransform: 'uppercase' }}>{src}</span>
  );
}

export default function CpaAuditPage() {
  const [rows, setRows]       = useState(null);
  const [tenantIds, setTids]  = useState([]);
  const [since, setSince]     = useState('');
  const [action, setAction]   = useState('');
  const [limit, setLimit]     = useState(200);
  const [error, setError]     = useState(null);

  const reload = async () => {
    try {
      setError(null);
      const params = new URLSearchParams();
      if (since)  params.set('since', since);
      if (action) params.set('action', action);
      params.set('limit', String(limit));
      const r = await api.get(`/api/admin/cpa_audit.php?${params.toString()}`);
      setRows(Array.isArray(r?.rows) ? r.rows : []);
      setTids(Array.isArray(r?.tenant_ids) ? r.tenant_ids : []);
    } catch (e) {
      setRows([]);
      setError(e.message || 'Failed to load audit feed');
    }
  };

  useEffect(() => { reload(); /* eslint-disable-next-line */ }, []);

  const validateAndApply = () => {
    if (since && !DATE_INPUT_PATTERN.test(since)) {
      setError('Since must be YYYY-MM-DD');
      return;
    }
    reload();
  };

  const distinctActions = useMemo(() => {
    const s = new Set();
    (rows || []).forEach(r => { if (r.action) s.add(r.action); });
    return Array.from(s).sort();
  }, [rows]);

  return (
    <div data-testid="cpa-audit-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, gap: 8, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <ScrollText size={20} /> CPA cross-tenant audit
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Every accounting + membership event across {tenantIds.length} client tenant{tenantIds.length === 1 ? '' : 's'} your firms manage.
          </p>
        </div>
        <button onClick={reload} className="btn btn--ghost" data-testid="cpa-audit-refresh">
          <RefreshCw size={14} style={{ marginRight: 6 }} />Refresh
        </button>
      </div>

      <Card>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, alignItems: 'end' }}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Since</span>
            <input
              type="date"
              value={since}
              onChange={(e) => setSince(e.target.value)}
              data-testid="cpa-audit-since"
            />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Action</span>
            <input
              value={action}
              onChange={(e) => setAction(e.target.value)}
              placeholder="filter by action"
              list="cpa-audit-action-list"
              data-testid="cpa-audit-action"
            />
            <datalist id="cpa-audit-action-list">
              {distinctActions.map(a => <option key={a} value={a} />)}
            </datalist>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Limit</span>
            <select
              value={limit}
              onChange={(e) => setLimit(Number(e.target.value))}
              data-testid="cpa-audit-limit"
            >
              <option value={50}>50</option>
              <option value={100}>100</option>
              <option value={200}>200</option>
              <option value={500}>500</option>
            </select>
          </label>
          <button onClick={validateAndApply} className="btn btn--primary" data-testid="cpa-audit-apply">Apply</button>
        </div>
      </Card>

      {error && (
        <Card data-testid="cpa-audit-error" style={{ background: '#fff1f0', border: '1px solid #ffccc7', marginTop: 12 }}>
          {error}
        </Card>
      )}

      <Card style={{ marginTop: 12 }} data-testid="cpa-audit-table-wrap">
        {!rows && <div data-testid="cpa-audit-loading">Loading…</div>}
        {rows && rows.length === 0 && (
          <div data-testid="cpa-audit-empty" style={{ padding: 20, color: 'var(--cf-text-secondary)' }}>
            No events for the current filters.
          </div>
        )}
        {rows && rows.length > 0 && (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }} data-testid="cpa-audit-table">
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)' }}>
                <th style={{ padding: '6px 4px' }}>When</th>
                <th style={{ padding: '6px 4px' }}>Source</th>
                <th style={{ padding: '6px 4px' }}>Action</th>
                <th style={{ padding: '6px 4px' }}>Acting tenant</th>
                <th style={{ padding: '6px 4px' }}>Counterparty</th>
                <th style={{ padding: '6px 4px' }}>Actor user</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={`${r.source}-${r.id}`}
                    style={{ borderTop: '1px solid var(--cf-border)' }}
                    data-testid={`cpa-audit-row-${r.source}-${r.id}`}>
                  <td style={{ padding: '8px 4px', fontFamily: 'monospace', fontSize: 11 }}>{r.occurred_at}</td>
                  <td style={{ padding: '8px 4px' }}><SourceBadge src={r.source} /></td>
                  <td style={{ padding: '8px 4px', fontFamily: 'monospace', fontSize: 12 }}>{r.action}</td>
                  <td style={{ padding: '8px 4px' }}>
                    {r.acting_tenant_name || `Tenant #${r.acting_tenant_id}`}
                  </td>
                  <td style={{ padding: '8px 4px' }}>
                    {r.right_tenant_id
                      ? `${r.left_tenant_name || `#${r.left_tenant_id}`} ⇄ ${r.right_tenant_name || `#${r.right_tenant_id}`}`
                      : (r.left_tenant_name || (r.left_tenant_id ? `#${r.left_tenant_id}` : '—'))}
                  </td>
                  <td style={{ padding: '8px 4px', fontSize: 12 }}>{r.actor_label || (r.actor_user_id ? `User #${r.actor_user_id}` : '—')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  );
}
