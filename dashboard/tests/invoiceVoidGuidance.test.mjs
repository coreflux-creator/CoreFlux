import test from 'node:test';
import assert from 'node:assert/strict';
import { invoiceVoidGuidance } from '../src/lib/invoiceVoidGuidance.js';

test('draft does not show a false void denial while approval is loading or failed', () => {
  assert.equal(invoiceVoidGuidance({ status: 'draft', canVoid: false, approvalReady: false }), null);
});

test('resolved draft shows the action or the actual approval blocker', () => {
  assert.equal(invoiceVoidGuidance({ status: 'draft', canVoid: true, approvalReady: true }), null);
  assert.match(invoiceVoidGuidance({ status: 'draft', canVoid: false, approvalReady: true,
    approvalPending: true }), /review is pending/);
});

test('posted or paid invoice keeps correction guidance', () => {
  assert.match(invoiceVoidGuidance({ status: 'paid', canVoid: false }), /cannot be voided/);
  assert.equal(invoiceVoidGuidance({ status: 'void', canVoid: false }), null);
});
