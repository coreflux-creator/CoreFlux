"""Install the reviewed read-only observation routes in the existing isolated provider."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import time
import urllib.request
import uuid

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
BACKEND = ROOT / 'pure/backend'
CONTAINER = 'core-provider-pilot-20261029-pure-pilot-web-1'
COMMIT = 'acb474daae863643ac700d756ea43a9b8be80399'
OLD_SERVER = 'dad3c60fd118b7e472949fea64482aca24a5358289db81a16bfcc17ac18485fd'
OLD_DIGEST = '3d0943861338cd1d277e58845f398e7f20e2ad5967ef408a4657297ef3ba8b8f'
if sys.argv[1:] != ['--install-isolated-observation-reader'] or ROOT.resolve() != ROOT or BACKEND.resolve() != BACKEND:
    raise SystemExit('Exact isolated host and explicit installation required')
if PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private directory permissions differ')
lock = open(PRIVATE / 'financial-observation-install.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)


def run(*args, input=None):
    result = subprocess.run(list(args), input=input, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('Isolated deployment command failed')
    return result.stdout


def digest():
    files = {p.relative_to(BACKEND).as_posix(): hashlib.sha256(p.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
             for p in sorted(BACKEND.rglob('*.py')) if not any(part.startswith('.') or
             part in {'tests', '__pycache__', 'rpa_evidence', 'uploads', 'data'} for part in p.relative_to(BACKEND).parts)}
    return hashlib.sha256(json.dumps(files, sort_keys=True).encode()).hexdigest()


def containers():
    ids = run('docker', 'ps', '-aq').split()
    data = json.loads(run('docker', 'inspect', *ids))
    return {x['Name']: {'id': x['Id'], 'state': x['State']['Status'], 'started_at': x['State']['StartedAt']}
            for x in data if x['Name'] != '/' + CONTAINER}


SNAPSHOT = r'''
import hashlib,json,os,socket
from bson import json_util
from pymongo import MongoClient
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
out={}
for name in sorted(db.list_collection_names()):
    rows=list(db[name].find({}))
    if name=='api_keys':
        for row in rows:row.pop('last_used_at',None)
    if rows:
        values=sorted(json_util.dumps(row,sort_keys=True) for row in rows)
        out[name]={'count':len(rows),'sha256':hashlib.sha256(json.dumps(values).encode()).hexdigest()}
print(json.dumps(out))
'''


def snapshot():
    return json.loads(run('docker', 'exec', '-w', '/app/backend', CONTAINER, 'python', '-c', SNAPSHOT))


def atomic(path, body, mode=0o644):
    if path.parent.resolve() != BACKEND or path.is_symlink():
        raise RuntimeError('Runtime destination differs')
    tmp = path.with_name(path.name + '.financial-next')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode), 'wb') as stream:
        stream.write(body)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(tmp, path)


report = {'source_commit': COMMIT, 'production_changed': False, 'payroll_or_payment_execution': False}
phase = 'preflight'
installed = False
stopped = False
try:
    manifest = json.loads((PRIVATE / 'financial-observations-release.json').read_text())
    archive = PRIVATE / 'financial-observations-release.tar'
    assert manifest['commit'] == COMMIT
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == manifest['archive_sha256']
    expected = {'backend/server.py', 'backend/integration_financial_observations.py'}
    with tarfile.open(archive) as tar:
        assert {m.name for m in tar.getmembers() if not m.isdir()} == expected
        payload = {}
        for name in expected:
            member = tar.getmember(name)
            assert member.isfile() and member.size < 4_000_000
            body = tar.extractfile(member).read()
            assert hashlib.sha256(body).hexdigest() == manifest['files'][name]
            compile(body, name, 'exec')
            payload[Path(name).name] = body
    current = json.loads(run('docker', 'inspect', CONTAINER))[0]
    env = dict(value.split('=', 1) for value in current['Config']['Env'])
    assert current['State']['Status'] == 'running'
    assert current['Config']['Hostname'] == 'pure-core-pilot-20261029'
    assert env['PUREPAY_STARTUP_MODE'] == 'isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS'] == 'disabled'
    assert env['DB_NAME'] == 'corepilot_pure_20261029' and env['MONGO_URL'] == 'mongodb://pure-pilot-mongo:27017'
    assert any(m['Source'] == str(BACKEND) and m['Destination'] == '/app/backend' and not m['RW'] for m in current['Mounts'])
    assert all(json.loads(run('docker', 'network', 'inspect', name))[0]['Internal']
               for name in current['NetworkSettings']['Networks'])
    old_server = (BACKEND / 'server.py').read_bytes()
    old_stamp = (BACKEND / 'release_handover.json').read_bytes()
    assert hashlib.sha256(old_server.replace(b'\r\n', b'\n')).hexdigest() == OLD_SERVER
    assert digest() == json.loads(old_stamp)['source_digest'] == OLD_DIGEST
    assert not (BACKEND / 'integration_financial_observations.py').exists()
    before, other_containers = snapshot(), containers()
    backup = PRIVATE / ('financial-observations-release-' + COMMIT[:12])
    backup.mkdir(mode=0o700)
    for name, value in [('server.py', old_server), ('release_handover.json', old_stamp)]:
        with os.fdopen(os.open(backup / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb') as stream:
            stream.write(value)
    phase = 'stop_only_isolated_backend'
    run('docker', 'stop', '--time', '30', CONTAINER)
    stopped = True
    assert json.loads(run('docker', 'inspect', CONTAINER))[0]['State']['Running'] is False
    phase = 'install_reviewed_runtime'
    # Mark restoration necessary before the first file can change.
    installed = True
    for name, body in payload.items():
        atomic(BACKEND / name, body)
    stamp = {'release_id': 'pure-core-financial-observations-20261030',
             'deployment_id': str(uuid.uuid4()), 'source_digest': digest()}
    atomic(BACKEND / 'release_handover.json', json.dumps(stamp).encode())
    phase = 'verify_held_startup'
    run('docker', 'start', CONTAINER)
    stopped = False
    health = None
    for attempt in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19180/api/release-status', timeout=3) as response:
                health = json.load(response)
            if health.get('state') == 'active':
                break
        except Exception:
            pass
        time.sleep(1)
    assert health and health['state'] == 'active' and health['startup_mode'] == 'isolated_pilot'
    assert all(health[k] is False for k in ['background_jobs', 'startup_migrations', 'scheduled_operations', 'provider_egress_enabled'])
    assert snapshot() == before
    assert containers() == other_containers
    report.update({'passed': True, 'release_stamp': stamp, 'health': health,
                   'provider_business_records_unchanged': True, 'other_containers_unchanged': True,
                   'private_rollback_retained': True})
except Exception as error:
    report.update({'passed': False, 'failed_phase': phase, 'failure_class': type(error).__name__})
    if installed:
        run('docker', 'stop', '--time', '30', CONTAINER)
        atomic(BACKEND / 'server.py', old_server)
        atomic(BACKEND / 'release_handover.json', old_stamp)
        target = BACKEND / 'integration_financial_observations.py'
        if target.parent.resolve() != BACKEND or target.is_symlink():
            raise RuntimeError('Rollback path differs')
        target.unlink(missing_ok=True)
        run('docker', 'start', CONTAINER)
        report['prior_runtime_restored'] = True
    elif stopped:
        run('docker', 'start', CONTAINER)
        report['prior_runtime_restarted'] = True
print(json.dumps(report))
raise SystemExit(0 if report['passed'] else 1)
