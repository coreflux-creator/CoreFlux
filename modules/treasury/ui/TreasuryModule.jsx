import React from 'react';
import { Routes, Route, Navigate, NavLink } from 'react-router-dom';
import TreasuryOverview   from './TreasuryOverview';
import DepositAccounts    from './DepositAccounts';
import LiabilityAccounts  from './LiabilityAccounts';
import SavedRules         from './SavedRules';
import PlaidTransferSettings from './PlaidTransferSettings';
import MercurySettings from './MercurySettings';
import MercuryRecipients from './MercuryRecipients';
import MercuryPayments from './MercuryPayments';
import LiquidityForecast        from '../../../dashboard/src/pages/LiquidityForecast';
import TreasuryScenario         from '../../../dashboard/src/pages/TreasuryScenario';
import TreasuryScenarioCompare  from '../../../dashboard/src/pages/TreasuryScenarioCompare';

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
        <TreasuryTab to="payout-rails" label="Pay-out Rails" />
        <TreasuryTab to="mercury-payments" label="Mercury Payments" />
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
        <Route path="payout-rails"  element={<><PlaidTransferSettings /><MercurySettings /><MercuryRecipients /></>} />
        <Route path="mercury-payments" element={<MercuryPayments />} />
        <Route path="*"             element={<Navigate to="overview" replace />} />
      </Routes>
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
