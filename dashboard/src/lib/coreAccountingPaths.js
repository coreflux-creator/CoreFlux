const MODULE_ROOTS = [
  '/modules/accounting', '/modules/billing', '/modules/ap', '/modules/treasury',
];
const SHARED_ROOTS = ['/inbox', '/profile', '/admin', '/select-tenant'];
const SETTINGS_PATHS = new Set(['/settings', '/settings/mail']);

export const CORE_ACCOUNTING_HOME = '/modules/accounting/overview';
export const CORE_ACCOUNTING_SEARCH_DESTINATIONS = [
  { label: 'Overview', detail: 'Accounting', to: CORE_ACCOUNTING_HOME },
  { label: 'Invoices', detail: 'Money in', to: '/modules/billing/invoices' },
  { label: 'Products & services', detail: 'Money in', to: '/modules/billing/items' },
  { label: 'Customer payments', detail: 'Money in', to: '/modules/billing/payments' },
  { label: 'Bills', detail: 'Money out', to: '/modules/ap/bills' },
  { label: 'Vendor payments', detail: 'Money out', to: '/modules/ap/payments' },
  { label: 'Bank feed', detail: 'Banking', to: '/modules/accounting/transactions-to-review' },
  { label: 'Reconcile', detail: 'Banking', to: '/modules/accounting/bank-rec' },
  { label: 'Journal entries', detail: 'Accounting', to: '/modules/accounting/journal-entries' },
  { label: 'Chart of accounts', detail: 'Accounting', to: '/modules/accounting/accounts' },
  { label: 'Reports', detail: 'Accounting', to: '/modules/accounting/reports' },
  { label: 'Income statement', detail: 'Reports', to: '/modules/accounting/pnl' },
  { label: 'Balance sheet', detail: 'Reports', to: '/modules/accounting/balance' },
  { label: 'Cash flow', detail: 'Reports', to: '/modules/accounting/cash-flow' },
  { label: 'Month-end close', detail: 'Accounting', to: '/modules/accounting/close' },
  { label: 'Approval inbox', detail: 'Operations', to: '/inbox' },
];

export function coreAccountingPathAllowed(pathname) {
  if (SETTINGS_PATHS.has(pathname)) return true;
  return [...MODULE_ROOTS, ...SHARED_ROOTS].some((root) =>
    pathname === root || pathname.startsWith(`${root}/`));
}
