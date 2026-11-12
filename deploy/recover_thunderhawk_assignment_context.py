"""Resume the exact isolated release after Mongo's file-descriptor exhaustion.

Normal startup only: no repair, configuration changes, replacement containers or
restoration over original data. Verify all business fingerprints before resuming.
"""
import ast,hashlib,json,os,re,subprocess,sys,time,uuid
from datetime import datetime,timezone
from pathlib import Path
import urllib.request

ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private';APP=ROOT/'quanta'
WEB='core-provider-pilot-20261029-quanta-pilot-web-1';MONGO='core-provider-pilot-20261029-quanta-pilot-mongo-1'
DATABASE='corepilot_quanta_20261029';GUARD='coreflux_commit_fences'
RECEIPT=PRIVATE/'quanta-assignment-context-r3-before-7e3f673230494445afb96c9a60fc2c09'
STATE=PRIVATE/'assignment-context-bounded-recovery-20261112.json'
sha=lambda b:hashlib.sha256(b).hexdigest()
def run(*args,input=None,timeout=40):
    r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
    if r.returncode:
        (PRIVATE/'assignment-context-recovery-command-error.txt').write_text(r.stderr)
        raise RuntimeError('Bounded recovery command failed: '+args[0])
    return r.stdout
def inspect(name):return json.loads(run('docker','inspect',name))[0]
def js(code):return json.loads(run('docker','exec',MONGO,'mongosh','--quiet','--eval',code))
def snapshot():return js(SNAPSHOT.replace('DBNAME',json.dumps(DATABASE)))
def fence():return js('print(JSON.stringify(db.getSiblingDB('+json.dumps(DATABASE)+').coreflux_commit_fences.findOne({})))')
def checkpoint(stage):
    report['stage']=stage;STATE.write_text(json.dumps(report,indent=2)+'\n')
def protected(before,after):
    assert set(before)==set(after)
    for name,prior in before.items():
        if name!=GUARD:assert after[name]==prior,'Business data changed: '+name
        else:assert after[name]['count']==prior['count']==1 and after[name]['indexes']==prior['indexes'] and after[name]['options']==prior['options']
def files():
    backend={p.relative_to(APP/'backend').as_posix():sha(p.read_bytes().replace(b'\r\n',b'\n')) for p in [APP/'backend/server.py',*(APP/'backend/app').rglob('*.py')]}
    frontend={p.relative_to(APP/'frontend').as_posix():sha(p.read_bytes()) for p in (APP/'frontend').rglob('*') if p.is_file()}
    assert backend==manifest['accepted_runtime'] and frontend==manifest['accepted_frontend']
def others():
    rows=json.loads(run('docker','inspect',*run('docker','ps','-aq').split()))
    return {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in rows if r['Name'] not in ['/'+WEB,'/'+MONGO]}

assert sys.argv[1:]==['--recover-exact-isolated-reporting-release'] and os.geteuid()==0
assert PRIVATE.resolve()==PRIVATE and PRIVATE.stat().st_mode&0o077==0 and not STATE.exists()
os.umask(0o077)
import fcntl
lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
manifest=json.loads((RECEIPT/'manifest.json').read_text())
failure=json.loads((RECEIPT/'result.json').read_text());assert failure['web_held_for_review'] and not failure['passed']
assert manifest['revision']=='651941e73701428736daa5fde0239102ff3296c7'
helper=Path(manifest['backup_helper']);assert sha(helper.read_bytes())==manifest['backup_helper_sha256']
SNAPSHOT=next(ast.literal_eval(n.value) for n in ast.parse(helper.read_text()).body if isinstance(n,ast.Assign) and any(getattr(t,'id',None)=='SNAPSHOT' for t in n.targets))
report={'started_at':datetime.now(timezone.utc).isoformat(),'passed':False,'source_commit':manifest['revision'],
 'production_changed':False,'financial_execution':False,'settings_changed':False,'original_database_restored_over':False}
try:
    files();web=inspect(WEB);mongo=inspect(MONGO)
    assert web['Id']==manifest['web_id'] and mongo['Id']==manifest['mongo_id']
    assert not web['State']['Running'] and not mongo['State']['Running'] and mongo['State']['ExitCode']==14
    env=dict(v.split('=',1) for v in web['Config']['Env'])
    assert env['DB_NAME']==DATABASE and env['COREFLUX_TIME_COMMITS']=='1' and env['COREFLUX_TIME_ACCESS_TENANTS']=='4f93d4e7-af74-4765-b812-3464bb6d974b'
    assert env['DISABLE_BACKGROUND_WORKERS']=='1' and env['DISABLE_SCHEDULER']=='1' and env['MAIL_DRIVER']=='devoutbox'
    assert sha((ROOT/'compose.yaml').read_bytes())==manifest['compose_sha256'] and sha((ROOT/'compose-https.yaml').read_bytes())==manifest['compose_https_sha256']
    other_baseline={k:v for k,v in manifest['other_services'].items() if k!='/'+MONGO};assert others()==other_baseline
    checkpoint('start_original_mongo');run('docker','start',MONGO)
    for attempt in range(30):
        try:
            hello=js('print(JSON.stringify(db.hello()))')
            if hello.get('isWritablePrimary'):break
        except RuntimeError:pass
        if attempt==29:raise RuntimeError('Original database did not become primary')
        time.sleep(.5)
    assert hello['setName']=='corepilotquanta' and hello['hosts']==['quanta-pilot-mongo:27017']
    current=snapshot();protected(manifest['database'],current)
    guard=fence();assert guard=={'_id':'core-time-writers-v1','revision':manifest['guard']['revision']+1}
    checkpoint('original_database_verified')
    names=js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(d=>d.name)))')
    leftovers=[n for n in names if n.startswith('corepilot_contextcheck_')];assert len(leftovers)<=1
    for name in leftovers:
        assert re.fullmatch('corepilot_contextcheck_[a-f0-9]{24}',name) and name!=DATABASE
        tenants=js('print(JSON.stringify(db.getSiblingDB('+json.dumps(name)+').tenants.find({}, {_id:0,id:1,slug:1}).toArray()))')
        assert sorted(tenants,key=lambda r:r['id'])==[{'id':'a','slug':'context-fixture-a'},{'id':'b','slug':'context-fixture-b'}]
        assert js('print(JSON.stringify(db.getSiblingDB('+json.dumps(name)+').dropDatabase()))')['ok']==1
    assert snapshot()==current
    report['abandoned_fixture_databases_removed']=len(leftovers)
    checkpoint('start_original_web_with_tested_release');run('docker','start',WEB)
    for attempt in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19181/api/health',timeout=4) as response:health=json.load(response)
            assert health['ok'] and health['startup_mode']=='web' and not health['usage_recording'] and not health['tenant_automations']['running'];break
        except (OSError,AssertionError):
            if attempt==29:raise
            time.sleep(.5)
    after=snapshot();protected(current,after)
    assert fence()=={'_id':'core-time-writers-v1','revision':guard['revision']+1}
    checkpoint('verify_bounded_assignment_contract')
    probe=Path(__file__).with_name('assignment-context-bounded-probe.py').read_text()
    assert sha(probe.encode())=='01d3a3335098ee9b26f09cd67f52756022da8bc88d33228d718ba345190de32e'
    result=json.loads(run('docker','exec','-i',WEB,'python','-',input=probe,timeout=60))
    assert result['passed'] and result['temporary_database_removed'] and result['external_network_attempts']==0
    assert snapshot()==after and others()==other_baseline;files()
    for path in ['/login',*manifest['entrypoints']]:
        with urllib.request.urlopen('http://127.0.0.1:19181'+path,timeout=10) as response:body=response.read()
        expected=manifest['accepted_frontend']['index.html' if path=='/login' else path.lstrip('/')]
        assert sha(body)==expected
    report.update(passed=True,hosted_probe=result,business_collections_preserved=len(after),original_records_preserved=sum(v['count'] for n,v in after.items() if n!=GUARD),
      original_time_entries=after['time_entries']['count'],other_services_unchanged=True,protected_other_services=len(other_baseline),
      target_container_id=web['Id'],target_started_at=inspect(WEB)['State']['StartedAt'],mongo_container_id=mongo['Id'],mongo_started_at=inspect(MONGO)['State']['StartedAt'],
      served_assets_verified=True,browser_policy_preserved=True,coordinator_guard=fence())
    (RECEIPT/'recovered-after.json').write_text(json.dumps(after,indent=2)+'\n');checkpoint('complete')
except Exception as error:
    report.update(failure_class=type(error).__name__,failure_message=str(error));checkpoint('needs_inspection')
finally:
    report['completed_at']=datetime.now(timezone.utc).isoformat();STATE.write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report))
raise SystemExit(0 if report['passed'] else 1)
