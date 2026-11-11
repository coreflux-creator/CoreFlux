"""Rehearse the actual installer against temporary files and simulated services.

Linux only. No Docker command, network request, or provider record is used.
The optional stdin source supports running in an existing Linux host process.
"""
import ast
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import tempfile
import urllib.error
from unittest.mock import patch

source = json.load(sys.stdin)['source'] if '--stdin-source' in sys.argv else (Path(__file__).resolve().parents[1] / 'deploy/install_thunderhawk_assignment_time.py').read_text()
required = ast.literal_eval(re.search(r'^REQUIRED = (.+)$', source, re.M).group(1))
revision = re.search(r"^REVISION = '([^']+)'", source, re.M).group(1)
digest = lambda value: hashlib.sha256(value).hexdigest()
results = []

for scenario in ['success', 'stale_container', 'enabled_policy', 'changed_backend', 'bad_digest', 'unexpected_record', 'active_policy_after', 'startup_failure', 'served_frontend_mismatch']:
    with tempfile.TemporaryDirectory(prefix='quanta-time-installer-test-') as folder:
        root = Path(folder)
        private = root / 'private'
        private.mkdir(mode=0o700)
        app = root / 'quanta'
        old = b'old_value = 1\n'
        new = b'new_value = 2\n'  # Same size catches stale Python cache timestamps.
        prior = {}
        for name in sorted(required - {'backend/app/coreflux_time_access.py'}):
            p = app / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(old)
            os.utime(p, (1_600_000_000, 1_600_000_000))
            prior[name] = digest(old)
        index = app / 'frontend/index.html'
        index.parent.mkdir(parents=True)
        index.write_bytes(b'old html')
        package = {name: new for name in required}
        package['frontend/index.html'] = b'<head>test policy</head>'
        manifest = {'revision': revision, 'files': {n: digest(b) for n, b in package.items()},
                    'target_container_id': 'target', 'target_started_at': 'before',
                    'previous_index_sha256': digest(index.read_bytes()), 'browser_policy': 'test policy',
                    'previous_backend': {name: prior.get(name) for name in required}, 'entire_previous_backend': prior}
        package['manifest.json'] = json.dumps(manifest).encode()
        archive_path = private / 'quanta-assignment-time-4354556.tgz'
        with tarfile.open(archive_path, 'w:gz') as archive:
            for name, body in package.items():
                entry = tarfile.TarInfo(name)
                entry.size = len(body)
                archive.addfile(entry, io.BytesIO(body))
        expected = digest(archive_path.read_bytes())
        if scenario == 'bad_digest':
            expected = '0' * 64
        if scenario == 'changed_backend':
            (app / sorted(prior)[0]).write_bytes(b'changed = 1\n')
        initial_files = {p.relative_to(app).as_posix(): p.read_bytes() for p in app.rglob('*') if p.is_file()}
        env = {'DB_NAME': 'corepilot_quanta_20261029', 'MONGO_URL': 'mongodb://quanta-pilot-mongo:27017',
               'QUANTA_STARTUP_MODE': 'web', 'MAIL_DRIVER': 'devoutbox',
               'DISABLE_BACKGROUND_WORKERS': '1', 'DISABLE_SCHEDULER': '1'}
        if scenario == 'enabled_policy':
            env['COREFLUX_TIME_ACCESS_TENANTS'] = 'already-enabled'
        calls = []
        state = {'running': True, 'started': 'stale' if scenario == 'stale_container' else 'before', 'snapshots': 0}

        def row():
            return {'Id': 'target', 'Name': '/core-provider-pilot-20261029-quanta-pilot-web-1',
                    'Config': {'Env': [k + '=' + v for k, v in env.items()]},
                    'Mounts': [{'Source': str(app / 'backend'), 'Destination': '/app/backend'}],
                    'State': {'Running': state['running'], 'StartedAt': state['started'], 'Status': 'running'}}

        def fake_run(args, **kwargs):
            calls.append(args)
            assert args[0] == 'docker'
            if args[1] == 'inspect':
                response = [row()]
                if args[2] == 'other':
                    response = [{'Id': 'other', 'Name': '/protected', 'State': {'Status': 'running', 'StartedAt': 'unchanged'}}]
                return subprocess.CompletedProcess(args, 0, json.dumps(response), '')
            if args[1] == 'ps':
                return subprocess.CompletedProcess(args, 0, 'other', '')
            if args[1] in {'stop', 'start'}:
                state['running'] = args[1] == 'start'
                if state['running']:
                    state['started'] = 'after'
                return subprocess.CompletedProcess(args, 0, '', '')
            assert args[1] == 'exec'
            snapshot = {'people': {'count': 1, 'sha256': 'unchanged', 'indexes': {}},
                        'api_usage_events': {'count': 1, 'sha256': 'usage', 'indexes': {}, 'row_hashes': ['existing']}}
            if state['snapshots']:
                snapshot['coreflux_time_access'] = {'count': 0, 'sha256': 'empty', 'indexes': {}}
                if scenario == 'unexpected_record':
                    snapshot['people']['sha256'] = 'changed'
                if scenario == 'active_policy_after':
                    snapshot['coreflux_time_access']['count'] = 1
            state['snapshots'] += 1
            return subprocess.CompletedProcess(args, 0, json.dumps(snapshot), '')

        def fake_url(url, **kwargs):
            assert url.startswith('http://127.0.0.1:19181/')
            if url.endswith('/api/health'):
                if scenario == 'startup_failure':
                    raise OSError('Forced unavailable pilot')
                return io.BytesIO(json.dumps({'startup_mode': 'web', 'usage_recording': False, 'tenant_automations': {'running': False}}).encode())
            if url.endswith('/login'):
                return io.BytesIO(b'wrong' if scenario == 'served_frontend_mismatch' else package['frontend/index.html'])
            raise urllib.error.HTTPError(url, 401, 'Unauthorized', {}, None)

        executable = source.replace("ROOT = Path('/srv/core-integration-pilot/20261029')", 'ROOT = Path(' + repr(str(root)) + ')')
        with patch('subprocess.run', fake_run), patch('urllib.request.urlopen', fake_url), patch('time.sleep'), patch.object(sys, 'argv', ['installer', '--install-isolated-assignment-time', expected]), contextlib.redirect_stdout(io.StringIO()):
            try:
                exec(compile(executable, 'actual_installer', 'exec'), {})
            except SystemExit as error:
                exit_code = error.code
        report = json.loads((private / 'quanta-assignment-time-install-result.json').read_text())
        assert state['running'], scenario
        if scenario == 'success':
            assert exit_code == 0 and report['passed'] and not report['operational_policy_enabled']
            assert report['new_empty_collections'] == ['coreflux_time_access']
            assert all((app / n).read_bytes() == b for n, b in package.items() if n != 'manifest.json')
            assert all((app / n).stat().st_mtime > 1_600_000_000 for n in prior), 'new runtime must not retain old Python cache timestamps'
        else:
            assert exit_code == 1 and not report['passed'], scenario
            assert initial_files == {p.relative_to(app).as_posix(): p.read_bytes() for p in app.rglob('*') if p.is_file()}, scenario
            if scenario in {'stale_container', 'enabled_policy', 'changed_backend', 'bad_digest'}:
                assert not any(c[1] in {'stop', 'start'} for c in calls), scenario
            else:
                assert report['previous_runtime_restored'], scenario
                assert all((app / n).stat().st_mtime == 1_600_000_000 for n in prior), 'rollback restores original metadata'
        results.append(scenario)

print(json.dumps({'passed': len(results), 'scenarios': results, 'real_docker_or_network_used': False}))
