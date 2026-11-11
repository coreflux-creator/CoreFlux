"""Activate the exact offline-tested current Pure source only in the isolated Core pilot."""
import fcntl, hashlib, json, os, shutil, subprocess, sys, time, urllib.request, urllib.error, uuid
from datetime import datetime, timezone
from pathlib import Path

ROOT=Path('/srv/core-integration-pilot/20261029'); PRIVATE=ROOT/'private'
WEB='core-provider-pilot-20261029-pure-pilot-web-1'; SITE='core-provider-pilot-20261029-pure-pilot-site-1'
PLAN=PRIVATE/'pure-compatible-release-20261111.json'
plan=json.loads(PLAN.read_text())
FIXTURE=plan['offline_fixture']['container_id']
STAGE=Path(plan['offline_fixture']['remote'])
UI_STAGE=Path(plan['ui_stage'])
PUBLIC=Path(plan['public_root'])
assert STAGE.parent==PRIVATE and UI_STAGE.parent==PRIVATE
assert PUBLIC.parent==Path('/srv/payroll-stack/releases')
assert plan['checks_passed'] and plan['source_commit'] and plan['mutation_window_expires_at']
EMPLOYEE='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'
STATE=PRIVATE/'pure-compatible-install-20261111.json'
sha=lambda b:hashlib.sha256(b).hexdigest()

def run(*args,input=None,timeout=45):
    r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
    if r.returncode: raise RuntimeError('Isolated command failed: '+args[0]+' '+r.stderr[-250:])
    return r.stdout

def inspect(name): return json.loads(run('docker','inspect',name))[0]

def hashes(root):
    return {p.relative_to(root).as_posix():sha(p.read_bytes()) for p in sorted(root.rglob('*'))
            if p.is_file() and '__pycache__' not in p.parts}

def snapshot():
    code=r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
print(json.dumps({n:{'rows':sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in db[n].find({})),'indexes':json_util.dumps(db[n].index_information(),sort_keys=True)} for n in sorted(db.list_collection_names())}))'''
    return json.loads(run('docker','exec','-i',WEB,'python','-',input=code))

def other_services():
    ids=run('docker','ps','-aq').split()
    return {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in json.loads(run('docker','inspect',*ids)) if r['Name'] not in ['/'+WEB,'/'+SITE]}

def fetch(path,body=None):
    req=urllib.request.Request('http://127.0.0.1:19180'+path,None if body is None else json.dumps(body).encode(),{'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req,timeout=5) as r: return r.status,r.read()
    except urllib.error.HTTPError as e: return e.code,e.read()

def ready():
    for attempt in range(30):
        try:
            status,raw=fetch('/api/release-status'); report=json.loads(raw)
            assert status==200 and report['state']=='active' and report['startup_mode']=='isolated_pilot'
            assert all(report[k] is False for k in ['background_jobs','startup_migrations','scheduled_operations','provider_egress_enabled'])
            return report
        except (OSError,ValueError,AssertionError):
            if attempt==29: raise
            time.sleep(1)

def save(report):
    temporary=STATE.with_suffix('.next')
    with os.fdopen(os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:
        json.dump(report,f,indent=2);f.flush();os.fsync(f.fileno())
    os.replace(temporary,STATE)

def write_env(path,body):
    previous=path.stat(); temporary=path.with_suffix('.lifecycle-next')
    with os.fdopen(os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:
        f.write(body);f.flush();os.fsync(f.fileno())
    os.chown(temporary,previous.st_uid,previous.st_gid);os.chmod(temporary,previous.st_mode&0o777)
    os.replace(temporary,path)

def main():
    assert sys.argv[1:]==['--activate-compatible-isolated-source'] and os.geteuid()==0
    assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['mutation_window_expires_at']), 'Coordination window expired'
    assert not STATE.exists(),'Inspect existing installation result before retrying'
    lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    test=inspect(FIXTURE)
    assert test['State']['Status']=='exited' and test['State']['ExitCode']==0 and test['HostConfig']['NetworkMode']=='none'
    assert test['Mounts'][0]['Source']==str(STAGE) and test['Mounts'][0]['RW'] is False
    logs=run('docker','logs',FIXTURE)
    assert plan['offline_pass_summary'] in logs and '"network_attempts": 0' in logs
    manifest=json.loads((STAGE/'manifest.json').read_text())
    for name,digest in manifest.items(): assert sha((STAGE/name).read_bytes())==digest
    old=inspect(WEB); site=inspect(SITE); env=dict(v.split('=',1) for v in old['Config']['Env'])
    assert old['Id']==plan['expected_web_id'] and site['Id']==plan['expected_site_id']
    assert old['State']['Running'] and site['State']['Running']
    assert old['Config']['Hostname']=='pure-core-pilot-20261029' and old['Config']['Image']=='payroll-migration/pure-backend:d7d89d01'
    assert env['DB_NAME']=='corepilot_pure_20261029' and env['PUREPAY_STARTUP_MODE']=='isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS']=='disabled'
    assert env['COREFLUX_PEOPLE_PROVISIONING']=='1' and env['COREFLUX_ASSIGNMENT_PROVISIONING']=='1' and 'PUREPAY_PILOT_EMPLOYEE_SETUP_IDS' not in env
    assert list(old['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
    assert json.loads(run('docker','network','inspect','core-provider-pilot-20261029_pure-private'))[0]['Internal'] is True
    assert any(m['Source']==str(ROOT/'pure/backend') and m['Destination']=='/app/backend' and not m['RW'] for m in old['Mounts'])
    assert any(m['Source']==str(ROOT/'pure/frontend') and m['Destination']=='/usr/share/nginx/html' and not m['RW'] for m in site['Mounts'])
    baseline=plan['baseline']
    for name,digest in baseline['runtime'].items(): assert sha((ROOT/'pure/backend'/name).read_bytes().replace(b'\r\n',b'\n'))==digest
    for name,digest in baseline['configuration_hashes'].items(): assert sha((ROOT/name).read_bytes())==digest
    publication=json.loads((PUBLIC/'published.json').read_text())
    assert publication['source_commit']==plan['public_source_commit']
    public_frontend=hashes(PUBLIC/'frontend/build')
    assert public_frontend==plan['public_frontend_hashes']
    frontend=hashes(UI_STAGE/'frontend'); assert 'index.html' in frontend and len(frontend)>5
    assert frontend==plan['frontend_hashes']
    assert not any(p.is_symlink() for p in (UI_STAGE/'frontend').rglob('*'))
    mongo=plan['mongo_fixture']; mongo_test=inspect(mongo['container_id'])
    assert not mongo_test['State']['Running'] and mongo_test['State']['ExitCode']==0
    assert mongo_test['Mounts'][0]['Source']==mongo['remote'] and mongo_test['Mounts'][0]['RW'] is False
    assert '145 passed' in run('docker','logs',mongo['container_id'])
    mongo_manifest=json.loads((Path(mongo['remote'])/'manifest.json').read_text())
    for name,digest in manifest.items():
        if name.startswith('source/backend/'):
            assert mongo_manifest[name]==digest, ('Backend changed after database tests',name)
    assert plan['php_customer']['passed'] and plan['php_bill']['passed']
    assert plan['ui_tests_passed']==27 and plan['missing_preserved_routes']==0
    backup=PRIVATE/('pure-lifecycle-before-'+uuid.uuid4().hex);backup.mkdir(mode=0o700)
    candidate=PRIVATE/('pure-lifecycle-candidate-'+uuid.uuid4().hex);candidate.mkdir(mode=0o700)
    shutil.copytree(STAGE/'source/backend',candidate/'backend')
    shutil.copytree(UI_STAGE/'frontend',candidate/'frontend')
    assert hashes(candidate/'frontend')==frontend
    # Docker must find nested mount targets before mounting a read-only parent.
    # Test the actual layout against empty disposable child directories first.
    children=[m for m in old['Mounts'] if m['Destination'].startswith('/app/backend/')]
    fixture_mounts=candidate/'mount-check';fixture_mounts.mkdir()
    command=['docker','run','--rm','--network','none','--read-only','--memory','128m',
        '--mount','type=bind,src='+str(candidate/'backend')+',dst=/app/backend,readonly']
    for m in children:
        relative=Path(m['Destination']).relative_to('/app/backend')
        assert len(relative.parts)==1 and m['Type']=='bind' and m['RW'] is True
        (candidate/'backend'/relative).mkdir(exist_ok=True)
        isolated=fixture_mounts/relative;isolated.mkdir()
        command+=['--mount','type=bind,src='+str(isolated)+',dst='+m['Destination']]
    command+=['--entrypoint','python',old['Config']['Image'],'-c',
        'from pathlib import Path; import json; paths='+repr([m['Destination'] for m in children])+'; [(Path(p)/"fixture-only.txt").write_text("synthetic") for p in paths]; print(json.dumps({"mounts_checked":len(paths)}))']
    mount_report=json.loads(run(*command))
    assert mount_report['mounts_checked']==len(children)
    # Match the application's exact normalized runtime fingerprint algorithm.
    pyfiles={p.relative_to(candidate/'backend').as_posix():sha(p.read_bytes().replace(b'\r\n',b'\n'))
        for p in sorted((candidate/'backend').rglob('*.py')) if not any(part.startswith('.') or part in {'tests','__pycache__','rpa_evidence','uploads','data'} for part in p.relative_to(candidate/'backend').parts)}
    stamp={'release_id':'pure-core-compatible-'+plan['source_commit'][:12]+'-20261111','deployment_id':str(uuid.uuid4()),'source_digest':sha(json.dumps(pyfiles,sort_keys=True).encode())}
    (candidate/'backend/release_handover.json').write_text(json.dumps(stamp))
    expected_backend=hashes(candidate/'backend'); expected_front=hashes(candidate/'frontend')
    before=snapshot(); others=other_services(); ready()
    config=PRIVATE/'pure.env'; assert config.resolve()==config and config.stat().st_mode&0o777==0o600
    env_before=config.read_bytes(); assert b'PUREPAY_PILOT_EMPLOYEE_SETUP_IDS' not in env_before
    env_after=env_before+(b'' if env_before.endswith(b'\n') else b'\n')+('PUREPAY_PILOT_EMPLOYEE_SETUP_IDS='+EMPLOYEE+'\n').encode()
    (backup/'pure.env').write_bytes(env_before);os.chmod(backup/'pure.env',0o600)
    (backup/'database-fingerprints.json').write_text(json.dumps(before));os.chmod(backup/'database-fingerprints.json',0o600)
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'phase':'prepared','passed':False,'backup':str(backup),'candidate':str(candidate),'stamp':stamp,
        'production_changed':False,'real_payroll_payments_postings':False,'offline_test_summary':plan['offline_pass_summary'],'mongo_tests':145,'php_contracts':2,'ui_tests':27,'provider_egress':False,
        'nested_mounts_preflight':mount_report,'preserved_source_verified':True,'source_commit':plan['source_commit']}
    save(report); moved=[]; stopped=False
    try:
        run('docker','compose','-f',str(ROOT/'compose.yaml'),'stop','pure-pilot-site','pure-pilot-web');stopped=True
        report['phase']='services-stopped';save(report)
        for part in ['backend','frontend']:
            current=ROOT/'pure'/part; assert current.resolve()==current and not current.is_symlink()
            os.rename(current,backup/part); moved.append(part);os.rename(candidate/part,current)
        write_env(config,env_after)
        report['phase']='source-installed';save(report)
        run('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web','pure-pilot-site',timeout=60)
        report['health']=ready()
        fresh=inspect(WEB)
        assert dict(v.split('=',1) for v in fresh['Config']['Env'])=={**env,'PUREPAY_PILOT_EMPLOYEE_SETUP_IDS':EMPLOYEE}
        assert fresh['Config']['Image']==old['Config']['Image'] and sorted(fresh['Mounts'],key=lambda m:m['Destination'])==sorted(old['Mounts'],key=lambda m:m['Destination'])
        assert list(fresh['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
        for route in ['/api/payroll/runs/synthetic/pay','/api/payroll/runs/synthetic/approve','/api/payments','/api/admin/release-handover/activate','/api/cron/pwp-auto-batch']:
            status,raw=fetch(route,{}); assert status==409 and json.loads(raw)['code']=='isolated_pilot_operation_held'
        status,raw=fetch('/'); assert status==200 and sha(raw)==expected_front['index.html']
        assert snapshot()==before,'Pilot documents or indexes changed during code-only activation'
        assert hashes(ROOT/'pure/backend')==expected_backend and hashes(ROOT/'pure/frontend')==expected_front
        assert hashes(PUBLIC/'frontend/build')==public_frontend and other_services()==others
        for name,digest in baseline['configuration_hashes'].items(): assert sha((ROOT/name).read_bytes())==digest
        report.update(passed=True,phase='complete',container_id=fresh['Id'],site_id=inspect(SITE)['Id'],
            original_documents_preserved=sum(len(v['rows']) for v in before.values()),collections_and_indexes_preserved=len(before),
            unrelated_services_unchanged=True,backend_files=len(expected_backend),frontend_files=len(expected_front),
            employee_setup_scope=[EMPLOYEE],execution_hold_checks=5)
    except Exception as e:
        report.update(failure=type(e).__name__,failure_message=str(e)[:350])
        if stopped:
            run('docker','compose','-f',str(ROOT/'compose.yaml'),'stop','pure-pilot-site','pure-pilot-web')
            for part in reversed(moved):
                current=ROOT/'pure'/part
                if current.exists():os.rename(current,candidate/(part+'-rejected'))
                os.rename(backup/part,current)
            if config.read_bytes()!=env_before:write_env(config,env_before)
            run('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web','pure-pilot-site',timeout=60)
            ready();report['previous_source_restored']=True
        report['phase']='refused'
    save(report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':raise SystemExit(main())
