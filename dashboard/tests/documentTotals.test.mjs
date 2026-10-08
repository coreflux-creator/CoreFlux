import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateDocumentTotals, roundMoney } from '../src/lib/documentTotals.js';

test('line amounts round before the document total', () => {
  const lines = Array.from({ length: 3 }, () => ({ quantity: 1, unit_price: 0.335 }));
  assert.deepEqual(calculateDocumentTotals(lines, 0), {
    subtotal: 1.02, taxTotal: 0, total: 1.02,
  });
});

test('tax rounds separately on each taxable line', () => {
  const lines = Array.from({ length: 3 }, () => ({ quantity: 1, unit_price: 0.335 }));
  assert.deepEqual(calculateDocumentTotals(lines, 10), {
    subtotal: 1.02, taxTotal: 0.09, total: 1.11,
  });
});

test('discounts stay untaxed and negative amounts round away from zero', () => {
  const lines = [
    { quantity: 1, unit_price: 10, item_type: 'other' },
    { quantity: 1, unit_price: -0.335, item_type: 'discount' },
  ];
  assert.deepEqual(calculateDocumentTotals(lines, 10, { discountsUntaxed: true }), {
    subtotal: 9.66, taxTotal: 1, total: 10.66,
  });
  assert.equal(roundMoney(-0.335), -0.34);
});

test('catalog non-taxable lines receive no tax', () => {
  const lines = [
    { quantity: 1, unit_price: 100, taxable: true },
    { quantity: 1, unit_price: 50, taxable: false },
  ];
  assert.deepEqual(calculateDocumentTotals(lines, 10), {
    subtotal: 150, taxTotal: 10, total: 160,
  });
});
