import React, { useEffect, useRef, useState } from 'react';
import { Routes, Route, Navigate, NavLink, useLocation } from 'react-router-dom';
import {
  AlertTriangle, BarChart3, BookOpen, Building2, Calendar, CheckSquare,
  ChevronDown, FileText, GitBranch, Landmark, Layers, ListChecks, Network,
  Repeat, Scale, Settings, Sparkles, TrendingUp, Upload, Wallet,
} from 'lucide-react';
import ChartOfAccounts from './ChartOfAccounts';
import AccountDetail from './AccountDetail';
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
import XTenantIntercompany from './XTenantIntercompany';
import EliminationWorksheet from './EliminationWorksheet';
import Consolidation from './Consolidation';
import Periods from './Periods';
import DimensionsAdmin from './DimensionsAdmin';
import PeriodCloseWorkflow from './PeriodCloseWorkflow';
import BookkeepingOverview from '../../../dashboard/src/pages/BookkeepingOverview';
import TransactionsToReview from '../../../dashboard/src/pages/TransactionsToReview';
import MissingDimensions from '../../../dashboard/src/pages/MissingDimensions';
import GLDetail from './GLDetail';
import TaxMappings from './TaxMappings';
import TaxExport from './TaxExport';
import DimensionalPnL from './DimensionalPnL';
import LayerSandboxModule from './layer/LayerSandboxModule';

// LayerFi sandbox embed (per-tenant embedded accounting evaluation).
// Production-safe default: HIDDEN unless VITE_ENABLE_LAYER_SANDBOX is
// explicitly set to "true" at build time. Shipping the bundle without that
// var leaves the native ledger as the only accounting surface (no routes,
// no nav, and the backend endpoints 404 unless ENABLE_LAYER_SANDBOX=true).
const LAYER_SANDBOX_ENABLED =
  typeof import.meta !== 'undefined' &&
  String(import.meta.env?.VITE_ENABLE_LAYER_SANDBOX) === 'true';

const PRIMARY_NAV = [
  { to: 'bookkeeping', label: 'Bookkeeping', Icon: BookOpen },
  { to: 'transactions-to-review', label: 'Transactions', Icon: ListChecks },
  { to: 'accounts', label: 'Chart of accounts', Icon: Landmark },
  { to: 'journal', label: 'Journal entries', Icon: FileText },
  { to: 'bank-rec', label: 'Bank reconciliation', Icon: Scale },
  { to: 'reports', label: 'Reports', Icon: BarChart3 },
];

const MORE_NAV = [
  {
    label: 'Financial statements',
    items: [
      { to: 'trial', label: 'Trial balance', Icon: Scale },
      { to: 'pnl', label: 'Income statement', Icon: TrendingUp },
      { to: 'balance', label: 'Balance sheet', Icon: BookOpen },
      { to: 'cash-flow', label: 'Cash flow', Icon: Wallet },
      { to: 'gl-detail', label: 'GL detail', Icon: Layers },
      { to: 'dim-pnl', label: 'Dimensional P&L', Icon: BarChart3 },
    ],
  },
  {
    label: 'Review and automation',
    items: [
      { to: 'ai-agents', label: 'AI agents', Icon: Sparkles },
      { to: 'recurring', label: 'Recurring entries', Icon: Repeat },
      { to: 'missing-dimensions', label: 'Missing dimensions', Icon: AlertTriangle },
      { to: 'close', label: 'Close workflow', Icon: CheckSquare },
    ],
  },
  {
    label: 'Data and configuration',
    items: [
      { to: 'tax-mappings', label: 'Tax mappings', Icon: Settings },
      { to: 'tax-export', label: 'Tax export', Icon: FileText },
      { to: 'import', label: 'Import', Icon: Upload },
      { to: 'periods', label: 'Periods', Icon: Calendar },
      { to: 'dimensions', label: 'Dimensions', Icon: Settings },
    ],
  },
  {
    label: 'Multi-entity',
    items: [
      { to: 'intercompany', label: 'Intercompany', Icon: GitBranch },
      { to: 'xtenant-ic', label: 'Cross-tenant IC', Icon: Network },
      { to: 'elimination', label: 'Elimination', Icon: Layers },
      { to: 'consolidation', label: 'Consolidation', Icon: Building2 },
    ],
  },
];

/**
 * Accounting Module — Phase 0 + 1 + 2 UI
 */
export default function AccountingModule({ session }) {
  return (
    <div data-testid="accounting-module">
      <AccountingNav />
      <Routes>
        <Route index           element={<Navigate to="accounts" replace />} />
        <Route path="bookkeeping" element={<BookkeepingOverview />} />
        <Route path="books-health" element={<Navigate to="../bookkeeping" replace />} />
        <Route path="transactions-to-review" element={<TransactionsToReview />} />
        <Route path="transactions_to_review" element={<Navigate to="../transactions-to-review" replace />} />
        <Route path="missing-dimensions" element={<MissingDimensions />} />
        <Route path="ai-agents" element={<Navigate to="/ai-agents" replace />} />
        <Route path="accounts" element={<ChartOfAccounts session={session} />} />
        <Route path="accounts/detail" element={<AccountDetail session={session} />} />
        <Route path="accounts/:id" element={<AccountDetail session={session} />} />
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
        <Route path="gl-detail" element={<GLDetail />} />
        <Route path="dim-pnl" element={<DimensionalPnL />} />
        <Route path="tax-mappings" element={<TaxMappings />} />
        <Route path="tax-export" element={<TaxExport />} />
        <Route path="import"   element={<AccountingImport session={session} />} />
        <Route path="intercompany" element={<IntercompanyMappings session={session} />} />
        <Route path="xtenant-ic"   element={<XTenantIntercompany session={session} />} />
        <Route path="elimination"  element={<EliminationWorksheet session={session} />} />
        <Route path="consolidation" element={<Consolidation session={session} />} />
        <Route path="periods"  element={<Periods         session={session} />} />
        <Route path="dimensions" element={<DimensionsAdmin session={session} />} />
        <Route path="close"      element={<PeriodCloseWorkflow session={session} />} />
        {LAYER_SANDBOX_ENABLED && (
          <Route path="layer-sandbox" element={<LayerSandboxModule session={session} view="sandbox" />} />
        )}
        {LAYER_SANDBOX_ENABLED && (
          <Route path="layer-integration" element={<LayerSandboxModule session={session} view="settings" />} />
        )}
      </Routes>
    </div>
  );
}

function AccountingNav() {
  const [moreOpen, setMoreOpen] = useState(false);
  const menuRef = useRef(null);
  const location = useLocation();
  const moreRoutes = MORE_NAV.flatMap(group => group.items.map(item => item.to));
  const moreActive = moreRoutes.some(route => location.pathname.includes(`/accounting/${route}`));

  useEffect(() => {
    const close = event => {
      if (menuRef.current && !menuRef.current.contains(event.target)) setMoreOpen(false);
    };
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, []);

  return (
    <nav className="accounting-nav" aria-label="Accounting sections" data-testid="accounting-section-nav">
      <div className="accounting-nav__primary">
        {PRIMARY_NAV.map(item => <AccountingNavLink key={item.to} item={item} />)}
      </div>
      <div className="accounting-nav__more" ref={menuRef}>
        <button
          type="button"
          className={`accounting-nav__more-button${moreActive ? ' is-active' : ''}`}
          aria-expanded={moreOpen}
          onClick={event => { event.stopPropagation(); setMoreOpen(open => !open); }}
          data-testid="accounting-more-trigger"
        >
          More <ChevronDown size={15} aria-hidden="true" />
        </button>
        {moreOpen && (
          <div className="accounting-nav__menu" data-testid="accounting-more-menu">
            {MORE_NAV.map(group => (
              <section className="accounting-nav__group" key={group.label}>
                <h3>{group.label}</h3>
                {group.items.map(item => (
                  <AccountingNavLink key={item.to} item={item} menu onSelect={() => setMoreOpen(false)} />
                ))}
              </section>
            ))}
          </div>
        )}
      </div>
    </nav>
  );
}

function AccountingNavLink({ item, menu = false, onSelect }) {
  const { to, label, Icon } = item;
  return (
    <NavLink
      to={to}
      data-testid={`accounting-tab-${to}`}
      className={({ isActive }) => `${menu ? 'accounting-nav__menu-link' : 'accounting-nav__link'}${isActive ? ' is-active' : ''}`}
      onClick={onSelect}
    >
      <Icon size={16} aria-hidden="true" />
      {label}
    </NavLink>
  );
}
