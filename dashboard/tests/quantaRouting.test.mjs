import test from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/lib/quantaRouting.js', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false });
const module = { exports: {} }; new Function('module', 'exports', compiled.outputFiles[0].text)(module, module.exports);
const { quantaRoutingOptions: options, quantaRoutingSuggestion: suggestion, quantaRoutingDraft: draft } = module.exports;
const placements = [{ id: 42, person_id: 19, status: 'active', placement_number: 'PL-42', person_number: 'P-19', placement_directory_tenant_id: 1, person_directory_tenant_id: 1, start_date: '2026-10-01', end_date: null, end_client_name: 'Same client' },
  { id: 43, person_id: 19, status: 'active', placement_number: 'PL-43', person_number: 'P-19', placement_directory_tenant_id: 1, person_directory_tenant_id: 1, start_date: '2026-10-01', end_date: null, end_client_name: 'Same client' }];
const row = { worker_id: 'worker-1', worksite_id: 'site-1', dimension_values: [], work_date: '2026-10-07', core_assignment: { assignment_id: 'cfa-original', person: { number: 'P-19', directory_tenant_id: 1 }, placement: { number: 'PL-43', directory_tenant_id: 1 } } };
test('captured placement wins over identical names and overlapping dates', () => { assert.deepEqual(options(row, placements, 19).map(p => p.id), [43]); assert.equal(suggestion(row, placements, 19, 'Unrelated display name'), '43'); });
test('equal business numbers in a different directory never match', () => { const foreign = structuredClone(row); foreign.core_assignment.placement.directory_tenant_id = 2; assert.deepEqual(options(foreign, placements, 19), []); });
test('other people and out-of-period placements cannot be selected', () => { assert.deepEqual(options(row, placements, 20), []); assert.deepEqual(options({ ...row, work_date: '2026-09-30' }, placements, 19), []); });
test('legacy naming ambiguity stays unselected', () => { const legacy = { ...row, core_assignment: undefined }; assert.equal(suggestion(legacy, placements, 19, 'Same client'), ''); assert.equal(suggestion(legacy, placements.slice(0, 1), 19, 'same client'), '42'); });
test('route submission carries the captured ID without replacing provider identity', () => { const result = draft(row, '43', placements, 19, '2026-10-01'); assert.equal(result.quanta_assignment_id, 'cfa-original'); assert.equal(result.placement_id, 43); assert.equal(result.effective_from, '2026-10-01'); assert.throws(() => draft(row, '42', placements, 19)); });
test('legacy submission retains the empty routing selector', () => { assert.equal(draft({ ...row, core_assignment: undefined }, '42', placements, 19).quanta_assignment_id, ''); });
