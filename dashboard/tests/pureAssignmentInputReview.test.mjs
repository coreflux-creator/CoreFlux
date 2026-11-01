import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { build } from 'esbuild';

const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const built = await build({ entryPoints: [fileURLToPath(new URL('../../modules/treasury/ui/PureAssignmentInputReview.jsx', import.meta.url))],
  bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'], plugins: [{ name: 'no-network', setup(b) {
    b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'api', namespace: 'test' }));
    b.onLoad({ filter: /.*/, namespace: 'test' }, () => ({ contents: 'export const api={post(){throw new Error("Render must not fetch or write")}};' }));
  } }] });
const mod = { exports: {} };
new Function('require', 'module', 'exports', built.outputFiles[0].text)(require, mod, mod.exports);
const packet = JSON.parse(execFileSync('php', [fileURLToPath(new URL('../../tests/purepay_assignment_fixture.php', import.meta.url)), '--emit'], { encoding: 'utf8' }));

test('Core preview retains all effective periods and clearly separates current from approved economics', () => {
  const html = renderToStaticMarkup(React.createElement(mod.exports.AssignmentCommercialPreview, { packet }));
  for (const text of ['PL-901', 'P-9001', 'CL-1', 'V-1', 'CO-10', '63.187500', '2026-10-31', '2026-11-01',
    '3.5%', '4%', 'weekly', 'monthly', '9999999999.99', 'Approved economic parties',
    'Current work details', 'Current cycles', 'One-time items', 'Client number is mapped from the current placement']) assert.ok(html.includes(text), text);
  assert.ok(!html.includes('<button') && !html.includes('<input'));
});

test('the initial placement screen performs no request or execution and needs a number', () => {
  const html = renderToStaticMarkup(React.createElement(mod.exports.default, { canSend: true }));
  assert.match(html, /<button[^>]*disabled=""[^>]*>Review placement/);
  assert.ok(html.includes('Placement review history'));
  assert.ok(!html.includes('Send for placement review') && !html.includes('Pay employees'));
});

test('source text is escaped and one-time credits remain configuration', () => {
  const changed = structuredClone(packet);
  changed.current_commercial_context.one_time_items[0].description = '<script>bad</script>';
  const html = renderToStaticMarkup(React.createElement(mod.exports.AssignmentCommercialPreview, { packet: changed }));
  assert.ok(html.includes('&lt;script&gt;') && !html.includes('<script>'));
  assert.ok(html.includes('Configuration only') && html.includes('credit'));
});
