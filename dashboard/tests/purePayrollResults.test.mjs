import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const built = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PurePayrollResults.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'no-network', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'api', namespace: 'test' }));
    b.onLoad({ filter: /.*/, namespace: 'test' }, () => ({ contents: 'export const api={post(){throw new Error("Render must not fetch or write")}};' }));
  } }] });
const module = { exports: {} };
new Function('require', 'module', 'exports', built.outputFiles[0].text)(require, module, module.exports);
const fixture = { status: 'draft', pay_date: '2026-10-16', calculation_mode: 'manual', archived: false, execution_hold: true,
  observed_at: '2026-10-22T00:00:00+00:00', run_id: 'run_synthetic', result_hash: 'observation_synthetic', lines: [{ employee_id: 'employee-A',
    components: [{ gross: '276.12', input_component: { person: { number: 'P-100' }, placement: { number: 'PL-200' }, assignment_id: 'assignment-A',
      source: { time_entry_id: 10, work_date: '2026-10-02', category: 'OT_billable', hours: '2.25', source_system: 'quanta', source_entry_id: 'quanta-entry-A', source_component: 'overtime' },
      rate: { pay_rate: '70.1250', multiplier: '1.75' } } }], amounts: { source_gross: '276.12', gross: '286.12', bonus: '10.00', reimbursements: '2.00', net: '260.12', funding_required: '300.12', federal_wh: '20.00', other_deductions: '8.00', garnishments: '1.00' } }] };
const render = (changes = {}) => renderToStaticMarkup(React.createElement(module.exports.PurePayrollResultView, { result: { ...fixture, ...changes } }));

test('result keeps canonical IDs, original source and decimal placement figures visible', () => {
  const html = render();
  for (const text of ['P-100', 'PL-200', 'employee-A', 'assignment-A', 'quanta-entry-A', 'overtime', '276.12', '70.1250', '1.75']) assert.ok(html.includes(text), text);
  assert.ok(html.includes('They have not been spread across placements'));
  assert.ok(html.includes('included in other deductions'));
});
test('draft figures explain the hold and carry no execution controls', () => {
  const html = render();
  assert.ok(html.includes('Manually entered payroll figures'));
  assert.ok(html.includes('This pilot run is held'));
  assert.ok(html.includes('does not post to Core'));
  assert.ok(!html.includes('<button'));
});
test('recorded paid status is not represented as cash delivery', () => {
  const html = render({ status: 'paid', execution_hold: false, calculation_mode: 'native' });
  assert.ok(html.includes('Recorded paid'));
  assert.ok(html.includes('employee payments and tax remittances are not confirmed'));
  assert.ok(!html.includes('This pilot run is held'));
});
test('void history remains distinguishable from a draft and retains observation identity', () => {
  const html = render({ status: 'void', archived: true });
  for (const text of ['Void', 'Archived', 'observation_synthetic', '2026-10-22T00:00:00+00:00']) assert.ok(html.includes(text));
});
test('initial history screen only offers a read action and does not fetch on render', () => {
  const html = renderToStaticMarkup(React.createElement(module.exports.default, { delivery: { id: 42, review_hash: 'synthetic' } }));
  assert.ok(html.includes('View result history'));
  assert.ok(!html.includes('Approve') && !html.includes('Pay employees'));
});
