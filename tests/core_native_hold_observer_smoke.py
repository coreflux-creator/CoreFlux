"""Adversarial composition checks; native SSH/FPM integration is a separate fixture."""
import copy
import hashlib
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import Coordinator
from core_native_hold_observer import NativeHoldObserver, MaintenanceStatus
from inspect_full_core_release import ROOT, encode

checks = 0


def check(ok, message):
    global checks
    if not ok:
        raise AssertionError(message)
    checks += 1


def refuse(fn, message):
    try:
        fn()
    except (ValueError, TypeError, KeyError):
        check(True, message)
        return
    check(False, message)


binding = {'root': ROOT, 'operation': 'a' * 32, 'candidate_commit': 'b' * 40,
           'manifest_sha256': 'c' * 64, 'migration_plan_sha256': 'd' * 64}
for field in ('held_web_sha256', 'application_held_cron_sha256', 'master_held_cron_sha256',
              'application_maintenance_receipt_sha256', 'master_maintenance_receipt_sha256', 'writer_inventory_sha256'):
    binding[field] = hashlib.sha256(field.encode()).hexdigest()


def hold(uid):
    prefix = 'master' if uid == 1003 else 'application'
    return {'scope': 'current-user-scheduler' if uid == 1003 else 'application-scheduler-and-web', 'cli_uid': uid,
            'root': ROOT, 'operation': binding['operation'], 'held_cron_sha256': binding[prefix + '_held_cron_sha256'],
            'receipt_sha256': binding[prefix + '_maintenance_receipt_sha256'], 'held_web_sha256': binding['held_web_sha256']}


class FakeCoordinator:
    _verify_writer_response = Coordinator._verify_writer_response
    config = {'control_spec': binding}
    mutate = None

    def __init__(self):
        self.calls = []

    def worker_command_hash(self, uid):
        return hashlib.sha256(str(uid).encode()).hexdigest()

    def call(self, uid, action, data=None):
        self.calls.append((uid, action))
        if action == 'witness-held':
            return hold(uid)
        now = time.time()
        report = {'version': 1, 'root': ROOT, 'scope': 'native-writer-classification', 'owner_uid': uid,
                  'challenge': data['challenge'], 'started_at_unix': now, 'observed_at_unix': now,
                  'runtime_sha256': hashlib.sha256(encode(data['runtime'])).hexdigest(), 'boot_id': 'boot',
                  'writers': {'owner_uid': uid, 'inventory_sha256': binding['writer_inventory_sha256'],
                              'registry_unchanged': True, 'core_cli_processes': 0, 'unmanaged_writers': 0,
                              'observation_sha256': 'e' * 64}}
        if uid != data['runtime']['owner_uid']:
            report['hold'] = hold(uid)
        if self.mutate:
            self.mutate(report)
        return report


def web():
    return {'scope': 'core-web-pool-only', 'operation': binding['operation'], 'candidate_commit': binding['candidate_commit'],
            'held_web_sha256': binding['held_web_sha256'], 'observed_at': datetime.now(timezone.utc).isoformat(),
            'pool': {'process_ids': [300, 301], 'total_processes': 2, 'other_idle_processes': 1, 'accepted_connections': 10,
                     'pool_matches': True, 'self_running': True, 'self_script_matches': True, 'counts_consistent': True,
                     'request_pool_quiet': True, 'self_process_count': 1, 'listen_queue': 0,
                     'other_busy_processes': 0, 'active_processes': 1}}


query = {'kind': 'hold-witness', 'owner_uid': 1004, 'owner_pid': 10, 'owner_start_ticks': '1234',
         'challenge': 'f' * 64, 'operation': binding['operation'], 'candidate_commit': binding['candidate_commit'],
         'migration_plan_sha256': binding['migration_plan_sha256'], 'own_hold': hold(1004)}
coordinator = FakeCoordinator()
observer = NativeHoldObserver(coordinator, binding, web)
result = observer(query)
check(result['writers']['unmanaged_writers'] == 0, 'Composes both native observations')
check(coordinator.calls == [(1003, 'witness-writers'), (1004, 'witness-writers')],
      'Both fresh native observations include the peer hold without another SSH session or active-owner lock')
master_query = copy.deepcopy(query)
master_query.update(owner_uid=1003, own_hold=hold(1003))
coordinator.calls = []
check(observer(master_query)['application']['cli_uid'] == 1004, 'Master action verifies the application peer hold')
check(coordinator.calls == [(1004, 'witness-writers'), (1003, 'witness-writers')],
      'Master action also observes peer first and never re-enters its own lock')
for key, value in [('owner_pid', True), ('owner_start_ticks', ''), ('challenge', 'old'), ('operation', '0' * 32)]:
    bad = copy.deepcopy(query); bad[key] = value
    refuse(lambda: observer(bad), 'Rejects changed active process or release')
bad = copy.deepcopy(query); bad['own_hold']['held_web_sha256'] = '0' * 64
refuse(lambda: observer(bad), 'Rejects changed own hold before reading peers')
for field, value in [('challenge', '0' * 64), ('runtime_sha256', '0' * 64), ('started_at_unix', time.time() - 30),
                     ('observed_at_unix', float('nan')), ('owner_uid', 0)]:
    coordinator.mutate = lambda r, field=field, value=value: r.update({field: value})
    refuse(lambda: observer(query), 'Rejects stale, replayed or mismatched native writer response')
coordinator.mutate = lambda r: r['writers'].update(unmanaged_writers=1)
refuse(lambda: observer(query), 'Unknown native process prevents positive witness')
coordinator.mutate = lambda r: r.pop('hold',None)
refuse(lambda: observer(query), 'Combined peer observation requires explicit current hold evidence')
for field, value in [('receipt_sha256','0'*64),('held_cron_sha256','0'*64),('cli_uid',1004)]:
    coordinator.mutate = lambda r, field=field, value=value: r.get('hold',{}).update({field:value})
    refuse(lambda: observer(query), 'Peer process evidence cannot substitute for its current matching hold')
coordinator.mutate = lambda r: r.update(boot_id=str(r['owner_uid']))
refuse(lambda: observer(query), 'Rejects different host observations')
coordinator.mutate = None
for field, value in [('request_pool_quiet', False), ('process_ids', [300, 300]), ('process_ids', [True, 301]),
                     ('process_ids', []), ('total_processes', 3), ('other_busy_processes', 1)]:
    status = web(); status['pool'][field] = value
    refuse(lambda: NativeHoldObserver(coordinator, binding, lambda: status)(query), 'Invalid authenticated pool blocks witness')
status = web(); status['observed_at'] = '2020-01-01T00:00:00+00:00'
refuse(lambda: NativeHoldObserver(coordinator, binding, lambda: status)(query), 'Stale pool report rejected')
# Real FPM emits gmdate('c') at second precision. A small legitimate clock skew
# across a second boundary must not interrupt a native phase after intent creation.
with patch('core_native_hold_observer.time.time', return_value=1000.8):
    for stamp in (999, 998):
        status = web(); status['observed_at'] = datetime.fromtimestamp(stamp, timezone.utc).isoformat()
        check(observer._web(status, 1000.2) == [300, 301], 'Accepts bounded cross-host skew at FPM second precision')
    for stamp in (997, 1003):
        status = web(); status['observed_at'] = datetime.fromtimestamp(stamp, timezone.utc).isoformat()
        refuse(lambda: observer._web(status, 1000.2), 'Rejects web timestamp outside bounded clock skew')
    status = web(); status['observed_at'] = '1970-01-01T00:16:40'
    refuse(lambda: observer._web(status, 1000.2), 'Rejects a web timestamp without a timezone')
path = '/core-maintenance-' + binding['operation'] + '-' + '1' * 32 + '.php'
check(MaintenanceStatus('https://www.corefluxapp.com' + path, '2' * 64, binding['operation']).path == path,
      'Exact HTTPS private endpoint accepted')
for url in ('http://www.corefluxapp.com' + path, 'https://evil.example' + path,
            'https://www.corefluxapp.com' + path + '?redirect=1', 'https://www.corefluxapp.com/api.php',
            'https://user:password@www.corefluxapp.com' + path):
    refuse(lambda: MaintenanceStatus(url, '2' * 64, binding['operation']), 'Credential endpoint is pinned')
print(f'core_native_hold_observer_smoke: {checks} checks passed')
