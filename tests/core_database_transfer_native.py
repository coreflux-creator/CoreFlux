"""Real external encrypted transfer, with the native fixture supplying actual host/FPM observations."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import Coordinator, Endpoint, WORKER_FILES
from core_database_archive import AesGcm, ArchiveReader
from core_database_backup import capture, restore

if not Path('/run/core-production-upgrade-fixture').is_file() or os.geteuid() != 0:
    raise RuntimeError('Disposable native fixture only')
base = Path(sys.argv[1])
if base.parent != Path('/fixture') or not base.is_dir():
    raise RuntimeError('Exact fixture path required')
initial = json.loads(sys.stdin.buffer.readline())


def observe(query):
    print(json.dumps({'request': query}, separators=(',', ':')), flush=True)
    return json.loads(sys.stdin.buffer.readline())


hashes = {name: hashlib.sha256((Path('/app') / name).read_bytes()).hexdigest() for name in WORKER_FILES}
config = {'version': 1, 'control_spec': json.loads((base / 'spec.json').read_bytes())['control'],
          'owners': {str(uid): {'bundle_directory': '/app', 'config_path': str(base / f'owner-config-{uid}.json'),
                                'config_sha256': hashlib.sha256((base / f'owner-config-{uid}.json').read_bytes()).hexdigest(),
                                'worker_files_sha256': hashes.copy()} for uid in (1003, 1004)}}
controller = Coordinator(config, '/fixture/ssh-key', known_hosts='/fixture/known-hosts', observe=observe,
                         php_binary='/usr/local/bin/php', endpoints={1003: Endpoint('127.0.0.1', 'coremaster', 2222),
                                                                  1004: Endpoint('127.0.0.1', 'coreapp', 2222)})
key_path = base / 'backup' / 'archive.key'
archive = base / 'backup' / 'database.aesgcm'
guard = hashlib.sha256((base / 'guard-1004.json').read_bytes()).hexdigest()
try:
    if sys.argv[2] == 'external-capture':
        with os.fdopen(os.open(key_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb') as key_file:
            key_file.write(secrets.token_bytes(32))
        result = capture(controller, archive, guard, cipher=AesGcm(key_path.read_bytes()))
    elif sys.argv[2] in ('external-restore','external-restore-short'):
        expected = json.loads((base / 'recovery-backup-pair.json').read_bytes())['database_backup']
        expectation_hash = hashlib.sha256((base / 'restore-expectation.json').read_bytes()).hexdigest()
        if sys.argv[2] == 'external-restore-short':
            with ArchiveReader(archive,AesGcm(key_path.read_bytes())) as reader:
                reader.verify(expected)
                binding={'guard_sha256':guard,'expectation_sha256':expectation_hash}
                controller.call(1004,'empty-recovery-database',binding)
                class ShortSource:
                    sent = False
                    def read(self,size):
                        if self.sent:
                            return b''
                        self.sent = True
                        return reader.read(4096)
                result=controller.call(1004,'restore-database-stream',binding,restore_source=ShortSource())
        else:
            result = restore(controller, archive, expected, guard, expectation_hash, cipher=AesGcm(key_path.read_bytes()))
    else:
        raise ValueError('Explicit fixture transfer action required')
    print(json.dumps({'response': result, 'challenge': initial['challenge'], 'owner_uid': 1004}, separators=(',', ':')))
except Exception as error:
    print(type(error).__name__ + ': native database transfer failed; inspect private native diagnostics', file=sys.stderr)
    sys.exit(2)
