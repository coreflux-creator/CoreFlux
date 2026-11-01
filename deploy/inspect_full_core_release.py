"""Inspect the reviewed full-Core package through both existing SSH owner identities.

This command has no deployment, retry, scheduler-write, database or activation path.
It pins the worker source before execution and binds each reply to a fresh challenge.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import secrets
import shlex
import subprocess
import time

ROOT = '/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html'
HOST = '138.197.121.111'
OWNERS = {1003: 'master_fpepuvndbb', 1004: 'kunalverma2'}
WORKER_FILES = {'inspect_full_core_release_owner.php', 'full_core_release_control.php', 'full_core_maintenance.php'}


def encode(value: object) -> bytes:
    return json.dumps(value, separators=(',', ':'), ensure_ascii=False, allow_nan=False).encode('utf-8')


def digest(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def exact_hex(value: object, length: int) -> bool:
    return isinstance(value, str) and re.fullmatch('[a-f0-9]{' + str(length) + '}', value) is not None


def private_path(value: object) -> str:
    if not isinstance(value, str) or not re.fullmatch(r'/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*', value):
        raise ValueError('A canonical private path is required')
    if value == ROOT or value.startswith(ROOT + '/'):
        raise ValueError('Inspection inputs must remain outside the webroot')
    return value


def validate_config(config: dict) -> None:
    if config.get('version') != 1 or set(config.get('owners', {})) != {'1003', '1004'}:
        raise ValueError('Both existing Core owners are required')
    spec = config['control_spec']
    if spec.get('version') != 1 or spec.get('root') != ROOT:
        raise ValueError('Exact Core release root required')
    for name, length in {'operation': 32, 'candidate_commit': 40, 'manifest_sha256': 64, 'migration_plan_sha256': 64}.items():
        if not exact_hex(spec.get(name), length):
            raise ValueError('Invalid control specification')
    if list(spec.get('owner_plan_sha256', {})) != ['1003', '1004'] or any(not exact_hex(h, 64) for h in spec['owner_plan_sha256'].values()):
        raise ValueError('Both ordered owner-plan hashes required')
    for owner in config['owners'].values():
        for name in ['worker_directory', 'package_directory', 'owner_plan_path']:
            private_path(owner[name])
        hashes = owner['worker_files_sha256']
        if set(hashes) != WORKER_FILES or any(not exact_hex(h, 64) for h in hashes.values()):
            raise ValueError('The exact three-file inspection worker must be pinned')


def verify_reply(reply: dict, request: dict) -> None:
    spec = request['control_spec']
    for name in ['version', 'action', 'challenge', 'owner_uid', 'control_spec_sha256']:
        if reply.get(name) != request[name]:
            raise ValueError('SSH reply does not match this owner and challenge')
    for name in ['root', 'operation', 'candidate_commit', 'manifest_sha256']:
        if reply.get(name) != spec[name]:
            raise ValueError('SSH reply belongs to another release')
    if reply.get('owner_plan_sha256') != spec['owner_plan_sha256'][str(request['owner_uid'])]:
        raise ValueError('SSH owner-plan reply differs')
    for name in ['production_changed', 'database_opened', 'writer_hold_active', 'ready_for_cutover']:
        if reply.get(name) is not False:
            raise ValueError('Inspection reply exceeds the read-only preparation scope')
    for name in ['preimage_drift', 'inaccessible_parents_or_files']:
        if not isinstance(reply.get(name), list):
            raise ValueError('Inspection reply is incomplete')


def inspect_owner(config: dict, uid: int, identity: str, ssh: str) -> dict:
    owner = config['owners'][str(uid)]
    spec = config['control_spec']
    request = {'version': 1, 'action': 'inspect', 'owner_uid': uid, 'challenge': secrets.token_hex(32),
               'control_spec': spec, 'control_spec_sha256': digest(encode(spec)),
               'package_directory': owner['package_directory'], 'owner_plan_path': owner['owner_plan_path']}
    checks = ''.join(f'{sha}  {name}\n' for name, sha in sorted(owner['worker_files_sha256'].items()))
    command = (f'test "$(id -u)" = {uid} && cd {shlex.quote(owner["worker_directory"])} && '
               f'printf %s {shlex.quote(checks)} | sha256sum --check --status && '
               'exec php inspect_full_core_release_owner.php')
    args = [ssh, '-T', '-i', str(Path(identity).resolve()), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=2', f'{OWNERS[uid]}@{HOST}', command]
    started = time.time()
    process = subprocess.run(args, input=encode(request), capture_output=True, timeout=180, check=False)
    if process.returncode:
        # The worker never emits schedules, credentials or application data. No retries are made.
        raise RuntimeError(f'Owner {uid} inspection refused (exit {process.returncode}): '
                           + process.stderr.decode('utf-8', errors='replace')[:1500])
    if len(process.stdout) > 4 * 1024 * 1024:
        raise ValueError('Unbounded SSH reply refused')
    reply = json.loads(process.stdout)
    verify_reply(reply, request)
    return {'request_sha256': digest(encode(request)), 'response_sha256': digest(process.stdout),
            'elapsed_seconds': round(time.time() - started, 3), 'observation': reply}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, required=True)
    parser.add_argument('--config-sha256', required=True)
    parser.add_argument('--identity', required=True)
    parser.add_argument('--ssh', default='ssh')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    raw = args.config.read_bytes()
    if not exact_hex(args.config_sha256, 64) or digest(raw) != args.config_sha256:
        raise ValueError('Reviewed local preparation config changed')
    config = json.loads(raw); validate_config(config)
    # Sequential explicit identities; each reply is retained before the second read begins.
    if args.output.exists():
        raise ValueError('Use a new inspection receipt directory')
    args.output.mkdir(mode=0o700, parents=False)
    observations = {}
    for uid in OWNERS:
        receipt = inspect_owner(config, uid, args.identity, args.ssh)
        (args.output / f'owner-{uid}.json').write_bytes(encode(receipt) + b'\n')
        observations[str(uid)] = receipt
    blocked = any(r['observation']['preimage_drift'] or r['observation']['inaccessible_parents_or_files'] for r in observations.values())
    summary = {'version': 1, 'scope': 'read-only two-owner SSH release preparation',
               'config_sha256': args.config_sha256, 'candidate_commit': config['control_spec']['candidate_commit'],
               'both_owner_packages_verified': True, 'preimage_or_access_changes': bool(blocked),
               'owners': {uid: {'response_sha256': r['response_sha256'],
                                'candidate_files_verified': r['observation']['candidate_files_verified'],
                                'planned_file_changes': r['observation']['planned_file_changes'],
                                'preimage_drift_count': len(r['observation']['preimage_drift']),
                                'access_issue_count': len(r['observation']['inaccessible_parents_or_files'])}
                          for uid, r in observations.items()},
               'production_changed': False, 'database_opened': False, 'writer_hold_active': False, 'ready_for_cutover': False,
               'limits': ['This is a point-in-time preparation check, not a writer hold or a backup.',
                          'The guarded execution adapter must revalidate metadata and candidate bytes under the final hold.',
                          'Existing control state requires separate outcome inspection before starting any operation.']}
    (args.output / 'summary.json').write_bytes(encode(summary) + b'\n')
    print(json.dumps(summary, indent=2))
    if blocked:
        raise SystemExit(3)


if __name__ == '__main__':
    main()
