"""Install accepted private onboarding file storage and guarded receipt commits only in the existing isolated Quanta.

Pins complete source/assets, the fresh restored backup, service identities and
settings. Never restores a dump over business data. Rollback refuses later writes.
"""
import ast,fcntl,hashlib,json,os,re,subprocess,sys,tarfile,time,uuid
from datetime import datetime,timezone
from pathlib import Path
import urllib.request

ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private';APP=ROOT/'quanta'
PROJECT='core-provider-pilot-20261029';WEB=PROJECT+'-quanta-pilot-web-1';MONGO=PROJECT+'-quanta-pilot-mongo-1'
DATABASE='corepilot_quanta_20261029';GUARD='coreflux_commit_fences';GUARD_ID='core-time-writers-v1'
REVISION='6b48c0d87e3349e4ff9ba4b28c9722c16cea15dc'
sha=lambda b:hashlib.sha256(b).hexdigest()
receipt=None;snapshot_source=''


def verify_preservation(before,after,old_guard,new_guard,*,rollback=False):
    assert set(old_guard)=={'_id','revision'} and old_guard['_id']==GUARD_ID
    assert type(old_guard['revision']) is int and old_guard['revision']>=1
    allowed={old_guard['revision']+1}
    if rollback:allowed.add(old_guard['revision'])
    assert set(new_guard)=={'_id','revision'} and new_guard['_id']==GUARD_ID
    assert type(new_guard['revision']) is int and new_guard['revision'] in allowed
    assert set(before)==set(after),'Read-only source update must not change schema'
    assert before[GUARD]['count']==after[GUARD]['count']==1
    assert before[GUARD]['indexes']==after[GUARD]['indexes'] and before[GUARD]['options']==after[GUARD]['options']
    for name,value in before.items():
        if name!=GUARD:assert after[name]==value,'Original data or indexes changed: '+name
    return []


def destination(name):
    assert re.fullmatch(r'backend/(?:server\.py|app/(?:[a-zA-Z0-9_]+/)*[a-zA-Z0-9_]+\.py)|frontend/(?:index\.html|asset-manifest\.json|static/(?:js|css)/[a-zA-Z0-9_.-]+)',name)
    path=APP/name
    assert path.resolve()==path and not path.is_symlink()
    return path


def run(*args,input=None,timeout=90):
    r=subprocess.run(args,input=input,text=True,capture_output=True,timeout=timeout)
    if r.returncode:
        if receipt:(receipt/'last-command-error.txt').write_text(r.stderr)
        raise RuntimeError('Isolated recovery install command failed: '+args[0])
    return r.stdout


def inspect(name):return json.loads(run('docker','inspect',name))[0]
def js(code):return json.loads(run('docker','exec',MONGO,'mongosh','--quiet','--eval',code))
def snapshot():return js(snapshot_source.replace('DBNAME',json.dumps(DATABASE)))
def guard_row():return js('print(JSON.stringify(db.getSiblingDB('+json.dumps(DATABASE)+').getCollection('+json.dumps(GUARD)+').findOne({})))')


def runtime():
    base=APP/'backend'
    return {p.relative_to(base).as_posix():sha(p.read_bytes().replace(b'\r\n',b'\n')) for p in [base/'server.py',*(base/'app').rglob('*.py')]}


def frontend():
    base=APP/'frontend';paths=list(base.rglob('*'))
    assert not any(p.is_symlink() for p in paths)
    return {p.relative_to(base).as_posix():sha(p.read_bytes()) for p in paths if p.is_file()}


def others():
    ids=run('docker','ps','-aq').split()
    return {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']]
        for r in json.loads(run('docker','inspect',*ids)) if r['Name']!='/'+WEB}


def state():
    row=inspect(WEB);env=dict(e.split('=',1) for e in row['Config']['Env'])
    assert row['Id']==manifest['web_id']
    assert env['DB_NAME']==DATABASE and env['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
    assert env['QUANTA_STARTUP_MODE']=='web' and env['MAIL_DRIVER']=='devoutbox'
    assert env['DISABLE_BACKGROUND_WORKERS']=='1' and env['DISABLE_SCHEDULER']=='1'
    assert env.get('COREFLUX_TIME_COMMITS')=='1' and env.get('COREFLUX_TIME_ACCESS_TENANTS')=='4f93d4e7-af74-4765-b812-3464bb6d974b'
    assert row['Config']['Labels']['com.docker.compose.project']==PROJECT
    assert any(m['Source']==str(APP/'backend') and m['Destination']=='/app/backend' and not m['RW'] for m in row['Mounts'])
    assert sha((ROOT/'compose.yaml').read_bytes())==manifest['compose_sha256']
    assert sha((ROOT/'compose-https.yaml').read_bytes())==manifest['compose_https_sha256']
    return row


def write(path,body,stat=None):
    path.parent.mkdir(exist_ok=True,parents=True,mode=0o755)
    tmp=path.with_name(path.name+'.onboarding-gridfs-'+uuid.uuid4().hex)
    with tmp.open('xb') as f:f.write(body);f.flush();os.fsync(f.fileno())
    os.chmod(tmp,stat.st_mode&0o7777 if stat else 0o644)
    if stat:os.chown(tmp,stat.st_uid,stat.st_gid)
    os.replace(tmp,path)


def fetch(path):
    assert path.startswith('/') and not path.startswith('//')
    with urllib.request.urlopen('http://127.0.0.1:19181'+path,timeout=8) as response:return response.read()


def health():
    for attempt in range(30):
        try:
            value=json.loads(fetch('/api/health'))
            assert value['ok'] and value['startup_mode']=='web' and not value['usage_recording']
            assert not value['tenant_automations']['running'] and value['tenant_automations']['job_count']==0
            return value
        except (OSError,AssertionError):
            if attempt==29:raise
            time.sleep(1)


PROBE="import asyncio,json,socket\nattempts=[]\noriginal=socket.socket.connect\nallowed={info[4] for info in socket.getaddrinfo('quanta-pilot-mongo',27017,type=socket.SOCK_STREAM)}\ndef bounded(sock,address):\n if address not in allowed:\n  attempts.append(str(address));raise RuntimeError('External network unavailable for private storage verification')\n return original(sock,address)\nsocket.socket.connect=bounded\nfrom app.db import get_db\nfrom app.coreflux_commit import private_blob_database,application_snapshot\nfrom motor.motor_asyncio import AsyncIOMotorGridFSBucket,AsyncIOMotorDatabase\nasync def main():\n db=get_db();raw=await private_blob_database(db)\n assert isinstance(raw,AsyncIOMotorDatabase)\n bucket=AsyncIOMotorGridFSBucket(raw,bucket_name='coreflux_onboarding_files')\n assert bucket is not None\n async def inside(bound):\n  try:await private_blob_database(bound)\n  except RuntimeError:pass\n  else:raise AssertionError('Blob IO accepted an active transaction')\n await application_snapshot(db,inside)\n assert not attempts\n print(json.dumps({'passed':True,'checks':['native_gridfs_constructor','active_snapshot_refusal'],\n  'database_writes':False,'temporary_database_created':False,'external_network_attempts':len(attempts)}))\nasyncio.run(main())\n"


def main():
    global receipt,manifest,snapshot_source
    assert len(sys.argv)==3 and sys.argv[1]=='--install-isolated-onboarding-gridfs' and re.fullmatch('[a-f0-9]{64}',sys.argv[2])
    assert ROOT.resolve()==ROOT and PRIVATE.resolve()==PRIVATE and APP.resolve()==APP and not PRIVATE.stat().st_mode&0o077
    os.umask(0o077)
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    report={'source_commit':REVISION,'passed':False,'started_at':datetime.now(timezone.utc).isoformat(),
        'coordination_enabled':True,'operational_time_enabled':True,'production_changed':False,'arabella_changed':False,
        'financial_execution':False,'settings_changed':False,'database_restored_over':False}
    stopped=False;changed=False;previous={}
    try:
        archive_path=PRIVATE/('quanta-onboarding-gridfs-'+REVISION[:8]+'.tgz')
        assert not archive_path.is_symlink() and sha(archive_path.read_bytes())==sys.argv[2]
        with tarfile.open(archive_path,'r:gz') as archive:
            members=archive.getmembers()
            assert len(members)<30 and sum(m.size for m in members)<40_000_000
            assert all(m.isfile() for m in members) and len({m.name for m in members})==len(members)
            package={m.name:archive.extractfile(m).read() for m in members}
        manifest=json.loads(package.pop('manifest.json'))
        assert manifest['revision']==REVISION and manifest['accepted_native_ci']==43304801568
        assert set(package)==set(manifest['files'])=={'backend/app/coreflux_commit.py','backend/app/coreflux_documents.py','backend/app/coreflux_onboarding.py'}
        assert manifest['frontend_shell_preserved'] is True and manifest['accepted_frontend']==manifest['previous_frontend']
        assert runtime()==manifest['previous_runtime'] and frontend()==manifest['previous_frontend']
        web=state();assert web['State']['Running'] and web['State']['StartedAt']==manifest['web_started_at']
        mongo=inspect(MONGO);assert mongo['Id']==manifest['mongo_id'] and mongo['State']['Running']
        hello=js('print(JSON.stringify(db.hello()))')
        assert hello['setName']=='corepilotquanta' and hello['isWritablePrimary'] and hello['hosts']==['quanta-pilot-mongo:27017']
        helper=Path(manifest['backup_helper']);assert helper.parent==PRIVATE and helper.resolve()==helper
        assert sha(helper.read_bytes())==manifest['backup_helper_sha256']
        snapshot_source=next(ast.literal_eval(n.value) for n in ast.parse(helper.read_text()).body if isinstance(n,ast.Assign)
            and any(getattr(t,'id',None)=='SNAPSHOT' for t in n.targets))
        backup=manifest['backup'];directory=Path(backup['private_backup'])
        assert directory.parent==PRIVATE and directory.resolve()==directory and backup['passed'] and backup['restore_verified']
        assert backup['original_business_records_and_indexes_preserved'] and backup['coordinator_guard']['startup_only']
        assert sha((directory/'quanta.archive.gz').read_bytes())==backup['archive_sha256']
        assert json.loads((directory/'restored.json').read_text())==json.loads((directory/'before.json').read_text())['database']
        assert snapshot()==manifest['database'] and guard_row()==manifest['guard'] and others()==manifest['other_services']
        for name,body in package.items():
            path=destination(name);assert sha(body)==manifest['files'][name]
            if name.startswith('backend/'):compile(body,name,'exec')
            previous[name]=(path.read_bytes(),path.stat()) if path.is_file() else (None,None)
        receipt=PRIVATE/('quanta-onboarding-gridfs-before-'+uuid.uuid4().hex);receipt.mkdir(mode=0o700)
        (receipt/'manifest.json').write_text(json.dumps(manifest,indent=2))
        for name,(body,stat) in previous.items():
            if body is not None:
                saved=receipt/name;saved.parent.mkdir(exist_ok=True,parents=True);saved.write_bytes(body)
        report['private_receipt_directory']=str(receipt)
        stopped=True;run('docker','stop','--time','20',WEB,timeout=45)
        assert not state()['State']['Running'] and snapshot()==manifest['database']
        changed=True
        for name,body in package.items():
            if name not in {'frontend/index.html','frontend/asset-manifest.json'}:write(destination(name),body,previous[name][1])
        assert runtime()==manifest['accepted_runtime']
        run('docker','start',WEB);report['health']=health();stopped=False
        after=snapshot();guard=guard_row();added=verify_preservation(manifest['database'],after,manifest['guard'],guard)
        for name in ['frontend/asset-manifest.json','frontend/index.html']:
            if name in package:write(destination(name),package[name],previous[name][1])
        assert frontend()==manifest['accepted_frontend']
        assert sha(fetch('/login'))==manifest['accepted_frontend']['index.html']
        for path in manifest['entrypoints']:assert sha(fetch(path))==manifest['accepted_frontend'][path.lstrip('/')]
        probe=json.loads(run('docker','exec','-i',WEB,'python','-',input=PROBE,timeout=90))
        assert probe['passed'] and not probe['database_writes'] and not probe['temporary_database_created'] and probe['external_network_attempts']==0
        assert snapshot()==after and guard_row()==guard
        assert runtime()==manifest['accepted_runtime'] and frontend()==manifest['accepted_frontend']
        assert others()==manifest['other_services'] and state()['State']['Running']
        (receipt/'after.json').write_text(json.dumps(after,indent=2))
        report.update(passed=True,hosted_probe=probe,runtime_files_verified=len(manifest['accepted_runtime']),
            frontend_files_verified=len(manifest['accepted_frontend']),installed_file_count=len(package),
            original_business_records_preserved=sum(v['count'] for n,v in manifest['database'].items() if n!=GUARD),
            original_time_entries=after['time_entries']['count'],new_empty_collections=added,coordinator_guard={'before':manifest['guard'],'after':guard},
            other_services_unchanged=True,frontend_shell_preserved=True,served_assets_verified=True,
            target_container_id=web['Id'],target_started_at=state()['State']['StartedAt'])
    except Exception as exc:
        report.update(failure_class=type(exc).__name__,failure_message=str(exc))
        if changed:
            try:
                stopped=True;run('docker','stop','--time','20',WEB,timeout=45)
                verify_preservation(manifest['database'],snapshot(),manifest['guard'],guard_row(),rollback=True)
                for name,(body,stat) in previous.items():
                    path=destination(name)
                    if body is not None:write(path,body,stat)
                    elif path.is_file():
                        assert sha(path.read_bytes())==manifest['files'][name];path.unlink()
                assert runtime()==manifest['previous_runtime'] and frontend()==manifest['previous_frontend']
                report['previous_runtime_restored']=True
            except Exception as recovery:
                report['recovery_failure']=type(recovery).__name__;report['web_held_for_review']=True;stopped=False
    finally:
        if stopped:
            try:run('docker','start',WEB);health();report['web_recovered']=True
            except Exception as recovery:report['web_recovery_failure']=type(recovery).__name__
        report['completed_at']=datetime.now(timezone.utc).isoformat()
        if receipt:(receipt/'result.json').write_text(json.dumps(report,indent=2))
        print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__=='__main__':raise SystemExit(main())
