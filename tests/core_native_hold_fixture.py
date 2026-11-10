"""Real observer wiring, restricted to the disposable systemd/network-isolated fixture."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import Coordinator, Endpoint, WORKER_FILES
from core_native_hold_observer import NativeHoldObserver
from inspect_full_core_release import ROOT, encode


def fixture_only(base):
    if (os.geteuid() != 0 or os.environ.get('CORE_FIXTURE_NATIVE_WRITERS') != '1'
            or not Path('/run/core-production-upgrade-fixture').is_file()
            or Path('/proc/1/comm').read_text().strip() != 'systemd'
            or base.parent != Path('/fixture') or base.resolve() != base):
        raise RuntimeError('Disposable systemd fixture only')


class FixtureStatus:
    """Only transport differs: a private loopback HTTP endpoint in an offline container."""
    def __init__(self, base):
        fixture_only(base)
        self.spec = json.loads((base / 'spec.json').read_bytes())

    def __call__(self):
        connection = http.client.HTTPConnection('127.0.0.1', timeout=8)
        try:
            connection.request('POST', '/' + self.spec['path'], body=b'{"action":"status"}', headers={
                'Host': 'fixture.local', 'Content-Type': 'application/json', 'X-CoreFlux-Maintenance': self.spec['token']})
            response = connection.getresponse(); body = response.read(1048577)
            if response.status != 200 or len(body) > 1048576:
                raise ValueError('Actual fixture FPM status unavailable')
            return json.loads(body)
        finally:
            connection.close()


def controller_for(base):
    fixture_only(base)
    hashes = {name: hashlib.sha256((Path('/app') / name).read_bytes()).hexdigest() for name in WORKER_FILES}
    config = {'version': 1, 'control_spec': json.loads((base / 'spec.json').read_bytes())['control'],
              'owners': {str(uid): {'bundle_directory': '/app', 'config_path': str(base / f'owner-config-{uid}.json'),
                                    'config_sha256': hashlib.sha256((base / f'owner-config-{uid}.json').read_bytes()).hexdigest(),
                                    'worker_files_sha256': hashes.copy()} for uid in (1003, 1004)}}
    return Coordinator(config, '/fixture/ssh-key', known_hosts='/fixture/known-hosts', php_binary='/usr/local/bin/php',
                       endpoints={1003: Endpoint('127.0.0.1', 'coremaster', 2222),
                                  1004: Endpoint('127.0.0.1', 'coreapp', 2222)})


def observer_for(controller, base, uid, *, upgrade=False):
    fixture_only(base)
    path = base / 'execution/release-binding.json' if upgrade else base / f'guard-{uid}.json'
    return NativeHoldObserver(controller, json.loads(path.read_bytes()), FixtureStatus(base))


def pin(record):
    return {key: record[key] for key in ('pid', 'ppid', 'start_ticks', 'uids', 'command_title_sha256')}


def prepare_policy(base):
    controller = controller_for(base)
    reports = {uid: controller.call(uid, 'inspect-writers', {'challenge': secrets.token_hex(32)}) for uid in (1003, 1004)}
    first = reports[1003]
    if (any(r['unresolved'] for r in reports.values())
            or any(r['registry_sha256'] != first['registry_sha256'] or r['boot_id'] != first['boot_id'] for r in reports.values())):
        raise ValueError('Actual fixture schedulers/registry must be visible and consistent: ' + str({u: r['unresolved'] for u, r in reports.items()}))
    status = FixtureStatus(base)()
    pids = status['pool']['process_ids']
    if not status['pool']['request_pool_quiet'] or not pids:
        raise ValueError('Actual quiet FPM pool required')
    records = {r['pid']: r for report in reports.values() for r in report['processes'] + report['ancestors']}
    listener = int(Path('/fixture/sshd.pid').read_text())
    pool_master = records[pids[0]]['ppid']
    retained = []
    for uid in (1003, 1004):
        manager = int(subprocess.check_output(['systemctl', 'show', f'user@{uid}.service', '-p', 'MainPID', '--value']))
        record = records[manager]
        if record['uids'] != [uid] * 4 or record['core_reference']:
            raise ValueError('Actual user service manager required')
        retained.append(pin(record) | {'core_reference': False, 'reason': 'Pinned empty native user service manager'})
        for process in records.values():
            if (process['ppid'] == manager and process['uids'] == [uid] * 4
                    and process['command_title_sha256'] == hashlib.sha256(b'(sd-pam)').hexdigest()):
                retained.append(pin(process) | {'core_reference': False, 'reason': 'Native user-manager PAM helper'})
    policy = {'version': 1, 'root': ROOT, 'operation': controller.config['control_spec']['operation'],
              'candidate_commit': controller.config['control_spec']['candidate_commit'],
              'boot_id': first['boot_id'], 'registry_sha256': first['registry_sha256'], 'reviewed_limits': [],
              'schedulers': {str(uid): {key: reports[uid][key] for key in
                                       ('user_timers', 'running_user_services', 'queued_at_jobs')} for uid in (1003, 1004)},
              'ssh_listener': pin(records[listener]), 'pool_master': pin(records[pool_master]), 'pool_name': 'www',
              'owners': {'1003': {'ssh_user': 'coremaster'}, '1004': {'ssh_user': 'coreapp'}}, 'retained_processes': retained}
    data = encode(policy); digest = hashlib.sha256(data).hexdigest()
    for uid in (1003, 1004):
        path = base / f'writer-policy-{uid}.json'
        path.write_bytes(data); os.chown(path, uid, 33); path.chmod(0o600)
        config_path = base / f'owner-config-{uid}.json'
        config = json.loads(config_path.read_bytes())
        config.update(writer_policy_path=str(path), writer_policy_sha256=digest)
        config_path.write_bytes(encode(config))
    (base / 'writer-policy-preparation.json').write_bytes(encode({'policy_sha256': digest, 'reports': reports}))
    return digest


if __name__ == '__main__':
    base = Path(sys.argv[1]); fixture_only(base)
    if sys.argv[2] == 'prepare':
        print(prepare_policy(base))
    else:
        message = json.loads(sys.stdin.buffer.readline())
        if message['query']['controller_challenge'] != message['controller_challenge']:
            raise ValueError('Authenticated fixture owner exchange challenge differs')
        controller = controller_for(base)
        result = observer_for(controller, base, message['query']['owner_uid'], upgrade=sys.argv[2] == 'upgrade')(message['query'])
        print(json.dumps(result, separators=(',', ':')))
