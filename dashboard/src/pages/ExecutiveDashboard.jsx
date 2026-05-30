import React, { useMemo, useState, useEffect } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';
import Sparkline from '../components/Sparkline';
import { fmtMoney } from '../lib/format';
import {
  TrendingUp, DollarSign, Users, Briefcase, Clock, BookmarkPlus, Star, Trash2, Share2, X,
  AlertTriangle, ArrowDownCircle, ArrowUpCircle, BarChart3, Filter, RefreshCw, Calendar,
} from 'lucide-react';
import LineChart from '../components/LineChart';

/**
 * ExecutiveDashboard — CEO/CFO snapshot of the business.
 *
 * Modular by design: every KPI card pulls from `data.finance.*` or
 * `data.staffing.*`. A future industry pack can hide/extend cards without
 * touching the API. Filters apply to staffing-side metrics and the margin
 * trendline (revenue / AR / payroll are always tenant-wide).
 *
 * Drill-downs route to existing module pages via React Router.
 *
 * Saved Views: persist (window + filter) tuples per user with optional
 * tenant-wide sharing. URL ?view=<slug> deep-links any team member to
 * the same slice. A user's `is_default=1` view auto-loads on /exec.
 */
const WEEKS_PRESETS = [
  { v: 4,   l: '4w'   },
  { v: 12,  l: '12w'  },
  { v: 26,  l: '26w'  },
  { v: 52,  l: '52w'  },
  { v: 104, l: '104w' },
];

export default function ExecutiveDashboard({ session, bandFilter = null }) {
  const location = useLocation();
  const navigate = useNavigate();
  const urlView  = new URLSearchParams(location.search).get('view') || '';

  const [weeks,         setWeeks]         = useState(12);
  const [dateFrom,      setDateFrom]      = useState('');   // YYYY-MM-DD; '' = use weeks preset
  const [dateTo,        setDateTo]        = useState('');
  const [comparePrior,  setComparePrior]  = useState(false);
  const [clientId,      setClientId]      = useState('');
  const [recruiterId,   setRecruiterId]   = useState('');
  const [placementType, setPlacementType] = useState('');
  const [worksiteState, setWorksiteState] = useState('');
  const [showFilters,   setShowFilters]   = useState(false);
  const [showSave,      setShowSave]      = useState(false);
  const [showManage,    setShowManage]    = useState(false);
  const [activeView,    setActiveView]    = useState(null);

  const viewsApi = useApi('/api/exec_dashboard_views.php');
  const savedViews = viewsApi.data?.views || [];

  const applyView = (view) => {
    const f = view?.filters || {};
    setWeeks(Number(f.weeks) || 12);
    setDateFrom(f.from              || '');
    setDateTo(f.to                  || '');
    setComparePrior(!!f.compare_prior_year);
    setClientId(f.client_id           || '');
    setRecruiterId(f.recruiter_id     || '');
    setPlacementType(f.placement_type || '');
    setWorksiteState(f.worksite_state || '');
    setActiveView(view || null);
  };

  useEffect(() => {
    if (!savedViews.length) return;
    if (activeView) return;
    if (urlView) {
      const match = savedViews.find(v => v.slug === urlView);
      if (match) { applyView(match); return; }
    }
    const def = savedViews.find(v => v.is_default && v.is_owner);
    if (def && !urlView) applyView(def);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savedViews.length]);

  const customRange = !!(dateFrom && dateTo);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    if (customRange) {
      p.set('from', dateFrom);
      p.set('to',   dateTo);
    } else {
      p.set('weeks', String(weeks));
    }
    if (comparePrior)  p.set('compare', 'prior_year');
    if (clientId)      p.set('client_id', clientId);
    if (recruiterId)   p.set('recruiter_id', recruiterId);
    if (placementType) p.set('placement_type', placementType);
    if (worksiteState) p.set('worksite_state', worksiteState);
    return p.toString();
  }, [weeks, customRange, dateFrom, dateTo, comparePrior, clientId, recruiterId, placementType, worksiteState]);

  const { data, loading, error, reload } = useApi(`/api/exec_dashboard.php?${qs}`);
  const filters = useApi('/api/exec_filters.php');

  const f = data?.finance  || {};
  const s = data?.staffing || {};
  const fmt   = fmtMoney;
  const fmtN  = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
  const fmtH  = (n) => `${Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 1 })} hrs`;

  const currentFilters = {
    weeks, from: dateFrom, to: dateTo,
    compare_prior_year: comparePrior,
    client_id: clientId, recruiter_id: recruiterId,
    placement_type: placementType, worksite_state: worksiteState,
  };

  const onPickView = (slug) => {
    if (!slug) {
      applyView(null);
      navigate('/exec', { replace: true });
      return;
    }
    const view = savedViews.find(v => v.slug === slug);
    if (view) {
      applyView(view);
      navigate(`/exec?view=${slug}`, { replace: true });
    }
  };

  const onSaved = (savedSlug) => {
    setShowSave(false);
    viewsApi.reload();
    if (savedSlug) navigate(`/exec?view=${savedSlug}`, { replace: true });
  };

  return (
    <div data-testid="executive-dashboard" style={{ padding: '0 0 48px' }}>
      <div style={{
        display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
        flexWrap: 'wrap', gap: 12,
        position: 'sticky', top: 0, zIndex: 5,
        background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
        padding: '12px 0 14px',
        borderBottom: '1px solid #e2e8f0',
        marginBottom: 'var(--cf-space-5)',
      }}>
        <div style={{ flex: 1, minWidth: 260 }}>
          <h1 data-testid="exec-title"
              style={{ margin: 0, fontSize: 22, fontWeight: 700,
                       color: '#0f172a', letterSpacing: '-0.01em',
                       display: 'flex', alignItems: 'center', gap: 8 }}>
            <BarChart3 size={20} />
            Executive Snapshot
          </h1>
          <p style={{ color: '#64748b', fontSize: 13, margin: '4px 0 0' }}>
            {data?.range
              ? <>Window: <strong style={{ color: '#0f172a' }}>{data.range.from}</strong> → <strong style={{ color: '#0f172a' }}>{data.range.to}</strong> ({data.range.weeks} weeks)</>
              : 'Loading the snapshot…'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <ViewPicker
            views={savedViews}
            activeSlug={activeView?.slug || ''}
            onPick={onPickView}
            onManage={() => setShowManage(true)}
          />
          <button className="btn btn--ghost" onClick={() => setShowSave(true)} data-testid="exec-save-view">
            <BookmarkPlus size={14} /> Save view
          </button>
          <div style={{ display: 'flex', gap: 4, background: '#f1f5f9', padding: 4, borderRadius: 8 }}>
            {WEEKS_PRESETS.map(p => (
              <button key={p.v}
                      onClick={() => { setWeeks(p.v); setDateFrom(''); setDateTo(''); }}
                      data-testid={`exec-weeks-${p.v}`}
                      style={{
                        padding: '4px 10px', borderRadius: 6, fontSize: 13, fontWeight: 500,
                        background: !customRange && weeks === p.v ? 'var(--cf-accent, #2563eb)' : 'transparent',
                        color:      !customRange && weeks === p.v ? '#fff' : 'var(--cf-text-secondary)',
                        border: 'none', cursor: 'pointer',
                      }}>{p.l}</button>
            ))}
          </div>
          <DateRangePicker
            from={dateFrom} to={dateTo}
            onChange={(f, t) => { setDateFrom(f); setDateTo(t); }}
            customRange={customRange} />
          <button className={`btn ${comparePrior ? 'btn--primary' : 'btn--ghost'}`}
                  onClick={() => setComparePrior(!comparePrior)}
                  data-testid="exec-toggle-compare"
                  title="Toggle prior-year comparison">
            <Calendar size={14} /> vs. prior year
          </button>
          <button className="btn btn--ghost" onClick={() => setShowFilters(!showFilters)} data-testid="exec-toggle-filters">
            <Filter size={14} /> Filters
          </button>
          <button className="btn btn--ghost" onClick={reload} data-testid="exec-refresh">
            <RefreshCw size={14} />
          </button>
        </div>
      </div>

      {showSave && (
        <SaveViewModal
          filters={currentFilters}
          isMaster={session?.user?.global_role === 'master_admin' ||
                    session?.user?.global_role === 'tenant_admin'}
          onClose={() => setShowSave(false)}
          onSaved={onSaved}
        />
      )}
      {showManage && (
        <ManageViewsModal
          views={savedViews}
          onClose={() => { setShowManage(false); viewsApi.reload(); }}
          onPicked={(slug) => { setShowManage(false); onPickView(slug); }}
        />
      )}

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
          {(!bandFilter || bandFilter === 'finance') && (
            <Section title="Corporate finance" icon={DollarSign}>
              {/* Headline chart band — real LineChart with optional prior-year overlay */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: 16, marginBottom: 16 }}>
                <ChartCard title="Revenue (weekly)" testid="chart-revenue">
                  <LineChart
                    height={280}
                    format={fmt}
                    series={[
                      { name: 'Current', color: '#2563eb', data: f.revenue?.trend || [] },
                      ...(comparePrior && f.revenue?.prev_period
                        ? [{ name: 'Prior year', color: '#94a3b8', dashed: true, data: f.revenue.prev_period }]
                        : []),
                    ]}
                  />
                </ChartCard>
                <ChartCard title="Gross margin (weekly)" testid="chart-margin">
                  <LineChart
                    height={280}
                    format={fmt}
                    series={[
                      { name: 'Current', color: '#10b981', data: f.margin?.trend || [] },
                    ]}
                  />
                </ChartCard>
              </div>
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
          )}

          {(!bandFilter || bandFilter === 'staffing') && (
          <Section title="Staffing operations" icon={Users}>
            {/* Staffing chart band */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: 16, marginBottom: 16 }}>
              <ChartCard title="New starts vs. terminations" testid="chart-headcount-flow">
                <LineChart
                  height={260}
                  format={fmtN}
                  series={[
                    { name: 'New starts',   color: '#10b981', data: s.new_starts?.trend   || [] },
                    { name: 'Terminations', color: '#ef4444', data: s.terminations?.trend || [] },
                  ]}
                />
              </ChartCard>
              <ChartCard title="Billable hours (weekly)" testid="chart-billable-hours">
                <LineChart
                  height={260}
                  format={fmtH}
                  series={[
                    { name: 'Hours', color: '#6366f1', data: s.billable_hours?.trend || [] },
                  ]}
                />
              </ChartCard>
            </div>
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
          )}
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
  const accent = color || '#334155';
  return (
    <Wrapper {...wrapperProps}
      data-testid={testid}
      style={{
        display: 'block', textDecoration: 'none', color: 'inherit',
        background: '#fff',
        border: '1px solid #e2e8f0',
        borderLeft: `3px solid ${accent}`,
        borderRadius: 6, padding: '12px 14px',
        transition: 'transform .15s, box-shadow .15s',
        cursor: href ? 'pointer' : 'default',
      }}
      onMouseEnter={(e) => href && (e.currentTarget.style.boxShadow = '0 4px 12px rgba(15,23,42,0.06)')}
      onMouseLeave={(e) => href && (e.currentTarget.style.boxShadow = 'none')}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
        <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600,
                       textTransform: 'uppercase', letterSpacing: 0.4 }}>
          {title}
        </span>
        {Icon && <Icon size={13} style={{ color: '#94a3b8' }} />}
      </div>
      <div style={{ fontSize: 22, fontWeight: 700, color: color || '#0f172a',
                    letterSpacing: '-0.02em', lineHeight: 1.15,
                    fontVariantNumeric: 'tabular-nums', marginBottom: 2 }}>{value}</div>
      {sub && <div style={{ fontSize: 11, color: '#64748b', marginBottom: 6 }}>{sub}</div>}
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


/* ===================== Saved Views — picker + modals ===================== */

function ViewPicker({ views, activeSlug, onPick, onManage }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
      <select
        className="input"
        value={activeSlug}
        onChange={(e) => onPick(e.target.value)}
        data-testid="exec-view-picker"
        style={{ minWidth: 180, height: 32 }}
      >
        <option value="">— Custom view —</option>
        {views.length > 0 && (
          <>
            <optgroup label="My views">
              {views.filter(v => v.is_owner).map(v => (
                <option key={v.id} value={v.slug}>
                  {v.is_default ? '★ ' : ''}{v.name}
                </option>
              ))}
            </optgroup>
            <optgroup label="Shared">
              {views.filter(v => !v.is_owner).map(v => (
                <option key={v.id} value={v.slug}>
                  {v.name} · {v.owner_name}
                </option>
              ))}
            </optgroup>
          </>
        )}
      </select>
      {views.length > 0 && (
        <button className="btn btn--ghost"
                onClick={onManage}
                data-testid="exec-views-manage"
                title="Manage saved views">
          ⚙︎
        </button>
      )}
    </div>
  );
}

function SaveViewModal({ filters, isMaster, onClose, onSaved }) {
  const [name,    setName]    = useState('');
  const [shared,  setShared]  = useState(false);
  const [defOn,   setDefOn]   = useState(false);
  const [busy,    setBusy]    = useState(false);
  const [err,     setErr]     = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      const res = await api.post('/api/exec_dashboard_views.php', {
        name, filters, is_shared: shared, is_default: defOn,
      });
      onSaved(res.slug);
    } catch (e) { setErr(e.message || 'Save failed'); }
    finally { setBusy(false); }
  };

  return (
    <SimpleModal title="Save current view" onClose={onClose} testid="exec-save-modal">
      <form onSubmit={submit}>
        <Field label="Name">
          <input className="input" value={name} required
                 onChange={(e) => setName(e.target.value)}
                 placeholder="e.g. Acme Q4 — staffing"
                 data-testid="exec-save-name" />
        </Field>
        <Field label="Filters captured">
          <pre style={{
            background: '#f8fafc', padding: 10, borderRadius: 6, fontSize: 12,
            color: '#475569', overflow: 'auto', maxHeight: 100,
          }} data-testid="exec-save-filters-preview">
            {JSON.stringify(filters, null, 2)}
          </pre>
        </Field>
        <label style={{ display: 'flex', gap: 8, marginBottom: 10, alignItems: 'center' }}>
          <input type="checkbox" checked={defOn} onChange={(e) => setDefOn(e.target.checked)}
                 data-testid="exec-save-default" />
          <span>Make this my default view (auto-loads on /exec)</span>
        </label>
        {isMaster && (
          <label style={{ display: 'flex', gap: 8, marginBottom: 12, alignItems: 'center' }}>
            <input type="checkbox" checked={shared} onChange={(e) => setShared(e.target.checked)}
                   data-testid="exec-save-shared" />
            <span><Share2 size={12} style={{ display: 'inline', marginRight: 4 }} />
              Share with everyone in this tenant</span>
          </label>
        )}
        {err && <p style={{ color: '#b91c1c', marginBottom: 10 }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy}
                  data-testid="exec-save-submit">
            {busy ? 'Saving…' : 'Save view'}
          </button>
        </div>
      </form>
    </SimpleModal>
  );
}

function ManageViewsModal({ views, onClose, onPicked }) {
  const [busyId, setBusyId] = useState(null);

  const onSetDefault = async (v) => {
    setBusyId(v.id);
    try { await api.patch(`/api/exec_dashboard_views.php?id=${v.id}`, { is_default: !v.is_default }); }
    catch (e) { alert(e.message || 'Update failed'); }
    finally { setBusyId(null); v.is_default = !v.is_default; }
  };
  const onToggleShared = async (v) => {
    setBusyId(v.id);
    try { await api.patch(`/api/exec_dashboard_views.php?id=${v.id}`, { is_shared: !v.is_shared }); }
    catch (e) { alert(e.message || 'Update failed'); }
    finally { setBusyId(null); v.is_shared = !v.is_shared; }
  };
  const onDelete = async (v) => {
    if (!confirm(`Delete "${v.name}"?`)) return;
    setBusyId(v.id);
    try { await api.delete(`/api/exec_dashboard_views.php?id=${v.id}`); onClose(); }
    catch (e) { alert(e.message || 'Delete failed'); setBusyId(null); }
  };

  return (
    <SimpleModal title="Manage saved views" onClose={onClose} testid="exec-manage-modal" wide>
      {views.length === 0 ? (
        <p style={{ color: 'var(--cf-text-secondary)' }}>No saved views yet.</p>
      ) : (
        <table className="data-table" data-testid="exec-manage-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Owner</th>
              <th style={{ textAlign: 'center', width: 80 }}>Default</th>
              <th style={{ textAlign: 'center', width: 80 }}>Shared</th>
              <th style={{ width: 200 }}></th>
            </tr>
          </thead>
          <tbody>
            {views.map(v => (
              <tr key={v.id} data-testid={`exec-manage-row-${v.slug}`}>
                <td>
                  <button onClick={() => onPicked(v.slug)}
                          className="btn btn--ghost"
                          style={{ padding: 0, fontWeight: 500 }}
                          data-testid={`exec-manage-load-${v.slug}`}>
                    {v.name}
                  </button>
                </td>
                <td style={{ color: 'var(--cf-text-secondary)' }}>
                  {v.is_owner ? 'You' : (v.owner_name || '—')}
                </td>
                <td style={{ textAlign: 'center' }}>
                  {v.is_owner ? (
                    <button className={`btn btn--ghost`}
                            onClick={() => onSetDefault(v)}
                            disabled={busyId === v.id}
                            data-testid={`exec-manage-default-${v.slug}`}
                            title={v.is_default ? 'Default view' : 'Set as default'}>
                      <Star size={14} fill={v.is_default ? '#facc15' : 'transparent'} />
                    </button>
                  ) : (v.is_default ? <Star size={14} fill="#facc15" /> : '—')}
                </td>
                <td style={{ textAlign: 'center' }}>
                  {v.is_owner ? (
                    <input type="checkbox" checked={v.is_shared}
                           disabled={busyId === v.id}
                           onChange={() => onToggleShared(v)}
                           data-testid={`exec-manage-shared-${v.slug}`} />
                  ) : (v.is_shared ? '✓' : '—')}
                </td>
                <td style={{ textAlign: 'right' }}>
                  {v.is_owner && (
                    <button className="btn btn--ghost"
                            onClick={() => onDelete(v)}
                            disabled={busyId === v.id}
                            data-testid={`exec-manage-delete-${v.slug}`}
                            style={{ color: '#b91c1c' }}>
                      <Trash2 size={14} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </SimpleModal>
  );
}

function SimpleModal({ title, onClose, children, testid, wide = false }) {
  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 1000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
    }} onClick={onClose} data-testid={testid}>
      <div style={{
        background: '#fff', borderRadius: 12, padding: 24,
        maxWidth: wide ? 720 : 480, width: '100%',
        boxShadow: '0 10px 40px rgba(0,0,0,0.2)',
      }} onClick={(e) => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <h2 style={{ fontSize: 18, fontWeight: 600 }}>{title}</h2>
          <button onClick={onClose} className="btn btn--ghost"><X size={16} /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'block', marginBottom: 14 }}>
      <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4 }}>{label}</div>
      {children}
    </label>
  );
}


/* ===================== Chart card + Date range picker ===================== */

function ChartCard({ title, testid, children }) {
  return (
    <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}
         data-testid={testid}>
      <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--cf-text-secondary)',
                    textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 8 }}>
        {title}
      </div>
      {children}
    </div>
  );
}

function DateRangePicker({ from, to, onChange, customRange }) {
  const [open, setOpen] = useState(false);
  const ref = React.useRef(null);

  useEffect(() => {
    if (!open) return;
    const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  const today = new Date();
  const iso = (d) => d.toISOString().slice(0, 10);
  const setBucket = (kind) => {
    const t = new Date(today); let f;
    if (kind === 'mtd')  f = new Date(t.getFullYear(), t.getMonth(), 1);
    if (kind === 'qtd')  f = new Date(t.getFullYear(), Math.floor(t.getMonth() / 3) * 3, 1);
    if (kind === 'ytd')  f = new Date(t.getFullYear(), 0, 1);
    if (kind === 'last_quarter') {
      const q = Math.floor(t.getMonth() / 3); f = new Date(t.getFullYear(), (q - 1) * 3, 1);
      const e = new Date(t.getFullYear(), q * 3, 0);
      onChange(iso(f), iso(e)); setOpen(false); return;
    }
    if (kind === 'last_year') {
      f = new Date(t.getFullYear() - 1, 0, 1);
      const e = new Date(t.getFullYear() - 1, 11, 31);
      onChange(iso(f), iso(e)); setOpen(false); return;
    }
    onChange(iso(f), iso(t)); setOpen(false);
  };

  const label = customRange ? `${from} → ${to}` : 'Date range';

  return (
    <div style={{ position: 'relative' }} ref={ref}>
      <button className={`btn ${customRange ? 'btn--primary' : 'btn--ghost'}`}
              onClick={() => setOpen(!open)} data-testid="exec-date-picker-toggle">
        <Calendar size={14} /> {label}
      </button>
      {open && (
        <div style={{
          position: 'absolute', top: 'calc(100% + 4px)', right: 0,
          background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8,
          boxShadow: '0 10px 30px rgba(0,0,0,0.12)', padding: 12, zIndex: 100, minWidth: 280,
        }} data-testid="exec-date-picker-panel">
          <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>Quick presets</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 6, marginBottom: 12 }}>
            {[
              ['mtd',          'MTD'],
              ['qtd',          'QTD'],
              ['ytd',          'YTD'],
              ['last_quarter', 'Last quarter'],
              ['last_year',    'Last year'],
            ].map(([k, l]) => (
              <button key={k} className="btn btn--ghost" onClick={() => setBucket(k)}
                      data-testid={`exec-date-preset-${k}`}>{l}</button>
            ))}
          </div>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b',
                        textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>Custom</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            <label style={{ fontSize: 12 }}>
              From
              <input type="date" className="input" value={from || ''}
                     onChange={(e) => onChange(e.target.value, to)}
                     data-testid="exec-date-from" />
            </label>
            <label style={{ fontSize: 12 }}>
              To
              <input type="date" className="input" value={to || ''}
                     onChange={(e) => onChange(from, e.target.value)}
                     data-testid="exec-date-to" />
            </label>
          </div>
          {customRange && (
            <button className="btn btn--ghost" style={{ marginTop: 10, width: '100%' }}
                    onClick={() => { onChange('', ''); setOpen(false); }}
                    data-testid="exec-date-clear">
              Clear range (use weeks preset)
            </button>
          )}
        </div>
      )}
    </div>
  );
}
