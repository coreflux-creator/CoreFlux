"""Convert only the existing isolated Quanta Mongo service, with a rollback rehearsal.

The original Compose file is backed up privately, then one service command is
changed in place so future ordinary Compose operations retain the storage mode.
No application source, feature flag, credential or business record is changed.
The logical backup must still match before either inspection or activation.
"""
import ast
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.request
import uuid
from datetime import datetime, timezone

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
PROJECT = 'core-provider-pilot-20261029'
WEB = PROJECT + '-quanta-pilot-web-1'
MONGO = PROJECT + '-quanta-pilot-mongo-1'
DATABASE = 'corepilot_quanta_20261029'
SET_NAME = 'corepilotquanta'
MEMBER = 'quanta-pilot-mongo:27017'
OLD_COMMAND = ['mongod', '--bind_ip_all', '--wiredTigerCacheSizeGB', '0.25']
NEW_COMMAND = OLD_COMMAND + ['--replSet', SET_NAME, '--oplogSize', '128']
COMPOSE_SHA = '6933782e761c7304a8bdbc9c2319bc1d52f86754b58b60612e43dfe53e6ea290'
HTTPS_SHA = '9522935c4ce5c27a9bf6fdbd133fcf8baf9ffcd6ec679bf18902859d8da2ef95'
MONGO_ID = 'bfa0f907c969972a62cbe3466ad01a64504a6bd0729c1f58d7df7764e66e2fa0'
BACKUP = PRIVATE / 'quanta-storage-backup-20261111T074411Z-13b2ea68'
ARCHIVE_SHA = '5bdad2b3c10f634e85e59c4d6557c49c4b6c2cb969a39d7d92d07a6985f01196'
BACKUP_HELPER = PRIVATE / 'quanta-storage-backup-4bcdfa8f1502.py'
HELPER_SHA = '4bcdfa8f1502dc50fa27d3bb5878defc7eb40e1934e81bba62b0732c13bc9c94'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def candidate_compose(original):
    """Refuse ambiguity; change exactly the reviewed service's command line."""
    blocks = list(re.finditer(r'(?ms)^  quanta-pilot-mongo:\n.*?(?=^  [^ ]|^\S|\Z)', original))
    if len(blocks) != 1:
        raise ValueError('Expected exactly one Quanta Mongo service')
    block = blocks[0]
    old = "    command: [mongod, --bind_ip_all, --wiredTigerCacheSizeGB, '0.25']\n"
    if block.group().count(old) != 1:
        raise ValueError('Original Quanta Mongo command differs')
    new = '    command: ' + json.dumps(NEW_COMMAND) + '\n'
    return original[:block.start()] + block.group().replace(old, new) + original[block.end():]


def run(*args, timeout=90, input=None):
    result = subprocess.run(args, input=input, text=True, capture_output=True, timeout=timeout)
    if result.returncode:
        # Docker output/config can contain private values; retain diagnostics on host only.
        if receipt_dir:
            (receipt_dir / 'last-command-error.txt').write_text(result.stderr)
        raise RuntimeError('Isolated storage command failed: ' + args[0])
    return result.stdout


def inspect(name):
    return json.loads(run('docker', 'inspect', name))[0]


def compose(path=None):
    return ['docker', 'compose', '--project-directory', str(ROOT), '-p', PROJECT,
            '-f', str(path or ROOT / 'compose.yaml'), '-f', str(ROOT / 'compose-https.yaml')]


def config(path=None):
    return json.loads(run(*compose(path), 'config', '--format', 'json'))


def js(source):
    return json.loads(run('docker', 'exec', MONGO, 'mongosh', '--quiet', '--eval', source))


def snapshot():
    return js(snapshot_source.replace('DBNAME', json.dumps(DATABASE)))


def other_services():
    ids = run('docker', 'ps', '-aq').split()
    return {r['Name']: [r['Id'], r['State']['Status'], r['State']['StartedAt']]
            for r in json.loads(run('docker', 'inspect', *ids))
            if r['Name'] not in ['/' + WEB, '/' + MONGO]}


def runtime_hashes():
    root = ROOT / 'quanta/backend'
    return {p.relative_to(root).as_posix(): sha(p.read_bytes())
            for p in [root / 'server.py', *(root / 'app').rglob('*.py')]}


def web_state(expected_id):
    row = inspect(WEB)
    env = dict(e.split('=', 1) for e in row['Config']['Env'])
    assert row['Id'] == expected_id
    assert env['DB_NAME'] == DATABASE and env['MONGO_URL'] == 'mongodb://' + MEMBER
    assert env['QUANTA_STARTUP_MODE'] == 'web' and env['MAIL_DRIVER'] == 'devoutbox'
    assert env['DISABLE_BACKGROUND_WORKERS'] == '1' and env['DISABLE_SCHEDULER'] == '1'
    assert env.get('COREFLUX_TIME_COMMITS', '0') == '0'
    assert not env.get('COREFLUX_TIME_ACCESS_TENANTS', '').strip()
    return row


def check_mongo(command, original):
    row = inspect(MONGO)
    assert row['State']['Running'] and row['Config']['Cmd'] == command
    assert row['Config']['Labels']['com.docker.compose.project'] == PROJECT
    assert row['Config']['Labels']['com.docker.compose.service'] == 'quanta-pilot-mongo'
    assert row['Image'] == original['Image'] and row['HostConfig']['Memory'] == 671088640
    assert not any((row['NetworkSettings'].get('Ports') or {}).values())
    assert set(row['NetworkSettings']['Networks']) == set(original['NetworkSettings']['Networks'])
    mounts = lambda r: {m['Destination']: [m['Type'], m['Source'], m['RW']] for m in r['Mounts']}
    assert mounts(row) == mounts(original), 'Persistent volumes changed'
    return row


def wait_mongo(replica):
    for attempt in range(45):
        try:
            hello = js('print(JSON.stringify(db.hello()))')
            assert hello.get('ok') == 1
            if replica:
                assert hello.get('setName') == SET_NAME and hello.get('isWritablePrimary')
                assert hello.get('hosts') == [MEMBER]
            else:
                assert not hello.get('setName')
            return hello
        except (RuntimeError, AssertionError, json.JSONDecodeError):
            if attempt == 44:
                raise
            time.sleep(1)


def health():
    for attempt in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19181/api/health', timeout=4) as response:
                data = json.load(response)
            assert data['ok'] and data['startup_mode'] == 'web'
            assert not data['usage_recording'] and not data['tenant_automations']['running']
            assert data['tenant_automations']['job_count'] == 0
            return data
        except (OSError, AssertionError):
            if attempt == 29:
                raise
            time.sleep(1)


def atomic_compose(content, expected):
    path = ROOT / 'compose.yaml'
    assert sha(path.read_bytes()) == expected, 'Compose changed during storage operation'
    stat = path.stat()
    temp = ROOT / ('.compose-storage-' + uuid.uuid4().hex + '.tmp')
    with temp.open('x') as stream:
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())
    os.chown(temp, stat.st_uid, stat.st_gid)
    os.chmod(temp, stat.st_mode & 0o777)
    os.replace(temp, path)


def recreate_mongo(content, expected_hash):
    run('docker', 'stop', '--time', '25', MONGO, timeout=50)
    assert not inspect(MONGO)['State']['Running']
    atomic_compose(content, expected_hash)
    run(*compose(), 'up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'quanta-pilot-mongo', timeout=120)


DRIVER_PROBE = r'''
import json,os,re,uuid
from pymongo import MongoClient
from pymongo.read_concern import ReadConcern
from pymongo.write_concern import WriteConcern
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
client=MongoClient(os.environ['MONGO_URL'],serverSelectionTimeoutMS=10000)
hello=client.admin.command('hello')
assert hello['setName']=='corepilotquanta' and hello['isWritablePrimary']
name=PROBE_NAME
assert re.fullmatch('corepilot_transactioncheck_[a-f0-9]{24}',name)
assert name not in client.list_database_names()
owned=False
try:
    owned=True
    d=client[name]
    d.create_collection('first');d.create_collection('second')
    with client.start_session() as s:
        s.start_transaction(read_concern=ReadConcern('snapshot'),write_concern=WriteConcern('majority'))
        d.first.insert_one({'_id':'commit','value':1},session=s)
        d.second.insert_one({'_id':'commit','value':2},session=s)
        assert d.first.count_documents({})==0 and d.second.count_documents({})==0
        s.commit_transaction()
        assert d.first.find_one({'_id':'commit'})['value']==1
        assert d.second.find_one({'_id':'commit'})['value']==2
        s.start_transaction()
        d.first.update_one({'_id':'commit'},{'$set':{'value':99}},session=s)
        d.second.delete_one({'_id':'commit'},session=s)
        s.abort_transaction()
        assert d.first.find_one({'_id':'commit'})['value']==1
        assert d.second.find_one({'_id':'commit'})['value']==2
    result={'real_commit':True,'uncommitted_writes_invisible':True,'abort_preserved_both_collections':True,
            'existing_web_driver':True,'replica_set':hello['setName']}
finally:
    if owned:client.drop_database(name)
assert name not in client.list_database_names()
result['temporary_database_removed']=True
print(json.dumps(result))
client.close()
'''


receipt_dir = None
snapshot_source = ''


def main():
    global receipt_dir, snapshot_source
    import fcntl
    assert len(sys.argv) == 3 and sys.argv[1] in ['--inspect-isolated-quanta', '--upgrade-isolated-quanta']
    assert re.fullmatch('[a-f0-9]{64}', sys.argv[2])
    apply = sys.argv[1] == '--upgrade-isolated-quanta'
    assert ROOT.resolve() == ROOT and PRIVATE.resolve() == PRIVATE and not PRIVATE.stat().st_mode & 0o077
    os.umask(0o077)
    lock = (PRIVATE / 'assignment-lifecycle-install.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    assert sha((ROOT / 'compose.yaml').read_bytes()) == COMPOSE_SHA
    assert sha((ROOT / 'compose-https.yaml').read_bytes()) == HTTPS_SHA
    assert sha((BACKUP / 'quanta.archive.gz').read_bytes()) == ARCHIVE_SHA
    assert sha(BACKUP_HELPER.read_bytes()) == HELPER_SHA
    parsed = ast.parse(BACKUP_HELPER.read_text())
    snapshot_source = next(ast.literal_eval(n.value) for n in parsed.body if isinstance(n, ast.Assign)
                           and any(getattr(t, 'id', None) == 'SNAPSHOT' for t in n.targets))
    prior = json.loads((BACKUP / 'before.json').read_text())
    assert prior['database'] == json.loads((BACKUP / 'restored.json').read_text())
    assert json.loads((BACKUP / 'result.json').read_text())['passed']
    web = web_state(sys.argv[2]); assert web['State']['Running']
    mongo = inspect(MONGO); assert mongo['Id'] == MONGO_ID
    check_mongo(OLD_COMMAND, mongo); wait_mongo(False)
    assert runtime_hashes() == prior['runtime_hashes']
    assert snapshot() == prior['database'], 'Source differs from verified recovery point'
    old_config = config()
    assert old_config['services']['quanta-pilot-mongo']['command'] == OLD_COMMAND
    assert old_config['services']['quanta-pilot-mongo'].get('ports', []) == []
    data_mount = next(m for m in mongo['Mounts'] if m['Destination'] == '/data/db')
    assert data_mount['Source'] == '/var/lib/docker/volumes/' + PROJECT + '_quanta-db/_data'
    ids = run('docker', 'ps', '-aq').split()
    consumers = [r['Name'] for r in json.loads(run('docker', 'inspect', *ids))
                 if any('quanta-pilot-mongo' in e for e in r['Config'].get('Env', []))]
    assert consumers == ['/' + WEB], 'Unexpected consumer of isolated Mongo'
    available = int(re.search(r'^MemAvailable:\s+(\d+)', Path('/proc/meminfo').read_text(), re.M)[1]) * 1024
    assert available >= 512 * 1024**2 and shutil.disk_usage(PRIVATE).free >= 2 * 1024**3
    old_text = (ROOT / 'compose.yaml').read_text()
    new_text = candidate_compose(old_text)
    new_hash = sha(new_text.encode())
    report = {'started_at': datetime.now(timezone.utc).isoformat(), 'preflight_passed': True,
              'passed': False, 'upgrade_executed': False, 'source_records': sum(c['count'] for c in prior['database'].values()),
              'source_collections': len(prior['database']), 'backup_sha256': ARCHIVE_SHA,
              'original_mongo_id': mongo['Id'], 'web_container_id': web['Id'],
              'original_compose_sha256': COMPOSE_SHA, 'candidate_compose_sha256': new_hash,
              'application_files_changed': False, 'web_settings_changed': False,
              'operational_time_enabled': False, 'production_changed': False, 'arabella_changed': False,
              'financial_execution': False, 'new_service_added': False}
    if not apply:
        print(json.dumps(report)); return
    receipt_dir = PRIVATE / ('quanta-storage-upgrade-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid.uuid4().hex[:8])
    receipt_dir.mkdir(mode=0o700)
    (receipt_dir / 'compose.original.yaml').write_text(old_text)
    candidate_path = receipt_dir / 'compose.candidate.yaml'
    candidate_path.write_text(new_text)
    candidate = config(candidate_path)
    expected = copy.deepcopy(old_config)
    expected['services']['quanta-pilot-mongo']['command'] = NEW_COMMAND
    assert candidate == expected, 'Candidate changes more than Mongo command'
    report['private_receipt_directory'] = str(receipt_dir)
    protected = other_services()
    web_stopped = False
    mutation_started = False
    probe_name = 'corepilot_transactioncheck_' + uuid.uuid4().hex[:24]
    probe_owned = False

    def stage(name):
        report['stage'] = name
        (receipt_dir / 'progress.json').write_text(json.dumps(report, indent=2))

    try:
        stage('drain_only_quanta_web')
        web_stopped = True
        run('docker', 'stop', '--time', '20', WEB, timeout=45)
        assert not web_state(sys.argv[2])['State']['Running']
        assert snapshot() == prior['database'], 'Data changed while draining; new backup required'
        stage('convert_existing_mongo')
        mutation_started = True
        recreate_mongo(new_text, COMPOSE_SHA)
        report['upgrade_executed'] = True
        # The uninitiated service answers hello without a setName until initiation.
        wait_mongo(False)
        initiate = js('print(JSON.stringify(rs.initiate({_id:' + json.dumps(SET_NAME)
                      + ',members:[{_id:0,host:' + json.dumps(MEMBER) + '}]})))')
        assert initiate.get('ok') == 1
        wait_mongo(True); check_mongo(NEW_COMMAND, mongo)
        assert snapshot() == prior['database']
        stage('rehearse_original_standalone_rollback')
        recreate_mongo(old_text, new_hash)
        wait_mongo(False); check_mongo(OLD_COMMAND, mongo)
        assert snapshot() == prior['database']
        assert config() == old_config
        report['standalone_rollback_rehearsed'] = True
        stage('return_to_verified_replica_set')
        recreate_mongo(new_text, COMPOSE_SHA)
        wait_mongo(True)
        final_mongo = check_mongo(NEW_COMMAND, mongo)
        assert snapshot() == prior['database']
        stage('resume_unchanged_web_and_test_driver')
        run('docker', 'start', WEB); health(); web_stopped = False
        names = js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))')
        assert probe_name not in names
        probe_owned = True
        report['driver_probe'] = json.loads(run('docker', 'exec', '-i', WEB, 'python', '-',
            input=DRIVER_PROBE.replace('PROBE_NAME', repr(probe_name)), timeout=60))
        assert snapshot() == prior['database']
        assert runtime_hashes() == prior['runtime_hashes']
        assert other_services() == protected
        assert web_state(sys.argv[2])['State']['Running']
        assert config() == expected
        assert sha((ROOT / 'compose-https.yaml').read_bytes()) == HTTPS_SHA
        report.update(passed=True, final_mongo_id=final_mongo['Id'], original_records_and_indexes_preserved=True,
                      original_volumes_preserved=True, other_services_unchanged=True,
                      runtime_files_preserved=len(prior['runtime_hashes']), health=health(),
                      replica_set=SET_NAME, member_host=MEMBER, oplog_size_mb=128,
                      original_time_entries=prior['database']['time_entries']['count'])
        stage('verified')
    except Exception as exc:
        report.update(failure_class=type(exc).__name__, failure_message=str(exc))
        if mutation_started:
            try:
                stage('recover_original_standalone_after_failure')
                web_stopped = True
                run('docker', 'stop', '--time', '20', WEB, timeout=45)
                current_hash = sha((ROOT / 'compose.yaml').read_bytes())
                assert current_hash in [COMPOSE_SHA, new_hash]
                recreate_mongo(old_text, current_hash)
                wait_mongo(False); check_mongo(OLD_COMMAND, mongo)
                report['failure_recovered_original_standalone'] = True
                report['recovered_source_matches_backup'] = snapshot() == prior['database']
            except Exception as recovery:
                report['storage_recovery_failure'] = type(recovery).__name__
    finally:
        # A probe timeout may leave its private namespace; never drop any other database.
        try:
            assert re.fullmatch('corepilot_transactioncheck_[a-f0-9]{24}', probe_name)
            names = js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))')
            if probe_owned and probe_name in names:
                js('print(JSON.stringify(db.getSiblingDB(' + json.dumps(probe_name) + ').dropDatabase()))')
            names = js('print(JSON.stringify(db.adminCommand({listDatabases:1,nameOnly:true}).databases.map(x=>x.name)))')
            report['temporary_probe_database_removed'] = not probe_owned or probe_name not in names
        except Exception as cleanup:
            report['probe_cleanup_failure'] = type(cleanup).__name__
        if web_stopped:
            try:
                run('docker', 'start', WEB); health()
                report['web_recovered'] = True
            except Exception as recovery:
                report['web_recovery_failure'] = type(recovery).__name__
        report['passed'] = bool(report['passed'] and report.get('temporary_probe_database_removed')
                              and not any(k.endswith('_failure') for k in report))
        report['completed_at'] = datetime.now(timezone.utc).isoformat()
        (receipt_dir / 'result.json').write_text(json.dumps(report, indent=2))
        print(json.dumps(report))
    if not report['passed']:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
