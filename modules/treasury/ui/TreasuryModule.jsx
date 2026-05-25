import React from 'react';
import { Routes, Route, Navigate, NavLink, Link } from 'react-router-dom';
import TreasuryOverview   from './TreasuryOverview';
import DepositAccounts    from './DepositAccounts';
import LiabilityAccounts  from './LiabilityAccounts';
import SavedRules         from './SavedRules';
import MercuryRecipients from './MercuryRecipients';
import MercuryPayments from './MercuryPayments';
import ReconciliationWorkbench from './ReconciliationWorkbench';
import SweepRulesAdmin        from './SweepRulesAdmin';
import LiquidityForecast        from '../../../dashboard/src/pages/LiquidityForecast';
import TreasuryScenario         from '../../../dashboard/src/pages/TreasuryScenario';
import TreasuryScenarioCompare  from '../../../dashboard/src/pages/TreasuryScenarioCompare';

/**
 * Treasury module shell. As of 2026-02 the Plaid Transfer + Mercury
 * *connection settings* live under /admin/integrations — Treasury keeps
 * the operational surfaces (recipient vault, payments, reconciliation,
 * forecasts) but no longer hosts tenant-level API token forms.
 */
export default function TreasuryModule({ session }) {
  return (
    <div className="treasury-module" data-testid="treasury-module">
      <nav
        className="treasury-module__tabs"
        style={{
          display: 'flex', gap: 16, borderBottom: '1px solid var(--cf-border, #e5e7eb)',
          padding: '0 24px', marginBottom: 16,
        }}
      >
        <TreasuryTab to="overview"    label="Overview" />
        <TreasuryTab to="forecast"    label="Liquidity Forecast" />
        <TreasuryTab to="scenario"    label="What-If Scenario" />
        <TreasuryTab to="compare"     label="Compare Scenarios" />
        <TreasuryTab to="deposits"    label="Deposit Accounts" />
        <TreasuryTab to="liabilities" label="Liability Accounts" />
        <TreasuryTab to="rules"       label="Saved Rules" />
        <TreasuryTab to="recipients"  label="Recipients" />
        <TreasuryTab to="mercury-payments" label="Mercury Payments" />
        <TreasuryTab to="reconciliation"   label="Reconciliation" />
        <TreasuryTab to="sweep-rules"      label="Sweep Rules" />
      </nav>
      <Routes>
        <Route index                element={<Navigate to="overview" replace />} />
        <Route path="overview"      element={<TreasuryOverview session={session} />} />
        <Route path="forecast"      element={<LiquidityForecast />} />
        <Route path="scenario"      element={<TreasuryScenario />} />
        <Route path="compare"       element={<TreasuryScenarioCompare />} />
        <Route path="deposits/*"    element={<DepositAccounts session={session} />} />
        <Route path="liabilities/*" element={<LiabilityAccounts session={session} />} />
        <Route path="rules"         element={<SavedRules session={session} />} />
        <Route path="recipients"    element={
          <>
            <IntegrationSettingsBanner />
            <MercuryRecipients />
          </>
        } />
        <Route path="mercury-payments" element={<MercuryPayments />} />
        <Route path="reconciliation"   element={<ReconciliationWorkbench />} />
        <Route path="sweep-rules"      element={<SweepRulesAdmin />} />
        {/* Back-compat: anyone deep-linked into the old payout-rails tab
            (AP PaymentsList CTA, bookmarks) lands on Admin → Integrations
            where the connection settings now live. */}
        <Route path="payout-rails"  element={<Navigate to="/admin/integrations" replace />} />
        <Route path="*"             element={<Navigate to="overview" replace />} />
      </Routes>
    </div>
  );
}

function IntegrationSettingsBanner() {
  return (
    <div
      data-testid="treasury-integrations-banner"
      style={{
        margin: '0 24px 16px',
        padding: '10px 14px',
        background: 'var(--cf-blue-bg, #eff6ff)',
        border: '1px solid var(--cf-blue, #2563eb)33',
        borderRadius: 6,
        fontSize: 13,
        color: 'var(--cf-text)',
      }}
    >
      Looking for Plaid Transfer or Mercury connection settings? They've moved to{' '}
      <Link to="/admin/integrations" data-testid="treasury-integrations-link" style={{ color: 'var(--cf-accent)', fontWeight: 500 }}>
        Admin → Integrations
      </Link>.
    </div>
  );
}

function TreasuryTab({ to, label }) {
  return (
    <NavLink
      to={to}
      end={to === 'overview'}
      data-testid={`treasury-tab-${to}`}
      className={({ isActive }) =>
        'treasury-module__tab' + (isActive ? ' treasury-module__tab--active' : '')
      }
      style={({ isActive }) => ({
        padding: '12px 4px',
        fontSize: 13,
        fontWeight: 500,
        textDecoration: 'none',
        color: isActive ? 'var(--cf-accent)' : 'var(--cf-text-muted, #94a3b8)',
        borderBottom: isActive ? '2px solid var(--cf-accent)' : '2px solid transparent',
      })}
    >
      {label}
    </NavLink>
  );
}
