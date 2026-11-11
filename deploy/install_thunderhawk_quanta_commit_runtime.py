"""Install the accepted coordinator runtime in the isolated Quanta pilot, held off.

Requires exact whole-runtime hashes, a matching database recovery point and the
verified storage conversion. Keeps the existing container and environment.
"""
import ast, fcntl, hashlib, json, os, re, subprocess, sys, tarfile, time, uuid
from pathlib import Path
from datetime import datetime, timezone
import urllib.request

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
APP = ROOT / 'quanta'
WEB = 'core-provider-pilot-20261029-quanta-pilot-web-1'
MONGO = 'core-provider-pilot-20261029-quanta-pilot-mongo-1'
DATABASE = 'corepilot_quanta_20261029'
REVISION = '22b8170cfe57052b2b06e61110cdc7555b5ed947'
BACKUP = PRIVATE / 'quanta-storage-backup-20261111T074411Z-13b2ea68'
COMPOSE_SHA = 'f13b3e0ab776946d165e96102ac806236aedc1f1d1b0e0e855b94e2b56b17274'
sha = lambda body: hashlib.sha256(body).hexdigest()
assert len(sys.argv) == 3 and sys.argv[1] == '--install-isolated-commit-runtime' and re.fullmatch('[a-f0-9]{64}', sys.argv[2])
assert ROOT.resolve() == ROOT and PRIVATE.resolve() == PRIVATE and APP.resolve() == APP
assert not PRIVATE.stat().st_mode & 0o077
os.umask(0o077)
lock = (PRIVATE / 'assignment-lifecycle-install.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
receipt = None


def run(*args, timeout=90):
    result = subprocess.run(args, text=True, capture_output=True, timeout=timeout)
    if result.returncode:
        if receipt: (receipt / 'last-command-error.txt').write_text(result.stderr)
        raise RuntimeError('Isolated runtime command failed: ' + args[0])
    return result.stdout


def inspect(name): return json.loads(run('docker', 'inspect', name))[0]


def js(source): return json.loads(run('docker', 'exec', MONGO, 'mongosh', '--quiet', '--eval', source))


def snapshot(): return js(snapshot_source.replace('DBNAME', json.dumps(DATABASE)))


def runtime():
    base = APP / 'backend'
    return {p.relative_to(base).as_posix(): sha(p.read_bytes().replace(b'\r\n', b'\n'))
            for p in [base / 'server.py', *(base / 'app').rglob('*.py')]}


def others():
    ids = run('docker', 'ps', '-aq').split()
    return {r['Name']: [r['Id'], r['State']['Status'], r['State']['StartedAt']]
            for r in json.loads(run('docker', 'inspect', *ids)) if r['Name'] != '/' + WEB}


def state():
    row = inspect(WEB); env = dict(e.split('=', 1) for e in row['Config']['Env'])
    assert row['Id'] == manifest['target_container_id']
    assert env['DB_NAME'] == DATABASE and env['MONGO_URL'] == 'mongodb://quanta-pilot-mongo:27017'
    assert env['QUANTA_STARTUP_MODE'] == 'web' and env['MAIL_DRIVER'] == 'devoutbox'
    assert env['DISABLE_BACKGROUND_WORKERS'] == '1' and env['DISABLE_SCHEDULER'] == '1'
    assert env.get('COREFLUX_TIME_COMMITS', '0') == '0' and not env.get('COREFLUX_TIME_ACCESS_TENANTS', '').strip()
    assert any(m['Source'] == str(APP / 'backend') and m['Destination'] == '/app/backend' and not m['RW'] for m in row['Mounts'])
    return row


def destination(name):
    assert name == 'server.py' or re.fullmatch(r'app/(?:[a-zA-Z0-9_]+/)*[a-zA-Z0-9_]+\.py', name)
    path = APP / 'backend' / name
    assert path.resolve() == path and not path.is_symlink()
    return path


def write(path, body, stat=None):
    path.parent.mkdir(exist_ok=True, parents=True, mode=0o755)
    tmp = path.with_name(path.name + '.commit-runtime-' + uuid.uuid4().hex)
    with tmp.open('xb') as stream:
        stream.write(body); stream.flush(); os.fsync(stream.fileno())
    os.chmod(tmp, stat.st_mode & 0o7777 if stat else 0o644)
    if stat: os.chown(tmp, stat.st_uid, stat.st_gid)
    os.replace(tmp, path)


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


def verify_original_records(before, after):
    # The original record and index snapshot must survive byte-for-byte.
    for name, value in before.items():
        assert after.get(name) == value, 'Original collection changed: ' + name
    additions = set(after) - set(before)
    assert additions.issubset({'pace_nudges', 'pace_conversations', 'pace_messages'})
    assert all(after[name]['count'] == 0 for name in additions)
    assert not after.get('coreflux_commit_fences', {}).get('count', 0)
    return sorted(additions)


report = {'source_commit': REVISION, 'passed': False, 'started_at': datetime.now(timezone.utc).isoformat(),
          'operational_time_enabled': False, 'coordination_enabled': False, 'production_changed': False,
          'arabella_changed': False, 'financial_execution': False, 'web_settings_changed': False}
stopped = False; changed = False; previous = {}
try:
    archive_path = PRIVATE / ('quanta-commit-runtime-' + REVISION[:8] + '.tgz')
    assert not archive_path.is_symlink() and sha(archive_path.read_bytes()) == sys.argv[2]
    with tarfile.open(archive_path, 'r:gz') as archive:
        members = archive.getmembers()
        assert len(members) < 100 and sum(m.size for m in members) < 25_000_000
        assert all(m.isfile() for m in members) and len({m.name for m in members}) == len(members)
        package = {m.name: archive.extractfile(m).read() for m in members}
    manifest = json.loads(package.pop('manifest.json'))
    assert manifest['revision'] == REVISION and set(package) == set(manifest['files'])
    assert manifest['accepted_native_ci'] == 42982131790
    assert runtime() == manifest['previous_runtime']
    assert {**manifest['previous_runtime'], **manifest['files']} == manifest['accepted_runtime']
    assert sha((ROOT / 'compose.yaml').read_bytes()) == COMPOSE_SHA
    web = state(); assert web['State']['Running'] and web['State']['StartedAt'] == manifest['target_started_at']
    hello = js('print(JSON.stringify(db.hello()))')
    assert hello['setName'] == 'corepilotquanta' and hello['isWritablePrimary'] and hello['hosts'] == ['quanta-pilot-mongo:27017']
    assert sha((APP / 'frontend/index.html').read_bytes()) == manifest['frontend_index_sha256']
    helper = PRIVATE / 'quanta-storage-backup-4bcdfa8f1502.py'
    assert sha(helper.read_bytes()) == '4bcdfa8f1502dc50fa27d3bb5878defc7eb40e1934e81bba62b0732c13bc9c94'
    parsed = ast.parse(helper.read_text())
    snapshot_source = next(ast.literal_eval(n.value) for n in parsed.body if isinstance(n, ast.Assign)
                           and any(getattr(t, 'id', None) == 'SNAPSHOT' for t in n.targets))
    original = json.loads((BACKUP / 'before.json').read_text())['database']
    assert snapshot() == original
    assert sha((BACKUP / 'quanta.archive.gz').read_bytes()) == '5bdad2b3c10f634e85e59c4d6557c49c4b6c2cb969a39d7d92d07a6985f01196'
    for name, body in package.items():
        assert sha(body) == manifest['files'][name]
        compile(body, name, 'exec')
        path = destination(name)
        previous[name] = (path.read_bytes(), path.stat()) if path.is_file() else (None, None)
    protected = others()
    receipt = PRIVATE / ('quanta-commit-runtime-before-' + uuid.uuid4().hex)
    receipt.mkdir(mode=0o700)
    (receipt / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    (receipt / 'before.json').write_text(json.dumps({'database': original, 'other_services': protected}, indent=2))
    for name, (body, stat) in previous.items():
        if body is not None:
            saved = receipt / 'backend' / name; saved.parent.mkdir(exist_ok=True, parents=True); saved.write_bytes(body)
    report['private_backup'] = str(receipt)
    stopped = True
    run('docker', 'stop', '--time', '20', WEB, timeout=45)
    assert not state()['State']['Running']
    assert snapshot() == original, 'Database changed while draining; obtain a fresh recovery point'
    changed = True
    for name, body in package.items(): write(destination(name), body, previous[name][1])
    assert runtime() == manifest['accepted_runtime']
    run('docker', 'start', WEB); report['health'] = health(); stopped = False
    after = snapshot()
    additions = verify_original_records(original, after)
    assert runtime() == manifest['accepted_runtime'] and others() == protected
    assert state()['State']['Running'] and sha((ROOT / 'compose.yaml').read_bytes()) == COMPOSE_SHA
    with urllib.request.urlopen('http://127.0.0.1:19181/login', timeout=10) as response:
        assert sha(response.read()) == manifest['frontend_index_sha256']
    (receipt / 'after.json').write_text(json.dumps(after, indent=2))
    report.update(passed=True, runtime_files_verified=len(manifest['accepted_runtime']), installed_file_count=len(package),
                  records_preserved=sum(c['count'] for c in original.values()), collections_preserved=len(original),
                  original_time_entries=original['time_entries']['count'], original_records_and_indexes_preserved=True,
                  new_empty_collections=additions, other_services_unchanged=True, frontend_unchanged=True,
                  target_container_id=state()['Id'], target_started_at=state()['State']['StartedAt'])
except Exception as exc:
    report.update(failure_class=type(exc).__name__, failure_message=str(exc))
    if changed:
        try:
            stopped = True; run('docker', 'stop', '--time', '20', WEB, timeout=45)
            for name, (body, stat) in previous.items():
                path = destination(name)
                if body is None:
                    if path.is_file(): path.unlink()
                else: write(path, body, stat)
            assert runtime() == manifest['previous_runtime']
            report['previous_runtime_restored'] = True
        except Exception as recovery: report['runtime_recovery_failure'] = type(recovery).__name__
finally:
    if stopped:
        try:
            run('docker', 'start', WEB); health(); report['web_recovered'] = True
        except Exception as recovery: report['web_recovery_failure'] = type(recovery).__name__
    report['completed_at'] = datetime.now(timezone.utc).isoformat()
    if receipt: (receipt / 'result.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report))
sys.exit(0 if report['passed'] else 1)
