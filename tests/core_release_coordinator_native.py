"""Only the disposable, network-isolated native fixture selects loopback endpoints."""
import hashlib
import json
import os
from pathlib import Path
import sys
import subprocess

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import Coordinator, Endpoint, WORKER_FILES, OwnerInterrupted

if not Path('/run/core-production-upgrade-fixture').is_file() or os.geteuid() != 0:
    raise RuntimeError('Disposable native fixture only')
if sys.argv[1] == '--drop-response':
    # Native SSH runs to completion, but the controller loses the final reply.
    process = subprocess.Popen(sys.argv[2:], stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    process.stdin.write(sys.stdin.buffer.readline())
    process.stdin.flush()
    for line in process.stdout:
        if 'response' in json.loads(line):
            continue
        sys.stdout.buffer.write(line)
        sys.stdout.buffer.flush()
        process.stdin.write(sys.stdin.buffer.readline())
        process.stdin.flush()
    process.stdin.close()
    if process.wait() != 0:
        sys.exit(2)
    sys.exit(86)
base = Path(sys.argv[1])
if base.parent != Path('/fixture') or not base.is_dir():
    raise RuntimeError('Exact fixture directory required')
spec = json.loads((base / 'spec.json').read_bytes())['control']
hashes = {name: hashlib.sha256((Path('/app') / name).read_bytes()).hexdigest() for name in WORKER_FILES}
config = {'version': 1, 'control_spec': spec, 'owners': {
    str(uid): {'bundle_directory': '/app', 'config_path': str(base / f'owner-config-{uid}.json'),
               'config_sha256': hashlib.sha256((base / f'owner-config-{uid}.json').read_bytes()).hexdigest(),
               'worker_files_sha256': hashes.copy()} for uid in (1003, 1004)}}
uid, action = int(sys.argv[2]), sys.argv[3]
controller = Coordinator(config, '/fixture/ssh-key', known_hosts='/fixture/known-hosts',
                         php_binary='/usr/local/bin/php',
                         endpoints={1003: Endpoint('127.0.0.1', 'coremaster', 2222),
                                    1004: Endpoint('127.0.0.1', 'coreapp', 2222)})
try:
    if action == 'abort-preparation':
        result = controller.abort_preparation()
    elif action == 'bad-bundle':
        controller.config['owners'][str(uid)]['worker_files_sha256']['deploy/full_core_release_control.php'] = '0' * 64
        result = controller.call(uid, 'inspect-control')
    elif action == 'bad-config':
        controller.config['owners'][str(uid)]['config_sha256'] = '0' * 64
        result = controller.call(uid, 'inspect-control')
    elif action == 'wrong-owner':
        controller.endpoints[uid] = controller.endpoints[1004 if uid == 1003 else 1003]
        result = controller.call(uid, 'inspect-control')
    elif action == 'inspect-control':
        result = controller.call(uid, action)
    elif action == 'lost-verify-reply':
        native_command = controller._command
        controller._command = lambda owner: [sys.executable, __file__, '--drop-response', *native_command(owner)]
        result = controller.call(uid, 'verify-preparation-abort')
    else:
        resume = action.startswith('resume:')
        result = controller.step(uid, action.removeprefix('resume:'), resume=resume)
    print(json.dumps(result, separators=(',', ':')))
except (OwnerInterrupted, ValueError) as error:
    print(str(error), file=sys.stderr)
    sys.exit(2)
