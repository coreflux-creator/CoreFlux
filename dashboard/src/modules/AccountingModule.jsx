import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { ModuleHero, StatsGrid, StatCard, Section, ActionCardsGrid, ActionCard, Card, EmptyState } from '../components/UIComponents';

// Accounting Overview
const AccountingOverview = () => {
  return (
    <>
      <ModuleHero
        title="Accounting"
        description="General ledger, accounts payable, accounts receivable, and financial reporting."
        image="/assets/icons/hero-accounting.png"
      />

      <StatsGrid>
        <StatCard value="156" label="Active Accounts" sublabel="Chart of Accounts" />
        <StatCard value="12" label="Pending Entries" sublabel="Awaiting approval" />
        <StatCard value="$1.2M" label="Total Assets" sublabel="Current period" />
      </StatsGrid>

      <Section title="Quick Actions">
        <ActionCardsGrid>
          <ActionCard
            icon="/assets/icons/icon-ledger.png"
            title="Chart of Accounts"
            description="View and manage accounts"
            href="/modules/accounting/chart-of-accounts"
          />
          <ActionCard
            icon="/assets/icons/icon-journal.png"
            title="Journal Entries"
            description="Create and post entries"
            href="/modules/accounting/journal-entries"
          />
          <ActionCard
            icon="/assets/icons/icon-payables.png"
            title="Accounts Payable"
            description="Vendor invoices & payments"
            href="/modules/accounting/accounts-payable"
          />
          <ActionCard
            icon="/assets/icons/icon-reports.png"
            title="Financial Reports"
            description="Balance sheet, P&L, cash flow"
            href="/modules/accounting/reports"
          />
        </ActionCardsGrid>
      </Section>
    </>
  );
};

// Chart of Accounts
const ChartOfAccounts = () => (
  <>
    <ModuleHero
      title="Chart of Accounts"
      description="View and manage your organization's chart of accounts."
      image="/assets/icons/icon-ledger.png"
    />
    <Section title="Accounts">
      <Card>
        <EmptyState 
          title="Account List"
          description="Your chart of accounts will be displayed here."
        />
      </Card>
    </Section>
  </>
);

// Journal Entries
const JournalEntries = () => (
  <>
    <ModuleHero
      title="Journal Entries"
      description="Create, review, and post journal entries."
      image="/assets/icons/icon-journal.png"
    />
    <StatsGrid>
      <StatCard value="8" label="Draft" sublabel="Not posted" />
      <StatCard value="4" label="Pending" sublabel="Awaiting approval" />
      <StatCard value="142" label="Posted" sublabel="This period" />
    </StatsGrid>
    <Section title="Recent Entries">
      <Card>
        <EmptyState 
          title="Journal Entries"
          description="Your journal entries will be displayed here."
        />
      </Card>
    </Section>
  </>
);

// Accounts Payable
const AccountsPayable = () => (
  <>
    <ModuleHero
      title="Accounts Payable"
      description="Manage vendor invoices and payments."
      image="/assets/icons/icon-payables.png"
    />
    <StatsGrid>
      <StatCard value="$45,200" label="Outstanding" sublabel="Total payables" />
      <StatCard value="12" label="Due Soon" sublabel="Next 7 days" />
      <StatCard value="3" label="Overdue" sublabel="Past due date" />
    </StatsGrid>
    <Section title="Pending Invoices">
      <Card>
        <EmptyState 
          title="Invoice List"
          description="Your vendor invoices will be displayed here."
        />
      </Card>
    </Section>
  </>
);

// Accounts Receivable
const AccountsReceivable = () => (
  <>
    <ModuleHero
      title="Accounts Receivable"
      description="Manage customer invoices and collections."
      image="/assets/icons/icon-arap.png"
    />
    <StatsGrid>
      <StatCard value="$128,500" label="Outstanding" sublabel="Total receivables" />
      <StatCard value="8" label="Due Soon" sublabel="Next 7 days" />
      <StatCard value="2" label="Overdue" sublabel="Past due date" />
    </StatsGrid>
    <Section title="Open Invoices">
      <Card>
        <EmptyState 
          title="Customer Invoices"
          description="Your customer invoices will be displayed here."
        />
      </Card>
    </Section>
  </>
);

// Bank Reconciliation
const BankReconciliation = () => (
  <>
    <ModuleHero
      title="Bank Reconciliation"
      description="Reconcile your bank accounts with the general ledger."
      image="/assets/icons/icon-recon.png"
    />
    <Section title="Accounts to Reconcile">
      <Card>
        <EmptyState 
          title="Bank Accounts"
          description="Select an account to begin reconciliation."
        />
      </Card>
    </Section>
  </>
);

// Reports
const AccountingReports = () => (
  <>
    <ModuleHero
      title="Financial Reports"
      description="Generate comprehensive financial reports."
      image="/assets/icons/icon-reports.png"
    />
    <Section title="Available Reports">
      <ActionCardsGrid>
        <ActionCard
          icon="/assets/icons/icon-ledger.png"
          title="Balance Sheet"
          description="Assets, liabilities, equity"
        />
        <ActionCard
          icon="/assets/icons/icon-reports.png"
          title="Income Statement"
          description="Revenue and expenses"
        />
        <ActionCard
          icon="/assets/icons/cashflow.png"
          title="Cash Flow"
          description="Cash movements"
        />
        <ActionCard
          icon="/assets/icons/icon-custom-reports.png"
          title="Trial Balance"
          description="Account balances"
        />
      </ActionCardsGrid>
    </Section>
  </>
);

// Main Accounting Module
const AccountingModule = ({ session }) => {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="overview" replace />} />
      <Route path="overview" element={<AccountingOverview />} />
      <Route path="chart-of-accounts" element={<ChartOfAccounts />} />
      <Route path="chart_of_accounts" element={<Navigate to="../chart-of-accounts" replace />} />
      <Route path="journal-entries" element={<JournalEntries />} />
      <Route path="journal_entries" element={<Navigate to="../journal-entries" replace />} />
      <Route path="accounts-payable" element={<AccountsPayable />} />
      <Route path="accounts_payable" element={<Navigate to="../accounts-payable" replace />} />
      <Route path="accounts-receivable" element={<AccountsReceivable />} />
      <Route path="accounts_receivable" element={<Navigate to="../accounts-receivable" replace />} />
      <Route path="bank-reconciliation" element={<BankReconciliation />} />
      <Route path="bank_reconciliation" element={<Navigate to="../bank-reconciliation" replace />} />
      <Route path="reports" element={<AccountingReports />} />
      <Route path="*" element={<Navigate to="overview" replace />} />
    </Routes>
  );
};

export default AccountingModule;
