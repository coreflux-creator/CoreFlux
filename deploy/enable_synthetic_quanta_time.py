"""Enable assignment time policy for one isolated tenant; preserve all business data."""
import copy,fcntl,hashlib,json,os,subprocess,sys,time,urllib.request
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path
ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private'
PROJECT='core-provider-pilot-20261029';WEB=PROJECT+'-quanta-pilot-web-1'
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b';FLAG='COREFLUX_TIME_ACCESS_TENANTS'
STATE=PRIVATE/'synthetic-quanta-time-enablement-r2-20261111.json'
sha=lambda b:hashlib.sha256(b).hexdigest()


def run(*args,input=None):
    r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=50)
    if r.returncode:
        (PRIVATE/'synthetic-time-config-error.private.txt').write_text(r.stderr)
        raise RuntimeError('Configuration command failed')
    return r.stdout


def compose():return ['docker','compose','--project-directory',str(ROOT),'-p',PROJECT,'-f',str(ROOT/'compose.yaml'),'-f',str(ROOT/'compose-https.yaml')]
def inspect():return json.loads(run('docker','inspect',WEB))[0]
def config():return json.loads(run(*compose(),'config','--format','json'))
def env(r):return dict(x.split('=',1) for x in r['Config']['Env'])
def others():
    rows=json.loads(run('docker','inspect',*run('docker','ps','-aq').split()))
    return {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in rows if r['Name']!='/'+WEB}
def runtime():
    base=ROOT/'quanta/backend'
    return {p.relative_to(base).as_posix():sha(p.read_bytes()) for p in [base/'server.py',*(base/'app').rglob('*.py')]}
def snapshot():
    code=r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];out={}
for n in sorted(d.list_collection_names()):
 rows=[]
 for row in d[n].find({}):
  if n=='coreflux_commit_fences':row.pop('revision',None)
  rows.append(json_util.dumps(row,sort_keys=True))
 out[n]={'hash':hashlib.sha256(json.dumps(sorted(rows)).encode()).hexdigest(),'count':len(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
 if n=='api_usage_events':out[n]['rows']=sorted(hashlib.sha256(r.encode()).hexdigest() for r in rows)
print(json_util.dumps({'collections':out,'fence':d.coreflux_commit_fences.find_one({})}))'''
    return json.loads(run('docker','exec','-i',WEB,'python','-',input=code))
def save(report):STATE.write_text(json.dumps(report,indent=2)+'\n')
def atomic(path,body):
    temp=PRIVATE/'quanta-time-next.private.env'
    assert not temp.exists();s=path.stat()
    with temp.open('xb') as f:f.write(body);f.flush();os.fsync(f.fileno())
    os.chown(temp,s.st_uid,s.st_gid);os.chmod(temp,s.st_mode&0o777);os.replace(temp,path)
def health():
    for attempt in range(25):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19181/api/health',timeout=3) as r:data=json.load(r)
            assert data['ok'] and data['startup_mode']=='web' and not data['usage_recording']
            assert not data['tenant_automations']['running'] and data['tenant_automations']['job_count']==0
            return
        except (OSError,AssertionError):
            if attempt==24:raise
            time.sleep(.5)


def main():
    assert sys.argv[1:]==['--enable-isolated-assignment-time'] and os.geteuid()==0 and not STATE.exists()
    os.umask(0o077)
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    plan=json.loads((PRIVATE/'synthetic-quanta-time-plan-20261111.json').read_text())
    assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
    preparation=json.loads((PRIVATE/'synthetic-quanta-time-preparation-20261111.json').read_text());assert preparation['passed']
    pre=json.loads((PRIVATE/'synthetic-time-configuration-preflight-r2.json').read_text())['quanta']
    original=inspect();assert original['Id']==pre['container_id'] and original['State']['Running']
    e=env(original)
    assert e['DB_NAME']=='corepilot_quanta_20261029' and e['COREFLUX_TIME_COMMITS']=='1' and not e.get(FLAG)
    assert e['MAIL_DRIVER']=='devoutbox' and e['DISABLE_SCHEDULER']=='1' and e['DISABLE_BACKGROUND_WORKERS']=='1'
    assert list(original['NetworkSettings']['Networks'])==pre['networks']
    for name,digest in pre['configuration_hashes'].items():assert sha((ROOT/name).read_bytes())==digest
    path=PRIVATE/'quanta.env';old=path.read_bytes()
    assert path.resolve()==path and path.stat().st_mode&0o777==0o600 and sha(old)==pre['environment_sha256']
    assert FLAG.encode() not in old
    before=snapshot();before_others=others();before_runtime=runtime();before_config=config()
    save({'phase':'preflight','passed':False,'started_at':datetime.now(timezone.utc).isoformat()})
    (PRIVATE/'synthetic-time-before-r2.private.json').write_text(json.dumps({'database':before,'other_services':before_others,'runtime':before_runtime,'service':original,'compose':before_config}))
    backup=PRIVATE/'quanta-before-time-access-r2.private.env';assert not backup.exists();backup.write_bytes(old)
    new=old.rstrip(b'\n')+b'\n'+FLAG.encode()+b'='+TENANT.encode()+b'\n'
    report={'started_at':datetime.now(timezone.utc).isoformat(),'passed':False,'phase':'configuration',
        'production_changed':False,'real_payroll_payments_postings':False,'tenant_id':TENANT}
    save(report)
    try:
        assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
        atomic(path,new)
        candidate=config();expected=copy.deepcopy(before_config)
        expected['services']['quanta-pilot-web']['environment'][FLAG]=TENANT
        assert candidate==expected,'Another Compose setting changed'
        report['phase']='recreate-quanta-web';save(report)
        run(*compose(),'up','-d','--no-deps','--force-recreate','quanta-pilot-web');health()
        current=inspect();assert current['State']['Running'] and current['Image']==original['Image']
        assert env(current)=={**e,FLAG:TENANT}
        for key in ['Cmd','Entrypoint','Hostname','User','WorkingDir']:assert current['Config'][key]==original['Config'][key],key
        for key in ['Memory','MemorySwap','PortBindings','RestartPolicy','Privileged']:assert current['HostConfig'][key]==original['HostConfig'][key],key
        mounts=lambda r:{m['Destination']:[m['Type'],m['Source'],m['RW']] for m in r['Mounts']}
        assert mounts(current)==mounts(original) and set(current['NetworkSettings']['Networks'])==set(original['NetworkSettings']['Networks'])
        after=snapshot();assert set(after['collections'])==set(before['collections'])
        appended_usage=0
        for name,prior in before['collections'].items():
            fresh=after['collections'][name]
            if name!='api_usage_events':assert fresh==prior,name
            else:
                assert fresh['indexes']==prior['indexes'] and not Counter(prior['rows'])-Counter(fresh['rows'])
                appended_usage=fresh['count']-prior['count']
        # Startup acquires one coordinator revision; shutdown may commit its
        # already buffered usage records. Neither may change an existing row.
        assert after['fence']['revision'] in [before['fence']['revision']+1,before['fence']['revision']+2]
        assert others()==before_others and runtime()==before_runtime
        check="from app.coreflux_time_access import configuration_enabled; assert configuration_enabled('"+TENANT+"'); assert not configuration_enabled('other-tenant'); assert not configuration_enabled('*'); print('exact tenant verified')"
        assert run('docker','exec',WEB,'python','-c',check).strip()=='exact tenant verified'
        report.update(passed=True,phase='complete',container_id=current['Id'],environment_sha256=sha(new),
            exact_tenant_verified=True,collections_preserved=len(after['collections']),other_services_unchanged=True,
            runtime_unchanged=True,appended_usage_records=appended_usage,core_policy_required_before_capture=True)
    except Exception as error:
        report.update(failure_class=type(error).__name__,failure_line=error.__traceback__.tb_lineno)
        # Revert configuration only, never restore old business data over a later write.
        if sha(path.read_bytes())==sha(new):
            atomic(path,old);run(*compose(),'up','-d','--no-deps','--force-recreate','quanta-pilot-web');health()
            assert not env(inspect()).get(FLAG);report['configuration_restored']=True
    report['finished_at']=datetime.now(timezone.utc).isoformat();save(report);print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__=='__main__':sys.exit(main())
