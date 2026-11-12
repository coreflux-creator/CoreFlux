"""Extend native setup to one verified synthetic employee, retaining execution holds."""
import fcntl,hashlib,json,os,subprocess,sys,time,urllib.request,urllib.error
from pathlib import Path
from datetime import datetime,timezone
ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private'
WEB='core-provider-pilot-20261029-pure-pilot-web-1'
OLD_ID='643c9c0d99dc30cc6cd06a5baf627902571df683b650ef2b32220f1c274cc9e2'
OLD_EMP='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'
NEW_EMP='emp_core_ddf206bef1e2b7a511b6b445ca9b49bbc65d56c9dc79e0617bf125c155c1606f'
STATE=PRIVATE/'operational-employee-setup-release-20261112.json'
CONFIG=PRIVATE/'pure.env';BACKUP=PRIVATE/'operational-employee-setup-before-20261112.env'
sha=lambda b:hashlib.sha256(b).hexdigest()
def command(*args,input=None,timeout=40):
 r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
 if r.returncode:raise RuntimeError('Command failed: '+args[0]+' '+r.stderr[-350:])
 return r.stdout
def inspect(name):return json.loads(command('docker','inspect',name))[0]
def save(v):
 temp=STATE.with_suffix('.next')
 with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(v,f,indent=2);f.flush();os.fsync(f.fileno())
 os.replace(temp,STATE)
def write_config(body):
 assert not CONFIG.is_symlink() and CONFIG.resolve()==CONFIG and CONFIG.stat().st_mode&0o777==0o600
 temp=CONFIG.with_suffix('.operational-next')
 with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write(body);f.flush();os.fsync(f.fileno())
 os.replace(temp,CONFIG)
def source_hashes():
 p=ROOT/'pure/backend'
 return {f.relative_to(p).as_posix():sha(f.read_bytes().replace(b'\r\n',b'\n')) for f in p.rglob('*') if f.is_file() and f.suffix in ['.py','.txt','.json'] and '__pycache__' not in f.parts and '.venv' not in f.parts}
def snapshot():
 code=r'''import os,json,hashlib
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];o='org_0369a63ff2cb'
e=d.employees.find_one({'organization_id':o,'id':'EMPLOYEE'})
assert e and e['coreflux_origin']['person']['number']=='P-387' and e['coreflux_origin']['person']['directory_tenant_id']==2002
assert e['status']=='inactive' and e['pay_method']=='record_only' and e['email']=='thunderhawk-correction-20261112@example.com'
assert e.get('pay_type') is None and not e.get('start_date') and not e.get('hourly_rate')
assert d.organizations.find_one({'id':o})['payroll']['processing']=={'calculation':'MANUAL','payments':'RECORD_ONLY'}
print(json.dumps({n:{'rows':sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in d[n].find({})),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)} for n in sorted(d.list_collection_names())}))'''.replace('EMPLOYEE',NEW_EMP)
 return json.loads(command('docker','exec','-i',WEB,'python','-',input=code))
def services():
 ids=command('docker','ps','-aq').split()
 return {v['Name']:[v['Id'],v['State']['Status'],v['State']['StartedAt'],v['RestartCount']] for v in json.loads(command('docker','inspect',*ids)) if v['Name']!='/'+WEB}
def fetch(path,body=None):
 q=urllib.request.Request('http://127.0.0.1:19180'+path,None if body is None else json.dumps(body).encode(),{'Content-Type':'application/json'})
 try:
  with urllib.request.urlopen(q,timeout=3) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
def ready():
 for attempt in range(25):
  try:
   status,v=fetch('/api/release-status');assert status==200 and v['startup_mode']=='isolated_pilot' and v['state']=='active'
   assert all(v[k] is False for k in ['background_jobs','startup_migrations','scheduled_operations','provider_egress_enabled'])
   return v
  except (OSError,ValueError,AssertionError):
   if attempt==24:raise
   time.sleep(1)
def main():
 assert sys.argv[1:]==['--enable-existing-synthetic-person'] and os.geteuid()==0 and not STATE.exists() and not BACKUP.exists()
 lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 pins=json.loads((Path(__file__).parent/'pure-runtime-pins.json').read_text());assert source_hashes()==pins
 old=inspect(WEB);env=dict(v.split('=',1) for v in old['Config']['Env'])
 assert old['Id']==OLD_ID and old['State']['Running'] and env['PUREPAY_PILOT_EMPLOYEE_SETUP_IDS']==OLD_EMP
 assert env['DB_NAME']=='corepilot_pure_20261029' and env['PUREPAY_STARTUP_MODE']=='isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS']=='disabled'
 assert list(old['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
 assert json.loads(command('docker','network','inspect','core-provider-pilot-20261029_pure-private'))[0]['Internal'] is True
 mem=dict(line.split(':',1) for line in Path('/proc/meminfo').read_text().splitlines());assert int(mem['MemAvailable'].split()[0])>512*1024
 before=snapshot();others=services();ready()
 original=CONFIG.read_bytes();needle=('PUREPAY_PILOT_EMPLOYEE_SETUP_IDS='+OLD_EMP+'\n').encode();assert original.count(needle)==1
 updated=original.replace(needle,('PUREPAY_PILOT_EMPLOYEE_SETUP_IDS='+OLD_EMP+','+NEW_EMP+'\n').encode())
 with os.fdopen(os.open(BACKUP,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write(original);f.flush();os.fsync(f.fileno())
 report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'prepared','passed':False,'old_container':old['Id'],
  'old_scope':[OLD_EMP],'new_scope':[OLD_EMP,NEW_EMP],'before_config_sha256':sha(original),'after_config_sha256':sha(updated),
  'source_files':len(pins),'real_payroll_payments_postings':False}
 save(report)
 try:
  write_config(updated);report['phase']='config_written';save(report)
  command('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web',timeout=60)
  report['health']=ready();fresh=inspect(WEB)
  assert dict(v.split('=',1) for v in fresh['Config']['Env'])=={**env,'PUREPAY_PILOT_EMPLOYEE_SETUP_IDS':OLD_EMP+','+NEW_EMP}
  assert fresh['Config']['Image']==old['Config']['Image'] and sorted(fresh['Mounts'],key=lambda m:m['Destination'])==sorted(old['Mounts'],key=lambda m:m['Destination'])
  assert list(fresh['NetworkSettings']['Networks'])==list(old['NetworkSettings']['Networks'])
  checks=[]
  for path in ['/api/payroll/runs/synthetic/pay','/api/payroll/runs/synthetic/approve','/api/payments','/api/admin/release-handover/activate','/api/cron/pwp-auto-batch','/api/payroll/employees/emp_ea232efec736/status']:
   status,body=fetch(path,{});assert status==409 and body['code']=='isolated_pilot_operation_held';checks.append(path)
  assert snapshot()==before and services()==others and source_hashes()==pins
  report.update(phase='complete',passed=True,new_container=fresh['Id'],original_rows_preserved=sum(len(v['rows']) for v in before.values()),collections=len(before),other_services_preserved=len(others),held_routes=checks)
 except Exception as e:
  report.update(phase='rolling_back',failure=type(e).__name__,failure_detail=str(e)[:500]);save(report)
  write_config(original)
  command('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web',timeout=60)
  report['rollback_health']=ready();assert snapshot()==before and source_hashes()==pins and services()==others
  report['phase']='rolled_back'
 report['finished_at']=datetime.now(timezone.utc).isoformat();save(report);print(json.dumps(report));return 0 if report['passed'] else 1
if __name__=='__main__':sys.exit(main())
