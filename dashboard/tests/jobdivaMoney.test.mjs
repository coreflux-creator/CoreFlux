import test from 'node:test';
import assert from 'node:assert/strict';
import { displayJobDivaMoney } from '../../modules/placements/ui/jobdivaMoney.mjs';
test('review displays the verified source currency and basis', () => {
  assert.equal(displayJobDivaMoney(70, 'CAD', 'hour'), 'CAD 70.00 / hour');
  assert.equal(displayJobDivaMoney(500, 'GBP', 'day'), 'GBP 500.00 / day');
  assert.equal(displayJobDivaMoney(70, 'USD', 'hour'), 'USD 70.00 / hour');
});
test('missing basis never becomes a USD amount or zero margin', () => {
  assert.equal(displayJobDivaMoney(70, null, null), 'Currency unverified 70.00');
  assert.equal(displayJobDivaMoney(null, 'USD', 'hour'), '-');
  assert.equal(displayJobDivaMoney(0, 'USD', 'hour'), 'USD 0.00 / hour');
});
