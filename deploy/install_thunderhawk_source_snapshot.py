"""Install accepted source snapshot reads only in the existing isolated Quanta.

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
REVISION='dd5d9d15a8a59d08fb7031c90bf6236d71e7c8d6'
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
    assert env.get('COREFLUX_TIME_COMMITS')=='1' and not env.get('COREFLUX_TIME_ACCESS_TENANTS','').strip()
    assert row['Config']['Labels']['com.docker.compose.project']==PROJECT
    assert any(m['Source']==str(APP/'backend') and m['Destination']=='/app/backend' and not m['RW'] for m in row['Mounts'])
    assert sha((ROOT/'compose.yaml').read_bytes())==manifest['compose_sha256']
    assert sha((ROOT/'compose-https.yaml').read_bytes())==manifest['compose_https_sha256']
    return row


def write(path,body,stat=None):
    path.parent.mkdir(exist_ok=True,parents=True,mode=0o755)
    tmp=path.with_name(path.name+'.source-snapshot-'+uuid.uuid4().hex)
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


PROBE="import asyncio,json,os,socket,uuid\nfrom types import SimpleNamespace\nfrom motor.motor_asyncio import AsyncIOMotorClient\n\nmongo_ip=socket.gethostbyname('quanta-pilot-mongo')\noriginal_connect=socket.socket.connect\nattempts=[]\ndef guarded_connect(sock,address):\n    if isinstance(address,tuple) and address[0]==mongo_ip and address[1]==27017:\n        return original_connect(sock,address)\n    attempts.append(str(address))\n    raise RuntimeError('External network is disabled for the source snapshot probe')\nsocket.socket.connect=guarded_connect\ndatabase='corepilot_snapshotcheck_'+uuid.uuid4().hex[:24]\nos.environ['DB_NAME']=database\nfrom app import db as db_mod,coreflux_time_reviews as reviews\nfrom app.coreflux_commit import application_commit\nfrom app.routers.v1 import time_entries as route\nfrom app.models import ApiKey\nfrom app.webhook_signing import hash_api_key\nfrom server import app\nfrom httpx import ASGITransport,AsyncClient\n\nasync def main():\n    client=AsyncIOMotorClient('mongodb://quanta-pilot-mongo:27017',serverSelectionTimeoutMS=5000)\n    assert database not in await client.list_database_names()\n    db=client[database];db_mod.set_db(db);owned=False;result={}\n    tenant='synthetic-snapshot';worker='snapshot-worker';site='snapshot-site';entry='snapshot-entry';sheet='snapshot-sheet'\n    aid='cfa_'+'a'*64;link='snapshot-person-link';instance=str(uuid.uuid4())\n    ref=lambda kind,number:{'instance_id':instance,'directory_tenant_id':999,'entity_type':kind,'number':number}\n    assignment={'_id':aid,'id':aid,'tenant_id':tenant,'worker_id':worker,'person_link_id':link,\n        'person':ref('person','P-1'),'placement':ref('placement','PL-1'),'worksite_id':site,\n        'start_date':'2026-10-01','end_date':None,'status':'active','version':1,'history':[]}\n    try:\n        owned=True\n        await db.tenants.insert_one({'id':tenant,'name':'Snapshot fixture','status':'active','timezone':'UTC'})\n        await db.users.insert_one({'id':worker,'tenant_id':tenant,'email':'snapshot@example.invalid',\n            'full_name':'Snapshot fixture worker','status':'active','roles':['worker']})\n        await db.worksites.insert_one({'id':site,'tenant_id':tenant,'name':'Snapshot fixture site','active':True})\n        await db.coreflux_person_links.insert_one({'_id':link,'id':link,'tenant_id':tenant,'worker_id':worker,'person':assignment['person']})\n        await db.coreflux_assignments.insert_one(assignment)\n        await db.time_entries.insert_one({'id':entry,'tenant_id':tenant,'user_id':worker,'worksite_id':site,\n            'entry_mode':'duration','work_date':'2026-10-07','status':'closed','duration_minutes':60,'raw_minutes':60,\n            'dimension_values':{},'created_at':'2026-10-10T12:00:00+00:00','updated_at':'2026-10-10T12:00:00+00:00'})\n        await db.timesheets.insert_one({'id':sheet,'tenant_id':tenant,'user_id':worker,\n            'period_start_local':'2026-10-03','period_end_local':'2026-10-09',\n            'period_start_utc':'2026-10-03T00:00:00+00:00','period_end_utc':'2026-10-09T23:59:59.999999+00:00',\n            'status':'approved','approved_at':'2026-10-10T12:00:00+00:00'})\n        key='ck_fixture_snapshot';headers={'Authorization':'Bearer '+key}\n        await db.api_keys.insert_one(ApiKey(id='snapshot-key',tenant_id=tenant,name='Unserved fixture',\n            key_prefix='ck_fixture_',key_hash=hash_api_key(key),scopes=['read:entries','read:timesheets','read:coreflux']).to_mongo())\n        await db_mod.ensure_indexes()\n        context=await reviews.context(db_mod.get_db(),tenant,aid,entry)\n        body={'contract_version':reviews.VERSION,'assignment_id':aid,'assignment_version':1,'source_entry_id':entry,\n            'source_revision':context['source_revision'],'core_tenant_id':999,'core_revision':'a'*64,'expected_version':0,\n            'components':[{'entry_id':1,'component':'regular','status':'approved'}]}\n        async def publish(bound):\n            return await reviews.publish(bound,SimpleNamespace(tenant_id=tenant),body,'snapshot-fixture-review')\n        assert (await application_commit(db_mod.get_db(),publish))[0]==201\n        checked=[]\n        async with AsyncClient(transport=ASGITransport(app=app),base_url='http://fixture.invalid') as http:\n            for surface in ['approved','state','observer','list','detail','context','portal']:\n                async def reset(bound):\n                    await bound.time_entries.update_one({'id':entry},{'$set':{'duration_minutes':60,'raw_minutes':60,'updated_at':'2026-10-10T12:00:00+00:00'}})\n                    await bound.timesheets.update_one({'id':sheet},{'$set':{'approved_at':'2026-10-10T12:00:00+00:00'}})\n                await application_commit(db_mod.get_db(),reset)\n                selected=asyncio.Event();release=asyncio.Event();calls=0\n                module=reviews if surface in {'context','portal'} else route\n                hook='approved_source' if module is reviews else '_partner_enrich' if surface in {'approved','state','observer'} else '_resolve_timesheet_meta_for_entries'\n                original=getattr(module,hook)\n                async def pause(*args,**kwargs):\n                    nonlocal calls\n                    calls+=1\n                    if calls==1:\n                        selected.set();await release.wait()\n                    return await original(*args,**kwargs)\n                setattr(module,hook,pause)\n                async def read():\n                    if surface=='observer':\n                        t=await db.tenants.find_one({'id':tenant})\n                        return (await route.observe_approved_state(db_mod.get_db(),t,[entry]))[0]['entry']\n                    if surface=='portal':return (await reviews.portal_page(db_mod.get_db(),tenant,assignment))['items'][0]\n                    path='/api/v1/time-entries';params={}\n                    if surface=='approved':path+='/approved'\n                    elif surface=='state':path+='/approved-state';params={'entry_ids':entry,'request_id':'a'*32}\n                    elif surface=='detail':path+='/'+entry\n                    elif surface=='context':path='/api/v1/integrations/coreflux/time-review-context';params={'assignment_id':aid,'source_entry_id':entry}\n                    response=await http.get(path,params=params,headers=headers)\n                    assert response.status_code==200,(surface,response.status_code,response.text)\n                    value=response.json()\n                    if surface=='approved':return value['data'][0]\n                    if surface=='state':return value['data'][0]['entry']\n                    if surface=='list':return value['items'][0]\n                    if surface=='context':return value['source_entry']\n                    return value\n                pending=asyncio.create_task(read())\n                try:\n                    await asyncio.wait_for(selected.wait(),5)\n                    async def correct(bound):\n                        await bound.time_entries.update_one({'id':entry},{'$set':{'duration_minutes':120,'raw_minutes':120,'updated_at':'2026-10-11T12:00:00+00:00'}})\n                        await bound.timesheets.update_one({'id':sheet},{'$set':{'approved_at':'2026-10-11T12:00:00+00:00'}})\n                    await asyncio.wait_for(application_commit(db_mod.get_db(),correct),5)\n                    release.set();old=await asyncio.wait_for(pending,5);new=await read()\n                    if surface=='portal':\n                        assert old['current'] is True and old['minutes']==60\n                        assert new['current'] is False and new['minutes'] is None\n                    else:\n                        assert old['duration_minutes']==60 and old['approved_at']=='2026-10-10T12:00:00Z'\n                        assert new['duration_minutes']==120 and new['approved_at']=='2026-10-11T12:00:00Z'\n                    checked.append(surface)\n                finally:\n                    release.set();pending.cancel();await asyncio.gather(pending,return_exceptions=True)\n                    setattr(module,hook,original)\n        assert len(checked)==7 and not attempts\n        assert await db.time_entries.count_documents({})==1 and await db.timesheets.count_documents({})==1\n        result={'passed':True,'native_database':True,'actual_application_http_surfaces':5,\n            'service_surfaces':2,'concurrent_read_surfaces':checked,'writer_completed_while_reader_paused':True,\n            'old_response_consistent':True,'fresh_response_current':True,'external_network_attempts':0}\n    finally:\n        if owned:\n            assert database.startswith('corepilot_snapshotcheck_') and database!='corepilot_quanta_20261029'\n            await client.drop_database(database)\n            assert database not in await client.list_database_names()\n        client.close()\n    result['temporary_database_removed']=True\n    print(json.dumps(result))\nasyncio.run(main())\n"


def main():
    global receipt,manifest,snapshot_source
    assert len(sys.argv)==3 and sys.argv[1]=='--install-isolated-source-snapshot' and re.fullmatch('[a-f0-9]{64}',sys.argv[2])
    assert ROOT.resolve()==ROOT and PRIVATE.resolve()==PRIVATE and APP.resolve()==APP and not PRIVATE.stat().st_mode&0o077
    os.umask(0o077)
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    report={'source_commit':REVISION,'passed':False,'started_at':datetime.now(timezone.utc).isoformat(),
        'coordination_enabled':True,'operational_time_enabled':False,'production_changed':False,'arabella_changed':False,
        'financial_execution':False,'settings_changed':False,'database_restored_over':False}
    stopped=False;changed=False;previous={}
    try:
        archive_path=PRIVATE/('quanta-source-snapshot-'+REVISION[:8]+'.tgz')
        assert not archive_path.is_symlink() and sha(archive_path.read_bytes())==sys.argv[2]
        with tarfile.open(archive_path,'r:gz') as archive:
            members=archive.getmembers()
            assert len(members)<30 and sum(m.size for m in members)<40_000_000
            assert all(m.isfile() for m in members) and len({m.name for m in members})==len(members)
            package={m.name:archive.extractfile(m).read() for m in members}
        manifest=json.loads(package.pop('manifest.json'))
        assert manifest['revision']==REVISION and manifest['accepted_native_ci']==43057613283
        assert set(package)==set(manifest['files'])=={'backend/app/coreflux_commit.py','backend/app/coreflux_time_reviews.py','backend/app/routers/v1/time_entries.py'}
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
        receipt=PRIVATE/('quanta-source-snapshot-before-'+uuid.uuid4().hex);receipt.mkdir(mode=0o700)
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
        schema=json.loads(fetch('/api/openapi.json'))['paths']
        for surface in ['me','admin']:
            assert 'delete' in schema['/api/'+surface+'/time-entries/pending/{request_id}']
            assert any(p['name']=='idempotency-key' and p['in']=='header' for p in schema['/api/'+surface+'/time-entries']['post']['parameters'])
        after=snapshot();guard=guard_row();added=verify_preservation(manifest['database'],after,manifest['guard'],guard)
        for name in ['frontend/asset-manifest.json','frontend/index.html']:
            if name in package:write(destination(name),package[name],previous[name][1])
        assert frontend()==manifest['accepted_frontend']
        assert sha(fetch('/login'))==manifest['accepted_frontend']['index.html']
        for path in manifest['entrypoints']:assert sha(fetch(path))==manifest['accepted_frontend'][path.lstrip('/')]
        probe=json.loads(run('docker','exec','-i',WEB,'python','-',input=PROBE,timeout=90))
        assert probe['passed'] and probe['temporary_database_removed'] and probe['external_network_attempts']==0
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
                    elif name.startswith('backend/') and path.is_file():
                        assert sha(path.read_bytes())==manifest['files'][name];path.unlink()
                assert runtime()==manifest['previous_runtime']
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
