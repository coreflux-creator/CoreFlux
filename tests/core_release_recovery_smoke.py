"""Recovery ordering, exact backup identity, cache scope and no-replay checks."""
import copy
import hashlib
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import (Coordinator, OwnerInterrupted, WORKER_FILES,
                                      validate_recovery_input, forward_evidence, native_json)
from inspect_full_core_release import ROOT, encode

spec = {'version': 1, 'root': ROOT, 'operation': 'a' * 32, 'candidate_commit': 'b' * 40,
        'manifest_sha256': 'c' * 64, 'migration_plan_sha256': 'd' * 64,
        'owner_plan_sha256': {'1003': 'e' * 64, '1004': 'f' * 64}}
config = {'version': 1, 'control_spec': spec, 'owners': {
    str(uid): {'bundle_directory': '/private/bundle', 'config_path': f'/private/{uid}.json',
               'config_sha256': '1' * 64, 'worker_files_sha256': {name: '2' * 64 for name in WORKER_FILES}}
    for uid in (1003, 1004)}}
guard = {'guard_sha256': '1' * 64}
database = guard | {'expectation_sha256': '2' * 64}
cache = guard | {'cache_plan_sha256': '3' * 64}
checks = 0


def check(ok, message):
    global checks
    assert ok, message
    checks += 1


def refuse(fn, message):
    try:
        fn()
    except ValueError:
        check(True, message)
        return
    check(False, message)


def completed(uid, phase, data=None):
    result = {'verified': True}
    control = {k: spec[k] for k in ('root', 'operation', 'candidate_commit', 'manifest_sha256', 'migration_plan_sha256')}
    evidence = database['expectation_sha256'] if phase == 'restore-database' else forward_evidence(phase, data or {})
    control.update(owner_uid=uid, action=phase, owner_plan_sha256=spec['owner_plan_sha256'][str(uid)],
                   evidence_sha256=evidence, native_receipt_sha256=hashlib.sha256(native_json(result)).hexdigest())
    return {'control': control, 'result': result}


class ObservedController(Coordinator):
    def __init__(self):
        super().__init__(config, 'unused-fixture-key')
        self.calls = []
        self.reports = {uid: {'state': {'direction': 'recovery', 'pending': None, 'terminal': None,
                                        'activation_may_have_started': False}, 'completed': {}}
                        for uid in (1003, 1004)}

    def call(self, uid, phase, data=None, peer=None, **kwargs):
        self.calls.append((uid, phase, data, peer, kwargs))
        return copy.deepcopy(self.reports[uid]) if phase == 'inspect-control' else {'executed': phase}


for uid, phase, data in [(1003, 'begin-database-recovery', database), (1003, 'restore-routing', guard),
                         (1004, 'restore-scheduler', {}), (1004, 'restore-database', database),
                         (1004, 'request-recovery', guard), (1004, 'recover-files', guard),
                         (True, 'request-recovery', {}), (1004, 'confirm-database-recovery', database | {'extra': 'f' * 64}),
                         (1004, 'verify-recovery', {'guard_sha256': 'short'})]:
    refuse(lambda: validate_recovery_input(uid, phase, data), 'Wrong owner, extra or missing evidence refused')
for uid in (1003, 1004):
    for field, value in [('activation_may_have_started', True), ('terminal', 'complete'),
                         ('direction', 'preparation-abort')]:
        c = ObservedController()
        c.reports[uid]['state'][field] = value
        refuse(lambda: c.recovery_step(1004, 'begin-database-recovery', database), 'Unsafe original-backup recovery refused')
        check(all(p == 'inspect-control' for _, p, *_ in c.calls), 'Preflight refusal never mutates native state')
c = ObservedController()
c.reports[1004]['state'].update(direction='release', pending={'action': 'upgrade-database'})
c.recovery_step(1004, 'request-recovery', {})
check(c.calls[-1][1] == 'request-recovery', 'Explicit recovery can fence an interrupted release')
c.reports[1004]['state'].update(direction='recovery', pending=None)
refuse(lambda: c.recovery_step(1004, 'request-recovery', {}), 'Direction selection is inspected, not replayed')
c.reports[1003]['state']['direction'] = 'release'
refuse(lambda: c.recovery_step(1004, 'begin-database-recovery', database), 'Both native recovery directions required')
c.reports[1003]['state']['direction'] = 'recovery'
c.recovery_step(1004, 'begin-database-recovery', database)
check(c.calls[-1][1] == 'begin-database-recovery', 'Only explicit begin establishes restore intent')
refuse(lambda: c.recovery_step(1004, 'confirm-database-recovery', database), 'Cannot confirm without durable intent')
c.reports[1004]['state']['pending'] = {'action': 'restore-database', 'evidence_sha256': '9' * 64}
refuse(lambda: c.recovery_step(1004, 'confirm-database-recovery', database), 'Cannot substitute another recovery expectation')
c.reports[1004]['state']['pending']['evidence_sha256'] = database['expectation_sha256']
c.recovery_step(1004, 'confirm-database-recovery', database)
check(c.calls[-1][4]['timeout'] == 3600, 'Full database confirmation receives bounded time')
refuse(lambda: c.recovery_step(1004, 'begin-database-recovery', database), 'Pending destructive restore is never restarted')
refuse(lambda: c.recovery_step(1003, 'recover-files', cache), 'File recovery cannot race pending database restore')
c.reports[1004]['state']['pending'] = None
refuse(lambda: c.recovery_step(1004, 'recover-files', cache), 'Database restore must finish first')
refuse(lambda: c.recovery_step(1003, 'recover-files', cache), 'Master requires actual application database receipt')
c.reports[1004]['completed']['restore-database'] = completed(1004, 'restore-database')
c.recovery_step(1003, 'recover-files', cache, timeout_seconds=7200)
check(c.calls[-1][3] == completed(1004, 'restore-database')['control'], 'Direct verified peer receipt authorizes master file recovery')
check(c.calls[-1][4]['timeout'] == 7200, 'Large file recovery uses explicit bounded budget')
for uid, phases in [(1004, ['recover-files', 'verify-recovery', 'refresh-recovery-cache']),
                     (1003, ['recover-files', 'verify-recovery'])]:
    for phase in phases:
        c.reports[uid]['completed'][phase] = completed(uid, phase)
c.recovery_step(1004, 'restore-routing', guard)
check(c.calls[-1][3] == completed(1003, 'verify-recovery')['control'], 'Routing requires master original-file verification')
c.reports[1004]['completed']['restore-routing'] = completed(1004, 'restore-routing', guard)
c.reports[1004]['state']['terminal'] = 'recovered'
c.recovery_step(1003, 'restore-scheduler', {})
check(c.calls[-1][3] == completed(1004, 'restore-routing', guard)['control'], 'Scheduler resumes only after restored routing')
refuse(lambda: c.recovery_step(1004, 'restore-routing', guard), 'Completed recovery is never replayed')
for value in [True, 0, 29, 7201, '7200', float('nan')]:
    c.calls.clear()
    refuse(lambda: c.recovery_step(1003, 'recover-files', cache, timeout_seconds=value), 'Invalid recovery budgets refused')
    check(not c.calls, 'Invalid budget cannot open native session')
c = Coordinator(config, 'unused-fixture-key')
for phase, data in [('begin-database-recovery', database), ('recover-files', cache)]:
    refuse(lambda: c.call(1004, phase, data), 'No SSH action without real hold observations')
c.observe = lambda query: {}
refuse(lambda: c.call(1004, 'recover-files', cache), 'Recovery requires authenticated cache channel before SSH')
calls = []
c.invalidate = lambda plan: calls.append(plan) or {'invalidated': True}
challenge = '4' * 64
query = {'kind': 'invalidate', 'controller_challenge': challenge, 'owner_uid': 1003,
         'operation': spec['operation'], 'candidate_commit': spec['candidate_commit'],
         'phase': 'recovery', 'cache_plan_sha256': cache['cache_plan_sha256']}
c._exchange(1003, 'recover-files', query, challenge, cache)
check(calls == [cache['cache_plan_sha256']], 'Both native owners can invalidate exact added PHP before removal')
for field, value in [('phase', 'candidate'), ('cache_plan_sha256', '9' * 64), ('operation', '0' * 32),
                      ('owner_uid', 1004), ('controller_challenge', '0' * 64)]:
    refuse(lambda: c._exchange(1003, 'recover-files', query | {field: value}, challenge, cache), 'Changed recovery cache request refused')
refuse(lambda: c._exchange(1003, 'verify-recovery', query, challenge, cache), 'Verification cannot invalidate files')
check(len(calls) == 1, 'Rejected exchanges make no maintenance-channel mutation')
for result in [{}, {'intent': 'short'}, {'intent': 'a' * 64, 'extra': True}]:
    refuse(lambda: c._verify_recovery_result(1004, 'begin-database-recovery', database, result), 'Exact native restore intent required')
c._verify_recovery_result(1004, 'begin-database-recovery', database, {'intent': 'a' * 64})
c._verify_recovery_result(1004, 'confirm-database-recovery', database, completed(1004, 'restore-database'))
for field, value in [('evidence_sha256', '0' * 64), ('native_receipt_sha256', '0' * 64), ('owner_uid', 1003)]:
    bad = completed(1004, 'restore-database'); bad['control'][field] = value
    refuse(lambda: c._verify_recovery_result(1004, 'confirm-database-recovery', database, bad), 'Wrong confirmed backup or receipt refused')
c = ObservedController()
call = c.call
def interrupted(uid, phase, *args, **kwargs):
    if phase == 'begin-database-recovery':
        raise OwnerInterrupted('Lost native response')
    return call(uid, phase, *args, **kwargs)
c.call = interrupted
try:
    c.recovery_step(1004, 'begin-database-recovery', database)
except OwnerInterrupted:
    check(all(p == 'inspect-control' for _, p, *_ in c.calls), 'Lost response is propagated without replay or direction change')
else:
    raise AssertionError('Lost response must propagate')
print(f'core_release_recovery_smoke: {checks} checks passed')
