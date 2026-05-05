import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import { Card } from '../components/UIComponents';
import Sparkline from '../components/Sparkline';
import { fmtMoney } from '../lib/format';
import {
  TrendingUp, DollarSign, Users, Briefcase, Clock,
  AlertTriangle, ArrowDownCircle, ArrowUpCircle, BarChart3, Filter, RefreshCw,
} from 'lucide-react';

/**
 * ExecutiveDashboard — CEO/CFO snapshot of the business.
 *
 * Modular by design: every KPI card pulls from `data.finance.*` or
 * `data.staffing.*`. A future industry pack can hide/extend cards without
 * touching the API. Filters apply to staffing-side metrics and the margin
 * trendline (revenue / AR / payroll are always tenant-wide).
 *
 * Drill-downs route to existing module pages via React Router.
 */
const WEEKS_PRESETS = [
  { v: 4,   l: '4w'   },
  { v: 12,  l: '12w'  },
  { v: 26,  l: '26w'  },
  { v: 52,  l: '52w'  },
  { v: 104, l: '104w' },
];

export default function ExecutiveDashboard({ session }) {
  const [weeks,         setWeeks]         = useState(12);
  const [clientId,      setClientId]      = useState('');
  const [recruiterId,   setRecruiterId]   = useState('');
  const [placementType, setPlacementType] = useState('');
  const [worksiteState, setWorksiteState] = useState('');
  const [showFilters,   setShowFilters]   = useState(false);

  const qs = useMemo(() => {
    const p = new URLSearchParams({ weeks: String(weeks) });
    if (clientId)      p.set('client_id', clientId);
    if (recruiterId)   p.set('recruiter_id', recruiterId);
    if (placementType) p.set('placement_type', placementType);
    if (worksiteState) p.set('worksite_state', worksiteState);
    return p.toString();
  }, [weeks, clientId, recruiterId, placementType, worksiteState]);

  const { data, loading, error, reload } = useApi(`/api/exec_dashboard.php?${qs}`);
  const filters = useApi('/api/exec_filters.php');

  const f = data?.finance  || {};
  const s = data?.staffing || {};
  const fmt   = fmtMoney;
  const fmtN  = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
  const fmtH  = (n) => `${Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 1 })} hrs`;

  return (
    <div data-testid="executive-dashboard" style={{ padding: '0 0 48px' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 'var(--cf-space-6)', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            <BarChart3 size={22} style={{ display: 'inline', marginRight: 8 }} />
            Executive snapshot
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            {data?.range
              ? <>Window: <strong>{data.range.from}</strong> → <strong>{data.range.to}</strong> ({data.range.weeks} weeks)</>
              : 'Loading the snapshot…'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <div style={{ display: 'flex', gap: 4, background: '#f1f5f9', padding: 4, borderRadius: 8 }}>
            {WEEKS_PRESETS.map(p => (
              <button key={p.v}
                      onClick={() => setWeeks(p.v)}
                      data-testid={`exec-weeks-${p.v}`}
                      style={{
                        padding: '4px 10px', borderRadius: 6, fontSize: 13, fontWeight: 500,
                        background: weeks === p.v ? 'var(--cf-accent, #2563eb)' : 'transparent',
                        color:      weeks === p.v ? '#fff' : 'var(--cf-text-secondary)',
                        border: 'none', cursor: 'pointer',
                      }}>{p.l}</button>
            ))}
          </div>
          <button className="btn btn--ghost" onClick={() => setShowFilters(!showFilters)} data-testid="exec-toggle-filters">
            <Filter size={14} /> Filters
          </button>
          <button className="btn btn--ghost" onClick={reload} data-testid="exec-refresh">
            <RefreshCw size={14} />
          </button>
        </div>
      </div>

      {showFilters && (
        <Card style={{ marginBottom: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
            <FilterSelect label="Client"
              value={clientId} onChange={setClientId}
              testid="exec-filter-client"
              options={[['', 'All clients']].concat(
                (filters.data?.clients || []).map(c => [String(c.id), c.name])
              )} />
            <FilterSelect label="Recruiter"
              value={recruiterId} onChange={setRecruiterId}
              testid="exec-filter-recruiter"
              options={[['', 'All recruiters']].concat(
                (filters.data?.recruiters || []).map(r => [String(r.id), r.name || r.email])
              )} />
            <FilterSelect label="Placement type"
              value={placementType} onChange={setPlacementType}
              testid="exec-filter-placement-type"
              options={[['', 'All types']].concat(
                (filters.data?.placement_types || []).map(t => [t, t.toUpperCase()])
              )} />
            <FilterSelect label="Worksite state"
              value={worksiteState} onChange={setWorksiteState}
              testid="exec-filter-state"
              options={[['', 'All states']].concat(
                (filters.data?.worksite_states || []).map(st => [st, st])
              )} />
          </div>
        </Card>
      )}

      {loading && <Card><p>Loading…</p></Card>}
      {error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {!loading && data && (
        <>
          {/* Finance band */}
          <Section title="Corporate finance" icon={DollarSign}>
            <KpiGrid>
              <KpiCard
                title="Revenue (YTD)" value={fmt(f.revenue?.ytd)}
                sub={`MTD ${fmt(f.revenue?.mtd)} · QTD ${fmt(f.revenue?.qtd)}`}
                trend={f.revenue?.trend} format={fmt}
                href="/modules/billing/invoices"
                testid="kpi-revenue" />
              <KpiCard
                title="Run rate (90d annualised)"
                value={fmt(f.revenue?.run_rate_90d)}
                sub="Trailing 90 days × 4"
                trend={f.revenue?.trend} format={fmt}
                href="/modules/billing/invoices"
                testid="kpi-run-rate" />
              <KpiCard
                title="Gross margin (YTD)"
                value={fmt(f.margin?.ytd)}
                sub={f.margin?.gross_pct ? `${f.margin.gross_pct}% of revenue` : '—'}
                trend={f.margin?.trend} format={fmt}
                href="/modules/placements/reports"
                testid="kpi-margin" />
              <KpiCard
                title="Payroll YTD"
                value={fmt(f.payroll?.ytd)}
                sub={`Last run ${fmt(f.payroll?.last_run_total)}`}
                href="/modules/payroll/runs"
                testid="kpi-payroll" />
            </KpiGrid>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginTop: 16 }}>
              <AgingCard title="AR aging"   data={f.ar_aging} href="/modules/billing/aging" testid="aging-ar" />
              <AgingCard title="AP aging"   data={f.ap_aging} href="/modules/ap/aging"      testid="aging-ap" />
            </div>
          </Section>

          {/* Staffing band */}
          <Section title="Staffing operations" icon={Users}>
            <KpiGrid>
              <KpiCard
                title="Active headcount" value={fmtN(s.headcount?.active)}
                sub={[
                  s.headcount?.contractors_w2   && `${s.headcount.contractors_w2} W-2`,
                  s.headcount?.contractors_c2c  && `${s.headcount.contractors_c2c} C2C`,
                  s.headcount?.contractors_1099 && `${s.headcount.contractors_1099} 1099`,
                  s.headcount?.perm             && `${s.headcount.perm} perm`,
                ].filter(Boolean).join(' · ') || '—'}
                href="/modules/people/directory"
                testid="kpi-headcount" />
              <KpiCard
                title="New starts" value={fmtN(s.new_starts?.period)}
                sub={`Last ${data.range.weeks} weeks`}
                trend={s.new_starts?.trend} format={fmtN} color="#10b981"
                icon={ArrowUpCircle}
                href="/modules/people/directory"
                testid="kpi-new-starts" />
              <KpiCard
                title="Terminations" value={fmtN(s.terminations?.period)}
                sub={`Last ${data.range.weeks} weeks`}
                trend={s.terminations?.trend} format={fmtN} color="#ef4444"
                icon={ArrowDownCircle}
                href="/modules/people/directory"
                testid="kpi-terminations" />
              <KpiCard
                title="Net change" value={(s.net_change?.period >= 0 ? '+' : '') + fmtN(s.net_change?.period)}
                sub="New starts − terminations"
                trend={s.net_change?.trend} format={fmtN}
                color={s.net_change?.period >= 0 ? '#10b981' : '#ef4444'}
                href="/modules/people/directory"
                testid="kpi-net-change" />
            </KpiGrid>
            <div style={{ marginTop: 16 }}>
              <KpiGrid>
                <KpiCard
                  title="Active placements" value={fmtN(s.active_placements)}
                  sub={`${s.ending_soon || 0} ending in 30d`}
                  href="/modules/placements/list"
                  icon={Briefcase}
                  testid="kpi-active-placements" />
                <KpiCard
                  title="New placements" value={fmtN(s.new_placements?.period)}
                  sub={`Last ${data.range.weeks} weeks`}
                  trend={s.new_placements?.trend} format={fmtN}
                  href="/modules/placements/list"
                  testid="kpi-new-placements" />
                <KpiCard
                  title="Ending soon" value={fmtN(s.ending_soon)}
                  sub="Active placements ending within 30 days"
                  href="/modules/placements/expiring"
                  icon={AlertTriangle}
                  color="#f59e0b"
                  testid="kpi-ending-soon" />
                <KpiCard
                  title="Billable hours"
                  value={fmtH(s.billable_hours?.period)}
                  sub={`Last ${data.range.weeks} weeks`}
                  trend={s.billable_hours?.trend} format={fmtH}
                  href="/modules/time/reports"
                  icon={Clock}
                  testid="kpi-billable-hours" />
              </KpiGrid>
            </div>
          </Section>
        </>
      )}
    </div>
  );
}

function Section({ title, icon: Icon, children }) {
  return (
    <section style={{ marginBottom: 24 }}>
      <h2 style={{
        fontSize: 14, fontWeight: 600, textTransform: 'uppercase',
        letterSpacing: 0.5, color: 'var(--cf-text-secondary)', marginBottom: 12,
        display: 'flex', alignItems: 'center', gap: 6,
      }}>
        {Icon && <Icon size={14} />}
        {title}
      </h2>
      {children}
    </section>
  );
}

function KpiGrid({ children }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16 }}>
      {children}
    </div>
  );
}

function KpiCard({ title, value, sub, trend, format, color, icon: Icon, href, testid }) {
  const Wrapper = href ? Link : 'div';
  const wrapperProps = href ? { to: href } : {};
  return (
    <Wrapper {...wrapperProps}
      data-testid={testid}
      style={{
        display: 'block', textDecoration: 'none', color: 'inherit',
        background: '#fff', borderRadius: 10, padding: 16,
        border: '1px solid #e2e8f0', transition: 'transform .15s, box-shadow .15s',
        cursor: href ? 'pointer' : 'default',
      }}
      onMouseEnter={(e) => href && (e.currentTarget.style.boxShadow = '0 4px 14px rgba(0,0,0,0.06)')}
      onMouseLeave={(e) => href && (e.currentTarget.style.boxShadow = 'none')}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 6 }}>
        <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)', fontWeight: 500, textTransform: 'uppercase', letterSpacing: 0.4 }}>
          {title}
        </span>
        {Icon && <Icon size={14} style={{ color: color || 'var(--cf-text-secondary)' }} />}
      </div>
      <div style={{ fontSize: 26, fontWeight: 700, color: color || '#0f172a', marginBottom: 4 }}>{value}</div>
      {sub && <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 8 }}>{sub}</div>}
      {trend && trend.length > 0 && (
        <div style={{ marginTop: 6 }}>
          <Sparkline data={trend} format={format} color={color || 'var(--cf-accent, #2563eb)'} height={42} />
        </div>
      )}
    </Wrapper>
  );
}

function AgingCard({ title, data, href, testid }) {
  if (!data) return null;
  const buckets = [
    { k: 'current',   l: 'Current'   },
    { k: 'd30',       l: '1–30'      },
    { k: 'd60',       l: '31–60'     },
    { k: 'd90',       l: '61–90'     },
    { k: 'd90_plus',  l: '90+'       },
  ];
  const total = Number(data.total || 0);
  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h3 style={{ fontSize: 14, fontWeight: 600 }}>{title}</h3>
        <Link to={href} style={{ fontSize: 12, color: 'var(--cf-accent, #2563eb)', textDecoration: 'none' }}
              data-testid={`${testid}-drilldown`}>
          View detail →
        </Link>
      </div>
      <div style={{ fontSize: 22, fontWeight: 700, marginBottom: 12 }} data-testid={`${testid}-total`}>
        {fmtMoney(total)}
      </div>
      <table className="data-table" data-testid={testid}>
        <thead><tr>
          <th>Bucket</th>
          <th style={{ textAlign: 'right' }}>Outstanding</th>
          <th style={{ textAlign: 'right', width: 80 }}>%</th>
        </tr></thead>
        <tbody>
          {buckets.map(b => {
            const v = Number(data[b.k] || 0);
            const pct = total > 0 ? (v / total * 100).toFixed(1) : '0.0';
            return (
              <tr key={b.k}>
                <td>{b.l}</td>
                <td style={{ textAlign: 'right' }}>{fmtMoney(v)}</td>
                <td style={{ textAlign: 'right', color: 'var(--cf-text-secondary)' }}>{pct}%</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </Card>
  );
}

function FilterSelect({ label, value, onChange, options, testid }) {
  return (
    <label style={{ display: 'block', fontSize: 13 }}>
      <div style={{ fontSize: 12, fontWeight: 500, marginBottom: 4, color: 'var(--cf-text-secondary)' }}>
        {label}
      </div>
      <select className="input" value={value} onChange={e => onChange(e.target.value)}
              data-testid={testid}
              style={{ width: '100%' }}>
        {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select>
    </label>
  );
}
