import test from 'node:test';
import assert from 'node:assert/strict';
import { requireSupportedJournalDimensions, visibleJournalDimensions } from '../../modules/accounting/ui/journalDimensions.js';

const dimensions = [
  { dim_key: 'placement', active: 1 },
  { dim_key: 'worker', active: 1 },
  { dim_key: 'client', active: 1 },
  { dim_key: 'department', active: 1 },
  { dim_key: 'legal_entity', active: 1 },
  { dim_key: 'cost_center', active: 0 },
];

test('standalone journals offer accounting dimensions without staffing pickers', () => {
  assert.deepEqual(visibleJournalDimensions(dimensions, 'coreaccounting').map((item) => item.dim_key), [
    'client', 'department',
  ]);
});

test('CoreFlux keeps its existing journal dimensions', () => {
  assert.deepEqual(visibleJournalDimensions(dimensions, 'coreflux').map((item) => item.dim_key), [
    'placement', 'worker', 'client', 'department',
  ]);
});

test('standalone refuses editing a placement-linked journal, but ordinary entries remain usable', () => {
  assert.throws(() => requireSupportedJournalDimensions([{ dims: { placement: '42' } }], 'coreaccounting'), /linked to a staffing placement/);
  assert.doesNotThrow(() => requireSupportedJournalDimensions([{ dims: { department: 'Ops' } }], 'coreaccounting'));
  assert.doesNotThrow(() => requireSupportedJournalDimensions([{ dims: { placement: '42' } }], 'coreflux'));
});
