import React, { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import {
  Activity, AlertCircle, AlertTriangle, ArrowRight, BookOpen, Building2,
  CheckCircle2, FileText, FlaskConical, Receipt, Sparkles, TrendingUp, Wallet,
} from 'lucide-react';

/**
 * BookkeepingOverview — Layer-style single-screen books snapshot.
 *
 * Hits GET /api/v1/accounting/books-health once and renders:
 *  - Books Health badge (0..100, label, reasons)
 *  - 6-month P&L bar chart
 *  - Tasks list (clickable counts → relevant pages)
 *  - Bank connections card
 *  - Recent engine activity (last 10 posted accounting_events)
 *  - Connect-a-bank CTA when no active connections
 */
const BOOKS_HEALTH_API = '/api/v1/accounting/books-health';

export default function BookkeepingOverview() {
  const { data, error, refetch } = useApi(BOOKS_HEALTH_API);

  const monthlyMax = useMemo(() => {
    const rows = data?.pl_monthly || [];
    const maxV = rows.reduce((m, r) => Math.max(m, Math.abs(r.revenue), Math.abs(r.expense)), 0);
    return maxV || 1; // avoid div by 0
  }, [data?.pl_monthly]);

  if (error) {
    return (
      <div data-testid="bookkeeping-overview-error" style={errBox}>
        <AlertCircle size={18} /> Couldn't load books health: {error.message}
        <button onClick={refetch} className="btn btn--ghost" style={{ marginLeft: 'auto' }}>Retry</button>
      </div>
    );
  }
  if (!data) {
    return <div data-testid="bookkeeping-overview-loading" style={{ padding: 40, color: '#94a3b8' }}>Loading books health…</div>;
  }

  const score = data.health_score ?? 0;
  const label = data.health_label ?? '';
  const scoreColor = score >= 90 ? '#059669' : score >= 75 ? '#0284c7' : score >= 50 ? '#d97706' : '#dc2626';
  const scoreBg    = score >= 90 ? '#ecfdf5' : score >= 75 ? '#eff6ff' : score >= 50 ? '#fffbeb' : '#fef2f2';

  return (
    <div data-testid="bookkeeping-overview-page" style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 22, fontWeight: 700, margin: 0 }}>
            <BookOpen size={22} color="#0284c7" /> Bookkeeping overview
          </h1>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            One-screen snapshot of your books — last refreshed {new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}.
          </p>
        </div>
        <button data-testid="bookkeeping-overview-refresh" onClick={refetch} className="btn btn--ghost" style={{ fontSize: 12 }}>
          Refresh
        </button>
      </header>

      {/* Connect-a-bank CTA */}
      {data.bank_connections?.active === 0 && (
        <div data-testid="bookkeeping-overview-connect-bank" style={ctaBox}>
          <Wallet size={20} color="#7c3aed" />
          <div style={{ flex: 1 }}>
            <strong style={{ fontSize: 14, color: '#5b21b6' }}>Connect your first bank to start auto-bookkeeping</strong>
            <p style={{ margin: '2px 0 0', fontSize: 12, color: '#6d28d9' }}>
              Without a live bank feed the engine has no transactions to categorize.
            </p>
          </div>
          <Link to="/accounting/bank-accounts" className="btn btn--primary" data-testid="bookkeeping-overview-connect-bank-cta">
            Connect bank <ArrowRight size={14} style={{ marginLeft: 4, verticalAlign: 'middle' }} />
          </Link>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 280px', gap: 16, alignItems: 'start' }}>
        {/* Left column — main content */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Score hero */}
          <div data-testid="bookkeeping-overview-health-card"
               style={{ padding: 20, background: scoreBg, border: `1px solid ${scoreColor}33`, borderRadius: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
              <div data-testid="bookkeeping-overview-health-score"
                   style={{ fontSize: 56, fontWeight: 800, color: scoreColor, lineHeight: 1, fontFamily: 'ui-monospace, monospace' }}>
                {score}
              </div>
              <div>
                <div data-testid="bookkeeping-overview-health-label"
                     style={{ fontSize: 18, fontWeight: 700, color: scoreColor, textTransform: 'capitalize' }}>
                  {label.replace('_', ' ')}
                </div>
                <div style={{ fontSize: 13, color: '#475569' }}>Books health · 0–100</div>
              </div>
            </div>
            {(data.health_reasons?.length ?? 0) > 0 && (
              <div data-testid="bookkeeping-overview-health-reasons" style={{ marginTop: 12, display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                {data.health_reasons.map(r => (
                  <span key={r} style={chipStyle(scoreColor)}>{r.replace(/_/g, ' ')}</span>
                ))}
              </div>
            )}
          </div>

          {/* Missing-dimension alert — Sprint 7f.4. Yellow CTA when posted JE
              lines are missing required dim values; deep-links to the filtered
              review page where each row has an "Open JE" jump. */}
          {(data.missing_dims?.count ?? 0) > 0 && (
            <div data-testid="bookkeeping-overview-missing-dims-card"
                 style={{ padding: 14, background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 12, display: 'flex', alignItems: 'flex-start', gap: 12 }}>
              <div style={{ width: 36, height: 36, borderRadius: 10, background: '#fef3c7', display: 'flex', alignItems: 'center', justifyContent: 'center', flex: '0 0 auto' }}>
                <AlertTriangle size={18} color="#b45309" />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 6, flexWrap: 'wrap' }}>
                  <strong data-testid="bookkeeping-overview-missing-dims-count"
                          style={{ fontSize: 15, color: '#92400e' }}>
                    {data.missing_dims.count}
                  </strong>
                  <span style={{ fontSize: 13, color: '#92400e' }}>posted JE line{data.missing_dims.count === 1 ? '' : 's'} missing a required dimension value</span>
                </div>
                {(data.missing_dims.sample_accounts?.length ?? 0) > 0 && (
                  <div data-testid="bookkeeping-overview-missing-dims-sample"
                       style={{ fontSize: 12, color: '#78350f', marginTop: 4 }}>
                    Top offenders: {data.missing_dims.sample_accounts.map(a => `${a.account_code} (${a.lines})`).join(', ')}
                  </div>
                )}
              </div>
              <Link to="/modules/accounting/missing-dimensions" className="btn"
                    data-testid="bookkeeping-overview-missing-dims-cta"
                    style={{ fontSize: 12, background: '#f59e0b', color: '#fff', borderRadius: 8, padding: '6px 12px', whiteSpace: 'nowrap' }}>
                Review now <ArrowRight size={12} style={{ marginLeft: 3, verticalAlign: 'middle' }} />
              </Link>
            </div>
          )}

          {/* Saved-hours KPI — counts AI assists accepted in the last 7 days
              × 30s/assist (conservative). Displays the categorization-history
              moat as something the operator can brag about. */}
          {(data.ai_assist?.count_7d ?? 0) > 0 && (
            <div data-testid="bookkeeping-overview-saved-hours-card"
                 style={{ padding: 16, background: '#faf5ff', border: '1px solid #ddd6fe', borderRadius: 12, display: 'flex', alignItems: 'center', gap: 14 }}>
              <div style={{ width: 44, height: 44, borderRadius: 12, background: '#ede9fe', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Sparkles size={22} color="#7c3aed" />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, flexWrap: 'wrap' }}>
                  <strong data-testid="bookkeeping-overview-saved-hours-value"
                          style={{ fontSize: 24, fontWeight: 800, color: '#5b21b6', fontFamily: 'ui-monospace, monospace' }}>
                    {Number(data.ai_assist.hours_saved ?? 0).toFixed(1)} hrs
                  </strong>
                  <span style={{ fontSize: 13, color: '#7c3aed', fontWeight: 600 }}>saved this week</span>
                </div>
                <div data-testid="bookkeeping-overview-saved-hours-detail" style={{ fontSize: 12, color: '#6d28d9', marginTop: 2 }}>
                  {data.ai_assist.count_7d} AI suggestions accepted · {data.ai_assist.cumulative_count} all-time
                </div>
              </div>
              <Link to="/admin/audit-log?source=ai" className="btn btn--ghost"
                    style={{ fontSize: 11, color: '#5b21b6' }} data-testid="bookkeeping-overview-saved-hours-cta">
                See AI activity <ArrowRight size={11} style={{ marginLeft: 3, verticalAlign: 'middle' }} />
              </Link>
            </div>
          )}

          {/* P&L bar chart */}
          <div data-testid="bookkeeping-overview-pl-chart"
               style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
              <TrendingUp size={16} color="#0284c7" />
              <strong style={{ fontSize: 14 }}>P&amp;L · last 6 months</strong>
            </div>
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, height: 160, paddingBottom: 28, position: 'relative' }}>
              {(data.pl_monthly || []).map(m => {
                const revH = (Math.abs(m.revenue) / monthlyMax) * 130;
                const expH = (Math.abs(m.expense) / monthlyMax) * 130;
                return (
                  <div key={m.month} data-testid={`bookkeeping-overview-pl-bar-${m.month}`}
                       style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
                    <div style={{ display: 'flex', alignItems: 'flex-end', gap: 2, height: 130 }}>
                      <div title={`Revenue ${m.revenue.toLocaleString()}`} style={{ width: 14, height: revH, background: '#10b981', borderRadius: '3px 3px 0 0' }} />
                      <div title={`Expense ${m.expense.toLocaleString()}`} style={{ width: 14, height: expH, background: '#ef4444', borderRadius: '3px 3px 0 0' }} />
                    </div>
                    <div style={{ fontSize: 11, color: '#64748b' }}>{m.month.slice(5)}</div>
                    <div style={{ fontSize: 10, color: m.net >= 0 ? '#059669' : '#dc2626', fontFamily: 'ui-monospace, monospace' }}>
                      {m.net >= 0 ? '+' : ''}{m.net.toFixed(0)}
                    </div>
                  </div>
                );
              })}
            </div>
            <div style={{ display: 'flex', gap: 16, fontSize: 11, color: '#64748b', marginTop: 6 }}>
              <span><span style={{ display: 'inline-block', width: 8, height: 8, background: '#10b981', borderRadius: 2, marginRight: 4 }} />Revenue</span>
              <span><span style={{ display: 'inline-block', width: 8, height: 8, background: '#ef4444', borderRadius: 2, marginRight: 4 }} />Expense</span>
            </div>
          </div>

          {/* Recent engine activity */}
          <div data-testid="bookkeeping-overview-recent-events"
               style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
              <Activity size={16} color="#7c3aed" />
              <strong style={{ fontSize: 14 }}>Recent engine activity</strong>
              <Link to="/admin/rule-sandbox" className="btn btn--ghost" style={{ marginLeft: 'auto', fontSize: 11 }}>
                <FlaskConical size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />Rule sandbox
              </Link>
            </div>
            {(!data.recent_events || data.recent_events.length === 0)
              ? <p data-testid="bookkeeping-overview-recent-empty" style={{ fontSize: 13, color: '#94a3b8', margin: 0 }}>
                  No engine-posted events yet. Once you seed default rules and start using the bank feed, recent posts will show here.
                </p>
              : <table style={{ width: '100%', fontSize: 12 }}>
                  <tbody>
                    {data.recent_events.map(e => (
                      <tr key={e.id} data-testid={`bookkeeping-overview-event-${e.id}`} style={{ borderTop: '1px solid #f1f5f9' }}>
                        <td style={{ padding: '6px 8px' }}>
                          <code style={{ fontFamily: 'ui-monospace, monospace', fontSize: 11, color: '#0f172a' }}>{e.event_type}</code>
                        </td>
                        <td style={{ padding: '6px 8px', color: '#64748b' }}>{e.source_record_id}</td>
                        <td style={{ padding: '6px 8px', textAlign: 'right' }}>
                          {e.journal_entry_id
                            ? <Link to={`/accounting/journal-entries/${e.journal_entry_id}`} style={{ color: '#0284c7', fontSize: 11 }}>JE #{e.journal_entry_id}</Link>
                            : <span style={{ color: '#94a3b8' }}>—</span>}
                        </td>
                        <td style={{ padding: '6px 8px', color: '#94a3b8', fontSize: 11, textAlign: 'right' }}>
                          {e.posted_at ? new Date(e.posted_at).toLocaleDateString() : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>}
          </div>
        </div>

        {/* Right column — tasks + connections */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Tasks */}
          <div data-testid="bookkeeping-overview-tasks-card"
               style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
            <strong style={{ fontSize: 14, display: 'block', marginBottom: 10 }}>Things to do</strong>
            <TaskRow icon={Receipt} label="Transactions to review" count={data.tasks?.transactions_to_review ?? 0}
                     to="/modules/accounting/transactions-to-review?prefilter=oldest_first&autoload=1" testId="task-tx-review" />
            <TaskRow icon={FileText} label="Bills awaiting action" count={data.tasks?.bills_pending ?? 0}
                     to="/accounting/ap" testId="task-bills" />
            <TaskRow icon={Wallet}  label="Payments pending" count={data.tasks?.payments_pending ?? 0}
                     to="/treasury/payments" testId="task-payments" />
            <TaskRow icon={ArrowRight} label="Transfers pending" count={data.tasks?.transfers_pending ?? 0}
                     to="/treasury/transfers" testId="task-transfers" />
            <TaskRow icon={CheckCircle2} label="Periods ready to close" count={data.tasks?.period_ready_to_close ?? 0}
                     to="/accounting/periods" testId="task-period-close" />
          </div>

          {/* Integration freshness — Sprint 8a follow-on. Trust-at-a-glance:
              "Last sync · 12 hours ago" per connected source. Hidden when
              there are no integrations configured. */}
          {(data.integrations?.length ?? 0) > 0 && (
            <div data-testid="bookkeeping-overview-integrations-card"
                 style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
              <strong style={{ fontSize: 14, display: 'block', marginBottom: 10 }}>Integrations</strong>
              {data.integrations.map(integ => {
                const stale  = integ.hours_since != null && integ.hours_since > 168; // 7 days
                const aging  = integ.hours_since != null && integ.hours_since > 24;
                const color  = stale ? '#dc2626' : aging ? '#d97706' : '#059669';
                const label  = integ.last_sync_at
                  ? (integ.hours_since == null ? integ.last_sync_at
                     : integ.hours_since < 1 ? 'just now'
                     : integ.hours_since < 24 ? `${integ.hours_since}h ago`
                     : `${Math.round(integ.hours_since / 24)}d ago`)
                  : 'never';
                return (
                  <div key={integ.source}
                       data-testid={`bookkeeping-overview-integration-row-${integ.source}`}
                       style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, padding: '4px 0' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                      <Activity size={12} color={color} />
                      <strong>{integ.label}</strong>
                      <span style={{ fontSize: 11, color: '#64748b', textTransform: 'capitalize' }}>· {integ.status}</span>
                    </span>
                    <span data-testid={`bookkeeping-overview-integration-last-sync-${integ.source}`}
                          style={{ fontSize: 12, color, fontFamily: 'ui-monospace, monospace' }}>{label}</span>
                  </div>
                );
              })}
            </div>
          )}

          {/* Bank connections */}
          <div data-testid="bookkeeping-overview-banks-card"
               style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
            <strong style={{ fontSize: 14, display: 'block', marginBottom: 10 }}>Bank connections</strong>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}>
              <span>Active</span>
              <strong data-testid="bookkeeping-overview-banks-active">{data.bank_connections?.active ?? 0}</strong>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}>
              <span>Total</span>
              <span>{data.bank_connections?.total ?? 0}</span>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: '#64748b', marginTop: 8, paddingTop: 8, borderTop: '1px solid #f1f5f9' }}>
              <span>Last reconciled</span>
              <span data-testid="bookkeeping-overview-last-reconciled">
                {data.reconciliation?.last_reconciled_date || 'never'}
                {data.reconciliation?.days_since != null && (
                  <span style={{ color: data.reconciliation.behind_60d ? '#dc2626' : data.reconciliation.behind_30d ? '#d97706' : '#94a3b8', marginLeft: 4 }}>
                    · {data.reconciliation.days_since}d ago
                  </span>
                )}
              </span>
            </div>
          </div>

          {/* Period card */}
          {data.fiscal_period && (
            <div data-testid="bookkeeping-overview-period-card"
                 style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
              <strong style={{ fontSize: 14, display: 'block', marginBottom: 6 }}>
                <Building2 size={14} style={{ marginRight: 4, verticalAlign: 'middle' }} />
                Current period
              </strong>
              <div style={{ fontSize: 13, color: '#0f172a' }}>
                FY{data.fiscal_period.fiscal_year} · Period {data.fiscal_period.period_number}
              </div>
              <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
                {data.fiscal_period.start_date} → {data.fiscal_period.end_date}
              </div>
              <div data-testid="bookkeeping-overview-period-status"
                   style={{ marginTop: 8, padding: '4px 8px', display: 'inline-block', borderRadius: 4, fontSize: 11, fontWeight: 600,
                            background: data.period_status === 'open' ? '#ecfdf5' : '#f1f5f9',
                            color:      data.period_status === 'open' ? '#059669' : '#475569' }}>
                {data.period_status}
              </div>
            </div>
          )}

          {/* Quick links — reports + tax */}
          <div data-testid="bookkeeping-overview-quick-links-card"
               style={{ padding: 18, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
            <strong style={{ fontSize: 14, display: 'block', marginBottom: 10 }}>Reports &amp; tax</strong>
            <Link to="/modules/accounting/gl-detail" data-testid="bookkeeping-overview-gl-detail-link"
                  style={quickLinkStyle}>
              <FileText size={14} color="#0284c7" />
              <span style={{ flex: 1, fontSize: 13 }}>GL Detail</span>
              <ArrowRight size={12} color="#94a3b8" />
            </Link>
            <Link to="/modules/accounting/tax-mappings" data-testid="bookkeeping-overview-tax-mappings-link"
                  style={quickLinkStyle}>
              <FlaskConical size={14} color="#7c3aed" />
              <span style={{ flex: 1, fontSize: 13 }}>Tax mappings</span>
              <ArrowRight size={12} color="#94a3b8" />
            </Link>
            <Link to="/modules/accounting/tax-export" data-testid="bookkeeping-overview-tax-export-link"
                  style={quickLinkStyle}>
              <TrendingUp size={14} color="#059669" />
              <span style={{ flex: 1, fontSize: 13 }}>Tax export (CSV)</span>
              <ArrowRight size={12} color="#94a3b8" />
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}

const quickLinkStyle = {
  display: 'flex', alignItems: 'center', gap: 8, padding: '8px 4px',
  textDecoration: 'none', color: '#0f172a',
  borderTop: '1px solid #f1f5f9', cursor: 'pointer',
};

const TaskRow = ({ icon: Icon, label, count, to, testId }) => {
  const isZero = count === 0;
  return (
    <Link to={to} data-testid={testId}
          style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 4px', textDecoration: 'none',
                   color: isZero ? '#94a3b8' : '#0f172a', borderTop: '1px solid #f1f5f9', cursor: isZero ? 'default' : 'pointer' }}>
      <Icon size={14} color={isZero ? '#cbd5e1' : '#0284c7'} />
      <span style={{ flex: 1, fontSize: 13 }}>{label}</span>
      <strong style={{ fontFamily: 'ui-monospace, monospace', fontSize: 13, color: isZero ? '#cbd5e1' : (count > 5 ? '#dc2626' : '#0f172a') }}>{count}</strong>
      {!isZero && <ArrowRight size={12} color="#94a3b8" />}
    </Link>
  );
};

const errBox = { display: 'flex', alignItems: 'center', gap: 8, padding: 14, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, color: '#7f1d1d', fontSize: 13 };
const ctaBox = { display: 'flex', alignItems: 'center', gap: 12, padding: 14, background: '#f5f3ff', border: '1px solid #ddd6fe', borderRadius: 10 };
const chipStyle = (color) => ({
  display: 'inline-block', padding: '2px 8px', background: '#fff',
  border: `1px solid ${color}33`, borderRadius: 12, fontSize: 11, color, textTransform: 'capitalize',
});
