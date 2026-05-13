import React from 'react';
import { Link } from 'react-router-dom';
import { ModuleCards, Section, ActionCardsGrid, ActionCard, HelpSection } from '../components/UIComponents';
import { Building2, Users, DollarSign, Layers, BarChart3, ArrowRight, Upload, History } from 'lucide-react';
import SubTenantSummaryCard from './SubTenantSummaryCard';
import SetupChecklistWidget from './SetupChecklistWidget';
import CashCycleHealthTile from './CashCycleHealthTile';
import { useApi } from '../lib/api';
import { fmtMoney } from '../lib/format';

/**
 * DashboardOverview — home page after login.
 *
 * Layout (top to bottom):
 *   1. Sub-tenant fleet card (master_admin only)
 *   2. Tiny KPI snapshot strip (4 numbers + "Open full reports →")
 *      — pulls from /api/exec_dashboard.php at default (12-week) window.
 *      — non-clickable; click "Open full reports" to drill in.
 *   3. Module access cards (the existing nice nav buttons).
 *   4. Admin quick actions.
 *   5. Help.
 *
 * The full executive snapshot lives under /modules/reports/exec.
 */
const DashboardOverview = ({ session, onModuleChange }) => {
  const { modules = [], user } = session;

  const isAdmin   = user?.role === 'admin' || user?.global_role === 'master_admin' || user?.global_role === 'tenant_admin';
  const isManager = isAdmin || user?.role === 'manager';

  return (
    <>
      {/* First-30-days onboarding checklist — auto-hides on completion,
          dismissal, or age > 30 days. */}
      <SetupChecklistWidget />

      {/* Sub-tenant fleet view (master_admin only; renders nothing otherwise) */}
      {isAdmin && <SubTenantSummaryCard session={session} />}

      {/* KPI snapshot strip — only for managers+ who have access to the
          exec data. Quietly hides for everyone else. */}
      {isManager && <KpiSnapshotStrip />}

      {/* Cash cycle health — DSO, PWP gating, last-week releases. Quietly
          hides on error / for users without billing.view permission (the
          endpoint returns 403 → useApi sets error → tile renders null). */}
      {isManager && <CashCycleHealthTile />}

      {/* Module Access Cards */}
      <ModuleCards modules={modules} onModuleClick={onModuleChange} />

      {/* Admin Quick Actions — only show for admins */}
      {isAdmin && (
        <Section title="Admin Quick Actions">
          <ActionCardsGrid>
            <ActionCard icon={Building2} title="Manage Tenants"  description="Create and configure tenants"        href="/admin/tenants" />
            <ActionCard icon={Users}     title="Manage Users"    description="Add users and assign roles"          href="/admin/users" />
            <ActionCard icon={DollarSign} title="Module Access"  description="Enable modules per tenant"           href="/admin/modules" />
            <ActionCard icon={Layers}    title="Sub-Tenants"     description="Provision sub-tenants & module scope" href="/admin/sub-tenants" />
            <ActionCard icon={Upload}    title="Bulk CSV Import" description="Drop multiple CSVs at once — people, vendors, clients, placements, time, bills, invoices" href="/data/bulk-import" data-testid="dashboard-bulk-csv-import" />
            <ActionCard icon={History}   title="CSV Import History" description="Audit trail of every bulk import — who, when, rows imported/skipped, errors" href="/data/import-history" data-testid="dashboard-csv-import-history" />
          </ActionCardsGrid>
        </Section>
      )}

      <HelpSection />
    </>
  );
};

/**
 * Compact 4-number snapshot. Lives on the home page so a manager glances it
 * before clicking through to the full Reports module. Pulls the same
 * /api/exec_dashboard.php at default 12w window with no filters.
 */
function KpiSnapshotStrip() {
  const { data, loading } = useApi('/api/exec_dashboard.php?weeks=12');
  const f = data?.finance  || {};
  const s = data?.staffing || {};

  const fmtN = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });

  return (
    <Section
      title="Snapshot"
      action={
        <Link to="/modules/reports/exec" className="btn btn--primary"
              data-testid="dashboard-open-reports"
              style={{ fontSize: 13 }}>
          Open full reports <ArrowRight size={14} />
        </Link>
      }
    >
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}
           data-testid="dashboard-snapshot-strip">
        <SnapshotTile label="Revenue MTD"        value={loading ? '…' : fmtMoney(f.revenue?.mtd || 0)}
                      sub={loading ? null : `YTD ${fmtMoney(f.revenue?.ytd || 0)}`}
                      testid="snapshot-revenue" />
        <SnapshotTile label="Run rate (90d)"     value={loading ? '…' : fmtMoney(f.revenue?.run_rate_90d || 0)}
                      sub="Trailing 90d × 4"
                      testid="snapshot-run-rate" />
        <SnapshotTile label="Active headcount"   value={loading ? '…' : fmtN(s.headcount?.active || 0)}
                      sub={loading ? null : `${fmtN(s.active_placements || 0)} active placements`}
                      testid="snapshot-headcount" />
        <SnapshotTile label="AR outstanding"     value={loading ? '…' : fmtMoney(f.ar_aging?.total || 0)}
                      sub={loading ? null : `${fmtMoney(f.ar_aging?.d90_plus || 0)} aged 90+`}
                      tone={(f.ar_aging?.d90_plus || 0) > 0 ? 'warn' : null}
                      testid="snapshot-ar" />
      </div>
    </Section>
  );
}

function SnapshotTile({ label, value, sub, tone, testid }) {
  const color = tone === 'warn' ? '#b45309' : '#0f172a';
  return (
    <div style={{
      background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '14px 16px',
    }} data-testid={testid}>
      <div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.4,
                    color: 'var(--cf-text-secondary)', marginBottom: 4 }}>
        {label}
      </div>
      <div style={{ fontSize: 22, fontWeight: 700, color }}>{value}</div>
      {sub && <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{sub}</div>}
    </div>
  );
}

export default DashboardOverview;
