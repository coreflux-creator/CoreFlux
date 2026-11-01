import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const built = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePeopleSetup.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'no-network', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'api', namespace: 'test' }));
    b.onLoad({ filter: /.*/, namespace: 'test' }, () => ({ contents: 'export const api={post(){throw new Error("Render must not fetch or write")}};' }));
  } }] });
const module = { exports: {} };
new Function('require', 'module', 'exports', built.outputFiles[0].text)(require, module, module.exports);
const fixture = { person: { number: 'P-123' }, provider_id: 'employee-synthetic', observed_at: '2026-10-22T22:00:00Z',
  indicators: { employee_status: 'active', invitation_recorded: true, profile_fields_present: true,
    tax_election_record: 'provider_recorded', bank_reference_present: true } };
test('all setup indicators present still do not imply completed onboarding or payroll approval', () => {
  const html = renderToStaticMarkup(React.createElement(module.exports.PurePeopleSetupView, { result: fixture }));
  for (const value of ['P-123', 'employee-synthetic', 'Recorded by payroll provider', '2026-10-22T22:00:00Z',
    'do not confirm document review, completed onboarding, bank verification or payroll approval']) assert.ok(html.includes(value), value);
  assert.ok(!html.includes('<button'));
});
test('employee confirmation and missing indicators remain distinguishable', () => {
  const result = { ...fixture, indicators: { ...fixture.indicators, tax_election_record: 'employee_confirmed', profile_fields_present: false, bank_reference_present: false } };
  const html = renderToStaticMarkup(React.createElement(module.exports.PurePeopleSetupView, { result }));
  for (const value of ['Employee confirmation recorded', 'Incomplete', 'Not recorded']) assert.ok(html.includes(value));
});
test('initial screen only requests a deliberate read against a confirmed person link', () => {
  const html = renderToStaticMarkup(React.createElement(module.exports.default));
  assert.ok(html.includes('Check employee setup') && html.includes('already linked'));
  assert.ok(!html.includes('role="status"') && !html.includes('Send invitation'));
});
