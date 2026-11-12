"""Exact code/UI update in the isolated provider. No database or configuration writes."""
import ast,fcntl,hashlib,importlib.util,json,os,shutil,subprocess,sys,time
from datetime import datetime,timezone
from pathlib import Path,PurePosixPath
from uuid import UUID

ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private'
BACKEND=ROOT/'pure/backend';FRONTEND=ROOT/'pure/frontend'
WEB='core-provider-pilot-20261029-pure-pilot-web-1';SITE='core-provider-pilot-20261029-pure-pilot-site-1'
FILES=('coreflux_commercial.py','coreflux_assignments.py','coreflux_exchange.py')
STATE=PRIVATE/'commission-exchange-release-20261112.json'
CHECKPOINT=PRIVATE/'commission-exchange-before-20261112.private.json'
sha=lambda raw:hashlib.sha256(raw).hexdigest()

def load_helpers():
    path=Path(__file__).parent/'install_pure_payment_display.py'
    spec=importlib.util.spec_from_file_location('verified_display_release',path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    return module

def hashes(path):
    assert path.resolve()==path and not path.is_symlink()
    files={}
    for p in sorted(path.rglob('*')):
        assert not p.is_symlink()
        if p.is_file():files[p.relative_to(path).as_posix()]=sha(p.read_bytes())
    return files

def validate_relative(name):
    p=PurePosixPath(name)
    if p.is_absolute() or '..' in p.parts or '\\' in name or ':' in name or not name or str(p)!=name:
        raise ValueError('Invalid package path')
    return p

def validate_plan(plan,stage):
    assert plan['provider_commit']=='579245c03bfa254dc2d01cee446f165af762b3bb'
    assert plan['core_commit']=='32f313a3da6ca556b5a072c51fa79b35412034ff'
    assert plan['ci_successful_jobs']==11 and plan['assignment_version']=='2026-11-12.1'
    assert set(plan['changed_backend'])==set(FILES)
    assert plan['local_checks']['provider_api']==102 and plan['local_checks']['provider_protected_records'] and plan['local_checks']['react']==5
    assert plan['helper_sha256']==sha((stage/'install_pure_payment_display.py').read_bytes())
    for name,digest in plan['changed_backend'].items():
        p=stage/'provider/backend'/validate_relative(name);assert not p.is_symlink() and sha(p.read_bytes())==digest;ast.parse(p.read_bytes())
    assert hashes(stage/'provider/frontend')==plan['new_frontend'] and 'index.html' in plan['new_frontend']
    stamp_bytes=(stage/'release_handover.json.next').read_bytes();assert sha(stamp_bytes)==plan['new_stamp_sha256']
    stamp=json.loads(stamp_bytes);assert stamp==plan['new_stamp']
    assert set(stamp)=={'release_id','deployment_id','source_digest'} and 0<len(stamp['release_id'])<=100
    UUID(stamp['deployment_id'])
    expected=dict(plan['before']['release_runtime']);expected.update(plan['changed_backend'])
    assert stamp['source_digest']==sha(json.dumps(expected,sort_keys=True).encode())
    return stamp_bytes

def write_private(path,value):
    assert path.parent==PRIVATE and path.resolve()==path and not path.is_symlink()
    temp=path.with_suffix('.next');assert not temp.is_symlink()
    with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w')as f:
        json.dump(value,f,indent=2);f.flush();os.fsync(f.fileno())
    os.replace(temp,path)

def write_backend(name,body):
    assert name in (*FILES,'release_handover.json')
    p=BACKEND/name;s=p.stat()
    assert p.resolve()==p and not p.is_symlink() and (s.st_uid,s.st_gid,s.st_mode&0o777)==(0,0,0o644)
    temp=BACKEND/('.commission-'+name+'.next')
    with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb')as f:f.write(body);f.flush();os.fsync(f.fileno())
    os.chown(temp,0,0);os.chmod(temp,0o644);os.replace(temp,p)

def other_services(base):
    return {n:v for n,v in base.services().items() if n!='/'+SITE}

def current_scope(base,plan):
    web=base.inspect(WEB);site=base.inspect(SITE);env=dict(v.split('=',1)for v in web['Config']['Env'])
    assert env['DB_NAME']=='corepilot_pure_20261029' and env['PUREPAY_STARTUP_MODE']=='isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS']=='disabled'
    assert list(web['NetworkSettings']['Networks'])==['core-provider-pilot-20261029_pure-private']
    assert json.loads(base.command('docker','network','inspect','core-provider-pilot-20261029_pure-private'))[0]['Internal'] is True
    assert any(m['Source']==str(BACKEND) and m['Destination']=='/app/backend' and not m['RW'] for m in web['Mounts'])
    assert any(m['Source']==str(FRONTEND) and m['Destination']=='/usr/share/nginx/html' and not m['RW'] for m in site['Mounts'])
    for name,h in plan['before']['configuration_hashes'].items():assert sha((ROOT/validate_relative(name)).read_bytes())==h
    return web,site

def ready(base):
    deadline=time.monotonic()+180
    while True:
        try:
            status,value=base.fetch('/api/release-status')
            assert status==200 and value['startup_mode']=='isolated_pilot' and value['state']=='active'
            assert all(value[k] is False for k in ['background_jobs','startup_migrations','scheduled_operations','provider_egress_enabled'])
            return value
        except (OSError,ValueError,AssertionError):
            if not all(base.inspect(n)['State']['Running'] for n in (WEB,SITE)):
                raise RuntimeError('An isolated service exited before health verification')
            if time.monotonic()>=deadline:raise base.HealthPending('Services remain running; resume observing these containers')
            time.sleep(2)

def contract_probe(base,plan):
    packet=plan['synthetic_packet']
    code='''import os,json
from pymongo import MongoClient
from coreflux_assignments import snapshot_adapter,digest,COMMISSION_ASSIGNMENT_VERSION
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
packet=json.loads(PACKET);assert snapshot_adapter.validate_python(packet).model_dump(mode='json')==packet
assert COMMISSION_ASSIGNMENT_VERSION==packet['contract_version']=='2026-11-12.1'
party=packet['terms'][0]['economics']['parties'][4]
assert (party['currency'],party['payment_frequency'],party['due_month'])==('INR','one_time','2026-08')
inputs=list(d.coreflux_assignment_inputs.find({}));assignments=list(d.assignments.find({'core_assignment':{'$exists':True}}))
for saved in inputs+[r['core_assignment'] for r in assignments]:
 value=snapshot_adapter.validate_python(saved['packet']).model_dump(mode='json');assert value==saved['packet'] and digest(value)==saved['content_hash']
print(json.dumps({'passed':True,'existing_inputs':len(inputs),'native_assignments':len(assignments),'new_packet_retained':True,'records_changed':False}))
'''.replace('PACKET',repr(json.dumps(packet)))
    return json.loads(base.command('docker','exec','-i',WEB,'python','-',input=code))

def verify_installed_files(base,plan):
    expected={**plan['before']['provider_pins'],**plan['changed_backend'],'release_handover.json':plan['new_stamp_sha256']}
    base.verify_release_stamp(BACKEND,(BACKEND/'server.py').read_bytes(),(BACKEND/'release_handover.json').read_bytes())
    assert base.source_hashes()==expected and hashes(FRONTEND)==plan['new_frontend']
    return expected

def finish(base,plan,report,saved):
    report['health']=ready(base)
    fresh_web,fresh_site=current_scope(base,plan)
    for old,new in [(saved['web'],fresh_web),(saved['site'],fresh_site)]:
        assert old['Config']['Image']==new['Config']['Image'] and old['Config']['Env']==new['Config']['Env']
        assert sorted(old['Mounts'],key=lambda m:m['Destination'])==sorted(new['Mounts'],key=lambda m:m['Destination'])
    report['contract_probe']=contract_probe(base,plan)
    checks=[]
    for path in ['/api/payroll/runs/synthetic/pay','/api/payroll/runs/synthetic/approve','/api/payments','/api/admin/release-handover/activate','/api/cron/pwp-auto-batch']:
        status,value=base.fetch(path,{});assert status==409 and value['code']=='isolated_pilot_operation_held';checks.append(path)
    expected=verify_installed_files(base,plan)
    assert base.snapshot()==saved['database'],'Pilot records or indexes changed during code-only activation'
    assert other_services(base)==saved['others'],'An unrelated service changed'
    report.update(phase='complete',passed=True,container_id=fresh_web['Id'],site_id=fresh_site['Id'],
        backend_files=len(expected),frontend_files=len(plan['new_frontend']),collections=len(saved['database']),
        original_rows_preserved=sum(len(v['rows'])for v in saved['database'].values()),other_services_preserved=len(saved['others']),
        configuration_preserved=True,held_routes=checks,finished_at=datetime.now(timezone.utc).isoformat())

def rollback(base,plan,report,saved):
    backup=Path(saved['backup']);assert backup.parent==PRIVATE and backup.resolve()==backup
    for name in (*FILES,'release_handover.json'):
        prior=sha((backup/name).read_bytes().replace(b'\r\n',b'\n'))
        after=plan['new_stamp_sha256'] if name=='release_handover.json' else plan['changed_backend'][name]
        assert prior==plan['before']['provider_pins'][name]
        assert sha((BACKEND/name).read_bytes().replace(b'\r\n',b'\n')) in (prior,after),'Unexpected source during recovery'
    base.command('docker','compose','-f',str(ROOT/'compose.yaml'),'stop', 'pure-pilot-site','pure-pilot-web',timeout=50)
    for name in (*FILES,'release_handover.json'):write_backend(name,(backup/name).read_bytes())
    if (backup/'frontend').exists():
        assert hashes(backup/'frontend')==plan['before']['frontend_files']
        if FRONTEND.exists():
            assert hashes(FRONTEND)==plan['new_frontend']
            rejected=backup/'rejected-frontend';assert not rejected.exists();os.rename(FRONTEND,rejected)
        os.rename(backup/'frontend',FRONTEND)
    base.command('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web','pure-pilot-site',timeout=60)
    report['rollback_health']=ready(base)
    assert base.source_hashes()==plan['before']['provider_pins'] and hashes(FRONTEND)==plan['before']['frontend_files']
    assert base.snapshot()==saved['database'] and other_services(base)==saved['others']
    current_scope(base,plan);report.update(phase='rolled_back',runtime_restored=True,finished_at=datetime.now(timezone.utc).isoformat())

def main():
    assert len(sys.argv)==3 and sys.argv[1] in ('--inspect','--install','--resume','--rollback') and os.geteuid()==0
    stage=Path(__file__).parent;assert stage.parent==PRIVATE and stage.resolve()==stage and stage.stat().st_mode&0o777==0o700
    assert len(sys.argv[2])==64 and sha((stage/'plan.json').read_bytes())==sys.argv[2]
    plan=json.loads((stage/'plan.json').read_text());stamp=validate_plan(plan,stage);base=load_helpers()
    lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    action=sys.argv[1]
    if action in ('--inspect','--install'):
        web,site=current_scope(base,plan)
        assert web['Id']==plan['before']['container_id'] and site['Id']==plan['before']['site_id']
        assert web['State']['Running'] and site['State']['Running']
        assert base.source_hashes()==plan['before']['provider_pins'] and hashes(FRONTEND)==plan['before']['frontend_files']
        base.verify_release_stamp(BACKEND,(BACKEND/'server.py').read_bytes(),(BACKEND/'release_handover.json').read_bytes())
        if action=='--inspect':print(json.dumps({'ready':True,'installed':False,'backend_changes':3,'frontend_files':len(plan['new_frontend'])}));return 0
        assert not STATE.exists() and not CHECKPOINT.exists()
        backup=PRIVATE/('commission-before-'+sys.argv[2][:20]);assert not backup.exists();backup.mkdir(mode=0o700)
        for name in (*FILES,'release_handover.json'):
            p=BACKEND/name;s=p.stat();assert not p.is_symlink() and (s.st_uid,s.st_gid,s.st_mode&0o777)==(0,0,0o644)
            (backup/name).write_bytes(p.read_bytes());os.chmod(backup/name,0o600)
        new_front=backup/'candidate-frontend';shutil.copytree(stage/'provider/frontend',new_front)
        for p in [new_front,*new_front.rglob('*')]:os.chmod(p,0o755 if p.is_dir() else 0o644)
        assert hashes(new_front)==plan['new_frontend']
        saved={'web':web,'site':site,'database':base.snapshot(),'others':other_services(base),'backup':str(backup)}
        write_private(CHECKPOINT,saved)
        report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'prepared','passed':False,'backup':str(backup),
            'plan_sha256':sys.argv[2],'provider_commit':plan['provider_commit'],'core_commit':plan['core_commit'],
            'production_changed':False,'financial_execution':False,'schema_changed':False,'release_stamp':plan['new_stamp']}
        write_private(STATE,report)
    else:
        report=json.loads(STATE.read_text());saved=json.loads(CHECKPOINT.read_text());assert report['plan_sha256']==sys.argv[2]
        if action=='--resume':assert report['phase']=='waiting_for_health' and all(base.inspect(n)['State']['Running'] for n in (WEB,SITE))
        else:
            assert report['phase']=='complete' and base.snapshot()==saved['database']
            verify_installed_files(base,plan);current_scope(base,plan)
            assert base.inspect(WEB)['Id']==report['container_id'] and base.inspect(SITE)['Id']==report['site_id']
            report['passed']=False;rollback(base,plan,report,saved);write_private(STATE,report);print(json.dumps(report));return 0
    try:
        if action=='--install':
            base.command('docker','compose','-f',str(ROOT/'compose.yaml'),'stop','pure-pilot-site','pure-pilot-web',timeout=50)
            report['phase']='services_stopped';write_private(STATE,report)
            for name in FILES:write_backend(name,(stage/'provider/backend'/name).read_bytes())
            write_backend('release_handover.json',stamp)
            assert hashes(FRONTEND)==plan['before']['frontend_files'];os.rename(FRONTEND,backup/'frontend');os.rename(new_front,FRONTEND)
            report['phase']='source_installed';write_private(STATE,report)
            base.command('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web','pure-pilot-site',timeout=60)
            report.update(phase='waiting_for_health',new_web=base.inspect(WEB)['Id'],new_site=base.inspect(SITE)['Id']);write_private(STATE,report)
        else:assert base.inspect(WEB)['Id']==report['new_web'] and base.inspect(SITE)['Id']==report['new_site']
        finish(base,plan,report,saved)
    except subprocess.TimeoutExpired:
        report.update(phase='operation_pending',observation='Inspect the existing command/container outcome before any recovery or resume');write_private(STATE,report);print(json.dumps(report));return 3
    except base.HealthPending as error:
        report.update(phase='waiting_for_health',observation=str(error));write_private(STATE,report);print(json.dumps(report));return 3
    except Exception as error:
        report.update(phase='rolling_back',failure=type(error).__name__,failure_detail=str(error)[:400]);write_private(STATE,report)
        try:
            logs={}
            for name in (WEB,SITE):
                result=subprocess.run(['docker','logs','--tail','100',name],capture_output=True,text=True,timeout=15)
                logs[name]={'returncode':result.returncode,'stdout':result.stdout,'stderr':result.stderr}
            write_private(PRIVATE/'commission-exchange-failure-20261112.private.json',logs)
        except Exception as capture_error:report['failure_capture_error']=type(capture_error).__name__
        try:rollback(base,plan,report,saved)
        except Exception as recovery_error:report.update(phase='recovery_needs_review',recovery_failure=type(recovery_error).__name__)
    write_private(STATE,report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':sys.exit(main())
