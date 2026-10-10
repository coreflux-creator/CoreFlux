import test from 'node:test';
import assert from 'node:assert/strict';
import {
  CORE_ACCOUNTING_HOME, CORE_ACCOUNTING_SEARCH_DESTINATIONS, coreAccountingPathAllowed,
} from '../src/lib/coreAccountingPaths.js';

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

test('standalone search destinations are unique, financial and directly allowed', () => {
  assert.equal(CORE_ACCOUNTING_HOME, '/modules/accounting/overview');
  const destinations = CORE_ACCOUNTING_SEARCH_DESTINATIONS.map(item => item.to);
  assert.equal(new Set(destinations).size, destinations.length);
  assert.ok(destinations.includes('/modules/accounting/reports'));
  assert.ok(destinations.includes('/modules/billing/invoices'));
  assert.ok(destinations.includes('/modules/ap/bills'));
  assert.ok(destinations.every(coreAccountingPathAllowed));
  assert.ok(!destinations.includes('/ai-agents'));
  assert.ok(!destinations.some(path => path.endsWith('/overview') && path !== CORE_ACCOUNTING_HOME));
  const matching = query => CORE_ACCOUNTING_SEARCH_DESTINATIONS
    .filter(item => `${item.label} ${item.detail}`.toLowerCase().includes(query.toLowerCase()))
    .map(item => item.to);
  assert.ok(matching('Billing').includes('/modules/billing/invoices'));
  assert.ok(matching('Accounts payable').includes('/modules/ap/bills'));
});
