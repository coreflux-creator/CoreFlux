"""Enable inactive Core employee-profile creation in the existing isolated Pure pilot."""
import fcntl,hashlib,json,os,subprocess,sys,time,urllib.request,urllib.error,uuid
from pathlib import Path
from datetime import datetime,timezone
ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private'
WEB='core-provider-pilot-20261029-pure-pilot-web-1';MONGO='core-provider-pilot-20261029-pure-pilot-mongo-1'
STATE=PRIVATE/'synthetic-lifecycle-pure-provisioning-20261111.json'
sha=lambda b:hashlib.sha256(b).hexdigest()
def run(*args,input=None,timeout=40):
    r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
    if r.returncode:raise RuntimeError('Isolated command failed: '+args[0])
    return r.stdout
def inspect(name):return json.loads(run('docker','inspect',name))[0]
def others():
    ids=run('docker','ps','-aq').split()
    return {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in json.loads(run('docker','inspect',*ids)) if r['Name']!='/'+WEB}
def runtime():
    source=ROOT/'pure/backend'
    return {p.relative_to(source).as_posix():sha(p.read_bytes().replace(b'\r\n',b'\n')) for p in source.rglob('*') if p.is_file() and p.suffix in ['.py','.txt','.json'] and '__pycache__' not in p.parts and '.venv' not in p.parts}
def snapshot():
    script=r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
print(json.dumps({name:{'rows':sorted(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest() for row in db[name].find({})),'indexes':json_util.dumps(db[name].index_information(),sort_keys=True)} for name in sorted(db.list_collection_names())}))'''
    return json.loads(run('docker','exec','-i',WEB,'python','-',input=script))
def fetch(path,body=None):
    request=urllib.request.Request('http://127.0.0.1:19180/api'+path,None if body is None else json.dumps(body).encode(),{'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(request,timeout=6) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:
        raw=e.read()
        try:value=json.loads(raw)
        except ValueError:value={}
        return e.code,value
def ready():
    for attempt in range(30):
        try:
            status,result=fetch('/release-status')
            assert status==200 and result['state']=='active' and result['startup_mode']=='isolated_pilot'
            assert result['background_jobs'] is False and result['scheduled_operations'] is False and result['provider_egress_enabled'] is False
            return result
        except (OSError,AssertionError,ValueError):
            if attempt==29:raise
            time.sleep(1)
def write(path,body,stat):
    temporary=path.with_name(path.name+'.next-'+uuid.uuid4().hex)
    with temporary.open('xb') as f:f.write(body);f.flush();os.fsync(f.fileno())
    os.chown(temporary,stat.st_uid,stat.st_gid);os.chmod(temporary,stat.st_mode&0o777)
    os.replace(temporary,path)
def save(report):
    with STATE.open('w') as f:json.dump(report,f,indent=2)
def main():
    assert sys.argv[1:]==['--enable-isolated-person-profiles'] and os.geteuid()==0 and ROOT.resolve()==ROOT and PRIVATE.resolve()==PRIVATE
    if STATE.exists():raise RuntimeError('Existing setting-change outcome must be inspected before retry')
    lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    baseline=json.loads((PRIVATE/'pure-lifecycle-release-inspection-20261111.json').read_text())
    row=inspect(WEB);env=dict(v.split('=',1) for v in row['Config']['Env'])
    assert row['Id']==baseline['container_id'] and row['State']['Running'] and row['State']['StartedAt']==baseline['started_at']
    assert row['Config']['Hostname']=='pure-core-pilot-20261029' and list(row['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
    assert json.loads(run('docker','network','inspect','core-provider-pilot-20261029_pure-private'))[0]['Internal'] is True
    assert env['DB_NAME']=='corepilot_pure_20261029' and env['PUREPAY_STARTUP_MODE']=='isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS']=='disabled'
    assert env.get('COREFLUX_PEOPLE_PROVISIONING') is None and env.get('COREFLUX_ASSIGNMENT_PROVISIONING')=='1'
    assert runtime()==baseline['runtime']
    for name,digest in baseline['configuration_hashes'].items():assert sha((ROOT/name).read_bytes())==digest
    path=PRIVATE/'pure.env';assert path.resolve()==path and not path.is_symlink() and path.stat().st_mode&0o777==0o600
    before_file=path.read_bytes();assert b'COREFLUX_PEOPLE_PROVISIONING' not in before_file
    next_file=before_file+(b'' if before_file.endswith(b'\n') else b'\n')+b'COREFLUX_PEOPLE_PROVISIONING=1\n'
    before=snapshot();unrelated=others();ready()
    backup=PRIVATE/('pure-people-before-'+uuid.uuid4().hex);backup.mkdir(mode=0o700)
    (backup/'pure.env').write_bytes(before_file);os.chmod(backup/'pure.env',0o600)
    (backup/'database.json').write_text(json.dumps(before));os.chmod(backup/'database.json',0o600)
    (backup/'services.json').write_text(json.dumps(unrelated));os.chmod(backup/'services.json',0o600)
    with os.fdopen(os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:f.write('{}')
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':False,'phase':'reconfigure','private_backup':str(backup),'production_changed':False,'payroll_payments_postings':False}
    changed=False
    try:
        write(path,next_file,path.stat());changed=True;save(report)
        run('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web',timeout=50)
        fresh=inspect(WEB);new_env=dict(v.split('=',1) for v in fresh['Config']['Env'])
        assert new_env=={**env,'COREFLUX_PEOPLE_PROVISIONING':'1'}
        report['health']=ready()
        for route in ['/payroll/runs/synthetic/pay','/payroll/runs/synthetic/approve','/payments','/admin/release-handover/activate','/cron/pwp-auto-batch']:
            status,result=fetch(route,{})
            assert status==409 and result['code']=='isolated_pilot_operation_held'
        assert snapshot()==before,'Existing records or indexes changed during setting-only restart'
        assert runtime()==baseline['runtime'] and others()==unrelated
        for name,digest in baseline['configuration_hashes'].items():assert sha((ROOT/name).read_bytes())==digest
        assert list(fresh['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
        report.update(passed=True,phase='complete',container_id=fresh['Id'],started_at=fresh['State']['StartedAt'],source_unchanged=True,
            original_documents_preserved=sum(len(v['rows']) for v in before.values()),collections_and_indexes_preserved=len(before),other_services_unchanged=True,execution_hold_checks=5,people_provisioning_enabled=True)
    except Exception as error:
        report.update(failure=type(error).__name__,failure_message=str(error)[:250])
        if changed:
            write(path,before_file,path.stat())
            run('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web',timeout=50)
            ready();report['configuration_restored']=True
    save(report);print(json.dumps(report));return 0 if report['passed'] else 1
if __name__=='__main__':raise SystemExit(main())
