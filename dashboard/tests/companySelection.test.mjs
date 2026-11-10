import test from 'node:test';
import assert from 'node:assert/strict';
import { selectedCompanyMatchesQuery, uniqueExactCompany } from '../src/lib/companySelection.js';

test('typed exact client resolves without picking a suggestion', () => {
  const client = { id: 7, name: 'QA4 Workflow Client' };
  assert.equal(uniqueExactCompany([client], ' qa4 workflow client '), client);
});

test('nearby names and ambiguous exact names never resolve implicitly', () => {
  const clients = [
    { id: 7, name: 'QA4 Workflow Client' },
    { id: 8, name: 'QA4 Workflow Client Holdings' },
  ];
  assert.equal(uniqueExactCompany(clients, 'QA4 Workflow'), null);
  assert.equal(uniqueExactCompany([...clients, { id: 9, name: 'qa4 workflow client' }], 'QA4 Workflow Client'), null);
  assert.equal(uniqueExactCompany(clients, ''), null);
});

test('edited text invalidates a previously selected company', () => {
  const selected = { id: 7, name: 'QA4 Workflow Client' };
  assert.equal(selectedCompanyMatchesQuery(selected, ' qa4 workflow client '), true);
  assert.equal(selectedCompanyMatchesQuery(selected, 'QA4 Workflow Client Holdings'), false);
  assert.equal(selectedCompanyMatchesQuery(selected, ''), false);
  assert.equal(selectedCompanyMatchesQuery(null, 'QA4 Workflow Client'), false);
});
