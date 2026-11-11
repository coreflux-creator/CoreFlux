"""Back up the isolated Quanta database and prove restoration in an unserved namespace.

Never changes application code, settings or original business records. Briefly
stops only the existing pilot web process for a consistent dump. No new container
is created. The temporary restore database is never connected to any application.
"""
import fcntl,hashlib,json,os,re,shutil,subprocess,sys,time,uuid
from datetime import datetime,timezone
from pathlib import Path
import urllib.request

ROOT=Path('/srv/core-integration-pilot/20261029')
PRIVATE=ROOT/'private'
WEB='core-provider-pilot-20261029-quanta-pilot-web-1'
MONGO='core-provider-pilot-20261029-quanta-pilot-mongo-1'
DATABASE='corepilot_quanta_20261029'
if len(sys.argv)!=3 or sys.argv[1]!='--backup-isolated-quanta' or not re.fullmatch('[a-f0-9]{64}',sys.argv[2]):
 raise SystemExit('Explicit isolated backup and expected web-container identity required')
if ROOT.resolve()!=ROOT or PRIVATE.resolve()!=PRIVATE or PRIVATE.stat().st_mode & 0o077:
 raise SystemExit('Private isolated target differs')
os.umask(0o077)
lock=open(PRIVATE/'assignment-lifecycle-install.lock','a')
fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)

def run(*args,timeout=120):
 result=subprocess.run(args,text=True,capture_output=True,timeout=timeout)
 if result.returncode:
  if backup:(backup/'last-command-error.txt').write_text(result.stderr)
  raise RuntimeError('Isolated database operation failed: '+args[0])
 return result.stdout

def inspect(name):return json.loads(run('docker','inspect',name))[0]

def others():
 ids=run('docker','ps','-aq').split()
 return {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in json.loads(run('docker','inspect',*ids)) if r['Name']!='/'+WEB}

def state():
 row=inspect(WEB);env=dict(v.split('=',1) for v in row['Config']['Env'])
 assert row['Id']==sys.argv[2]
 assert env['DB_NAME']==DATABASE and env['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
 assert env['QUANTA_STARTUP_MODE']=='web' and env['MAIL_DRIVER']=='devoutbox'
 assert env['DISABLE_BACKGROUND_WORKERS']=='1' and env['DISABLE_SCHEDULER']=='1'
 assert not env.get('COREFLUX_TIME_ACCESS_TENANTS','').strip() and env.get('COREFLUX_TIME_COMMITS','0')=='0'
 assert row['Config']['Labels']['com.docker.compose.project']=='core-provider-pilot-20261029'
 return {'id':row['Id'],'running':row['State']['Running'],'started_at':row['State']['StartedAt']}

def js(code):return run('docker','exec',MONGO,'mongosh','--quiet','--eval',code)

def database_names():return json.loads(js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))'))

SNAPSHOT=r'''
const d=db.getSiblingDB(DBNAME);
const crypto=require('crypto');
const canonical=x=>Array.isArray(x)?x.map(canonical):(x&&typeof x==='object'?Object.fromEntries(Object.keys(x).sort().map(k=>[k,canonical(x[k])])):x);
const normalized=x=>canonical(JSON.parse(EJSON.stringify(x,{relaxed:false})));
const sha=x=>crypto.createHash('sha256').update(JSON.stringify(x)).digest('hex');
const out={};
for (const info of d.getCollectionInfos().sort((a,b)=>a.name.localeCompare(b.name))) {
 if(info.type!=='collection')throw Error('Unexpected view in isolated database');
 const c=d.getCollection(info.name), hashes=[];
 c.find({}).forEach(r=>{ hashes.push(sha(normalized(r))); if(hashes.length>100000)throw Error('Snapshot row bound exceeded'); });
 hashes.sort();
 const indexes=c.getIndexes().map(i=>{const copy={...i};delete copy.ns;copy.key=Object.entries(copy.key);return normalized(copy);}).sort((a,b)=>a.name.localeCompare(b.name));
 out[info.name]={count:hashes.length,sha256:sha(hashes),indexes,options:normalized(info.options||{})};
 if(info.name==='api_usage_events')out[info.name].row_hashes=hashes;
}
print(JSON.stringify(out));
'''

def snapshot(name):
 assert name==DATABASE or re.fullmatch('corepilot_restorecheck_[a-f0-9]{24}',name)
 return json.loads(js(SNAPSHOT.replace('DBNAME',json.dumps(name))))

def health():
 for attempt in range(30):
  try:
   with urllib.request.urlopen('http://127.0.0.1:19181/api/health',timeout=4) as response:value=json.load(response)
   assert value.get('startup_mode')=='web' and value.get('usage_recording') is False and not value['tenant_automations']['running']
   return value
  except (OSError,AssertionError):
   if attempt==29:raise
   time.sleep(1)

def file_hash(path):
 result=hashlib.sha256()
 with path.open('rb') as stream:
  for block in iter(lambda:stream.read(1024*1024),b''):result.update(block)
 return result.hexdigest()

def runtime_hashes():
 base=ROOT/'quanta/backend'
 return {p.relative_to(base).as_posix():file_hash(p) for p in [base/'server.py',*(base/'app').rglob('*.py')]}

report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':False,'database':DATABASE,
 'original_database_restored_over':False,'application_code_changed':False,'settings_changed':False,
 'new_container_created':False,'production_changed':False,'arabella_changed':False,'financial_execution':False}
backup=None;stopped=False;clone_owned=False
clone='corepilot_restorecheck_'+uuid.uuid4().hex[:24]
try:
 original=state();assert original['running']
 mongo=inspect(MONGO)
 assert mongo['State']['Running'] and mongo['Config']['Labels']['com.docker.compose.project']=='core-provider-pilot-20261029'
 assert any(m['Destination']=='/data/db' and m['Source']=='/var/lib/docker/volumes/core-provider-pilot-20261029_quanta-db/_data' for m in mongo['Mounts'])
 assert not any(v for v in (mongo['NetworkSettings'].get('Ports') or {}).values())
 hello=json.loads(js('print(JSON.stringify(db.hello()))'))
 if hello.get('setName'):
  assert hello['setName']=='corepilotquanta' and hello.get('isWritablePrimary') and hello.get('hosts')==['quanta-pilot-mongo:27017']
 report['storage_mode']='replica_set' if hello.get('setName') else 'standalone'
 assert clone not in database_names()
 mem={k:int(v.strip().split()[0])*1024 for k,v in (line.split(':',1) for line in Path('/proc/meminfo').read_text().splitlines())}
 assert mem['MemAvailable']>=512*1024**2 and shutil.disk_usage(PRIVATE).free>=2*1024**3
 run('docker','exec',MONGO,'mongodump','--version')
 run('docker','exec',MONGO,'mongorestore','--version')
 preflight=snapshot(DATABASE)
 assert preflight.get('time_entries',{}).get('count')==12
 protected=others();runtime=runtime_hashes()
 backup=PRIVATE/('quanta-storage-backup-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:8])
 backup.mkdir(mode=0o700)
 report.update(private_backup=str(backup),temporary_restore_database=clone,web_before=original,mongo_container_id=mongo['Id'])
 # Record responsibility before stop so a timed-out command still recovers this service.
 report['stage']='drain_web'
 stopped=True
 run('docker','stop','--time','20',WEB,timeout=45)
 assert not state()['running']
 report['stage']='snapshot_held_source'
 before=snapshot(DATABASE)
 assert before.get('time_entries',{}).get('count')==12
 assert not before.get('coreflux_legacy_time',{}).get('count',0) and not before.get('coreflux_time_access',{}).get('count',0)
 report['stage']='dump_held_source'
 archive=backup/'quanta.archive.gz'
 with archive.open('xb') as stream:
  result=subprocess.run(['docker','exec',MONGO,'mongodump','--db',DATABASE,'--archive','--gzip'],stdout=stream,stderr=subprocess.PIPE,timeout=180)
 if result.returncode:raise RuntimeError('Database dump failed')
 assert archive.stat().st_size>0
 after_dump=snapshot(DATABASE)
 assert before==after_dump,'Source changed during held backup'
 (backup/'before.json').write_text(json.dumps({'database':before,'runtime_hashes':runtime,'other_services':protected},indent=2))
 report.update(archive_sha256=file_hash(archive),archive_bytes=archive.stat().st_size,consistent_source_snapshot=True)
 report['stage']='resume_web'
 run('docker','start',WEB);health();stopped=False
 # Restore only a fresh unserved namespace, using the same bounded existing Mongo service.
 assert clone not in database_names() and inspect(MONGO)['Id']==mongo['Id']
 report['stage']='restore_unserved_namespace'
 clone_owned=True
 with archive.open('rb') as stream:
  result=subprocess.run(['docker','exec','-i',MONGO,'mongorestore','--archive','--gzip',
   '--nsInclude',DATABASE+'.*','--nsFrom',DATABASE+'.*','--nsTo',clone+'.*'],stdin=stream,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=180)
 if result.returncode:raise RuntimeError('Isolated restore rehearsal failed')
 report['stage']='verify_restored_records_and_indexes'
 restored=snapshot(clone)
 (backup/'restored.json').write_text(json.dumps(restored,indent=2))
 assert restored==before,'Restored records or indexes differ from the consistent snapshot'
 assert runtime_hashes()==runtime,'Application files changed during backup'
 after=snapshot(DATABASE)
 for name,prior in before.items():
  if name!='api_usage_events':assert after.get(name)==prior,'Original collection changed during restore rehearsal: '+name
 prior_usage=before.get('api_usage_events');after_usage=after.get('api_usage_events')
 if prior_usage:
  assert prior_usage['indexes']==after_usage['indexes'] and prior_usage['options']==after_usage['options']
  assert set(prior_usage['row_hashes']).issubset(after_usage['row_hashes'])
  assert len(set(after_usage['row_hashes'])-set(prior_usage['row_hashes']))==after_usage['count']-prior_usage['count']
 assert set(before)==set(after),'Original collection set changed'
 assert others()==protected,'Other service state changed during backup'
 report['stage']='verified'
 report.update(restore_verified=True,restored_collections=len(restored),restored_records=sum(v['count'] for v in restored.values()),
  original_records_and_indexes_preserved=True,application_files_preserved=True,other_services_unchanged=True,
  appended_usage_events=(after_usage['count']-prior_usage['count']) if prior_usage else 0)
except Exception as exc:
 report.update(failure_class=type(exc).__name__,failure_message=str(exc))
finally:
 if stopped:
  try:run('docker','start',WEB);health();report['web_recovered_after_failure']=True
  except Exception as recovery:report['web_recovery_failure']=type(recovery).__name__
 if clone_owned:
  try:
   assert re.fullmatch('corepilot_restorecheck_[a-f0-9]{24}',clone) and clone!=DATABASE and inspect(MONGO)['Id']==report['mongo_container_id']
   result=json.loads(js('print(JSON.stringify(db.getSiblingDB('+json.dumps(clone)+').dropDatabase()))'))
   assert result.get('ok')==1 and clone not in database_names()
   report['temporary_restore_database_removed']=True
  except Exception as cleanup:report['restore_cleanup_failure']=type(cleanup).__name__
 try:report['web_after']=state();report['health']=health()
 except Exception as current:report['health_failure']=type(current).__name__
 report['passed']=bool(report.get('restore_verified') and report.get('temporary_restore_database_removed')
   and report.get('web_after',{}).get('running') and 'failure_class' not in report and 'health_failure' not in report)
 report['completed_at']=datetime.now(timezone.utc).isoformat()
 if backup:(backup/'result.json').write_text(json.dumps(report,indent=2))
 (PRIVATE/'quanta-storage-backup-result.json').write_text(json.dumps(report,indent=2))
 print(json.dumps(report))
sys.exit(0 if report['passed'] else 1)
