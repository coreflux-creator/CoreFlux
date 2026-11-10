import assert from 'node:assert/strict';
import { test } from 'node:test';
import { preferredAccountEntityId } from '../../modules/accounting/ui/accountEntityScope.mjs';

const entities = [{ id: 1 }, { id: 3 }];

test('a sole Treasury liability owner takes precedence over the active entity', () => {
  assert.equal(preferredAccountEntityId(entities, {
    liability_accounts: [{ entity_id: 3 }],
  }, 1), '3');
});

test('a sole linked bank owner takes precedence over the active entity', () => {
  assert.equal(preferredAccountEntityId(entities, {
    bank_accounts: [{ entity_id: '3' }],
  }, 1), '3');
});

test('a general ledger account uses the active entity', () => {
  assert.equal(preferredAccountEntityId(entities, {}, 3), '3');
  assert.equal(preferredAccountEntityId(entities, {}, 999), '');
  assert.equal(preferredAccountEntityId([{ id: 1 }], {}, null), '1');
});

test('multiple linked owners require a choice unless active entity is one of them', () => {
  const connections = { bank_accounts: [{ entity_id: 1 }, { entity_id: 3 }] };
  assert.equal(preferredAccountEntityId(entities, connections, 3), '3');
  assert.equal(preferredAccountEntityId(entities, connections, 999), '');
});

test('unknown and missing entities never become account scope', () => {
  assert.equal(preferredAccountEntityId(entities, {
    liability_accounts: [{ entity_id: 999 }],
  }, 3), '');
  assert.equal(preferredAccountEntityId(entities, {
    bank_accounts: [{ entity_id: null }],
  }, 3), '');
  assert.equal(preferredAccountEntityId([], {}, 3), '');
});
