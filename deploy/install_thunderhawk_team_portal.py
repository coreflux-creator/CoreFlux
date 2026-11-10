"""Install an exact reviewed Quanta portal build in the existing isolated pilot."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
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
REVISION = '95e2eb9cddacf5e9969378f8852cd578ba97717f'
if sys.argv[1:] != ['--install-isolated-team-portal'] or APP.resolve() != APP or PRIVATE.resolve() != PRIVATE:
    raise SystemExit('Exact isolated pilot and explicit installation required')
if PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private directory permissions differ')
lock = open(PRIVATE / 'team-portal-install.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)


def run(*args):
    result = subprocess.run(list(args), text=True, capture_output=True, timeout=90)
    if result.returncode:
        raise RuntimeError('Isolated deployment command failed')
    return result.stdout


def other_containers():
    ids = run('docker', 'ps', '-aq').split()
    return {c['Name']: [c['Id'], c['State']['Status'], c['State']['StartedAt']]
            for c in json.loads(run('docker', 'inspect', *ids)) if c['Name'] != '/' + CONTAINER}


SNAPSHOT = '''import hashlib,json,os,socket
from bson import json_util
from pymongo import MongoClient
assert socket.gethostname()=='quanta-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['QUANTA_STARTUP_MODE']=='web'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
assert os.environ['MAIL_DRIVER']=='devoutbox'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
out={}
for name in sorted(d.list_collection_names()):
    values=sorted(json_util.dumps(r,sort_keys=True) for r in d[name].find({}))
    out[name]={'count':len(values),'sha256':hashlib.sha256(json.dumps(values).encode()).hexdigest()}
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


def write(path, body):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + '.team-next')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644), 'wb') as stream:
        stream.write(body)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(tmp, path)


report = {'source_commit': REVISION, 'production_changed': False, 'financial_execution': False}
backup = PRIVATE / ('quanta-team-before-95e2eb9-' + uuid.uuid4().hex[:8])
previous = {}
changed = False
try:
    with tarfile.open(PRIVATE / 'quanta-team-portal-95e2eb9.tgz', 'r:gz') as archive:
        members = archive.getmembers()
        if len(members) > 200 or sum(m.size for m in members) > 25_000_000 or any(not m.isfile() for m in members):
            raise RuntimeError('Unexpected release package')
        package = {m.name: archive.extractfile(m).read() for m in members}
    manifest = json.loads(package.pop('manifest.json'))
    if manifest['revision'] != REVISION or set(package) != set(manifest['files']):
        raise RuntimeError('Release manifest differs')
    required = {'backend/app/routers/core_assignments.py', 'backend/app/routers/core_onboarding.py'}
    if {p for p in package if p.startswith('backend/')} != required:
        raise RuntimeError('Unexpected backend change')
    for relative, body in package.items():
        if not (relative in required or relative.startswith('frontend/')) or digest(body) != manifest['files'][relative]:
            raise RuntimeError('Release bytes differ')
        path = destination(relative)
        previous[relative] = path.read_bytes() if path.is_file() else None
    for relative in required:
        if digest(previous[relative].replace(b'\r\n', b'\n')) != manifest['previous_backend'][relative]:
            raise RuntimeError('Existing backend differs from reviewed baseline')
    baseline, others = snapshot(), other_containers()
    backup.mkdir(mode=0o700)
    (backup / 'before.json').write_text(json.dumps({'database': baseline, 'other_containers': others}, indent=2))
    for relative, body in previous.items():
        if body is not None:
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(body)
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    changed = True
    for relative, body in package.items():
        write(destination(relative), body)
    # Nginx's existing mount remains in place; retained old assets support open tabs.
    for path in (APP / 'frontend').rglob('*'):
        if path.is_dir():
            path.chmod(0o755)
    run('docker', 'restart', CONTAINER)
    for attempt in range(25):
        try:
            index = urllib.request.urlopen('http://127.0.0.1:19181/', timeout=4).read()
            if index != package['frontend/index.html']:
                raise RuntimeError('Served frontend differs')
            try:
                urllib.request.urlopen('http://127.0.0.1:19181/api/core/assignments?view=team', timeout=4)
            except urllib.error.HTTPError as error:
                if error.code == 401:
                    break
            raise RuntimeError('Native portal authentication differs')
        except (OSError, RuntimeError):
            if attempt == 24:
                raise
            time.sleep(1)
    after, others_after = snapshot(), other_containers()
    report['changed_collections'] = {k: {'before': baseline.get(k), 'after': after.get(k)} for k in sorted(set(baseline) | set(after)) if baseline.get(k) != after.get(k)}
    report['changed_other_services'] = [k for k in set(others) | set(others_after) if others.get(k) != others_after.get(k)]
    (backup / 'after.json').write_text(json.dumps({'database': after, 'other_containers': others_after}, indent=2))
    if after != baseline or others_after != others:
        raise RuntimeError('Protected data or another service changed')
    report.update(passed=True, all_provider_records_unchanged=True, other_services_unchanged=True,
                  private_backup_retained=True, native_unauthenticated_access_denied=True,
                  served_index_verified=True, background_work_held=True, files=manifest['files'])
except Exception as error:
    report.update(passed=False, failure_class=type(error).__name__)
    if changed:
        for relative, body in previous.items():
            path = destination(relative)
            if body is None:
                if path.is_file():
                    path.unlink()
            else:
                write(path, body)
        run('docker', 'restart', CONTAINER)
        report['previous_runtime_restored'] = True
    raise
finally:
    (PRIVATE / 'quanta-team-install-result.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report))
