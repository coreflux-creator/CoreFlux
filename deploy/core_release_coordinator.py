"""Outside-host Core owner transport, guarded database transfer and early cleanup.

This command does not publish code, run migrations, restore a database or start a
release. It can inspect an already reviewed operation and finish its early abort.
The imported transfer API additionally requires actual writer observations and,
for restore, a previously established native recovery intent.
Native owners retain the authoritative intents and receipts; transport failures
never trigger an automatic replay or a change of recovery direction.
"""
from __future__ import annotations

import argparse
import base64
from dataclasses import dataclass
import hashlib
import json
import math
import os
from pathlib import Path
import queue
import secrets
import shlex
import subprocess
import threading
import time

from inspect_full_core_release import ROOT, HOST, OWNERS, encode, exact_hex, private_path

WORKER_FILES = frozenset({
    'deploy/run_full_core_release_owner.php', 'deploy/full_core_release_owner.php',
    'deploy/full_core_release_control.php', 'deploy/full_core_release_files.php',
    'deploy/full_core_upgrade_executor.php', 'deploy/full_core_production_upgrade_context.php',
    'deploy/full_core_maintenance_host.php', 'deploy/full_core_maintenance.php',
    'deploy/full_core_migration_plan.php', 'deploy/full_core_migration_history.json',
    'deploy/full_core_database_state.php', 'core/migration_hash.php',
    'deploy/full_core_database_transfer.php',
    'deploy/inspect_full_core_writers.php', 'deploy/full_core_writer_policy.php',
})
ABORT_PHASES = ('verify-preparation-abort', 'restore-preparation-hold', 'complete-preparation-abort')
READ_ACTIONS = ('inspect-control', 'inspect-preparation-abort', 'inspect-writers', 'witness-writers', 'witness-held')
MAX_MESSAGE = 1024 * 1024


class OwnerInterrupted(RuntimeError):
    """The native outcome must be inspected before another action is selected."""


@dataclass(frozen=True)
class Endpoint:
    host: str
    user: str
    port: int = 22


def validate_config(config: dict) -> None:
    if config.get('version') != 1 or set(config.get('owners', {})) != {'1003', '1004'}:
        raise ValueError('Both native Core accounts are required')
    spec = config['control_spec']
    if spec.get('version') != 1 or spec.get('root') != ROOT:
        raise ValueError('Exact Core target required')
    for key, size in {'operation': 32, 'candidate_commit': 40, 'manifest_sha256': 64,
                      'migration_plan_sha256': 64}.items():
        if not exact_hex(spec.get(key), size):
            raise ValueError('Incomplete release identity')
    if list(spec.get('owner_plan_sha256', {})) != ['1003', '1004'] or any(
            not exact_hex(value, 64) for value in spec['owner_plan_sha256'].values()):
        raise ValueError('Both ordered native owner plans are required')
    for owner in config['owners'].values():
        private_path(owner['bundle_directory'])
        private_path(owner['config_path'])
        if not exact_hex(owner.get('config_sha256'), 64):
            raise ValueError('Native configuration must be pinned')
        hashes = owner['worker_files_sha256']
        if set(hashes) != WORKER_FILES or any(not exact_hex(value, 64) for value in hashes.values()):
            raise ValueError('Complete native worker dependency bundle must be pinned')
    if config['owners']['1003']['worker_files_sha256'] != config['owners']['1004']['worker_files_sha256']:
        raise ValueError('Both native accounts must execute the same reviewed worker')


def _read_lines(stream, events: queue.Queue) -> None:
    try:
        while True:
            line = stream.readline(MAX_MESSAGE + 2)
            if not line:
                events.put(('eof', None))
                return
            if len(line) > MAX_MESSAGE or not line.endswith(b'\n'):
                events.put(('invalid', None))
                return
            events.put(('line', line))
    except (OSError, ValueError):
        events.put(('invalid', None))


class Coordinator:
    def __init__(self, config: dict, identity: str, *, ssh: str = 'ssh',
                 known_hosts: str | None = None, endpoints: dict[int, Endpoint] | None = None,
                 timeout: float = 120, php_binary: str = '/usr/bin/php8.2', observe=None):
        validate_config(config)
        # Freeze caller-owned dictionaries so a callback cannot change the pinned target.
        self.config = json.loads(encode(config))
        self.identity = str(Path(identity).resolve())
        self.ssh = ssh
        self.known_hosts = str(Path(known_hosts).resolve()) if known_hosts else None
        self.endpoints = dict(endpoints or {uid: Endpoint(HOST, user) for uid, user in OWNERS.items()})
        self.timeout = timeout
        self.php_binary = private_path(php_binary)
        self.observe = observe
        self.spec_hash = hashlib.sha256(encode(config['control_spec'])).hexdigest()

    def _command(self, uid: int) -> list[str]:
        owner = self.config['owners'][str(uid)]
        endpoint = self.endpoints[uid]
        checks = ''.join(f'{sha}  {name}\n' for name, sha in sorted(owner['worker_files_sha256'].items()))
        php = shlex.quote(self.php_binary)
        version_check = shlex.quote('if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==2)exit(2);')
        command = (f'test "$(id -u)" = {uid} && cd {shlex.quote(owner["bundle_directory"])} && '
                   f'printf %s {shlex.quote(checks)} | sha256sum --check --status && '
                   f'{php} -r {version_check} && '
                   f'exec {php} deploy/run_full_core_release_owner.php --execute-owner-config '
                   + shlex.quote(owner['config_path']) + ' ' + owner['config_sha256'])
        args = [self.ssh, '-T', '-i', self.identity, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
                '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15',
                '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2']
        if self.known_hosts:
            args += ['-o', 'UserKnownHostsFile=' + self.known_hosts]
        return args + ['-p', str(endpoint.port), endpoint.user + '@' + endpoint.host, command]

    def worker_command_hash(self, uid: int) -> str:
        owner = self.config['owners'][str(uid)]
        arguments = [self.php_binary, 'deploy/run_full_core_release_owner.php', '--execute-owner-config',
                     owner['config_path'], owner['config_sha256']]
        return hashlib.sha256('\0'.join(arguments).encode('utf-8')).hexdigest()

    def _verify_writer_response(self, uid: int, action: str, data: dict, result: dict, requested: float) -> None:
        scope = 'native-writer-preparation-evidence' if action == 'inspect-writers' else 'native-writer-classification'
        if (result.get('version') != 1 or result.get('owner_uid') != uid or result.get('root') != ROOT
                or result.get('scope') != scope or not exact_hex(data.get('challenge'), 64)
                or result.get('challenge') != data['challenge']):
            raise ValueError('Fresh native writer observation identity differs')
        now = time.time()
        start, end = result.get('started_at_unix'), result.get('observed_at_unix')
        if (type(start) not in (int, float) or type(end) not in (int, float)
                or not math.isfinite(start) or not math.isfinite(end)
                or start < requested - 2 or end < start or end > now + 2 or now - start > 15):
            raise ValueError('Native writer observation is stale or incomplete')
        if action == 'witness-writers' and result.get('runtime_sha256') != hashlib.sha256(encode(data.get('runtime'))).hexdigest():
            raise ValueError('Native writer observation belongs to another runtime')

    def _verify_state(self, uid: int, result: dict) -> None:
        state = result.get('state', {})
        if state.get('owner_uid') != uid or state.get('spec_sha256') != self.spec_hash:
            raise ValueError('Native control state belongs to another account or release')
        if state.get('operation') != self.config['control_spec']['operation']:
            raise ValueError('Native control operation differs')

    def _verify_outcome(self, uid: int, phase: str, outcome: dict) -> None:
        spec = self.config['control_spec']
        for key in ('operation', 'candidate_commit', 'manifest_sha256', 'migration_plan_sha256', 'root'):
            if outcome.get(key) != spec[key]:
                raise ValueError('Native outcome belongs to another release')
        if outcome.get('owner_uid') != uid or outcome.get('action') != phase or (
                outcome.get('owner_plan_sha256') != spec['owner_plan_sha256'][str(uid)]):
            raise ValueError('Native outcome account, phase or plan differs')
        if any(not exact_hex(outcome.get(key), 64) for key in ('evidence_sha256', 'native_receipt_sha256')):
            raise ValueError('Incomplete native outcome')

    def _exchange(self, uid: int, action: str, query: dict, challenge: str) -> dict:
        if not isinstance(query, dict) or query.get('owner_uid') != uid or query.get('controller_challenge') != challenge:
            raise ValueError('Native exchange identity differs')
        if query.get('kind') == 'hold-witness' and action in ('capture-database-stream','empty-recovery-database','restore-database-stream'):
            spec = self.config['control_spec']
            if self.observe is None or any(query.get(key) != spec[key] for key in ('operation','candidate_commit','migration_plan_sha256')):
                raise ValueError('Actual current writer observations are required for database transfer')
            return self.observe(query)
        if query.get('operation') != self.config['control_spec']['operation'] or query.get('control_spec_sha256') != self.spec_hash:
            raise ValueError('Native exchange belongs to another release')
        if query.get('kind') != 'preparation-abort-witness' or action not in (
                'restore-preparation-hold', 'complete-preparation-abort', 'resume-preparation-abort'):
            raise ValueError('This coordinator command does not authorize the requested exchange')
        if not exact_hex(query.get('challenge'), 64):
            raise ValueError('Fresh peer witness challenge required')
        # A separate authenticated SSH session reads the other native account directly.
        return self.call(1004 if uid == 1003 else 1003, 'inspect-preparation-abort',
                         {'challenge': query['challenge']})

    def call(self, uid: int, action: str, data: dict | None = None, peer: dict | None = None,
             *, capture_sink=None, restore_source=None, timeout: float | None = None) -> dict:
        if uid not in OWNERS or action not in (*READ_ACTIONS, *ABORT_PHASES,
                                              'request-preparation-abort', 'resume-preparation-abort',
                                              'capture-database-stream','empty-recovery-database','restore-database-stream'):
            raise ValueError('Explicit reviewed native action required')
        if ((action == 'capture-database-stream') != (capture_sink is not None)
                or (action == 'restore-database-stream') != (restore_source is not None)):
            raise ValueError('Explicit bounded database stream endpoint required')
        challenge = secrets.token_hex(32)
        request = {'action': action, 'input': data or {}, 'peer': peer, 'challenge': challenge}
        wire = encode(request) + b'\n'
        if len(wire) > MAX_MESSAGE:
            raise ValueError('Unbounded native input refused')
        requested = time.time()
        process = subprocess.Popen(self._command(uid), stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.DEVNULL)
        events: queue.Queue = queue.Queue(maxsize=32)
        reader = threading.Thread(target=_read_lines, args=(process.stdout, events), daemon=True)
        reader.start()
        deadline = time.monotonic() + (self.timeout if timeout is None else timeout)
        result = None
        exchanges = 0
        stream_bytes = 0
        stream_hash = hashlib.sha256()
        try:
            process.stdin.write(wire)
            process.stdin.flush()
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError()
                kind, line = events.get(timeout=remaining)
                if kind == 'eof':
                    break
                if kind != 'line':
                    raise ValueError('Unbounded or incomplete native response')
                event = json.loads(line)
                if not isinstance(event, dict) or result is not None:
                    raise ValueError('Unexpected native response sequence')
                if 'stream' in event:
                    if (action != 'capture-database-stream' or event.get('challenge') != challenge or event.get('owner_uid') != uid
                            or set(event) != {'stream','challenge','owner_uid'} or event['stream'].get('offset') != stream_bytes):
                        raise ValueError('Native database stream identity or order differs')
                    raw = base64.b64decode(event['stream']['data'], validate=True)
                    if not raw or len(raw) > 524288:
                        raise ValueError('Bounded native SQL frame required')
                    capture_sink(raw)
                    stream_bytes += len(raw); stream_hash.update(raw)
                elif 'response' in event:
                    if set(event) != {'response', 'challenge', 'owner_uid'} or (
                            event['challenge'] != challenge or event['owner_uid'] != uid):
                        raise ValueError('Native SSH response identity differs')
                    result = event['response']
                    if not isinstance(result, dict):
                        raise ValueError('Native response object required')
                elif set(event) == {'request'} and exchanges < (40000 if action in ('capture-database-stream','empty-recovery-database','restore-database-stream') else 2):
                    exchanges += 1
                    query = event['request']
                    if query.get('kind') == 'database-restore-chunk':
                        if (action != 'restore-database-stream' or query.get('controller_challenge') != challenge or query.get('owner_uid') != uid
                                or query.get('operation') != self.config['control_spec']['operation'] or query.get('control_spec_sha256') != self.spec_hash
                                or query.get('offset') != stream_bytes or query.get('max_bytes') != 524288):
                            raise ValueError('Exact native restore stream request required')
                        raw = restore_source.read(524288)
                        response = {'offset':stream_bytes,'data':base64.b64encode(raw).decode('ascii')}
                        stream_bytes += len(raw); stream_hash.update(raw)
                    else:
                        response = self._exchange(uid, action, query, challenge)
                    process.stdin.write(encode(response) + b'\n')
                    process.stdin.flush()
                else:
                    raise ValueError('Unexpected native exchange')
            process.stdin.close()
            code = process.wait(timeout=max(0.001, deadline - time.monotonic()))
            if code != 0 or result is None:
                raise OwnerInterrupted(f'Owner {uid} action {action} refused or interrupted (exit {code})')
            if 'state' in result:
                self._verify_state(uid, result)
            if action in ('inspect-writers', 'witness-writers'):
                self._verify_writer_response(uid, action, data or {}, result, requested)
            if action in ('capture-database-stream','restore-database-stream') and (
                    result.get('sql_bytes') != stream_bytes or result.get('sql_sha256') != stream_hash.hexdigest()):
                raise ValueError('Native database stream completion differs')
            if action == 'inspect-preparation-abort':
                if (result.get('owner_uid') != uid or result.get('challenge') != (data or {}).get('challenge')
                        or result.get('operation') != self.config['control_spec']['operation']
                        or result.get('control_spec_sha256') != self.spec_hash
                        or result.get('direction') != 'preparation-abort'
                        or result.get('verified_preparation_abort') is not True
                        or result.get('original_files_verified') is not True
                        or result.get('owner_release_mutations_never_started') is not True):
                    raise ValueError('Fresh native preparation inspection differs')
            phase = (data or {}).get('phase') if action == 'resume-preparation-abort' else action
            if phase in ABORT_PHASES:
                self._verify_outcome(uid, phase, result.get('control', {}))
            return result
        except (ValueError, OSError, queue.Empty, TimeoutError, subprocess.TimeoutExpired) as error:
            raise OwnerInterrupted(f'Owner {uid} action {action} lost its verified response; inspect native state, do not replay') from error
        finally:
            if process.poll() is None:
                process.kill()  # Stops local SSH only; the native intent remains authoritative.
                process.wait()
            if not process.stdin.closed:
                process.stdin.close()
            reader.join(timeout=1)
            process.stdout.close()

    def outcome(self, uid: int, phase: str) -> dict:
        report = self.call(uid, 'inspect-control')
        completed = (report.get('completed') or {}).get(phase)
        if not isinstance(completed, dict):
            raise ValueError(f'Owner {uid} has no verified completed {phase} receipt')
        outcome = completed['control']
        self._verify_outcome(uid, phase, outcome)
        return outcome

    def step(self, uid: int, phase: str, *, resume: bool = False) -> dict:
        if phase not in (*ABORT_PHASES, 'request-preparation-abort'):
            raise ValueError('Explicit preparation-abort phase required')
        report = self.call(uid, 'inspect-control')
        state = report['state']
        if phase in (report.get('completed') or {}):
            # A lost reply can be recovered from a completed native receipt, never by replay.
            if resume:
                raise ValueError('No pending native phase to resume')
            self._verify_outcome(uid, phase, report['completed'][phase]['control'])
            return report['completed'][phase]
        if phase == 'request-preparation-abort' and state['direction'] == 'preparation-abort':
            return report
        if resume and (phase not in ABORT_PHASES or (state.get('pending') or {}).get('action') != phase):
            raise ValueError('Explicit resume must match the durable pending abort phase')
        if not resume and phase in ABORT_PHASES and state['pending'] is not None:
            raise ValueError('Interrupted phase requires explicit resume after native inspection')
        required = {'restore-preparation-hold': 'verify-preparation-abort',
                    'complete-preparation-abort': 'restore-preparation-hold'}.get(phase)
        if phase == 'request-preparation-abort' and uid == 1003:
            required = 'verify-preparation-abort'
        peer = self.outcome(1004 if uid == 1003 else 1003, required) if required else None
        return self.call(uid, 'resume-preparation-abort' if resume else phase,
                         {'phase': phase} if resume else {}, peer)

    def abort_preparation(self) -> dict:
        # Fence the sole database writer first; failure stops before fencing the master.
        for uid in (1004, 1003):
            self.step(uid, 'request-preparation-abort')
            self.step(uid, 'verify-preparation-abort')
        for uid in (1004, 1003):
            self.step(uid, 'restore-preparation-hold')
        for uid in (1003, 1004):
            self.step(uid, 'complete-preparation-abort')
        reports = {str(uid): self.call(uid, 'inspect-control') for uid in OWNERS}
        if any(r['state']['terminal'] != 'preparation-aborted' or r['state']['pending'] is not None
               for r in reports.values()):
            raise ValueError('Both native aborts must be complete')
        for uid in OWNERS:
            observed = self.call(uid, 'inspect-preparation-abort', {'challenge': secrets.token_hex(32)})
            if observed.get('host', {}).get('original') is not True:
                raise ValueError('Original native service state must still be present')
            reports[str(uid)]['current_preparation'] = observed
        return reports


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['inspect', 'abort-preparation', 'resume-preparation-abort'])
    parser.add_argument('--config', type=Path, required=True)
    parser.add_argument('--config-sha256', required=True)
    parser.add_argument('--identity', required=True)
    parser.add_argument('--ssh', default='ssh')
    parser.add_argument('--known-hosts')
    parser.add_argument('--owner', type=int, choices=[1003, 1004])
    parser.add_argument('--phase', choices=ABORT_PHASES)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    raw = args.config.read_bytes()
    if not exact_hex(args.config_sha256, 64) or hashlib.sha256(raw).hexdigest() != args.config_sha256:
        raise ValueError('Reviewed local coordinator configuration changed')
    if ((args.action == 'resume-preparation-abort' and (args.owner is None or args.phase is None))
            or (args.action != 'resume-preparation-abort' and (args.owner is not None or args.phase is not None))):
        raise ValueError('Resume requires both the exact owner and pending phase')
    controller = Coordinator(json.loads(raw), args.identity, ssh=args.ssh, known_hosts=args.known_hosts)
    # Reserve a fresh record before an action; a lost response leaves this intent for inspection.
    with os.fdopen(os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb') as receipt:
        receipt.write(encode({'action': args.action, 'config_sha256': args.config_sha256,
                              'status': 'requested', 'owner': args.owner, 'phase': args.phase}) + b'\n')
        receipt.flush()
        os.fsync(receipt.fileno())
        if args.action == 'inspect':
            result = {str(uid): controller.call(uid, 'inspect-control') for uid in OWNERS}
        elif args.action == 'abort-preparation':
            result = controller.abort_preparation()
        else:
            result = controller.step(args.owner, args.phase, resume=True)
        receipt.write(encode({'status': 'verified', 'result': result}) + b'\n')
        receipt.flush()
        os.fsync(receipt.fileno())
    print(json.dumps({'action': args.action, 'status': 'verified', 'receipt': str(args.output)}))


if __name__ == '__main__':
    main()
