import test from 'node:test';
import assert from 'node:assert/strict';
import { defaultRequires1099, extractedRequires1099, withVendorType } from '../../modules/ap/ui/vendorTaxDefaults.mjs';

test('only an individual vendor defaults to 1099-NEC', () => {
  assert.equal(defaultRequires1099('1099_individual'), true);
  for (const type of ['c2c_corp', 'w9_business', 'utility', 'other']) {
    assert.equal(defaultRequires1099(type), false, type);
  }
});

test('changing vendor type resets its 1099 default without losing entered fields', () => {
  const entered = { vendor_name: 'Example', default_terms: 'NET45', vendor_type: 'other', requires_1099: false };
  const individual = withVendorType(entered, '1099_individual');
  assert.deepEqual(individual, { ...entered, vendor_type: '1099_individual', requires_1099: true });
  assert.deepEqual(withVendorType(individual, 'other'), entered);
});

test('a reviewed W-9 classification overrides the broad vendor type', () => {
  assert.equal(extractedRequires1099({ vendor_type: 'w9_business', requires_1099: true }, false), true);
  assert.equal(extractedRequires1099({ vendor_type: 'c2c_corp', requires_1099: false }, true), false);
  assert.equal(extractedRequires1099({ vendor_type: '1099_individual', requires_1099: 'false' }, false), true);
  assert.equal(extractedRequires1099({}, true), true);
});
