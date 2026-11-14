import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
const require=createRequire(import.meta.url),React=require('react');
const {create,act}=require('react-test-renderer');
let get,post;
const fixture={api:{get:(...args)=>get(...args),post:(...args)=>post(...args)}};
const router={useParams:()=>({lineId:'11',runId:'7'}),Link:({children,...props})=>React.createElement('a',props,children)};
async function component(name){
  const built=await build({entryPoints:[fileURLToPath(new URL(`../../modules/payroll/ui/${name}.jsx`,import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react','react-router-dom'],plugins:[{name:'fixture',setup(b){
    b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));
    b.onResolve({filter:/components\/(AISuggestion|ExportTemplatePicker|CsvUploadWidget)$/},()=>({path:'fixture-child',external:true}));
  }}]});
  const module={exports:{}};
  new Function('require','module','exports',built.outputFiles[0].text)(id=>id==='fixture-api'?fixture:id==='react-router-dom'?router:id==='fixture-child'?()=>null:require(id),module,module.exports);
  return module.exports.default;
}
const Stub=await component('PayStub'),Run=await component('PayrollRunDetail');
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
const stub=(extra={})=>({line:{id:11,run_id:7,employee_number:'E-1',legal_first_name:'Synthetic',legal_last_name:'Worker',gross_cents:11376,net_cents:10000,earnings_basis:'approved_time',...extra},earnings:[{id:1,code:'overtime',hours:'1.0000',rate_cents:null,source_rate:'65.0050',source_multiplier:'1.7500',amount_cents:11376}],taxes:[],deductions:[],company:{legal_name:'CI payroll'}});
async function open(Component){let tree;await act(async()=>{tree=create(React.createElement(Component));});return tree;}
test('pay stub displays the original fractional-cent rate and overtime multiplier',async()=>{
  get=async()=>stub();const tree=await open(Stub);try{const value=text(tree.root);assert.match(value,/\$65\.0050 × 1\.7500/);assert.match(value,/Rates and hours retained from approved time/);assert.match(value,/\$113\.76/);}finally{act(()=>tree.unmount());}
});
test('regular source rate does not add a confusing unit multiplier',async()=>{
  const data=stub();data.earnings[0]={...data.earnings[0],code:'regular',source_multiplier:'1.0000'};get=async()=>data;
  const tree=await open(Stub);try{assert.match(text(tree.root),/\$65\.0050/);assert.doesNotMatch(text(tree.root),/ × /);}finally{act(()=>tree.unmount());}
});
test('compensation pay stub still shows its whole-cent hourly rate',async()=>{
  const data=stub({earnings_basis:'compensation'});data.earnings[0]={id:1,code:'regular',hours:1,rate_cents:3000,amount_cents:3000};get=async()=>data;
  const tree=await open(Stub);try{assert.match(text(tree.root),/\$30\.00/);assert.doesNotMatch(text(tree.root),/retained from approved time/);}finally{act(()=>tree.unmount());}
});
test('compute submits the revision actually shown and reloads the new one',async()=>{
  let revision=4;const calls=[];
  get=async path=>path.includes('runs?id=')?{run:{id:7,status:'draft',pay_period_id:8,computation_revision:revision},lines:[]}
    :path.includes('/preflight?')?{summary:{ready_to_run:true,total_w2_employees:1},employees:[]}:{};
  post=async(path,body)=>{calls.push([path,body]);revision++;return {};};
  const tree=await open(Run);try{
    const button=()=>tree.root.findByProps({'data-testid':'payroll-run-compute'});
    await act(async()=>button().props.onClick());await act(async()=>button().props.onClick());
    assert.equal(calls.length,2);assert.equal(calls[0][1].expected_computation_revision,4);assert.equal(calls[1][1].expected_computation_revision,5);
  }finally{act(()=>tree.unmount());}
});
test('approval submits the calculation identity shown to the reviewer',async()=>{
  const calls=[],hash='b'.repeat(64);
  get=async path=>path.includes('runs?id=')?{run:{id:7,status:'computed',pay_period_id:8,computation_revision:6,computation_sha256:hash},lines:[{id:11,employee_id:12,legal_first_name:'Synthetic',legal_last_name:'Worker'}]}
    :path.includes('/preflight?')?{summary:{ready_to_run:true,total_w2_employees:1},employees:[]}:{};
  post=async(path,body)=>{calls.push([path,body]);return {status:'approved'};};
  const tree=await open(Run);try{
    assert.match(text(tree.root),/Calculation version 6/);
    await act(async()=>tree.root.findByProps({'data-testid':'payroll-run-approve'}).props.onClick());
    assert.deepEqual(calls[0][1],{run_id:7,action:'approve',expected_computation_revision:6,expected_computation_sha256:hash});
  }finally{act(()=>tree.unmount());}
});
test('an older unsealed calculation offers recompute before approval',async()=>{
  get=async path=>path.includes('runs?id=')?{run:{id:7,status:'computed',pay_period_id:8,computation_revision:0},lines:[{id:11}]}
    :path.includes('/preflight?')?{summary:{ready_to_run:true,total_w2_employees:1},employees:[]}:{};
  const tree=await open(Run);try{
    const approve=tree.root.findByProps({'data-testid':'payroll-run-approve'});
    assert.equal(approve.props.disabled,true);assert.match(approve.props.title,/Recompute/);
    assert.equal(tree.root.findByProps({'data-testid':'payroll-run-recompute'}).props.disabled,false);
  }finally{act(()=>tree.unmount());}
});
