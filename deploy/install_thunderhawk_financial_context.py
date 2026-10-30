"""Update only the isolated PurePay source-evidence module; retain an exact rollback."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.request
import uuid

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
BACKEND = ROOT / 'pure/backend'
CONTAINER = 'core-provider-pilot-20261029-pure-pilot-web-1'
COMMIT = 'c9a2db6d6d01530c519247ca731e6d98ac412f0e'
OLD_MODULE = 'ad9920ee7e6d5d5f298491bd1cb9a856437384e7dc863b36cb9b5ceb7e719678'
OLD_DIGEST = 'ccf3398f3030eb2be5efcff350814dc218e577b3a684d0a3c2c63d49d43d8a48'
if sys.argv[1:] != ['--install-isolated-financial-context'] or ROOT.resolve() != ROOT or BACKEND.resolve() != BACKEND:
    raise SystemExit('Exact isolated destination required')
if PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private directory differs')
lock = open(PRIVATE / 'financial-observation-install.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)


def run(*args):
    result = subprocess.run(args, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('Isolated runtime command failed')
    return result.stdout


def digest():
    files = {p.relative_to(BACKEND).as_posix(): hashlib.sha256(p.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
             for p in sorted(BACKEND.rglob('*.py')) if not any(part.startswith('.') or
             part in {'tests', '__pycache__', 'rpa_evidence', 'uploads', 'data'} for part in p.relative_to(BACKEND).parts)}
    return hashlib.sha256(json.dumps(files, sort_keys=True).encode()).hexdigest()


def other_containers():
    rows = json.loads(run('docker', 'inspect', *run('docker', 'ps', '-aq').split()))
    return {r['Name']: {'id': r['Id'], 'state': r['State']['Status'], 'started_at': r['State']['StartedAt']}
            for r in rows if r['Name'] != '/' + CONTAINER}


SNAPSHOT = r'''
import hashlib,json,os,socket
from bson import json_util
from pymongo import MongoClient
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];out={}
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


def atomic(path, body):
    if path.parent.resolve() != BACKEND or path.is_symlink():
        raise RuntimeError('Runtime path differs')
    tmp = path.with_name(path.name + '.context-next')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644), 'wb') as stream:
        stream.write(body)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(tmp, path)


report = {'source_commit': COMMIT, 'production_changed': False, 'payroll_or_payment_execution': False}
changed = stopped = False
phase = 'preflight'
try:
    manifest = json.loads((PRIVATE / 'financial-context-release.json').read_text())
    body = (PRIVATE / 'financial-context-module.py').read_bytes()
    assert manifest['commit'] == COMMIT and hashlib.sha256(body).hexdigest() == manifest['module_sha256']
    compile(body, 'integration_financial_observations.py', 'exec')
    current = json.loads(run('docker', 'inspect', CONTAINER))[0]
    env = dict(value.split('=', 1) for value in current['Config']['Env'])
    assert current['State']['Status'] == 'running' and current['Config']['Hostname'] == 'pure-core-pilot-20261029'
    assert env['PUREPAY_STARTUP_MODE'] == 'isolated_pilot' and env['PUREPAY_PROVIDER_EGRESS'] == 'disabled'
    assert env['DB_NAME'] == 'corepilot_pure_20261029' and env['MONGO_URL'] == 'mongodb://pure-pilot-mongo:27017'
    assert any(m['Source'] == str(BACKEND) and m['Destination'] == '/app/backend' and not m['RW'] for m in current['Mounts'])
    assert all(json.loads(run('docker', 'network', 'inspect', n))[0]['Internal'] for n in current['NetworkSettings']['Networks'])
    old_module = (BACKEND / 'integration_financial_observations.py').read_bytes()
    old_stamp = (BACKEND / 'release_handover.json').read_bytes()
    assert hashlib.sha256(old_module.replace(b'\r\n', b'\n')).hexdigest() == OLD_MODULE
    assert digest() == json.loads(old_stamp)['source_digest'] == OLD_DIGEST
    before, others = snapshot(), other_containers()
    backup = PRIVATE / ('financial-context-before-' + uuid.uuid4().hex)
    backup.mkdir(mode=0o700)
    for name, value in [('integration_financial_observations.py', old_module), ('release_handover.json', old_stamp)]:
        with os.fdopen(os.open(backup / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb') as stream:
            stream.write(value)
    phase = 'install_isolated_module'
    run('docker', 'stop', '--time', '30', CONTAINER)
    stopped = changed = True
    atomic(BACKEND / 'integration_financial_observations.py', body)
    stamp = {'release_id': 'pure-core-financial-context-20261030', 'deployment_id': str(uuid.uuid4()), 'source_digest': digest()}
    atomic(BACKEND / 'release_handover.json', json.dumps(stamp).encode())
    run('docker', 'start', CONTAINER)
    stopped = False
    phase = 'verify_held_runtime'
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
    assert snapshot() == before and other_containers() == others
    report.update(passed=True, release_stamp=stamp, health=health, provider_business_records_unchanged=True,
                  other_containers_unchanged=True, private_rollback_retained=True)
except Exception as error:
    report.update(passed=False, failed_phase=phase, failure_class=type(error).__name__)
    if changed:
        run('docker', 'stop', '--time', '30', CONTAINER)
        atomic(BACKEND / 'integration_financial_observations.py', old_module)
        atomic(BACKEND / 'release_handover.json', old_stamp)
        run('docker', 'start', CONTAINER)
        report['prior_runtime_restored'] = True
    elif stopped:
        run('docker', 'start', CONTAINER)
        report['prior_runtime_restarted'] = True
print(json.dumps(report))
raise SystemExit(0 if report['passed'] else 1)
