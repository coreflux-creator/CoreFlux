import React from 'react';
import { Link } from 'react-router-dom';
import { ModuleCards, Section, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import {
  ArrowRight, Building2, CheckCircle2, CircleAlert, FileClock,
  FlaskConical, History, Layers, Upload, Users,
} from 'lucide-react';
import SubTenantSummaryCard from './SubTenantSummaryCard';
import SetupChecklistWidget from './SetupChecklistWidget';
import CashCycleHealthTile from './CashCycleHealthTile';
import LineChart from '../components/LineChart';
import { useApi } from '../lib/api';
import { fmtMoney } from '../lib/format';

/**
 * Workspace home. This is deliberately an operational surface: live financial
 * and staffing signals first, direct access to business areas second, and
 * exceptions that need attention beside the trend view.
 */
const DashboardOverview = ({ session, onModuleChange }) => {
  const { modules = [], user, tenant } = session;
  const isAdmin = user?.role === 'admin' || user?.global_role === 'master_admin' || user?.global_role === 'tenant_admin';
  const isManager = isAdmin || user?.role === 'manager';
  const { data, loading } = useApi(isManager ? '/api/exec_dashboard.php?weeks=12' : null);
  const greeting = greetingForHour(new Date().getHours());
  const firstName = user?.first_name || user?.name?.split(' ')[0] || 'there';
  const finance = data?.finance || {};
  const staffing = data?.staffing || {};

  return (
    <div className="workspace-home" data-testid="workspace-home">
      <SetupChecklistWidget />
      {isAdmin && <SubTenantSummaryCard session={session} />}

      <header className="workspace-home__header">
        <div>
          <span className="workspace-eyebrow">{tenant || 'CoreFlux'} workspace</span>
          <h1>{greeting}, {firstName}.</h1>
          <p>Here is what is happening across your business.</p>
        </div>
        <div className="workspace-home__period">
          <span>Reporting window</span>
          <strong>{formatRange(data?.range)}</strong>
        </div>
      </header>

      {isManager && <KpiSnapshotStrip data={data} loading={loading} />}

      <div className="workspace-home__body">
        <section className="workspace-panel workspace-panel--trend">
          <div className="workspace-panel__header">
            <div>
              <span className="workspace-eyebrow">Performance</span>
              <h2>Revenue and gross margin</h2>
            </div>
            <Link to="/modules/reports/exec" className="text-link" data-testid="dashboard-open-reports">
              Open full reports <ArrowRight size={14} aria-hidden="true" />
            </Link>
          </div>
          <div className="workspace-chart-legend" aria-hidden="true">
            <span><i className="is-revenue" /> Revenue</span>
            <span><i className="is-margin" /> Gross margin</span>
          </div>
          <LineChart
            height={236}
            format={value => fmtMoney(value)}
            series={[
              { name: 'Revenue', color: '#1683f8', data: finance.revenue?.trend || [] },
              { name: 'Gross margin', color: '#1db486', data: finance.margin?.trend || [] },
            ]}
          />
        </section>

        <section className="workspace-panel workspace-panel--attention">
          <div className="workspace-panel__header">
            <div>
              <span className="workspace-eyebrow">Open items</span>
              <h2>Needs attention</h2>
            </div>
            <Link to="/inbox" className="text-link">View all <ArrowRight size={14} aria-hidden="true" /></Link>
          </div>
          <AttentionItem
            Icon={CircleAlert}
            tone="red"
            value={fmtMoney(finance.ar_aging?.d90_plus || 0)}
            label="AR aged 90+ days"
            to="/modules/billing/aging"
          />
          <AttentionItem
            Icon={FileClock}
            tone="amber"
            value={String(staffing.ending_soon || 0)}
            label="placements ending in 30 days"
            to="/modules/placements/expiring"
          />
          <AttentionItem
            Icon={CheckCircle2}
            tone="green"
            value={String(staffing.new_starts?.period || 0)}
            label="new starts in this window"
            to="/modules/reports/overview"
          />
        </section>
      </div>

      <section className="workspace-section">
        <div className="workspace-section__header">
          <div>
            <span className="workspace-eyebrow">Workspaces</span>
            <h2>Run the business</h2>
          </div>
          <p>Each area shares the same people, placements, terms, and financial events.</p>
        </div>
        <ModuleCards modules={modules} onModuleClick={onModuleChange} />
      </section>

      {isManager && <CashCycleHealthTile />}

      {isAdmin && (
        <Section title="Workspace administration">
          <ActionCardsGrid>
            <ActionCard icon={Building2} title="Manage Tenants" description="Entities and access" href="/admin/tenants" />
            <ActionCard icon={Users} title="Manage Users" description="People and roles" href="/admin/users" />
            <ActionCard icon={Layers} title="Sub-Tenants" description="Provision and scope" href="/admin/sub-tenants" />
            <ActionCard icon={Upload} title="Bulk CSV Import" description="Preview and import source data" href="/data/bulk-import" data-testid="dashboard-bulk-csv-import" />
            <ActionCard icon={History} title="CSV Import History" description="Review every import" href="/data/import-history" data-testid="dashboard-csv-import-history" />
            <ActionCard icon={FlaskConical} title="Simulation Harness" description="Replay financial scenarios" href="/sim" data-testid="dashboard-sim-harness" />
          </ActionCardsGrid>
        </Section>
      )}
    </div>
  );
};

function KpiSnapshotStrip({ data, loading }) {
  const f = data?.finance || {};
  const s = data?.staffing || {};
  const fmtN = value => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });

  return (
    <div className="workspace-kpi-strip" data-testid="dashboard-snapshot-strip">
      <SnapshotTile label="Revenue MTD" value={loading ? '...' : fmtMoney(f.revenue?.mtd || 0)} sub={`YTD ${fmtMoney(f.revenue?.ytd || 0)}`} testid="snapshot-revenue" />
      <SnapshotTile label="Gross margin" value={loading ? '...' : fmtMoney(f.margin?.mtd || 0)} sub={`${Number(f.margin?.gross_pct || 0).toFixed(1)}% of revenue`} tone="green" testid="snapshot-margin" />
      <SnapshotTile label="Open AP" value={loading ? '...' : fmtMoney(f.ap_aging?.total || 0)} sub="Outstanding vendor obligations" tone="amber" testid="snapshot-ap" />
      <SnapshotTile label="Open AR" value={loading ? '...' : fmtMoney(f.ar_aging?.total || 0)} sub={`${fmtMoney(f.ar_aging?.d90_plus || 0)} aged 90+`} tone="blue" testid="snapshot-ar" />
      <SnapshotTile label="Active headcount" value={loading ? '...' : fmtN(s.headcount?.active || 0)} sub={`${fmtN(s.active_placements || 0)} active placements`} tone="teal" testid="snapshot-headcount" />
    </div>
  );
}

function SnapshotTile({ label, value, sub, tone = 'navy', testid }) {
  const accent = tone === 'green' ? '#1db486' : tone === 'amber' ? '#f2a51a' : tone === 'blue' ? '#1683f8' : tone === 'teal' ? '#17a5a0' : '#0a2540';
  return (
    <div className={`workspace-kpi workspace-kpi--${tone}`} data-testid={testid}
         style={{ borderLeft: `3px solid ${accent}` }}>
      <div className="workspace-kpi__label">{label}</div>
      <div className="workspace-kpi__value" style={{ fontVariantNumeric: 'tabular-nums' }}>{value}</div>
      <div className="workspace-kpi__sub">{sub}</div>
    </div>
  );
}

function AttentionItem({ Icon, tone, value, label, to }) {
  return (
    <Link to={to} className="attention-item">
      <span className={`attention-item__icon attention-item__icon--${tone}`}><Icon size={17} aria-hidden="true" /></span>
      <span><strong>{value}</strong><small>{label}</small></span>
      <ArrowRight size={15} aria-hidden="true" />
    </Link>
  );
}

function greetingForHour(hour) {
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

function formatRange(range) {
  if (!range?.from || !range?.to) return 'Last 12 weeks';
  const format = value => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return `${format(range.from)} - ${format(range.to)}`;
}

export default DashboardOverview;
