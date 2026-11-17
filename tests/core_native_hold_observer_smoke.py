"""Adversarial composition checks; native SSH/FPM integration is a separate fixture."""
import copy
import hashlib
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import Coordinator, CoreWebPoolChanged, OwnerInterrupted
from core_native_hold_observer import NativeHoldObserver, MaintenanceStatus, CoreRetiredWorkerCount
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

class ChangingPool(FakeCoordinator):
    def __init__(self, failure_at, failure=CoreWebPoolChanged, repeat=False):
        super().__init__()
        self.failure_at, self.failure, self.repeat = failure_at, failure, repeat
        self.runtimes, self.challenges = [], []

    def call(self, uid, action, data=None):
        self.runtimes.append(copy.deepcopy(data['runtime']))
        self.challenges.append(data['challenge'])
        if len(self.runtimes) == self.failure_at or self.repeat:
            self.calls.append((uid, action))
            raise self.failure('Fixture refusal')
        return super().call(uid, action, data)

for failed_call in (1, 2):
    changing = ChangingPool(failed_call)
    samples = []
    def changed_status():
        state = web(); state['pool']['process_ids'] = [300, 301 + len(samples)]
        samples.append(state)
        return state
    with patch('core_native_hold_observer.time.sleep') as pause:
        fresh = NativeHoldObserver(changing, binding, changed_status)(query)
    check(len(samples) == 2 and pause.call_count == 1 and len(changing.runtimes) == failed_call + 2,
          'Either native owner can request one complete new three-source observation')
    check(fresh['web']['pool']['process_ids'] == [300, 302]
          and all(r['pool_pids'] == [300, 302] for r in changing.runtimes[-2:])
          and len(set(changing.challenges)) == len(changing.challenges),
          'Partial witness is discarded; both native checks bind the new web snapshot and new challenges')
for failure, repeat in ((OwnerInterrupted, False), (CoreWebPoolChanged, True)):
    changing = ChangingPool(1, failure, repeat)
    with patch('core_native_hold_observer.time.sleep'):
        try:
            NativeHoldObserver(changing, binding, web)(query)
            check(False, 'Refusal expected')
        except OwnerInterrupted:
            check(len(changing.runtimes) == (2 if repeat else 1),
                  'Other native refusals never retry; a second pool change is terminal')
with patch('core_native_hold_observer.time.time', side_effect=[1000, 1007]), \
        patch.object(NativeHoldObserver, '_observe', side_effect=CoreWebPoolChanged('changed')) as sample, \
        patch('core_native_hold_observer.time.sleep') as pause:
    try:
        NativeHoldObserver(FakeCoordinator(), binding, web)(query)
        check(False, 'Insufficient freshness must refuse another observation')
    except CoreWebPoolChanged:
        check(sample.call_count == 1 and not pause.called, 'No new deadline after the retry allowance is exhausted')

clock = [time.time()]
class SlowFreshSample(ChangingPool):
    def call(self, uid, action, data=None):
        result = super().call(uid, action, data)
        if len(self.runtimes) == 3:
            clock[0] += 16
        return result
slow = SlowFreshSample(1)
with patch('core_native_hold_observer.time.time', side_effect=lambda: clock[0]), \
        patch('core_native_hold_observer.time.sleep', side_effect=lambda seconds: clock.__setitem__(0, clock[0] + seconds)):
    refuse(lambda: NativeHoldObserver(slow, binding, web)(query), 'A complete second sample cannot reset the original fifteen-second deadline')
check(len(slow.runtimes) == 3, 'Stale resampling never asks for a third native attempt')

def retired_count():
    # Recorded live failure: one detailed worker, active=1, idle=0, total=2.
    state = web()
    state['pool'].update(process_ids=[300], other_idle_processes=0, total_processes=2,
                         counts_consistent=False, request_pool_quiet=False)
    return state

samples = []
def retirement_then_quiet():
    state = retired_count() if not samples else web()
    samples.append(state)
    return state
fresh_native = ChangingPool(999)
with patch('core_native_hold_observer.time.sleep') as pause:
    result = NativeHoldObserver(fresh_native, binding, retirement_then_quiet)(query)
check(len(samples) == 2 and pause.call_count == 1 and result['web']['pool']['request_pool_quiet'],
      'Recorded retired-worker total requests one fresh observation, never acceptance of the inconsistent snapshot')
check(len(fresh_native.runtimes) == 2 and all(r['pool_pids'] == [300, 301] for r in fresh_native.runtimes),
      'Both native owners bind only the new complete quiet inventory')
for field, value in [('listen_queue', 1), ('other_busy_processes', 1), ('active_processes', 2),
                     ('total_processes', 3), ('total_processes', 2.0), ('other_idle_processes', True),
                     ('accepted_connections', True), ('self_process_count', True), ('pool_matches', False),
                     ('self_running', False), ('self_script_matches', False), ('counts_consistent', True),
                     ('request_pool_quiet', True), ('process_ids', [True]), ('process_ids', [300,300])]:
    state = retired_count(); state['pool'][field] = value
    native = FakeCoordinator()
    with patch('core_native_hold_observer.time.sleep') as pause:
        refuse(lambda: NativeHoldObserver(native, binding, lambda: state)(query),
               'Activity, identity changes, malformed counts and different mismatches cannot request retirement resampling')
    check(not pause.called and not native.calls, 'Non-retirement failures stop before native evidence without retry')
native = FakeCoordinator()
with patch('core_native_hold_observer.time.sleep') as pause:
    refuse(lambda: NativeHoldObserver(native, binding, retired_count)(query), 'Persistent retired total refuses the witness')
check(pause.call_count == 1 and not native.calls, 'Persistent discrepancy gets exactly two web samples and no native positive proof')
with patch('core_native_hold_observer.time.time', side_effect=[1000,1007]), \
        patch.object(NativeHoldObserver, '_observe', side_effect=CoreRetiredWorkerCount('retired')) as sample, \
        patch('core_native_hold_observer.time.sleep') as pause:
    refuse(lambda: NativeHoldObserver(FakeCoordinator(), binding, web)(query), 'Late retirement cannot reset the freshness budget')
check(sample.call_count == 1 and not pause.called, 'Retirement resampling uses the original allowance')
samples.clear(); native = FakeCoordinator()
native.mutate = lambda r: r['writers'].update(unmanaged_writers=1)
with patch('core_native_hold_observer.time.sleep'):
    refuse(lambda: NativeHoldObserver(native, binding, retirement_then_quiet)(query),
           'A fresh quiet pool never substitutes for the independent unknown-writer check')
check(len(samples) == 2 and len(native.calls) == 1, 'Native writer refusal after the fresh read remains terminal')
state = web(); state['pool'].update(active_processes='private-sentinel', private_detail='private-sentinel')
try:
    NativeHoldObserver(FakeCoordinator(), binding, lambda: state)(query)
    check(False, 'Malformed activity must fail')
except ValueError as error:
    check('reported_worker_count' in str(error) and 'private-sentinel' not in str(error),
          'Refusal explains numeric pool state without echoing malformed or private content')
samples.clear(); native = ChangingPool(1)
with patch('core_native_hold_observer.time.sleep') as pause:
    try:
        NativeHoldObserver(native, binding, retirement_then_quiet)(query)
        check(False, 'Two different pool transitions must refuse')
    except CoreWebPoolChanged:
        check(len(samples) == 2 and pause.call_count == 1, 'Counter resampling and PID resampling share one total retry')

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


class FakeResponse:
    def __init__(self, status, body, media_type='application/json'):
        self.status, self.body, self.media_type = status, body, media_type

    def read(self, limit):
        return self.body[:limit]

    def getheader(self, name):
        return self.media_type if name == 'Content-Type' else None


def transport_case(responses, action='status', clock=None):
    connections = []

    class Connection:
        def __init__(self, host, timeout, context):
            self.response = responses[len(connections)]
            self.host, self.timeout, self.closed = host, timeout, False
            connections.append(self)

        def request(self, method, target, body, headers):
            self.requested = (method, target, body, headers)

        def getresponse(self):
            return self.response

        def close(self):
            self.closed = True

    endpoint = MaintenanceStatus('https://www.corefluxapp.com' + path, '2' * 64, binding['operation'])
    with patch('core_native_hold_observer.http.client.HTTPSConnection', Connection), \
            patch('core_native_hold_observer.ssl.create_default_context', return_value=object()), \
            patch('core_native_hold_observer.time.sleep') as pause, \
            patch('core_native_hold_observer.time.monotonic', side_effect=clock or [0] * 12):
        try:
            result = endpoint() if action == 'status' else endpoint.invalidate('3' * 64)
            failure = None
        except ValueError as error:
            result, failure = None, str(error)
    check(pause.call_count <= 1 and (not pause.called or pause.call_args.args == (1,)),
          'Only one bounded worker-recovery pause is permitted')
    if len(connections) == 2:
        check(pause.call_count == 1, 'A retried observation waits for worker retirement')
    check(all(c.closed for c in connections), 'Every observation connection closes')
    return connections, result, failure


good = FakeResponse(200, b'{"fresh":true}')
gateway = FakeResponse(503, b'<html><title>503 Service Unavailable</title></html>', 'text/html; charset=utf-8')
calls, result, failure = transport_case([gateway, good], clock=[0, 0, .25, .25, 1.25, 1.5])
check(len(calls) == 2 and result == {'fresh': True} and failure is None, 'A transient FPM gateway response permits one fresh read')
check(calls[0].requested == calls[1].requested and calls[0].host == calls[1].host == 'www.corefluxapp.com',
      'Read retry retains exact authenticated endpoint and request')
check([c.timeout for c in calls] == [8, 6.75], 'Recovery pause and retry share the original eight-second request budget')
held_page = FakeResponse(503, b'CoreFlux is temporarily unavailable for maintenance. Please try again shortly.\n', 'text/html')
calls, result, failure = transport_case([held_page, good])
check(len(calls) == 2 and result == {'fresh': True} and failure is None,
      'Apache may apply the exact protected-ingress page to a transient gateway reset')
for response in [FakeResponse(503, b'{"error":"Maintenance diagnostic unavailable; keep the hold in place."}'),
                 FakeResponse(404, b''), FakeResponse(403, b'Forbidden', 'text/html'),
                 FakeResponse(503, b'<html><title>503 Service Unavailable</title>CoreFlux maintenance</html>', 'text/html'),
                 FakeResponse(503, b'unknown response', 'text/html'),
                 FakeResponse(503, gateway.body, 'application/json')]:
    calls, result, failure = transport_case([response, good])
    check(len(calls) == 1 and failure is not None, 'Authentication, hold and unrecognized failures stop without retry')
calls, result, failure = transport_case([gateway, good], action='invalidate')
check(len(calls) == 1 and failure is not None, 'Cache mutation is never retried')
calls, result, failure = transport_case([held_page, good], action='invalidate')
check(len(calls) == 1 and failure is not None, 'Protected-ingress page cannot cause cache mutation replay')
calls, result, failure = transport_case([gateway, gateway, good])
check(len(calls) == 2 and failure is not None, 'A second gateway failure is terminal')
calls, result, failure = transport_case([gateway, good], clock=[0, 0, 7.5, 8.1])
check(len(calls) == 1 and failure is not None, 'Expired original budget prevents a retry')
calls, result, failure = transport_case([FakeResponse(200, b'x' * 1048577), good])
check(len(calls) == 1 and failure is not None, 'Oversized status is rejected without retry')
print(f'core_native_hold_observer_smoke: {checks} checks passed')
