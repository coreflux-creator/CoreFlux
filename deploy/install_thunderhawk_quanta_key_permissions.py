"""Install the tested key permission controls, without changing keys, only in the existing isolated Quanta pilot."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
APP = ROOT / 'quanta'
CONTAINER = 'core-provider-pilot-20261029-quanta-pilot-web-1'
REVISION = 'd8c82eb59389cb85f766dc00f56cbc2671943d7f'
REQUIRED = {'backend/app/models.py', 'backend/app/routers/api_keys.py'}
if len(sys.argv) != 3 or sys.argv[1] != '--install-isolated-key-permissions' or not re.fullmatch('[a-f0-9]{64}', sys.argv[2]):
    raise SystemExit('Explicit isolated installation and exact package digest required')
if ROOT.resolve() != ROOT or APP.resolve() != APP or PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Isolated private target differs')
lock = open(PRIVATE / 'assignment-lifecycle-install.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)


def run(*args):
    result = subprocess.run(args, text=True, capture_output=True, timeout=90)
    if result.returncode:
        raise RuntimeError('Isolated deployment command failed')
    return result.stdout


def state():
    value = json.loads(run('docker', 'inspect', CONTAINER))[0]
    env = dict(v.split('=', 1) for v in value['Config']['Env'])
    assert env['DB_NAME'] == 'corepilot_quanta_20261029'
    assert env['MONGO_URL'] == 'mongodb://quanta-pilot-mongo:27017'
    assert env['QUANTA_STARTUP_MODE'] == 'web' and env['MAIL_DRIVER'] == 'devoutbox'
    assert env['DISABLE_BACKGROUND_WORKERS'] == '1' and env['DISABLE_SCHEDULER'] == '1'
    assert not env.get('COREFLUX_TIME_ACCESS_TENANTS', '').strip()
    assert any(m['Source'] == str(APP / 'backend') and m['Destination'] == '/app/backend' for m in value['Mounts'])
    return {'id': value['Id'], 'running': value['State']['Running'], 'started_at': value['State']['StartedAt']}


def others():
    ids = run('docker', 'ps', '-aq').split()
    return {v['Name']: [v['Id'], v['State']['Status'], v['State']['StartedAt']]
            for v in json.loads(run('docker', 'inspect', *ids)) if v['Name'] != '/' + CONTAINER}


SNAPSHOT = '''import hashlib,json,os,socket
from bson import json_util
from pymongo import MongoClient
assert socket.gethostname()=='quanta-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
out={}
for name in sorted(d.list_collection_names()):
 values=sorted(json_util.dumps(r,sort_keys=True) for r in d[name].find({}))
 out[name]={'count':len(values),'sha256':hashlib.sha256(json.dumps(values).encode()).hexdigest(),
            'indexes':json.loads(json_util.dumps(d[name].index_information()))}
 if name=='api_usage_events':
  out[name]['row_hashes']=sorted(hashlib.sha256(v.encode()).hexdigest() for v in values)
print(json.dumps(out))
'''


def snapshot():
    return json.loads(run('docker', 'exec', '-w', '/app/backend', CONTAINER, 'python', '-c', SNAPSHOT))


def digest(body):
    return hashlib.sha256(body).hexdigest()


def destination(relative):
    path = APP / relative
    if path.is_symlink() or not path.resolve().is_relative_to(APP) or path.resolve() != path:
        raise RuntimeError('Runtime path escaped isolated app')
    return path


def write(path, body, metadata=None, restore_times=False):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
    tmp = path.with_name(path.name + '.assignment-time-' + uuid.uuid4().hex)
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644), 'wb') as stream:
        stream.write(body)
        stream.flush()
        os.fsync(stream.fileno())
    if metadata:
        os.chown(tmp, metadata.st_uid, metadata.st_gid)
        tmp.chmod(metadata.st_mode & 0o7777)
        if restore_times:
            os.utime(tmp, ns=(metadata.st_atime_ns, metadata.st_mtime_ns))
    os.replace(tmp, path)


report = {'source_commit': REVISION, 'production_changed': False, 'financial_execution': False, 'passed': False}
backup = PRIVATE / ('quanta-key-permissions-before-' + uuid.uuid4().hex)
previous = {}
stopped = False
changed = False
try:
    archive_path = PRIVATE / 'quanta-key-permissions-d8c82eb.tgz'
    if archive_path.is_symlink() or digest(archive_path.read_bytes()) != sys.argv[2]:
        raise RuntimeError('Package digest differs')
    with tarfile.open(archive_path, 'r:gz') as archive:
        members = archive.getmembers()
        if len(members) > 100 or sum(m.size for m in members) > 25_000_000 or any(not m.isfile() for m in members):
            raise RuntimeError('Unexpected release package')
        if len({m.name for m in members}) != len(members):
            raise RuntimeError('Duplicate package destination')
        package = {m.name: archive.extractfile(m).read() for m in members}
    manifest = json.loads(package.pop('manifest.json'))
    if manifest['revision'] != REVISION or set(package) != set(manifest['files']):
        raise RuntimeError('Release manifest differs')
    if {p for p in package if p.startswith('backend/')} != REQUIRED:
        raise RuntimeError('Unexpected backend change')
    prior_state = state()
    if not prior_state['running'] or prior_state['id'] != manifest['target_container_id'] or prior_state['started_at'] != manifest['target_started_at']:
        raise RuntimeError('Expected running target changed')
    if digest((APP / 'frontend/index.html').read_bytes()) != manifest['previous_index_sha256']:
        raise RuntimeError('Private frontend changed since review')
    for relative, body in package.items():
        if not (relative in REQUIRED or relative.startswith('frontend/')) or digest(body) != manifest['files'][relative]:
            raise RuntimeError('Release bytes differ')
        path = destination(relative)
        previous[relative] = (path.read_bytes(), path.stat()) if path.is_file() else (None, None)
    for relative in REQUIRED:
        old = previous[relative][0]
        if (digest(old.replace(b'\r\n', b'\n')) if old is not None else None) != manifest['previous_backend'][relative]:
            raise RuntimeError('Existing backend differs from reviewed baseline')
        compile(package[relative], relative, 'exec')
    if manifest['browser_policy'].encode() not in package['frontend/index.html']:
        raise RuntimeError('Private browser policy missing')
    # Every installed backend file must still match the reviewed source, not only changed files.
    actual_backend = {p.relative_to(APP).as_posix(): digest(p.read_bytes().replace(b'\r\n', b'\n'))
                      for p in (APP / 'backend/app').rglob('*.py')}
    if actual_backend != manifest['entire_previous_backend']:
        raise RuntimeError('Complete backend baseline changed since review')
    before, protected = snapshot(), others()
    if before.get('coreflux_time_access', {}).get('count', 0) != 0:
        raise RuntimeError('Existing time policy needs a separate activation review')
    backup.mkdir(mode=0o700)
    (backup / 'before.json').write_text(json.dumps({'database': before, 'protected': protected, 'target': prior_state}, indent=2))
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    for relative, (body, metadata) in previous.items():
        if body is not None:
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(body)
    run('docker', 'stop', '--time', '20', CONTAINER)
    stopped = True
    if state()['running']:
        raise RuntimeError('Pilot web process did not stop')
    changed = True
    for relative, body in package.items():
        write(destination(relative), body, previous[relative][1])
    run('docker', 'start', CONTAINER)
    for attempt in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19181/api/health', timeout=4) as r:
                health = json.load(r)
            if health.get('startup_mode') != 'web' or health.get('usage_recording') is not False or health['tenant_automations']['running']:
                raise RuntimeError('Unexpected background work')
            break
        except (OSError, RuntimeError):
            if attempt == 29:
                raise
            time.sleep(1)
    with urllib.request.urlopen('http://127.0.0.1:19181/login', timeout=10) as r:
        if r.read() != package['frontend/index.html']:
            raise RuntimeError('Served frontend differs')
    for route in ['/api/core/onboarding', '/api/core/assignments', '/api/v1/integrations/coreflux/time-review-context', '/api/core/assignments/time-options', '/api/core/assignments/time-access-context', '/api/v1/integrations/coreflux/time-access-context']:
        try:
            urllib.request.urlopen('http://127.0.0.1:19181' + route, timeout=10)
            raise RuntimeError('Unauthenticated assignment route accepted')
        except urllib.error.HTTPError as error:
            if error.code != 401:
                raise RuntimeError('Native authentication or route differs')
    after, protected_after = snapshot(), others()
    if any(after.get(name) != values for name, values in before.items() if name != 'api_usage_events'):
        raise RuntimeError('An existing provider collection or index changed')
    # Native shutdown flushes the already-buffered API meter even with its
    # periodic background flusher held. Preserve every old log and allow only
    # appended usage records; contacts, assignments, time and money stay exact.
    old_usage, new_usage = before.get('api_usage_events'), after.get('api_usage_events')
    if old_usage is None or new_usage is None or old_usage['indexes'] != new_usage['indexes']:
        raise RuntimeError('API usage collection or indexes changed')
    old_hashes, new_hashes = set(old_usage['row_hashes']), set(new_usage['row_hashes'])
    if not old_hashes.issubset(new_hashes) or len(new_hashes-old_hashes) != new_usage['count']-old_usage['count']:
        raise RuntimeError('An existing API usage record changed or disappeared')
    additions = set(after) - set(before)
    if not additions.issubset({'coreflux_time_access'}) or any(after[name]['count'] != 0 for name in additions):
        raise RuntimeError('Unexpected new collection or business records')
    if after.get('coreflux_time_access', {}).get('count', 0) != 0:
        raise RuntimeError('Operational time policy unexpectedly activated')
    if protected != protected_after:
        raise RuntimeError('Another service changed during installation')
    for relative, body in package.items():
        if destination(relative).read_bytes() != body:
            raise RuntimeError('Installed file differs')
    report.update(passed=True, existing_business_records_and_indexes_unchanged=True,
                  previous_usage_records_unchanged=True, appended_usage_events=len(new_hashes-old_hashes),
                  new_empty_collections=sorted(additions),
                  background_work_held=True, operational_policy_enabled=False, new_container=False, other_services_unchanged=True, private_backup=str(backup),
                  native_unauthenticated_access_denied=True, target=state(), installed_files=manifest['files'], health=health)
    (backup / 'after.json').write_text(json.dumps({'database': after, 'protected': protected_after}, indent=2))
except Exception as error:
    report.update(failure_class=type(error).__name__, failure_message=str(error))
    if changed:
        run('docker', 'stop', '--time', '20', CONTAINER)
        for relative, (body, metadata) in previous.items():
            path = destination(relative)
            if body is None:
                if path.is_file():
                    path.unlink()
            else:
                write(path, body, metadata, restore_times=True)
        report['previous_runtime_restored'] = True
    if stopped:
        run('docker', 'start', CONTAINER)
finally:
    (PRIVATE / 'quanta-key-permissions-install-result.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report))
sys.exit(0 if report['passed'] else 1)
