import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../src/pages/QuantaApprovalEvidence.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'] });
const module = { exports: {} };
new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(require, module, module.exports);
const render = value => renderToStaticMarkup(React.createElement(module.exports.default, { value }));
const proof = { version: 1, timesheet_id: 'sheet-1', timesheet_status: 'approved', approved_at: '2026-10-03T10:00:00.000000Z', approved_by_email: 'approver@example.test', entry_updated_at: '2026-10-03T11:00:00.000000Z' };

test('preview object and stored JSON render the same source evidence', () => {
  const html = render(proof);
  assert.equal(html, render(JSON.stringify(proof)));
  for (const value of ['approver@example.test', 'sheet-1', proof.approved_at, proof.entry_updated_at, 'Core approval is a separate review.']) assert.ok(html.includes(value));
  assert.ok(!html.includes('<button'));
});
test('missing, malformed and unrecognized evidence never imply captured approval', () => {
  for (const value of [null, '{broken', { version: 2 }, 'null']) assert.ok(render(value).includes('Source approval details were not captured.'));
  assert.ok(render({ version: 1, timesheet_id: 'partial' }).includes('Not reported'));
});
test('source text is escaped and malformed field values cannot become markup', () => {
  const html = render({ ...proof, approved_by_email: '<img src=x onerror=alert(1)>', approved_at: { unsafe: true } });
  assert.ok(!html.includes('<img'));
  assert.ok(html.includes('&lt;img'));
  assert.ok(html.includes('Not reported'));
});
