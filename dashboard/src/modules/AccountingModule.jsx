import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { ModuleHero, Section, StatsGrid, StatCard, ActionCardsGrid, ActionCard } from '../components/UIComponents';
import { BookOpen, FileText, TrendingUp, CreditCard, Receipt, PieChart, CheckSquare } from 'lucide-react';
import BookkeepingOverview from '../pages/BookkeepingOverview';
import TransactionsToReview from '../pages/TransactionsToReview';
import CloseDashboard from '../pages/CloseDashboard';

// Accounting Overview
const AccountingOverview = () => (
  <>
    <ModuleHero
      title="Accounting"
      description="General ledger, accounts payable, accounts receivable, and financial reporting."
      image="/assets/icons/hero-accounting.png"
    />

    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="156" label="Active Accounts" type="completed" />
        <StatCard value="12" label="Pending Entries" type="pending" />
        <StatCard value="$1.2M" label="Total Assets" type="revenue" />
        <StatCard value="8" label="This Month" type="this_month" />
      </StatsGrid>
    </Section>

    <Section title="Quick Actions">
      <ActionCardsGrid>
        <ActionCard icon={TrendingUp} title="Bookkeeping overview" description="Layer-style books health snapshot" href="/modules/accounting/bookkeeping" />
        <ActionCard icon={BookOpen} title="Chart of Accounts" description="View and manage accounts" href="/modules/accounting/chart-of-accounts" />
        <ActionCard icon={FileText} title="Journal Entries" description="Create and post entries" href="/modules/accounting/journal-entries" />
        <ActionCard icon={CreditCard} title="Accounts Payable" description="Vendor invoices & payments" href="/modules/accounting/accounts-payable" />
        <ActionCard icon={CheckSquare} title="Close dashboard" description="Period close orchestrator: checklist progress, packet build, lock + reopen lifecycle. Spec §11." href="/modules/accounting/close" />
      </ActionCardsGrid>
    </Section>
  </>
);

const ChartOfAccounts = () => (
  <>
    <ModuleHero title="Chart of Accounts" description="View and manage your organization's chart of accounts." />
    <Section title="Accounts">
      <div className="stat-card" style={{ padding: '40px', textAlign: 'center' }}>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Account list will be displayed here.</p>
      </div>
    </Section>
  </>
);

const JournalEntries = () => (
  <>
    <ModuleHero title="Journal Entries" description="Create, review, and post journal entries." />
    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="8" label="Draft" type="pending" />
        <StatCard value="4" label="Pending Approval" type="this_month" />
        <StatCard value="142" label="Posted" type="completed" />
        <StatCard value="$45K" label="This Period" type="revenue" />
      </StatsGrid>
    </Section>
  </>
);

const GeneralLedger = () => (
  <>
    <ModuleHero title="General Ledger" description="View account activity and balances." />
    <Section title="Ledger Overview">
      <div className="stat-card" style={{ padding: '40px', textAlign: 'center' }}>
        <p style={{ color: 'var(--cf-text-secondary)' }}>General ledger data will be displayed here.</p>
      </div>
    </Section>
  </>
);

const AccountsPayable = () => (
  <>
    <ModuleHero title="Accounts Payable" description="Manage vendor invoices and payments." />
    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="$45.2K" label="Outstanding" type="revenue" />
        <StatCard value="12" label="Due Soon" type="pending" />
        <StatCard value="3" label="Overdue" type="this_month" />
        <StatCard value="18" label="Paid This Month" type="completed" />
      </StatsGrid>
    </Section>
  </>
);

const AccountsReceivable = () => (
  <>
    <ModuleHero title="Accounts Receivable" description="Manage customer invoices and collections." />
    <Section title="Quick Overview">
      <StatsGrid>
        <StatCard value="$128.5K" label="Outstanding" type="revenue" />
        <StatCard value="8" label="Due Soon" type="pending" />
        <StatCard value="2" label="Overdue" type="this_month" />
        <StatCard value="24" label="Collected" type="completed" />
      </StatsGrid>
    </Section>
  </>
);

const Reports = () => (
  <>
    <ModuleHero title="Financial Reports" description="Generate comprehensive financial reports." />
    <Section title="Available Reports">
      <ActionCardsGrid>
        <ActionCard icon={BookOpen} title="Balance Sheet" description="Assets, liabilities, equity" />
        <ActionCard icon={PieChart} title="Income Statement" description="Revenue and expenses" />
        <ActionCard icon={TrendingUp} title="Cash Flow" description="Cash movements" />
      </ActionCardsGrid>
    </Section>
  </>
);

const AccountingSettings = () => (
  <>
    <ModuleHero title="Accounting Settings" description="Configure accounting preferences and defaults." />
    <Section title="Settings">
      <div className="stat-card" style={{ padding: '40px', textAlign: 'center' }}>
        <p style={{ color: 'var(--cf-text-secondary)' }}>Settings panel will be displayed here.</p>
      </div>
    </Section>
  </>
);

const AccountingModule = ({ session }) => (
  <Routes>
    <Route path="/" element={<Navigate to="overview" replace />} />
    <Route path="overview" element={<AccountingOverview />} />
    <Route path="bookkeeping" element={<BookkeepingOverview />} />
    <Route path="books-health" element={<Navigate to="../bookkeeping" replace />} />
    <Route path="transactions-to-review" element={<TransactionsToReview />} />
    <Route path="transactions_to_review" element={<Navigate to="../transactions-to-review" replace />} />
    <Route path="chart-of-accounts" element={<ChartOfAccounts />} />
    <Route path="chart_of_accounts" element={<Navigate to="../chart-of-accounts" replace />} />
    <Route path="journal-entries" element={<JournalEntries />} />
    <Route path="journal_entries" element={<Navigate to="../journal-entries" replace />} />
    <Route path="general-ledger" element={<GeneralLedger />} />
    <Route path="general_ledger" element={<Navigate to="../general-ledger" replace />} />
    <Route path="accounts-payable" element={<AccountsPayable />} />
    <Route path="accounts_payable" element={<Navigate to="../accounts-payable" replace />} />
    <Route path="accounts-receivable" element={<AccountsReceivable />} />
    <Route path="accounts_receivable" element={<Navigate to="../accounts-receivable" replace />} />
    <Route path="reports" element={<Reports />} />
    <Route path="close" element={<CloseDashboard />} />
    <Route path="settings" element={<AccountingSettings />} />
    <Route path="*" element={<Navigate to="overview" replace />} />
  </Routes>
);

export default AccountingModule;
