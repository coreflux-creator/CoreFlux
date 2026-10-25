import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';
const require=createRequire(import.meta.url), React=require('react');
const {create,act}=require('react-test-renderer');
const compiled=await build({entryPoints:[fileURLToPath(new URL('../../modules/treasury/ui/PurePayCoreConnection.jsx',import.meta.url))],bundle:true,platform:'node',format:'cjs',write:false,external:['react'],plugins:[{name:'fixture-api',setup(b){b.onResolve({filter:/lib\/api$/},()=>({path:'fixture-api',external:true}));}}]});
const text=n=>typeof n==='string'?n:(n.children||[]).map(text).join('');
test('verified current assignment contract opens commercial review; unknown versions stay held',async()=>{
  for(const version of ['2026-10-25.1','future']){
    const module={exports:{}};
    const api={useApi:()=>({data:{connected:true},loading:false}),api:{post:async()=>({verified:true,tenant_id:999,review_hash:'review',organization:{name:'Demo'},assignment_input_contract_version:version,capabilities:['read:coreflux','write:assignment_inputs']})}};
    new Function('require','module','exports',compiled.outputFiles[0].text)(id=>id==='fixture-api'?api:require(id),module,module.exports);
    let tree;await act(async()=>{tree=create(React.createElement(module.exports.default));});
    try{const check=tree.root.findAllByType('button').find(b=>text(b)==='Check company and access');await act(async()=>check.props.onClick());
      assert.equal(tree.root.findAll(n=>n.props['aria-label']==='Placement commercial review').length,version==='2026-10-25.1'?1:0);
    }finally{act(()=>tree.unmount());}
  }
});
