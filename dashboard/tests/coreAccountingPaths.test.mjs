import test from 'node:test';
import assert from 'node:assert/strict';
import { coreAccountingPathAllowed } from '../src/lib/coreAccountingPaths.js';

test('standalone keeps financial and essential account routes', () => {
  for (const path of [
    '/modules/accounting/overview', '/modules/accounting/reports',
    '/modules/billing/invoices', '/modules/ap/bills', '/modules/treasury/accounts',
    '/admin/users', '/profile', '/settings', '/settings/mail', '/inbox',
  ]) {
    assert.equal(coreAccountingPathAllowed(path), true, path);
  }
});

test('standalone redirects unrelated ERP and inactive settings routes', () => {
  for (const path of [
    '/modules/payroll/runs', '/modules/staffing/timesheets', '/modules/people',
    '/settings/staffing-economics', '/settings/notifications', '/data/bulk-import',
    '/modules/accounting-rogue/overview',
  ]) {
    assert.equal(coreAccountingPathAllowed(path), false, path);
  }
});
