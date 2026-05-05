import React from 'react';
import { Routes, Route, Navigate, NavLink } from 'react-router-dom';
import TreasuryOverview   from './TreasuryOverview';
import DepositAccounts    from './DepositAccounts';
import LiabilityAccounts  from './LiabilityAccounts';
import SavedRules         from './SavedRules';

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
        <TreasuryTab to="deposits"    label="Deposit Accounts" />
        <TreasuryTab to="liabilities" label="Liability Accounts" />
        <TreasuryTab to="rules"       label="Saved Rules" />
      </nav>
      <Routes>
        <Route index                element={<Navigate to="overview" replace />} />
        <Route path="overview"      element={<TreasuryOverview session={session} />} />
        <Route path="deposits/*"    element={<DepositAccounts session={session} />} />
        <Route path="liabilities/*" element={<LiabilityAccounts session={session} />} />
        <Route path="rules"         element={<SavedRules session={session} />} />
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
