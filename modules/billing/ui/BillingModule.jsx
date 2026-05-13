import React from 'react';
import { Routes, Route, NavLink, Navigate } from 'react-router-dom';
import InvoicesList from './InvoicesList';
import InvoiceCreate from './InvoiceCreate';
import InvoiceDetail from './InvoiceDetail';
import InvoicesCsvImport from './InvoicesCsvImport';
import PaymentsList from './PaymentsList';
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

export default function BillingModule() {
  return (
    <div className="people-directory" data-testid="billing-module">
      <header style={{ marginBottom: 'var(--cf-space-5)' }}>
        <h2 style={{ margin: '0 0 var(--cf-space-3)' }}>Billing</h2>
        <nav style={{ display: 'flex', gap: 'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
          {navItems.map(n => (
            <NavLink
              key={n.to}
              to={n.to}
              data-testid={`billing-nav-${n.label.toLowerCase()}`}
              style={({ isActive }) => ({
                padding: '8px 12px',
                borderBottom: isActive ? '2px solid var(--cf-text, #111827)' : '2px solid transparent',
                marginBottom: '-1px',
                textDecoration: 'none',
                color: isActive ? 'var(--cf-text, #111827)' : 'var(--cf-text-secondary, #6b7280)',
                fontWeight: isActive ? 600 : 400,
              })}
            >
              {n.label}
            </NavLink>
          ))}
        </nav>
      </header>

      <Routes>
        <Route index element={<Navigate to="invoices" replace />} />
        <Route path="invoices" element={<InvoicesList />} />
        <Route path="invoices/csv_import" element={<InvoicesCsvImport />} />
        <Route path="invoices/new" element={<InvoiceCreate />} />
        <Route path="invoices/:id" element={<InvoiceDetail />} />
        <Route path="payments" element={<PaymentsList />} />
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
