import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require = createRequire(import.meta.url), React = require('react');
const { create, act } = require('react-test-renderer');
const { MemoryRouter, Routes, Route } = require('react-router-dom');
let view, failure = null, pin = '2002';
const apiFixture = {
  useApi: path => ({ data: path?.includes('placements.php') ? { rows: [] } : view, loading: false, error: failure, reload: () => {}, mutate: () => {} }),
  getPinnedTenantId: () => pin,
  api: { post: () => { throw new Error('History must never write'); } }, bustApiCachePrefix: () => {},
};
async function component(path) {
  const compiled = await build({ entryPoints: [fileURLToPath(new URL(path, import.meta.url))], bundle: true, platform: 'node', format: 'cjs', write: false,
    external: ['react', 'react-router-dom', 'lucide-react'], plugins: [{ name: 'fixture', setup(b) {
      b.onResolve({ filter: /lib\/api$/ }, () => ({ path: 'fixture-api', external: true }));
    } }] });
  const module = { exports: {} };
  new Function('require', 'module', 'exports', compiled.outputFiles[0].text)(id => id === 'fixture-api' ? apiFixture : require(id), module, module.exports);
  return module.exports.default;
}
const Tab = await component('../../modules/placements/ui/PlacementTimesheetsTab.jsx');
const Detail = await component('../../modules/staffing/ui/TimesheetDetail.jsx');
const text = n => typeof n === 'string' ? n : (n.children || []).map(text).join('');
const id = (tree, testId) => tree.root.findAllByProps({ 'data-testid': testId });
const row = { id: 42, person_id: 15, status: 'submitted', period_start: '2026-10-05', period_end: '2026-10-11', placement_hours: 8, billable_hours: 8, unbilled_billable_hours: 8, first_name: 'Original', last_name: 'Worker' };
const data = (history = true) => ({ workspace_id: 2002, placement_id: 902, current_placement_id: history ? 903 : 902, read_only: history,
  rows: [row], timesheet: row, entries: [{ id: 51, placement_id: 902, placement_title: 'Original assignment', work_date: '2026-10-05', hour_type: 'regular', category: 'regular_billable', hours: 8, status: 'pending_review' }], placement_hours: 8 });
async function open(Component) {
  globalThis.window = { location: { search: '?placement_id=902' } };
  let tree;
  await act(async () => { tree = create(React.createElement(MemoryRouter, { initialEntries: ['/42'] },
    React.createElement(Routes, null, React.createElement(Route, { path: '/:id', element: React.createElement(Component, { pid: 902, placement: { person_id: 15 } }) })))); });
  return tree;
}
test('historical placement tab keeps original rows and omits invoice and new-time actions', async () => {
  view = data(); failure = null; pin = '2002'; const tree = await open(Tab);
  try { assert.match(text(tree.root), /Original Worker/); assert.equal(id(tree, 'placement-timesheets-suggest-invoice').length, 0); assert.equal(id(tree, 'placement-timesheets-create-new').length, 0); assert.ok(tree.root.findAllByType('a').some(a => a.props.href === '/modules/placements/903')); }
  finally { act(() => tree.unmount()); }
});
test('current placement tab retains invoice and weekly-grid actions', async () => {
  view = data(false); failure = null; pin = '2002'; const tree = await open(Tab);
  try { assert.equal(id(tree, 'placement-timesheets-suggest-invoice')[0].props.disabled, false); assert.equal(id(tree, 'placement-timesheets-create-new').length, 2); }
  finally { act(() => tree.unmount()); }
});
test('current placement without approved unbilled hours cannot prepare an invoice', async () => {
  view = data(false); view.rows = [{ ...row, unbilled_billable_hours: 0 }]; failure = null; pin = '2002'; const tree = await open(Tab);
  try { assert.equal(id(tree, 'placement-timesheets-suggest-invoice')[0].props.disabled, true); }
  finally { act(() => tree.unmount()); }
});
for (const mode of ['wrong-placement', 'wrong-workspace', 'revoked']) test(`placement tab conceals stale rows and actions after ${mode}`, async () => {
  view = data(false); failure = null; pin = '2002';
  if (mode === 'wrong-placement') view.placement_id = 903;
  if (mode === 'wrong-workspace') view.workspace_id = 2003;
  if (mode === 'revoked') failure = new Error('Access removed');
  const tree = await open(Tab);
  try { assert.doesNotMatch(text(tree.root), /Original Worker/); assert.equal(id(tree, 'placement-timesheets-suggest-invoice').length, 0); assert.equal(id(tree, 'placement-timesheets-create-new').length, 0); }
  finally { act(() => tree.unmount()); }
});
for (const status of ['draft', 'submitted', 'rejected']) test(`historical ${status} timesheet cannot edit, add, submit or approve`, async () => {
  view = data(); view.timesheet = { ...row, status }; failure = null; pin = '2002'; const tree = await open(Detail);
  try {
    assert.match(text(tree.root), /Original assignment/); assert.equal(id(tree, 'timesheet-detail-history-notice').length, 1);
    for (const key of ['timesheet-detail-open-week', 'timesheet-detail-approve', 'timesheet-detail-reject-open', 'timesheet-detail-submit']) assert.equal(id(tree, key).length, 0);
    assert.equal(tree.root.findAllByType('input').length, 0); assert.equal(tree.root.findAllByType('select').length, 0);
  } finally { act(() => tree.unmount()); }
});
test('placement timesheet detail rejects a response for a different workspace', async () => {
  view = data(); view.workspace_id = 2003; failure = null; pin = '2002'; const tree = await open(Detail);
  try { assert.match(text(tree.root), /Reload the timesheet/); assert.doesNotMatch(text(tree.root), /Original assignment/); }
  finally { act(() => tree.unmount()); }
});

for (const [kind,fields] of [
  ['approved',{status:'approved'}],['superseded',{status:'superseded'}],
  ['Quanta source',{quanta_entry_id:'quanta-original'}],['unmapped Quanta source',{source_system:'quanta'}],
  ['used payroll',{payroll_extracted_ref:'original-payroll'}],['closed period',{period_status:'closed'}],
  ['locked period',{period_status:'locked'}],['retained placement',{placement_deleted_at:'2026-10-20'}],
  ['missing placement',{placement_record_id:null}],
]) test(`mixed draft week preserves ${kind} while native pending time stays editable`,async()=>{
  view=data(false);view.timesheet={...row,status:'draft'};failure=null;pin='2002';
  view.entries=[{...view.entries[0],...fields},{...view.entries[0],id:52,work_date:'2026-10-06',status:'pending_review'}];
  const tree=await open(Detail);
  try {
    for(const action of ['save','delete','date','hours','hour-type','description']) assert.equal(id(tree,`timesheet-detail-entry-51-${action}`).length,0);
    assert.equal(id(tree,'timesheet-detail-entry-51-read-only').length,1);
    assert.equal(id(tree,'timesheet-detail-entry-52-save').length,1);
    assert.equal(id(tree,'timesheet-detail-entry-52-hours').length,1);
  } finally {act(()=>tree.unmount());}
});

test('a refreshed approval removes stale local edits from row controls and totals',async()=>{
  view=data(false);view.timesheet={...row,status:'draft'};failure=null;pin='2002';
  const tree=await open(Detail);
  try {
    await act(async()=>id(tree,'timesheet-detail-entry-51-hours')[0].props.onChange({target:{value:'12'}}));
    assert.match(text(id(tree,'timesheet-detail-header-total')[0]),/12.00h/);
    view={...view,entries:[{...view.entries[0],status:'approved'}]};
    await act(async()=>tree.update(React.createElement(MemoryRouter,{initialEntries:['/42']},
      React.createElement(Routes,null,React.createElement(Route,{path:'/:id',element:React.createElement(Detail,{pid:902,placement:{person_id:15}})})))));
    assert.match(text(id(tree,'timesheet-detail-header-total')[0]),/8.00h/);
    assert.equal(id(tree,'timesheet-detail-entry-51-save').length,0);
    assert.equal(id(tree,'timesheet-detail-save-all').length,0);
    assert.equal(id(tree,'timesheet-detail-entry-51')[0].props.style.background,undefined);
  } finally {act(()=>tree.unmount());}
});

for(const mode of ['eligible','no-authority','Quanta','extracted','locked-period','retired-placement','historical','locked-week'])test(`approved correction navigation respects ${mode}`,async()=>{
  view=data(false);view.timesheet={...row,status:'approved'};view.permissions={time_history:true,correct_time:true};failure=null;pin='2002';
  view.entries=[{...view.entries[0],status:'approved',period_status:'open',placement_record_id:902}];
  if(mode==='no-authority')view.permissions.correct_time=false;
  if(mode==='Quanta')view.entries[0].source_system='quanta';
  if(mode==='extracted')view.entries[0].ap_extracted_ref='retained-bill';
  if(mode==='locked-period')view.entries[0].period_status='locked';
  if(mode==='retired-placement')view.entries[0].placement_deleted_at='2026-10-20';
  if(mode==='historical')view.read_only=true;
  if(mode==='locked-week')view.timesheet.status='locked';
  const tree=await open(Detail);try{
    assert.equal(id(tree,'timesheet-detail-entry-51-correct').filter(node=>node.type==='a').length,mode==='eligible'?1:0);
    assert.equal(id(tree,'timesheet-detail-entry-51-history').filter(node=>node.type==='a').length,1);
    assert.equal(id(tree,'timesheet-detail-entry-51-save').length,0);
  }finally{act(()=>tree.unmount());}
});
