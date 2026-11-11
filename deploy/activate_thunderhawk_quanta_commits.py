"""Enable coordinated commits only in the installed, isolated Quanta pilot.

Operational tenant time access remains disabled. Recovery may remove only the
new startup guard while every original record/index is unchanged and no later
coordinated write has occurred. Never restores an old dump over business data.
"""
import ast, copy, hashlib, json, os, re, subprocess, sys, time, uuid
from datetime import datetime, timezone
from pathlib import Path
import urllib.request

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
PROJECT = 'core-provider-pilot-20261029'
WEB = PROJECT + '-quanta-pilot-web-1'
MONGO = PROJECT + '-quanta-pilot-mongo-1'
DATABASE = 'corepilot_quanta_20261029'
GUARD = 'coreflux_commit_fences'
GUARD_ID = 'core-time-writers-v1'
SOURCE = '22b8170cfe57052b2b06e61110cdc7555b5ed947'
OLD_COMPOSE_SHA = 'f13b3e0ab776946d165e96102ac806236aedc1f1d1b0e0e855b94e2b56b17274'
NEW_COMPOSE_SHA = '85b40cd6c8bb857f3927d82539e4cff41239beb1dff97211211c25d4c276aad3'
CANDIDATE = PRIVATE / 'quanta-coordination-config-preflight-b426af136bf540c794ea6857c5bd39da.yaml'
BACKUP_HELPER = PRIVATE / 'quanta-storage-backup-91c441ba02c0.py'
BACKUP_HELPER_SHA = '91c441ba02c0c61c854fdfa00d06225d9de34bfa929a85c68ca1d3572f67af09'
RUNTIME_MANIFEST = PRIVATE / 'quanta-commit-runtime-before-f2989f8d09084c1da84e0b998971ab98/manifest.json'
sha = lambda body: hashlib.sha256(body).hexdigest()
receipt = None
snapshot_source = ''


def verify_preservation(before, after, guard, rollback=False):
    assert GUARD not in before, 'A preexisting coordinator needs a different change plan'
    assert set(after) - set(before) <= {GUARD}, 'Unexpected new collection'
    for name, value in before.items():
        assert after.get(name) == value, 'Existing collection changed: ' + name
    if GUARD not in after:
        assert rollback and guard is None
        return
    assert after[GUARD]['count'] == 1 and len(after[GUARD]['indexes']) == 1
    assert after[GUARD]['indexes'][0]['name'] == '_id_'
    assert guard == {'_id': GUARD_ID, 'revision': 1} or (rollback and guard == {'_id': GUARD_ID, 'revision': 0}), 'Later writes prohibit removing the coordinator'


def run(*args, input=None, timeout=90):
    result = subprocess.run(args, input=input, text=True, capture_output=True, timeout=timeout)
    if result.returncode:
        if receipt: (receipt / 'last-command-error.txt').write_text(result.stderr)
        raise RuntimeError('Isolated coordinator command failed: ' + args[0])
    return result.stdout


def inspect(name): return json.loads(run('docker', 'inspect', name))[0]


def js(source): return json.loads(run('docker', 'exec', MONGO, 'mongosh', '--quiet', '--eval', source))


def snapshot(): return js(snapshot_source.replace('DBNAME', json.dumps(DATABASE)))


def guard_row():
    return js('print(JSON.stringify(db.getSiblingDB(' + json.dumps(DATABASE) + ').getCollection(' + json.dumps(GUARD) + ').findOne({_id:' + json.dumps(GUARD_ID) + '})))')


def runtime_hashes():
    base = ROOT / 'quanta/backend'
    return {p.relative_to(base).as_posix(): sha(p.read_bytes()) for p in [base / 'server.py', *(base / 'app').rglob('*.py')]}


def others():
    ids = run('docker', 'ps', '-aq').split()
    return {r['Name']: [r['Id'], r['State']['Status'], r['State']['StartedAt']]
            for r in json.loads(run('docker', 'inspect', *ids)) if r['Name'] != '/' + WEB}


def compose(path=None):
    return ['docker', 'compose', '--project-directory', str(ROOT), '-p', PROJECT,
            '-f', str(path or ROOT / 'compose.yaml'), '-f', str(ROOT / 'compose-https.yaml')]


def config(path=None): return json.loads(run(*compose(path), 'config', '--format', 'json'))


def state(enabled):
    row = inspect(WEB); env = dict(e.split('=', 1) for e in row['Config']['Env'])
    assert env['DB_NAME'] == DATABASE and env['MONGO_URL'] == 'mongodb://quanta-pilot-mongo:27017'
    assert env['QUANTA_STARTUP_MODE'] == 'web' and env['MAIL_DRIVER'] == 'devoutbox'
    assert env['DISABLE_BACKGROUND_WORKERS'] == '1' and env['DISABLE_SCHEDULER'] == '1'
    assert env.get('COREFLUX_TIME_COMMITS', '0') == str(int(enabled))
    assert not env.get('COREFLUX_TIME_ACCESS_TENANTS', '').strip()
    assert row['Config']['Labels']['com.docker.compose.project'] == PROJECT
    assert row['Config']['Labels']['com.docker.compose.service'] == 'quanta-pilot-web'
    return row


def same_service(before, after):
    assert after['Image'] == before['Image']
    for key in ['Cmd', 'Entrypoint', 'Hostname', 'User', 'WorkingDir']:
        assert after['Config'][key] == before['Config'][key], key
    for key in ['Memory', 'MemorySwap', 'PortBindings', 'RestartPolicy', 'Privileged']:
        assert after['HostConfig'][key] == before['HostConfig'][key], key
    mounts = lambda r: {m['Destination']: [m['Type'], m['Source'], m['RW']] for m in r['Mounts']}
    assert mounts(after) == mounts(before)
    assert set(after['NetworkSettings']['Networks']) == set(before['NetworkSettings']['Networks'])
    a = dict(e.split('=', 1) for e in before['Config']['Env'])
    b = dict(e.split('=', 1) for e in after['Config']['Env'])
    a.pop('COREFLUX_TIME_COMMITS', None); b.pop('COREFLUX_TIME_COMMITS', None)
    assert a == b, 'Another web setting changed'


def health():
    for attempt in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19181/api/health', timeout=4) as response: data = json.load(response)
            assert data['ok'] and data['startup_mode'] == 'web'
            assert not data['usage_recording'] and not data['tenant_automations']['running'] and data['tenant_automations']['job_count'] == 0
            return data
        except (OSError, AssertionError):
            if attempt == 29: raise
            time.sleep(1)


def atomic_compose(content, expected):
    path = ROOT / 'compose.yaml'; stat = path.stat()
    assert sha(path.read_bytes()) == expected
    temp = ROOT / ('.compose-commits-' + uuid.uuid4().hex + '.tmp')
    with temp.open('x') as stream:
        stream.write(content); stream.flush(); os.fsync(stream.fileno())
    os.chown(temp, stat.st_uid, stat.st_gid); os.chmod(temp, stat.st_mode & 0o777)
    os.replace(temp, path)


PROBE = r'''
import asyncio,json,os,re,socket
from motor.motor_asyncio import AsyncIOMotorClient
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['COREFLUX_TIME_COMMITS']=='1'
allowed={x[4][:2] for x in socket.getaddrinfo('quanta-pilot-mongo',27017,type=socket.SOCK_STREAM)}
attempts=[]
connect=socket.socket.connect;connect_ex=socket.socket.connect_ex
def checked(fn,sock,address):
    if address not in allowed:
        attempts.append(True);raise RuntimeError('Hosted probe outbound connection blocked')
    return fn(sock,address)
socket.socket.connect=lambda s,a:checked(connect,s,a)
socket.socket.connect_ex=lambda s,a:checked(connect_ex,s,a)
from app.coreflux_commit import ApplicationDatabase,application_commit,validate_application_commit_mode
async def main():
    client=AsyncIOMotorClient(os.environ['MONGO_URL'],serverSelectionTimeoutMS=10000)
    name=PROBE_NAME
    assert re.fullmatch('corepilot_coordinationcheck_[a-f0-9]{24}',name)
    assert name not in await client.list_database_names()
    raw=client[name];db=ApplicationDatabase(raw)
    try:
        await raw.create_collection('first');await raw.create_collection('second');await raw.create_collection('time')
        await validate_application_commit_mode(db)
        async def pair(b):
            await b.first.insert_one({'_id':'committed','value':1})
            await b.second.insert_one({'_id':'committed','value':2})
        await application_commit(db,pair)
        assert await raw.first.count_documents({})==1 and await raw.second.count_documents({})==1
        fence_before=await raw.coreflux_commit_fences.find_one({})
        async def fail(b):
            await b.first.update_one({'_id':'committed'},{'$set':{'value':99}})
            await b.second.delete_one({'_id':'committed'})
            raise ValueError('synthetic abort')
        try:await application_commit(db,fail)
        except ValueError as e:assert str(e)=='synthetic abort'
        else:raise AssertionError('Failed operation committed')
        assert (await raw.first.find_one({'_id':'committed'}))['value']==1
        assert (await raw.second.find_one({'_id':'committed'}))['value']==2
        assert await raw.coreflux_commit_fences.find_one({})==fence_before
        await db.first.insert_one({'_id':'assignment','active':True})
        entered=asyncio.Event();release=asyncio.Event();contender_started=asyncio.Event();contender_inside=asyncio.Event()
        async def close(b):
            assert (await b.first.find_one({'_id':'assignment'}))['active']
            entered.set();await release.wait()
            await b.first.update_one({'_id':'assignment'},{'$set':{'active':False}})
        async def capture(b):
            contender_inside.set()
            if not (await b.first.find_one({'_id':'assignment'}))['active']:raise ValueError('closed assignment')
            await b.time.insert_one({'_id':'must-not-exist'})
        async def contender():
            contender_started.set()
            try:await application_commit(db,capture)
            except ValueError as e:assert str(e)=='closed assignment';return 'refused'
            raise AssertionError('Stale eligibility accepted')
        first=asyncio.create_task(application_commit(db,close))
        await asyncio.wait_for(entered.wait(),5)
        second=asyncio.create_task(contender());await contender_started.wait()
        try:
            await asyncio.wait_for(contender_inside.wait(),0.2)
            raise AssertionError('Contender read while earlier write was held')
        except asyncio.TimeoutError:pass
        finally:release.set()
        await first;assert await second=='refused'
        assert await raw.time.count_documents({})==0
        fence_before=await raw.coreflux_commit_fences.find_one({})
        os.environ['COREFLUX_TIME_COMMITS']='0'
        try:
            try:await validate_application_commit_mode(db)
            except RuntimeError as e:assert 'requires coordinated writes' in str(e)
            else:raise AssertionError('Lost coordination setting accepted')
        finally:os.environ['COREFLUX_TIME_COMMITS']='1'
        assert await raw.coreflux_commit_fences.find_one({})==fence_before
        assert not attempts
        result={'commit_preserved_both_writes':True,'abort_preserved_both_records_and_guard':True,
                'contender_waited_for_prior_commit':True,'stale_eligibility_refused':True,
                'lost_mode_refused':True,'external_network_attempts':len(attempts)}
    finally:
        await client.drop_database(name)
    assert name not in await client.list_database_names()
    result['temporary_database_removed']=True
    client.close();print(json.dumps(result))
asyncio.run(main())
'''


def main():
    global receipt, snapshot_source
    import fcntl
    assert len(sys.argv) == 3 and sys.argv[1] == '--activate-isolated-commits' and re.fullmatch('[a-f0-9]{64}', sys.argv[2])
    assert ROOT.resolve() == ROOT and PRIVATE.resolve() == PRIVATE and not PRIVATE.stat().st_mode & 0o077
    os.umask(0o077)
    lock = (PRIVATE / 'assignment-lifecycle-install.lock').open('a'); fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    backup_result = json.loads((PRIVATE / 'quanta-storage-backup-result.json').read_text())
    backup = Path(backup_result['private_backup'])
    assert backup.resolve() == backup and backup.parent == PRIVATE and backup.name.startswith('quanta-storage-backup-')
    assert backup_result['passed'] and backup_result['storage_mode'] == 'replica_set'
    assert backup_result['archive_sha256'] == sys.argv[2] == sha((backup / 'quanta.archive.gz').read_bytes())
    before = json.loads((backup / 'before.json').read_text())
    assert before['database'] == json.loads((backup / 'restored.json').read_text())
    assert sha(BACKUP_HELPER.read_bytes()) == BACKUP_HELPER_SHA
    parsed = ast.parse(BACKUP_HELPER.read_text())
    snapshot_source = next(ast.literal_eval(n.value) for n in parsed.body if isinstance(n, ast.Assign)
                           and any(getattr(t, 'id', None) == 'SNAPSHOT' for t in n.targets))
    assert snapshot() == before['database'] and GUARD not in before['database']
    assert runtime_hashes() == before['runtime_hashes']
    accepted = json.loads(RUNTIME_MANIFEST.read_text()); assert accepted['revision'] == SOURCE
    base = ROOT / 'quanta/backend'
    assert {name: sha((base / name).read_bytes().replace(b'\r\n', b'\n')) for name in before['runtime_hashes']} == accepted['accepted_runtime']
    old = (ROOT / 'compose.yaml').read_text(); new = CANDIDATE.read_text()
    assert sha(old.encode()) == OLD_COMPOSE_SHA and sha(new.encode()) == NEW_COMPOSE_SHA
    original_config = config(); expected = copy.deepcopy(original_config)
    expected['services']['quanta-pilot-web']['environment']['COREFLUX_TIME_COMMITS'] = '1'
    assert config(CANDIDATE) == expected
    web = state(False); assert web['State']['Running']
    assert web['Id'] == backup_result['web_after']['id'] and web['State']['StartedAt'] == backup_result['web_after']['started_at']
    mongo = inspect(MONGO); assert mongo['Id'] == backup_result['mongo_container_id'] and mongo['State']['Running']
    hello = js('print(JSON.stringify(db.hello()))'); assert hello['setName'] == 'corepilotquanta' and hello['isWritablePrimary']
    protected = others()
    receipt = PRIVATE / ('quanta-commits-activation-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid.uuid4().hex[:8])
    receipt.mkdir(mode=0o700)
    (receipt / 'compose.original.yaml').write_text(old)
    (receipt / 'before.json').write_text(json.dumps({'database': before['database'], 'runtime_hashes': before['runtime_hashes'], 'other_services': protected}, indent=2))
    report = {'source_commit': SOURCE, 'started_at': datetime.now(timezone.utc).isoformat(), 'passed': False,
              'coordination_enabled': False, 'operational_time_enabled': False, 'production_changed': False,
              'arabella_changed': False, 'financial_execution': False, 'private_receipt_directory': str(receipt),
              'backup_directory': str(backup), 'backup_sha256': sys.argv[2], 'original_web_id': web['Id']}
    stopped = False; changed = False; probe_owned = False
    probe_name = 'corepilot_coordinationcheck_' + uuid.uuid4().hex[:24]

    def stage(name):
        report['stage'] = name; (receipt / 'progress.json').write_text(json.dumps(report, indent=2))

    try:
        stage('drain_only_quanta_web')
        stopped = True; run('docker', 'stop', '--time', '20', WEB, timeout=45)
        assert not state(False)['State']['Running'] and snapshot() == before['database']
        stage('activate_coordinator_setting')
        changed = True
        atomic_compose(new, OLD_COMPOSE_SHA)
        run(*compose(), 'up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'quanta-pilot-web', timeout=120)
        report['health'] = health(); stopped = False
        after_web = state(True); same_service(web, after_web)
        verify_preservation(before['database'], snapshot(), guard_row())
        stage('verify_installed_coordinator')
        names = js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))')
        assert probe_name not in names; probe_owned = True
        report['hosted_coordinator_probe'] = json.loads(run('docker', 'exec', '-i', '-w', '/app/backend', WEB, 'python', '-',
            input=PROBE.replace('PROBE_NAME', repr(probe_name)), timeout=80))
        after = snapshot(); verify_preservation(before['database'], after, guard_row())
        assert runtime_hashes() == before['runtime_hashes'] and others() == protected
        assert config() == expected and state(True)['State']['Running']
        (receipt / 'after.json').write_text(json.dumps(after, indent=2))
        report.update(passed=True, coordination_enabled=True, original_records_and_indexes_preserved=True,
                      original_records=sum(v['count'] for v in before['database'].values()), original_collections=len(before['database']),
                      original_time_entries=before['database']['time_entries']['count'], runtime_files_preserved=len(before['runtime_hashes']),
                      startup_guard_revision=guard_row()['revision'], other_services_unchanged=True,
                      final_web_id=after_web['Id'], final_web_started_at=after_web['State']['StartedAt'], compose_sha256=NEW_COMPOSE_SHA)
        stage('verified')
    except Exception as exc:
        report.update(failure_class=type(exc).__name__, failure_message=str(exc))
        if changed:
            try:
                stage('recover_only_unused_startup_activation')
                stopped = True; run('docker', 'stop', '--time', '20', WEB, timeout=45)
                after = snapshot(); row = guard_row()
                verify_preservation(before['database'], after, row, rollback=True)
                if GUARD in after:
                    assert js('print(JSON.stringify(db.getSiblingDB(' + json.dumps(DATABASE) + ').getCollection(' + json.dumps(GUARD) + ').drop()))') is True
                current_hash = sha((ROOT / 'compose.yaml').read_bytes()); assert current_hash in [OLD_COMPOSE_SHA, NEW_COMPOSE_SHA]
                atomic_compose(old, current_hash)
                run(*compose(), 'up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'quanta-pilot-web', timeout=120)
                health(); stopped = False
                assert snapshot() == before['database'] and config() == original_config
                same_service(web, state(False)); report['unused_activation_rolled_back'] = True
            except Exception as recovery:
                report['activation_recovery_failure'] = type(recovery).__name__
                # If later writes exist, retain the guard and enabled configuration.
                # Do not restore the old mode or overwrite the source database.
    finally:
        if probe_owned:
            try:
                assert re.fullmatch('corepilot_coordinationcheck_[a-f0-9]{24}', probe_name)
                names = js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))')
                if probe_name in names: js('print(JSON.stringify(db.getSiblingDB(' + json.dumps(probe_name) + ').dropDatabase()))')
                names = js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))')
                report['temporary_probe_removed'] = probe_name not in names
            except Exception as cleanup: report['probe_cleanup_failure'] = type(cleanup).__name__
        if stopped and not changed:
            try: run('docker', 'start', WEB); health(); report['web_recovered'] = True
            except Exception as recovery: report['web_recovery_failure'] = type(recovery).__name__
        report['passed'] = bool(report['passed'] and report.get('temporary_probe_removed') and not any(k.endswith('_failure') for k in report))
        report['completed_at'] = datetime.now(timezone.utc).isoformat()
        (receipt / 'result.json').write_text(json.dumps(report, indent=2)); print(json.dumps(report))
    if not report['passed']: raise SystemExit(1)


if __name__ == '__main__': main()
