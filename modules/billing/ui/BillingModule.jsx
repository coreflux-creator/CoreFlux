import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import ModuleTabs from '../../../dashboard/src/components/ModuleTabs';
import InvoicesList from './InvoicesList';
import InvoiceCreate from './InvoiceCreate';
import InvoiceDetail from './InvoiceDetail';
import InvoicesCsvImport from './InvoicesCsvImport';
import PaymentsList from './PaymentsList';
import PaymentsCsvImport from './PaymentsCsvImport';
import AgingTable from './AgingTable';
import RecurringContracts from './RecurringContracts';
import DunningQueue from './DunningQueue';
import ClientContacts from './ClientContacts';
import MoneyMovementPreview from './MoneyMovementPreview';
import MoneyMovementArchive from './MoneyMovementArchive';

const navItems = [
  { to: '/modules/billing/invoices',  label: 'Invoices' },
  { to: '/modules/billing/payments',  label: 'Payments' },
  { to: '/modules/billing/aging',     label: 'Aging' },
  { to: '/modules/billing/dunning',   label: 'Dunning' },
  { to: '/modules/billing/contracts', label: 'Contracts' },
  { to: '/modules/billing/clients',   label: 'Client contacts' },
  { to: '/modules/billing/money-movement',         label: 'Money movement' },
  { to: '/modules/billing/money-movement/archive', label: 'Archive' },
];

export default function BillingModule({ session }) {
  return (
    <div className="people-directory" data-testid="billing-module">
      <header className="module-workspace-header">
        <span className="workspace-eyebrow">Money in</span>
        <h1>Billing</h1>
        <p>Invoice clients, collect cash, and manage receivables.</p>
        <ModuleTabs items={navItems.map(n => ({ ...n, testId: `billing-nav-${n.label.toLowerCase()}` }))} primaryCount={5} label="Billing sections" testId="billing-section-nav" />
      </header>

      <Routes>
        <Route index element={<Navigate to="invoices" replace />} />
        <Route path="invoices" element={<InvoicesList session={session} />} />
        <Route path="invoices/csv_import" element={<InvoicesCsvImport />} />
        <Route path="invoices/new" element={<InvoiceCreate />} />
        <Route path="invoices/:id" element={<InvoiceDetail />} />
        <Route path="payments" element={<PaymentsList />} />
        <Route path="payments/csv_import" element={<PaymentsCsvImport />} />
        <Route path="aging" element={<AgingTable />} />
        <Route path="dunning" element={<DunningQueue />} />
        <Route path="contracts" element={<RecurringContracts />} />
        <Route path="clients" element={<ClientContacts />} />
        <Route path="money-movement" element={<MoneyMovementPreview />} />
        <Route path="money-movement/archive" element={<MoneyMovementArchive />} />
      </Routes>
    </div>
  );
}
