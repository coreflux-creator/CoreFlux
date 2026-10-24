import test from 'node:test';
import assert from 'node:assert/strict';
import { billDetailUrl } from '../../modules/ap/ui/billNavigation.mjs';

test('saved bill opens in its actual entity after changing ownership on the form', () => {
  const url = billDetailUrl(8, new URLSearchParams('entity_id=3&status=all'), 1);
  assert.equal(url, '../bills/8?entity_id=1&status=all');
});

test('attachment failure also returns to the bill owner', () => {
  const url = billDetailUrl(8, new URLSearchParams('entity_id=3'), 1, true);
  assert.equal(url, '../bills/8?entity_id=1&attach_error=1');
});

test('no resolved owner cannot preserve a stale entity filter', () => {
  assert.equal(billDetailUrl(8, new URLSearchParams('entity_id=3'), null), '../bills/8');
});
