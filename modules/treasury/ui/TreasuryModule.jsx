import React from 'react';
import { Routes, Route, Navigate, Link } from 'react-router-dom';
import ModuleTabs from '../../../dashboard/src/components/ModuleTabs';
import TreasuryOverview   from './TreasuryOverview';
import DepositAccounts    from './DepositAccounts';
import LiabilityAccounts  from './LiabilityAccounts';
import SavedRules         from './SavedRules';
import MercuryRecipients from './MercuryRecipients';
import MercuryPayments from './MercuryPayments';
import ReconciliationWorkbench from './ReconciliationWorkbench';
import SweepRulesAdmin        from './SweepRulesAdmin';
import SweepDestinations      from './SweepDestinations';
import MercuryWebhookConfig   from './MercuryWebhookConfig';
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
  const navItems = [
    { to: '/modules/treasury/overview', label: 'Overview' },
    { to: '/modules/treasury/forecast', label: 'Liquidity Forecast' },
    { to: '/modules/treasury/deposits', label: 'Deposit accounts' },
    { to: '/modules/treasury/liabilities', label: 'Liability accounts' },
    { to: '/modules/treasury/reconciliation', label: 'Reconciliation' },
    { to: '/modules/treasury/scenario', label: 'What-If Scenario' },
    { to: '/modules/treasury/compare', label: 'Compare Scenarios' },
    { to: '/modules/treasury/rules', label: 'Saved Rules' },
    { to: '/modules/treasury/recipients', label: 'Recipients' },
    { to: '/modules/treasury/mercury-payments', label: 'Mercury Payments' },
    { to: '/modules/treasury/sweep-rules', label: 'Sweep Rules' },
    { to: '/modules/treasury/sweep-destinations', label: 'Sweep destinations' },
    { to: '/modules/treasury/mercury-webhooks', label: 'Webhooks' },
  ];
  return (
    <div className="treasury-module" data-testid="treasury-module">
      <header className="module-workspace-header">
        <span className="workspace-eyebrow">Cash and capital</span>
        <h1>Treasury</h1>
        <p>Monitor accounts, liquidity, obligations, and cash movement.</p>
        <ModuleTabs items={navItems} primaryCount={5} label="Treasury sections" testId="treasury-section-nav" />
      </header>
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
        <Route path="sweep-destinations" element={<SweepDestinations />} />
        <Route path="mercury-webhooks"   element={<MercuryWebhookConfig />} />
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
