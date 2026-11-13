import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../modules/people/ui/CompanyMergeReview.jsx', import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false, external: ['react'] });
const module = { exports: {} };
new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(require, module, module.exports);
const Component = module.exports.default;
const text = node => typeof node === 'string' ? node : (node?.children || []).map(text).join('');
const review = {
  survivor: { id: 11, company_number: 'CO-11', values: { name: 'Acme LLC', phone: '111', roles: ['client'] } },
  retired: { id: 22, company_number: 'CO-22', values: { name: 'Acme Incorporated', phone: null, roles: ['vendor'] } },
  fields: ['name', 'phone', 'roles'], custom_fields: { segment: { label: 'Segment', survivor: { value: 'Old' }, retired: { value: 'Reviewed' } } },
  contacts: [{ id: 31, company_id: 11, name: 'One', is_primary: 1 }, { id: 32, company_id: 22, name: 'Two', is_primary: 1 }],
  addresses: [{ id: 41, company_id: 11, kind: 'hq', line1: 'First street', city: 'One', is_primary: 1 }, { id: 42, company_id: 22, kind: 'hq', line1: 'Second street', city: 'Two', is_primary: 1 }],
  impact: [{ table: 'placements', count: 2, action: 'redirect' }, { table: 'ap_bills', count: 3, action: 'retain' }],
  sources: [{ tenant_id: 1, source_system: 'jobdiva', external_id: 'original-101' }],
};
async function fixture(overrides = {}) {
  const calls = []; let refreshes = 0, cancels = 0;
  let props = { review, busy: false, error: '', onApply: value => calls.push(value), onRefresh: () => refreshes++, onCancel: () => cancels++, ...overrides }, tree;
  await act(async () => { tree = create(React.createElement(Component, props)); });
  return { tree, calls, refreshes: () => refreshes, cancels: () => cancels,
    button: title => tree.root.findAllByType('button').find(n => text(n) === title),
    select: title => tree.root.findAllByType('select').find(n => n.props['aria-label'] === title),
    confirm: value => act(() => tree.root.findByType('input').props.onChange({ target: { checked: value } })),
    update: changes => act(() => { props = { ...props, ...changes }; tree.update(React.createElement(Component, props)); }),
    close: () => act(() => tree.unmount()) };
}
test('company merge explains retained history and cannot apply before confirmation', async () => {
  const f = await fixture(); try {
    const content = text(f.tree.toJSON());
    assert.match(content, /CO-22 → CO-11/); assert.match(content, /Placements: 2 — link to the surviving company/);
    assert.match(content, /Bills: 3 — retain original references/); assert.match(content, /jobdiva: original-101/);
    assert.match(content, /protected tax information/);
    assert.ok(f.button('Apply reviewed merge').props.disabled);
    await act(async () => f.button('Apply reviewed merge').props.onClick()); assert.equal(f.calls.length, 0);
    assert.equal(f.select('Primary contact').props.value, 31); assert.equal(f.select('Primary hq address').props.value, 41);
  } finally { f.close(); }
});
test('reviewed values, roles, custom values and primary records are submitted explicitly', async () => {
  const f = await fixture(); try {
    for (const [name, value] of [['Choose Name', 'retired'], ['Choose Phone', 'retired'], ['Choose Company roles', 'union'], ['Choose Segment', 'retired'], ['Primary contact', '32'], ['Primary hq address', '42']]) {
      await f.confirm(true);
      await act(async () => f.select(name).props.onChange({ target: { value } }));
      assert.ok(f.button('Apply reviewed merge').props.disabled, `${name} requires confirmation of new choices`);
    }
    await f.confirm(true); await act(async () => f.button('Apply reviewed merge').props.onClick());
    assert.deepEqual(f.calls, [{ fields: { name: 'retired', phone: 'retired', roles: 'union' }, custom_fields: { segment: 'retired' }, primary_contact_id: 32, primary_addresses: { hq: 42 } }]);
  } finally { f.close(); }
});
test('busy state disables edits and repeats; failed response preserves exact choices for retry', async () => {
  const f = await fixture(); try {
    await act(async () => f.select('Choose Phone').props.onChange({ target: { value: 'retired' } })); await f.confirm(true);
    await f.update({ busy: true });
    for (const input of [...f.tree.root.findAllByType('select'), ...f.tree.root.findAllByType('input'), ...f.tree.root.findAllByType('button')]) assert.ok(input.props.disabled);
    await act(async () => f.button('Apply reviewed merge').props.onClick()); assert.equal(f.calls.length, 0);
    await f.update({ busy: false, error: 'Response unavailable. Retry this reviewed request.' });
    assert.match(text(f.tree.root.findByProps({ role: 'alert' })), /Response unavailable/);
    assert.equal(f.select('Choose Phone').props.value, 'retired');
    await act(async () => f.button('Apply reviewed merge').props.onClick()); assert.equal(f.calls[0].fields.phone, 'retired');
    await act(async () => f.button('Refresh review').props.onClick()); await act(async () => f.button('Cancel').props.onClick());
    assert.equal(f.refreshes(), 1); assert.equal(f.cancels(), 1);
  } finally { f.close(); }
});
test('ambiguous primary records are not silently chosen', async () => {
  const f = await fixture({ review: { ...review, contacts: [...review.contacts, { id: 33, company_id: 11, name: 'Another', is_primary: 1 }] } });
  try { assert.equal(f.select('Primary contact').props.value, 0); } finally { f.close(); }
});
