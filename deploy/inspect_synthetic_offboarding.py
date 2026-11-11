"""Read-only independent provider evidence for the existing PL-874 offboarding."""
import hashlib,json,shlex,subprocess,sys
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path

Q='4f93d4e7-af74-4765-b812-3464bb6d974b'
P='org_0369a63ff2cb'
QA='cfa_e134dbe4453e1e563ba7329c42ba5fd6a82d8e6eac02c2a7a58a1f5c6e55d4dc'
PA='asg_core_aff68d7460216d12beedbe1e0e6a3ddee7921840a562577d275d992b1d131008'
NAMES={'quanta':'core-provider-pilot-20261029-quanta-pilot-web-1','pure':'core-provider-pilot-20261029-pure-pilot-web-1'}
IDS={'quanta':'a56cf7351dc985bc41c69fafd48117a40e14ec277a2f81682341ed246fd52281','pure':'643c9c0d99dc30cc6cd06a5baf627902571df683b650ef2b32220f1c274cc9e2'}
REMOTE=r'''
import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
assert os.environ['DB_NAME']=='corepilot_'+('quanta' if KIND=='quanta' else 'pure')+'_20261029'
if KIND=='quanta':
 assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1' and os.environ['MAIL_DRIVER']=='devoutbox'
else:
 assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
 org=db.organizations.find_one({'id':P});assert org['payroll']['processing']['calculation']=='MANUAL' and org['payroll']['processing']['payments']=='RECORD_ONLY'
collections={};assignment=None;fences=[]
for name in sorted(db.list_collection_names()):
 rows=[]
 for row in db[name].find({}):
  if KIND=='quanta':
   if name=='coreflux_assignments' and row.get('id')==QA and row.get('tenant_id')==Q:assignment=row;continue
   if name=='api_keys' and row.get('id')=='4aae55c2-313b-4515-bc3c-12419811bdf2' and row.get('tenant_id')==Q:row.pop('last_used_at',None)
   if name=='coreflux_commit_fences' and row.get('_id')=='core-time-writers-v1':
    fences.append(dict(row));row.pop('revision',None)
  else:
   if name=='assignments' and row.get('id')==PA and row.get('organization_id')==P:assignment=row;continue
   if name=='api_keys' and row.get('id')=='key_3afdf4dbff6c' and row.get('organization_id')==P:row.pop('last_used_at',None)
   if name=='users' and row.get('id')=='usr_2d19df3dfa23' and row.get('organization_id')==P:row.pop('last_login',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 collections[name]={'rows':sorted(rows),'indexes':json_util.dumps(db[name].index_information(),sort_keys=True)}
assert assignment
files=['app/coreflux_contract.py','app/coreflux_time_access.py','app/coreflux_time_reviews.py','app/routers/core_assignments.py','app/routers/core_onboarding.py'] if KIND=='quanta' else ['coreflux_assignments.py','coreflux_payroll_drafts.py','coreflux_payroll_authority.py']
runtime={f:hashlib.sha256((Path('/app/backend')/f).read_bytes().replace(b'\r\n',b'\n')).hexdigest() for f in files}
print(json_util.dumps({'collections':collections,'assignment':assignment,'fences':fences,'runtime':runtime}))
'''

def capture():
 ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/purepay_quanta_migration_20261028','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','root@142.93.20.44']
 result={}
 for kind,name in NAMES.items():
  info=json.loads(subprocess.check_output(ssh+['docker inspect '+name],text=True,timeout=30))[0]
  assert info['Id']==IDS[kind] and info['State']['Running']
  code='\n'.join(k+'='+repr(v) for k,v in {'KIND':kind,'Q':Q,'P':P,'QA':QA,'PA':PA}.items())+'\n'+REMOTE
  run=subprocess.run(ssh+['docker exec -i '+name+' python -'],input=code,text=True,capture_output=True,timeout=40)
  if run.returncode:raise RuntimeError('Read-only provider capture failed: '+run.stderr[-800:])
  data=json.loads(run.stdout)
  config={k:info[k] for k in ['Config','HostConfig','Mounts','NetworkSettings']}
  config['Mounts']=sorted(config['Mounts'],key=lambda x:x['Destination'])
  data['container']={'id':info['Id'],'image':info['Image'],'started_at':info['State']['StartedAt'],'config_hash':hashlib.sha256(json.dumps(config,sort_keys=True).encode()).hexdigest()}
  result[kind]=data
 return result

def main():
 phase=sys.argv[1];assert phase in ['before','after','inspect']
 root=Path(__file__).resolve().parents[1];out=root/'output';base=out/'synthetic-offboarding-provider-before.private.json'
 current=capture()
 for kind in NAMES:
  source=root.parent/('Quanta-profile-return' if kind=='quanta' else 'PurePay-pilot-lifecycle')/'backend'
  assert all(hashlib.sha256((source/f).read_bytes().replace(b'\r\n',b'\n')).hexdigest()==h for f,h in current[kind]['runtime'].items())
 if phase=='inspect':
  print(json.dumps({k:{'assignment':v['assignment'],'fences':v['fences']} for k,v in current.items()},indent=2));return
 if phase=='before':
  assert not base.exists() and current['quanta']['assignment']['status']=='active' and current['pure']['assignment']['status']=='active'
  with base.open('x') as f:json.dump(current,f,indent=2)
  report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':True,'baseline_sha256':hashlib.sha256(base.read_bytes()).hexdigest(),'previous_pure_revision':current['pure']['assignment']['core_assignment']['packet']['source_revision'],'quanta_assignment_version':current['quanta']['assignment']['version']}
  (out/'synthetic-offboarding-provider-preflight.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report));return
 before=json.loads(base.read_text());added={}
 (out/'synthetic-offboarding-provider-after.private.json').write_text(json.dumps(current,indent=2)+'\n')
 for kind in NAMES:
  a,b=before[kind],current[kind]
  assert a['runtime']==b['runtime'] and a['container']==b['container']
  ac,bc=a['collections'],b['collections'];assert set(ac)==set(bc)
  added[kind]={}
  allowed=['coreflux_deliveries','audit_events','api_usage_events'] if kind=='quanta' else ['coreflux_assignment_inputs','sessions','login_events']
  for name in ac:
   assert ac[name]['indexes']==bc[name]['indexes'],(kind,name,'indexes')
   old,new=Counter(ac[name]['rows']),Counter(bc[name]['rows'])
   assert not old-new,(kind,name,'changed original')
   if new-old:
    assert name in allowed,(kind,name,'unexpected addition')
    added[kind][name]=sum((new-old).values())
  old,new=a['assignment'].copy(),b['assignment'].copy()
  assert new['id']==old['id'] and new['status']=='ended' and new['end_date']=='2026-11-10'
  fields=['status','end_date','version','updated_at','last_delivery_id','history'] if kind=='quanta' else ['status','end_date','source_revision','core_assignment']
  if kind=='quanta':
   assert new['version']==old['version']+1 and len(new['history'])==len(old['history'])+1
   assert new['history'][:-1]==old['history']
   assert new['history'][-1]['record']=={k:v for k,v in old.items() if k not in ['_id','history','last_actor']}
   assert new['history'][-1]['actor']==old['last_actor']
  else:
   previous,newcore=old['core_assignment'],new['core_assignment']
   assert newcore['packet']['previous_revision']==previous['packet']['source_revision']
   assert len(newcore['history'])==len(previous.get('history',[]))+1
   assert newcore['history'][:-1]==previous.get('history',[])
   history=newcore['history'][-1]
   for field in ['input_id','content_hash','reviewed_by','reviewed_at','activation']:assert history[field]==previous.get(field)
   assert history['source_revision']==previous['packet']['source_revision'] and history['status']=='active'
  for field in fields:old.pop(field,None);new.pop(field,None)
  assert old==new,(kind,'immutable assignment changed')
 report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':True,'quanta_status':'ended','pure_status':'ended','actual_end_date':'2026-11-10','stable_provider_ids':True,'prior_approval_time_and_void_payroll_preserved':True,'other_original_records_preserved':True,'provider_runtime_and_configuration_unchanged':True,'collections_verified':{k:len(v['collections']) for k,v in current.items()},'additions':added,'production_changed':False,'real_payroll_payments_postings':False}
 (out/'synthetic-offboarding-provider-verification.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report))

if __name__=='__main__':main()
