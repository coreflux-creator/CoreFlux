import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import ModuleTabs from '../../../dashboard/src/components/ModuleTabs';
import BillsList from './BillsList';
import BillCreate from './BillCreate';
import BillDetail from './BillDetail';
import BillFromTimeBundleModal from './BillFromTimeBundleModal'; // eslint-disable-line no-unused-vars
import PaymentsList from './PaymentsList';
import PaymentsCsvImport from './PaymentsCsvImport';
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
import VendorsCsvImport from './VendorsCsvImport';
import BillsCsvImport from './BillsCsvImport';
import WeeklyQueue from './WeeklyQueue';

const navItems = [
  { to: '/modules/ap/bills',            label: 'Bills' },
  { to: '/modules/ap/approvals',        label: 'Approvals' },
  { to: '/modules/ap/payments',         label: 'Payments' },
  { to: '/modules/ap/vendors',          label: 'Vendors' },
  { to: '/modules/ap/expenses',         label: 'Expenses' },
  { to: '/modules/ap/weekly-queue',     label: 'Weekly Queue' },
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
      <header className="module-workspace-header">
        <span className="workspace-eyebrow">Money out</span>
        <h1>Accounts payable</h1>
        <p>Review obligations, approve bills, and control vendor payments.</p>
        <ModuleTabs items={navItems.map(n => ({ ...n, testId: `ap-nav-${n.label.toLowerCase()}` }))} primaryCount={5} label="Accounts payable sections" testId="ap-section-nav" />
      </header>

      <Routes>
        <Route index element={<Navigate to="bills" replace />} />
        <Route path="bills" element={<BillsList />} />
        <Route path="bills/csv_import" element={<BillsCsvImport />} />
        <Route path="bills/new" element={<BillCreate />} />
        <Route path="bills/:id" element={<BillDetail />} />
        <Route path="weekly-queue" element={<WeeklyQueue />} />
        <Route path="payments" element={<PaymentsList />} />
        <Route path="payments/csv_import" element={<PaymentsCsvImport />} />
        <Route path="approvals" element={<Approvals />} />
        <Route path="vendors" element={<VendorsList />} />
        <Route path="vendors/csv_import" element={<VendorsCsvImport />} />
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
