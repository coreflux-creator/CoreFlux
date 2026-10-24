import test from 'node:test';
import assert from 'node:assert/strict';
import { paymentListRequestUrl, receiptId, receiptSourcePath } from '../src/lib/paymentNavigation.js';

test('source-owned receipt journals link to the exact payment', () => {
  for (const source_ref_type of ['billing_payment', 'billing_deposit_application', 'billing_deposit_refund']) {
    assert.equal(receiptSourcePath({ source_module: 'billing', source_ref_type, source_ref_id: '7' }),
      '/modules/billing/payments?payment_id=7');
  }
});

test('unrelated and malformed journal sources cannot create payment links', () => {
  assert.equal(receiptSourcePath({ source_module: 'billing', source_ref_type: 'billing_invoice', source_ref_id: '7' }), null);
  assert.equal(receiptSourcePath({ source_module: 'ap', source_ref_type: 'billing_payment', source_ref_id: '7' }), null);
  for (const bad of [null, '0', '-1', '1e3', '1/2', '99999999999999999999']) {
    assert.equal(receiptId(bad), null);
  }
});

test('payment worklist keeps its exact focus while searching and paging', () => {
  assert.equal(paymentListRequestUrl(2, 25, ' QA4 ', '7'),
    '/api/v1/billing/payments?page=2&per_page=25&q=QA4&payment_id=7');
  assert.equal(paymentListRequestUrl(1, 50, '', null),
    '/api/v1/billing/payments?page=1&per_page=50');
});
