"""Install one verified display helper in the isolated provider; never alter records."""
import ast, fcntl, hashlib, json, os, subprocess, sys, time, urllib.request, urllib.error
from pathlib import Path
from datetime import datetime, timezone

ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private'
WEB='core-provider-pilot-20261029-pure-pilot-web-1'
SOURCE=ROOT/'pure/backend/server.py';STAMP=SOURCE.parent/'release_handover.json';CONFIG=PRIVATE/'pure.env'
STATE=PRIVATE/'payment-display-release-v3-20261112.json'
BACKUP=PRIVATE/'payment-display-before-v3-20261112.server.py'
STAMP_BACKUP=PRIVATE/'payment-display-before-v3-20261112.stamp.json'
CHECKPOINT=PRIVATE/'payment-display-before-v3-20261112.private.json'
FAILURE=PRIVATE/'payment-display-failure-v3-20261112.private.json'
sha=lambda b:hashlib.sha256(b).hexdigest()

def command(*args,input=None,timeout=40):
 r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
 if r.returncode:raise RuntimeError('Command failed: '+args[0]+' '+r.stderr[-400:])
 return r.stdout

def inspect(name):return json.loads(command('docker','inspect',name))[0]

def save(v):
 temp=STATE.with_suffix('.next')
 assert not temp.is_symlink() and not STATE.is_symlink()
 with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:
  json.dump(v,f,indent=2);f.flush();os.fsync(f.fileno())
 os.replace(temp,STATE)

def write_source(body,target=SOURCE):
 assert target in [SOURCE,STAMP] and target.resolve()==target and not target.is_symlink()
 s=target.stat();assert (s.st_uid,s.st_gid,s.st_mode&0o777)==(0,0,0o644)
 temp=target.with_name('.'+target.name+'-payment-display-next')
 with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:
  f.write(body);f.flush();os.fsync(f.fileno())
 os.chown(temp,0,0);os.chmod(temp,0o644);os.replace(temp,target)

def release_digest(root,server):
 root=Path(root)
 files={p.relative_to(root).as_posix():sha(p.read_bytes().replace(b'\r\n',b'\n'))
        for p in sorted(root.rglob('*.py')) if not any(part.startswith('.') or
        part in {'tests','__pycache__','rpa_evidence','uploads','data'} for part in p.relative_to(root).parts)}
 assert 'server.py' in files
 files['server.py']=sha(server.replace(b'\r\n',b'\n'))
 return sha(json.dumps(files,sort_keys=True).encode())

def verify_release_stamp(root,server,stamp_bytes):
 from uuid import UUID
 stamp=json.loads(stamp_bytes)
 assert set(stamp)=={'release_id','deployment_id','source_digest'}
 assert isinstance(stamp['release_id'],str) and 0<len(stamp['release_id'])<=100
 UUID(stamp['deployment_id'])
 assert stamp['source_digest']==release_digest(root,server),'Release source does not match its reviewed stamp'
 return stamp

def capture_failure():
 # Keep logs private: application errors can contain operational data. Retain
 # evidence before removing a failed container, without exposing its secrets.
 value=inspect(WEB)
 logs=subprocess.run(['docker','logs','--tail','200',WEB],capture_output=True,text=True,timeout=15)
 evidence={'container':value['Id'],'state':value['State'],'stdout':logs.stdout,'stderr':logs.stderr}
 body=json.dumps(evidence,indent=2).encode()
 with os.fdopen(os.open(FAILURE,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write(body);f.flush();os.fsync(f.fileno())
 return {'path':str(FAILURE),'sha256':sha(body),'container':value['Id'],'exit_code':value['State']['ExitCode']}

def source_hashes():
 p=ROOT/'pure/backend'
 return {f.relative_to(p).as_posix():sha(f.read_bytes().replace(b'\r\n',b'\n')) for f in p.rglob('*') if f.is_file() and f.suffix in ['.py','.txt','.json'] and '__pycache__' not in f.parts and '.venv' not in f.parts}

def snapshot():
 code=r'''import os,json,hashlib
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
r=d.pay_runs.find_one({'organization_id':'org_0369a63ff2cb','id':'run_core_54cb6e8156e6bfd5386a133ed5ea4d41c015aab8f45d25aeb0813cb090d35938'})
assert r and r['status']=='draft' and r['core_execution_hold'] and r['core_allocation_ready'] and r['totals']['gross']==100
assert d.organizations.find_one({'id':'org_0369a63ff2cb'})['payroll']['processing']=={'calculation':'MANUAL','payments':'RECORD_ONLY'}
print(json.dumps({n:{'rows':sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in d[n].find({})),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)} for n in sorted(d.list_collection_names())}))'''
 return json.loads(command('docker','exec','-i',WEB,'python','-',input=code))

def services():
 ids=command('docker','ps','-aq').split()
 return {v['Name']:[v['Id'],v['State']['Status'],v['State']['StartedAt'],v['RestartCount']] for v in json.loads(command('docker','inspect',*ids)) if v['Name']!='/'+WEB}

def fetch(path,body=None):
 q=urllib.request.Request('http://127.0.0.1:19180'+path,None if body is None else json.dumps(body).encode(),{'Content-Type':'application/json'})
 try:
  with urllib.request.urlopen(q,timeout=3) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:
  try:body=json.load(e)
  except ValueError:body={'non_json_response':True}
  return e.code,body

class HealthPending(Exception):pass

def ready():
 deadline=time.monotonic()+180
 while True:
  try:
   status,v=fetch('/api/release-status');assert status==200 and v['startup_mode']=='isolated_pilot' and v['state']=='active'
   assert all(v[k] is False for k in ['background_jobs','startup_migrations','scheduled_operations','provider_egress_enabled'])
   return v
  except (OSError,ValueError,AssertionError):
   current=inspect(WEB)
   if not current['State']['Running']:raise RuntimeError('Isolated application exited before health verification')
   if time.monotonic()>=deadline:raise HealthPending('Application is still running; retain current operation for observation/resume')
   time.sleep(2)

def native_projection(source_path,expected_hash):
 code=r'''import os,ast,asyncio,copy,hashlib,json,re
from pathlib import Path
from motor.motor_asyncio import AsyncIOMotorClient
source=Path(SOURCE_PATH).read_bytes();assert hashlib.sha256(source).hexdigest()==EXPECTED_HASH
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
client=AsyncIOMotorClient(os.environ['MONGO_URL']);db=client[os.environ['DB_NAME']]
async def forbidden(*a,**kw):raise AssertionError('Provider lookup attempted')
ns={'db':db,'re':re,'_mercury_ach_id':forbidden};nodes=[]
for node in ast.parse(source).body:
 if isinstance(node,(ast.FunctionDef,ast.AsyncFunctionDef)) and node.name in {'_payment_method','_failure_info','_enrich_payments'}:nodes.append(node)
 elif isinstance(node,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='ACH_RETURN_CODES' for t in node.targets):nodes.append(node)
exec(compile(ast.fix_missing_locations(ast.Module(body=nodes,type_ignores=[])),'installed-payment-projection','exec'),ns)
async def verify():
 org='org_0369a63ff2cb';ids=['pmt_87cf14ddb4','pmt_274cf7d401']
 stored=await db.payments.find({'organization_id':org,'id':{'$in':ids}}).to_list(2);assert len(stored)==2
 inputs=copy.deepcopy(stored)
 for row in inputs:row['invoice_number']=None
 before=copy.deepcopy(inputs)
 for attempt in range(2):
  projected=await ns['_enrich_payments'](org,inputs)
  assert inputs==before and all(row['invoice_number']=='Sep_26' for row in projected)
  assert all(a['id']==b['id'] and a['status']==b['status'] and a['amount']==b['amount'] for a,b in zip(projected,inputs))
 try:await ns['_enrich_payments']('not-the-owner',inputs)
 except ValueError:pass
 else:raise AssertionError('Foreign company input accepted')
 assert await db.payments.find({'organization_id':org,'id':{'$in':ids}}).to_list(2)==stored
 return {'passed':True,'checks':['missing_cached_labels_project_in_memory','repeated_projection_preserves_inputs_and_stored_payments','amount_status_and_ids_preserved','foreign_company_input_refused','provider_lookup_not_called']}
try:print(json.dumps(asyncio.run(verify())))
finally:client.close()
'''.replace('SOURCE_PATH',repr(str(source_path))).replace('EXPECTED_HASH',repr(expected_hash))
 return json.loads(command('docker','exec','-i',WEB,'python','-',input=code))

def main():
 assert sys.argv[1:] in [['--install-reviewed-display'],['--resume-reviewed-display']] and os.geteuid()==0
 resume=sys.argv[1]=='--resume-reviewed-display'
 assert PRIVATE.resolve()==PRIVATE and (PRIVATE.stat().st_mode&0o777)==0o700
 for target in [SOURCE,STAMP]:
  s=target.stat();assert target.resolve()==target and not target.is_symlink() and (s.st_uid,s.st_gid,s.st_mode&0o777)==(0,0,0o644)
 lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 package=json.loads((Path(__file__).parent/'payment-display-preflight.json').read_text())
 updated=(Path(__file__).parent/'server.py.next').read_bytes()
 assert sha(updated)==package['after_sha256'];ast.parse(updated)
 updated_stamp=(Path(__file__).parent/'release_handover.json.next').read_bytes()
 assert sha(updated_stamp)==package['after_stamp_sha256']
 release=verify_release_stamp(SOURCE.parent,updated,updated_stamp)
 assert release==package['release_stamp']
 pins=package['pins']
 new_pins={**pins,'server.py':sha(updated.replace(b'\r\n',b'\n')),'release_handover.json':sha(updated_stamp.replace(b'\r\n',b'\n'))}
 assert not CONFIG.is_symlink() and (CONFIG.stat().st_mode&0o777)==0o600 and sha(CONFIG.read_bytes())==package['config_sha256']
 if resume:
  report=json.loads(STATE.read_text());assert report['phase']=='waiting_for_health' and not report['passed']
  saved=json.loads(CHECKPOINT.read_text());old=saved['old'];before=saved['before'];others=saved['others'];original=BACKUP.read_bytes();original_stamp=STAMP_BACKUP.read_bytes()
  assert sha(SOURCE.read_bytes())==package['after_sha256'] and source_hashes()==new_pins
  assert inspect(WEB)['Id']==report['new_container'] and inspect(WEB)['State']['Running']
 else:
  assert not any(p.exists() for p in [STATE,BACKUP,STAMP_BACKUP,CHECKPOINT,FAILURE])
  original=SOURCE.read_bytes();original_stamp=STAMP.read_bytes();assert source_hashes()==pins
  verify_release_stamp(SOURCE.parent,original,original_stamp)
  old=inspect(WEB);assert old['Id']==package['container_id'] and old['State']['Running']
 assert sha(original)==package['before_sha256']
 assert sha(original_stamp)==package['before_stamp_sha256']
 env=dict(v.split('=',1) for v in old['Config']['Env'])
 assert env['DB_NAME']=='corepilot_pure_20261029' and env['PUREPAY_STARTUP_MODE']=='isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS']=='disabled'
 assert list(old['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
 assert json.loads(command('docker','network','inspect','core-provider-pilot-20261029_pure-private'))[0]['Internal'] is True
 mounts=[m for m in old['Mounts'] if SOURCE.is_relative_to(Path(m['Source']))]
 assert mounts;mount=max(mounts,key=lambda m:len(m['Source']));container_source=Path(mount['Destination'])/SOURCE.relative_to(mount['Source'])
 mem=dict(line.split(':',1) for line in Path('/proc/meminfo').read_text().splitlines());assert int(mem['MemAvailable'].split()[0])>512*1024
 if not resume:
  before=snapshot();others=services();ready()
  with os.fdopen(os.open(BACKUP,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write(original);f.flush();os.fsync(f.fileno())
  with os.fdopen(os.open(STAMP_BACKUP,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write(original_stamp);f.flush();os.fsync(f.fileno())
  with os.fdopen(os.open(CHECKPOINT,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:json.dump({'old':old,'before':before,'others':others},f);f.flush();os.fsync(f.fileno())
  report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'prepared','passed':False,'old_container':old['Id'],
   'before_source_sha256':sha(original),'after_source_sha256':sha(updated),'configuration_sha256':package['config_sha256'],
   'source_files':len(pins),'release_stamp':release,'before_stamp_sha256':sha(original_stamp),'after_stamp_sha256':sha(updated_stamp),'schema_changed':False,'real_payroll_payments_postings':False,'production_changed':False}
  save(report)
 try:
  if not resume:
   write_source(updated);write_source(updated_stamp,STAMP)
   verify_release_stamp(SOURCE.parent,SOURCE.read_bytes(),STAMP.read_bytes())
   assert source_hashes()==new_pins
   report['phase']='source_written';save(report)
   command('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web',timeout=60)
   report['new_container']=inspect(WEB)['Id'];report['phase']='waiting_for_health';save(report)
  report['health']=ready();fresh=inspect(WEB)
  assert dict(v.split('=',1) for v in fresh['Config']['Env'])==env
  assert fresh['Config']['Image']==old['Config']['Image'] and sorted(fresh['Mounts'],key=lambda m:m['Destination'])==sorted(old['Mounts'],key=lambda m:m['Destination'])
  assert list(fresh['NetworkSettings']['Networks'])==list(old['NetworkSettings']['Networks'])
  checks=[]
  for path in ['/api/payroll/runs/synthetic/pay','/api/payroll/runs/synthetic/approve','/api/payments','/api/admin/release-handover/activate','/api/cron/pwp-auto-batch','/api/payroll/employees/emp_ea232efec736/status']:
   status,body=fetch(path,{});assert status==409 and body['code']=='isolated_pilot_operation_held';checks.append(path)
  report['native_projection']=native_projection(container_source,sha(updated))
  assert snapshot()==before and services()==others and source_hashes()==new_pins
  assert sha(CONFIG.read_bytes())==package['config_sha256']
  report.update(phase='complete',passed=True,new_container=fresh['Id'],original_rows_preserved=sum(len(v['rows']) for v in before.values()),collections=len(before),other_services_preserved=len(others),held_routes=checks)
 except subprocess.TimeoutExpired:
  report['phase']='operation_pending';report['observation']='Command observation expired; inspect the existing container/process outcome before any resume or recovery';save(report);print(json.dumps(report));return 3
 except HealthPending as e:
  report['phase']='waiting_for_health';report['observation']=str(e);save(report);print(json.dumps(report));return 3
 except Exception as e:
  report.update(phase='rolling_back',failure=type(e).__name__,failure_detail=str(e)[:500]);save(report)
  try:report['private_failure_evidence']=capture_failure()
  except Exception as capture_error:report['failure_capture_error']=type(capture_error).__name__
  save(report)
  write_source(original);write_source(original_stamp,STAMP)
  verify_release_stamp(SOURCE.parent,SOURCE.read_bytes(),STAMP.read_bytes())
  command('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web',timeout=60)
  report['rollback_health']=ready();assert snapshot()==before and source_hashes()==pins and services()==others
  report['phase']='rolled_back'
 report['finished_at']=datetime.now(timezone.utc).isoformat();save(report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':sys.exit(main())
