import React from 'react';
import { Routes, Route, NavLink, Navigate } from 'react-router-dom';
import BillsList from './BillsList';
import BillCreate from './BillCreate';
import BillDetail from './BillDetail';
import BillFromTimeBundleModal from './BillFromTimeBundleModal'; // eslint-disable-line no-unused-vars
import PaymentsList from './PaymentsList';
import VendorsList from './VendorsList';
import ExpensesList from './ExpensesList';
import ExpenseCreate from './ExpenseCreate';
import AgingTable from './AgingTable';
import Ledger1099 from './Ledger1099';
import Export from './Export';
import Settings from './Settings';
import Approvals from './Approvals';
import RecurringBills from './RecurringBills';
import PurchaseOrders from './PurchaseOrders';
import VendorUploadsReview from './VendorUploadsReview';

const navItems = [
  { to: '/modules/ap/bills',            label: 'Bills' },
  { to: '/modules/ap/approvals',        label: 'Approvals' },
  { to: '/modules/ap/payments',         label: 'Payments' },
  { to: '/modules/ap/vendors',          label: 'Vendors' },
  { to: '/modules/ap/expenses',         label: 'Expenses' },
  { to: '/modules/ap/recurring',        label: 'Recurring' },
  { to: '/modules/ap/purchase-orders',  label: 'POs' },
  { to: '/modules/ap/vendor-uploads',   label: 'Vendor uploads' },
  { to: '/modules/ap/aging',            label: 'Aging' },
  { to: '/modules/ap/1099',             label: '1099' },
  { to: '/modules/ap/export',           label: 'Export' },
  { to: '/modules/ap/settings',         label: 'Settings' },
];

export default function APModule() {
  return (
    <div className="people-directory" data-testid="ap-module">
      <header style={{ marginBottom: 'var(--cf-space-5)' }}>
        <h2 style={{ margin: '0 0 var(--cf-space-3)' }}>Accounts Payable</h2>
        <nav style={{ display: 'flex', gap: 'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)', flexWrap: 'wrap' }}>
          {navItems.map(n => (
            <NavLink
              key={n.to}
              to={n.to}
              data-testid={`ap-nav-${n.label.toLowerCase()}`}
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
        <Route index element={<Navigate to="bills" replace />} />
        <Route path="bills" element={<BillsList />} />
        <Route path="bills/new" element={<BillCreate />} />
        <Route path="bills/:id" element={<BillDetail />} />
        <Route path="payments" element={<PaymentsList />} />
        <Route path="approvals" element={<Approvals />} />
        <Route path="vendors" element={<VendorsList />} />
        <Route path="expenses" element={<ExpensesList />} />
        <Route path="expenses/new" element={<ExpenseCreate />} />
        <Route path="recurring" element={<RecurringBills />} />
        <Route path="purchase-orders" element={<PurchaseOrders />} />
        <Route path="purchase-orders/:id" element={<PurchaseOrders />} />
        <Route path="vendor-uploads" element={<VendorUploadsReview />} />
        <Route path="aging" element={<AgingTable />} />
        <Route path="1099" element={<Ledger1099 />} />
        <Route path="export" element={<Export />} />
        <Route path="settings" element={<Settings />} />
      </Routes>
    </div>
  );
}
