import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { LayoutDashboard, RefreshCw, AlertTriangle, Inbox, CalendarClock, ExternalLink } from 'lucide-react';

/**
 * CpaFirmDashboard — KPI rollup across every client tenant the user
 * sees via any firm membership.
 *
 * Three KPIs per client:
 *   - open_exceptions     — accounting_exceptions in {open,assigned}
 *   - draft_outbox        — accounting_outbox_events in {queued,retrying,dead_letter}
 *   - late_close_periods  — accounting_periods past end_date in {open,soft_closed}
 *
 * Plus a portfolio-wide totals card + a "needs attention" sort so the
 * client with the most pending work floats to the top of each firm.
 *
 * Backend: /api/admin/cpa_firm_dashboard.php?firm_tenant_id={N}
 * Mounted at /admin/cpa-dashboard.
 */

function KpiTile({ label, value, accent, testid }) {
  return (
    <div data-testid={testid} style={{
      background: 'var(--cf-surface, #fff)',
      border: '1px solid var(--cf-border, #e2e8f0)',
      borderRadius: 8, padding: 12, minWidth: 140,
    }}>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {label}
      </div>
      <div style={{ fontSize: 24, fontWeight: 700, color: value > 0 ? accent : 'var(--cf-text-secondary)' }}>
        {value}
      </div>
    </div>
  );
}

function NeedsAttentionPill({ n }) {
  if (n === 0) {
    return (
      <span data-testid="cpa-dashboard-pill-clean"
            style={{ background: '#2f7a3b22', color: '#2f7a3b', fontSize: 11, padding: '2px 8px', borderRadius: 10, fontWeight: 600 }}>
        all clear
      </span>
    );
  }
  const tone = n >= 10 ? '#b94a4a' : '#a06a00';
  return (
    <span data-testid="cpa-dashboard-pill-attention"
          style={{ background: tone + '22', color: tone, fontSize: 11, padding: '2px 8px', borderRadius: 10, fontWeight: 600 }}>
      {n} need{n === 1 ? 's' : ''} attention
    </span>
  );
}

export default function CpaFirmDashboard() {
  const [data, setData]       = useState(null);
  const [firmFilter, setFirm] = useState('');
  const [error, setError]     = useState(null);
  const [jumpingTo, setJump]  = useState(null);

  const reload = async () => {
    try {
      setError(null);
      const url = firmFilter
        ? `/api/admin/cpa_firm_dashboard.php?firm_tenant_id=${firmFilter}`
        : '/api/admin/cpa_firm_dashboard.php';
      const r = await api.get(url);
      setData(r || { firms: [], totals: { open_exceptions: 0, draft_outbox: 0, late_close_periods: 0, needs_attention: 0 } });
    } catch (e) {
      setData({ firms: [], totals: { open_exceptions: 0, draft_outbox: 0, late_close_periods: 0, needs_attention: 0 } });
      setError(e.message || 'Failed to load dashboard');
    }
  };

  useEffect(() => { reload(); /* eslint-disable-next-line */ }, [firmFilter]);

  const firms     = data?.firms   || [];
  const totals    = data?.totals  || { open_exceptions: 0, draft_outbox: 0, late_close_periods: 0, needs_attention: 0 };
  const totalClients = useMemo(
    () => firms.reduce((acc, f) => acc + (f.clients || []).length, 0),
    [firms]
  );

  const sortedFirms = useMemo(() => firms.slice().map(f => ({
    ...f,
    clients: (f.clients || []).slice().sort((a, b) => (b.kpis.needs_attention - a.kpis.needs_attention)),
  })), [firms]);

  const jumpIn = async (clientTenantId, hasMembership) => {
    if (!hasMembership) {
      alert('You have no membership on this client yet. Use Admin → Firm clients → seat yourself first.');
      return;
    }
    setJump(clientTenantId);
    try {
      await api.post('/api/sub_tenants.php?action=switch', { tenant_id: clientTenantId });
      window.location.href = '/';
    } catch (e) {
      alert(e.message || 'Switch failed');
      setJump(null);
    }
  };

  return (
    <div data-testid="cpa-dashboard">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <LayoutDashboard size={20} /> Firm dashboard
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Open exceptions, drafts pending post, and late-close periods across every client your firms manage.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          {firms.length > 1 && (
            <select
              value={firmFilter}
              onChange={(e) => setFirm(e.target.value)}
              data-testid="cpa-dashboard-firm-filter"
            >
              <option value="">All firms</option>
              {firms.map(f => (
                <option key={f.firm_tenant_id} value={f.firm_tenant_id}>{f.firm_name}</option>
              ))}
            </select>
          )}
          <button onClick={reload} className="btn btn--ghost" data-testid="cpa-dashboard-refresh">
            <RefreshCw size={14} style={{ marginRight: 6 }} />Refresh
          </button>
        </div>
      </div>

      {error && (
        <Card data-testid="cpa-dashboard-error" style={{ background: '#fff1f0', border: '1px solid #ffccc7' }}>{error}</Card>
      )}

      <div style={{ display: 'flex', gap: 12, marginBottom: 16, flexWrap: 'wrap' }} data-testid="cpa-dashboard-totals">
        <KpiTile label="Firms"            value={firms.length}             accent="#0c4a6e" testid="cpa-dashboard-total-firms" />
        <KpiTile label="Clients"          value={totalClients}             accent="#0c4a6e" testid="cpa-dashboard-total-clients" />
        <KpiTile label="Open exceptions"  value={totals.open_exceptions}   accent="#b94a4a" testid="cpa-dashboard-total-exceptions" />
        <KpiTile label="Draft outbox"     value={totals.draft_outbox}      accent="#a06a00" testid="cpa-dashboard-total-outbox" />
        <KpiTile label="Late close"       value={totals.late_close_periods} accent="#a06a00" testid="cpa-dashboard-total-late-close" />
      </div>

      {data && firms.length === 0 && (
        <Card data-testid="cpa-dashboard-empty">
          <div style={{ padding: 24, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>
            <LayoutDashboard size={28} style={{ opacity: 0.4, marginBottom: 8 }} />
            <p style={{ margin: 0 }}>No client tenants linked yet.</p>
            <p style={{ margin: '4px 0 0', fontSize: 12 }}>Wire your first client via Admin → Firm clients.</p>
          </div>
        </Card>
      )}

      {sortedFirms.map((firm) => (
        <Card key={firm.firm_tenant_id} data-testid={`cpa-dashboard-firm-${firm.firm_tenant_id}`} style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
            <div>
              <strong style={{ fontSize: 15 }}>{firm.firm_name}</strong>
              <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                {firm.client_count} client{firm.client_count === 1 ? '' : 's'}
              </div>
            </div>
            <NeedsAttentionPill n={firm.kpis.needs_attention} />
          </div>

          <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid={`cpa-dashboard-table-${firm.firm_tenant_id}`}>
            <thead>
              <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                <th style={{ padding: '6px 4px' }}>Client</th>
                <th style={{ padding: '6px 4px', textAlign: 'right' }}><AlertTriangle size={11} style={{ verticalAlign: 'middle' }} /> Exceptions</th>
                <th style={{ padding: '6px 4px', textAlign: 'right' }}><Inbox size={11} style={{ verticalAlign: 'middle' }} /> Outbox</th>
                <th style={{ padding: '6px 4px', textAlign: 'right' }}><CalendarClock size={11} style={{ verticalAlign: 'middle' }} /> Late close</th>
                <th style={{ padding: '6px 4px' }}></th>
                <th style={{ padding: '6px 4px' }}></th>
              </tr>
            </thead>
            <tbody>
              {(firm.clients || []).map((c) => (
                <tr key={c.link_id} style={{ borderTop: '1px solid var(--cf-border)' }} data-testid={`cpa-dashboard-row-${c.link_id}`}>
                  <td style={{ padding: '8px 4px', fontWeight: 500 }}>{c.client_name}</td>
                  <td style={{ padding: '8px 4px', textAlign: 'right' }} data-testid={`cpa-dashboard-cell-exceptions-${c.client_tenant_id}`}>
                    <span style={{ color: c.kpis.open_exceptions > 0 ? '#b94a4a' : 'inherit' }}>
                      {c.kpis.open_exceptions}
                    </span>
                  </td>
                  <td style={{ padding: '8px 4px', textAlign: 'right' }} data-testid={`cpa-dashboard-cell-outbox-${c.client_tenant_id}`}>
                    <span style={{ color: c.kpis.draft_outbox > 0 ? '#a06a00' : 'inherit' }}>
                      {c.kpis.draft_outbox}
                    </span>
                  </td>
                  <td style={{ padding: '8px 4px', textAlign: 'right' }} data-testid={`cpa-dashboard-cell-late-${c.client_tenant_id}`}>
                    <span style={{ color: c.kpis.late_close_periods > 0 ? '#a06a00' : 'inherit' }}>
                      {c.kpis.late_close_periods}
                    </span>
                  </td>
                  <td style={{ padding: '8px 4px' }}>
                    <NeedsAttentionPill n={c.kpis.needs_attention} />
                  </td>
                  <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                    <button
                      onClick={() => jumpIn(c.client_tenant_id, c.has_client_membership)}
                      className="btn btn--primary btn--sm"
                      disabled={jumpingTo === c.client_tenant_id || !c.has_client_membership}
                      data-testid={`cpa-dashboard-jump-${c.client_tenant_id}`}
                    >
                      <ExternalLink size={12} style={{ marginRight: 4 }} />
                      {jumpingTo === c.client_tenant_id ? 'Switching…' : 'Open'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      ))}
    </div>
  );
}
