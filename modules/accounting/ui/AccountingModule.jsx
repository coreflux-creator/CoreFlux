import React, { useState } from 'react';
import { Routes, Route, Navigate, NavLink } from 'react-router-dom';
import ChartOfAccounts from './ChartOfAccounts';
import JournalEntries from './JournalEntries';
import JournalEntryCreate from './JournalEntryCreate';
import JournalEntryDetail from './JournalEntryDetail';
import TrialBalance from './TrialBalance';
import IncomeStatement from './IncomeStatement';
import BalanceSheet from './BalanceSheet';
import CashFlowStatement from './CashFlowStatement';
import BankReconciliation from './BankReconciliation';
import RecurringJournalEntries from './RecurringJournalEntries';
import StandardReports from './StandardReports';
import AccountingImport from './AccountingImport';
import IntercompanyMappings from './IntercompanyMappings';
import EliminationWorksheet from './EliminationWorksheet';
import Consolidation from './Consolidation';
import Periods from './Periods';
import DimensionsAdmin from './DimensionsAdmin';
import PeriodCloseWorkflow from './PeriodCloseWorkflow';

/**
 * Accounting Module — Phase 0 + 1 + 2 UI
 */
export default function AccountingModule({ session }) {
  return (
    <div data-testid="accounting-module">
      <nav style={{ display: 'flex', gap: 8, borderBottom: '1px solid #e5e7eb', marginBottom: '1rem', flexWrap: 'wrap' }}>
        <Tab to="accounts" label="Chart of Accounts" />
        <Tab to="journal"  label="Journal Entries" />
        <Tab to="trial"    label="Trial Balance" />
        <Tab to="pnl"      label="Income Statement" />
        <Tab to="balance"  label="Balance Sheet" />
        <Tab to="cash-flow" label="Cash Flow" />
        <Tab to="bank-rec"  label="Bank Rec" />
        <Tab to="recurring" label="Recurring JEs" />
        <Tab to="reports"   label="Reports" />
        <Tab to="import"    label="Import" />
        <Tab to="intercompany" label="Intercompany" />
        <Tab to="elimination"  label="Elimination" />
        <Tab to="consolidation" label="Consolidation" />
        <Tab to="periods"  label="Periods" />
        <Tab to="dimensions" label="Dimensions" />
        <Tab to="close"      label="Close workflow" />
      </nav>
      <Routes>
        <Route index           element={<Navigate to="accounts" replace />} />
        <Route path="accounts" element={<ChartOfAccounts session={session} />} />
        <Route path="journal"  element={<JournalEntries  session={session} />} />
        <Route path="journal/new"  element={<JournalEntryCreate session={session} />} />
        <Route path="journal-entries"        element={<JournalEntries  session={session} />} />
        <Route path="journal-entries/new"    element={<JournalEntryCreate session={session} />} />
        <Route path="journal-entries/:id"    element={<JournalEntryDetail session={session} />} />
        <Route path="trial"    element={<TrialBalance    session={session} />} />
        <Route path="pnl"      element={<IncomeStatement session={session} />} />
        <Route path="balance"  element={<BalanceSheet    session={session} />} />
        <Route path="cash-flow" element={<CashFlowStatement session={session} />} />
        <Route path="bank-rec/*" element={<BankReconciliation session={session} />} />
        <Route path="recurring/*" element={<RecurringJournalEntries session={session} />} />
        <Route path="reports"  element={<StandardReports  session={session} />} />
        <Route path="import"   element={<AccountingImport session={session} />} />
        <Route path="intercompany" element={<IntercompanyMappings session={session} />} />
        <Route path="elimination"  element={<EliminationWorksheet session={session} />} />
        <Route path="consolidation" element={<Consolidation session={session} />} />
        <Route path="periods"  element={<Periods         session={session} />} />
        <Route path="dimensions" element={<DimensionsAdmin session={session} />} />
        <Route path="close"      element={<PeriodCloseWorkflow session={session} />} />
      </Routes>
    </div>
  );
}

function Tab({ to, label }) {
  return (
    <NavLink
      to={to}
      data-testid={`accounting-tab-${to}`}
      className={({ isActive }) => (isActive ? 'tab tab--active' : 'tab')}
      style={({ isActive }) => ({
        padding: '0.5rem 1rem',
        borderBottom: isActive ? '2px solid #2563eb' : '2px solid transparent',
        color: isActive ? '#2563eb' : '#444',
        fontWeight: isActive ? 600 : 400,
        textDecoration: 'none',
      })}
    >
      {label}
    </NavLink>
  );
}
