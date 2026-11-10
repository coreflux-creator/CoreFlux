"""Forward-controller ordering, identity, replay and cache-scope refusal checks."""
import copy
import hashlib
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_release_coordinator import Coordinator, FORWARD_PHASES, WORKER_FILES, validate_forward_input
from inspect_full_core_release import ROOT, encode

spec = {'version': 1, 'root': ROOT, 'operation': 'a' * 32, 'candidate_commit': 'b' * 40,
        'manifest_sha256': 'c' * 64, 'migration_plan_sha256': 'd' * 64,
        'owner_plan_sha256': {'1003': 'e' * 64, '1004': 'f' * 64}}
config = {'version': 1, 'control_spec': spec, 'owners': {
    str(uid): {'bundle_directory': '/private/bundle', 'config_path': f'/private/{uid}.json',
               'config_sha256': '1' * 64, 'worker_files_sha256': {name: '2' * 64 for name in WORKER_FILES}}
    for uid in (1003, 1004)}}
checks = 0


def check(ok, message):
    global checks
    if not ok:
        raise AssertionError(message)
    checks += 1


def refuse(fn, message):
    try:
        fn()
    except ValueError:
        check(True, message)
        return
    check(False, message)


def completed(uid, phase):
    result = {'verified': True}
    control = {k: spec[k] for k in ('root', 'operation', 'candidate_commit', 'manifest_sha256', 'migration_plan_sha256')}
    control.update(owner_uid=uid, action=phase, owner_plan_sha256=spec['owner_plan_sha256'][str(uid)],
                   evidence_sha256='3' * 64, native_receipt_sha256=hashlib.sha256(encode(result)).hexdigest())
    return {'control': control, 'result': result}


class ObservedController(Coordinator):
    def __init__(self):
        super().__init__(config, 'unused-fixture-key')
        self.calls = []
        self.reports = {uid: {'state': {'direction': 'release', 'pending': None, 'terminal': None},
                              'completed': {}} for uid in (1003, 1004)}

    def call(self, uid, phase, data=None, peer=None, **kwargs):
        self.calls.append((uid, phase, data, peer))
        return copy.deepcopy(self.reports[uid]) if phase == 'inspect-control' else {'executed': phase}


controller = ObservedController()
controller.forward_step(1003, 'hold', {})
check([(u, p) for u, p, _, _ in controller.calls] == [(1003, 'inspect-control'), (1004, 'inspect-control'), (1003, 'hold')],
      'Freshly inspects both owners before a single explicit phase')
for uid, phase, data in [(1003, 'upgrade-database', {'binding_sha256': '1' * 64}),
                         (1003, 'activate', {'guard_sha256': '1' * 64}),
                         (1004, 'hold', {'guard_sha256': '1' * 64}),
                         (1004, 'prepare-files', {}), (1004, 'prepare-files', {'guard_sha256': 'short'}),
                         (True, 'hold', {})]:
    refuse(lambda: validate_forward_input(uid, phase, data), 'Wrong owner, extra input or unpinned input refused')
for uid in (1003, 1004):
    for key, value in [('direction', 'recovery'), ('direction', 'preparation-abort'),
                       ('pending', {'action': 'hold'})]:
        controller = ObservedController()
        controller.reports[uid]['state'][key] = value
        refuse(lambda: controller.forward_step(1004, 'hold', {}), 'Uncertain or non-forward owner blocks both accounts')
        check(all(phase == 'inspect-control' for _, phase, _, _ in controller.calls), 'Refusal performs no native phase')
controller = ObservedController()
refuse(lambda: controller.forward_step(1004, 'activate', {'guard_sha256': '1' * 64}), 'Activation cannot skip phases')
controller.reports[1004]['completed']['hold'] = completed(1004, 'hold')
refuse(lambda: controller.forward_step(1004, 'hold', {}), 'Completed action is never replayed')
refuse(lambda: controller.forward_step(1004, 'prepare-files', {'guard_sha256': '1' * 64}), 'Missing peer hold refused')
controller.reports[1003]['completed']['hold'] = completed(1003, 'hold')
controller.forward_step(1004, 'prepare-files', {'guard_sha256': '1' * 64})
check(controller.calls[-1][3] == completed(1003, 'hold')['control'], 'Only fresh authenticated peer receipt is forwarded')
for field, value in [('operation', '0' * 32), ('owner_uid', 1004), ('owner_plan_sha256', '0' * 64),
                      ('candidate_commit', '0' * 40), ('native_receipt_sha256', '0' * 64)]:
    bad = completed(1003, 'hold'); bad['control'][field] = value
    controller.reports[1003]['completed']['hold'] = bad
    refuse(lambda: controller.forward_step(1004, 'prepare-files', {'guard_sha256': '1' * 64}), 'Changed peer identity or result refused')
controller = Coordinator(config, 'unused-fixture-key')
for phase, data in [('inspect-database-state', {'guard_sha256': '1' * 64}),
                     ('upgrade-database', {'binding_sha256': '1' * 64})]:
    refuse(lambda: controller.call(1004, phase, data), 'No SSH or native action without real observations')
challenge = '4' * 64
query = {'owner_uid': 1004, 'controller_challenge': challenge, 'kind': 'invalidate',
         'operation': spec['operation'], 'candidate_commit': spec['candidate_commit'],
         'phase': 'candidate', 'cache_plan_sha256': '5' * 64}
calls = []
controller.invalidate = lambda plan: calls.append(plan) or {'invalidated': True}
controller._exchange(1004, 'refresh-cache', query, challenge, {'cache_plan_sha256': '5' * 64})
check(calls == ['5' * 64], 'Only the requested exact cache plan is sent')
for field, value in [('phase', 'recovery'), ('cache_plan_sha256', '6' * 64),
                      ('candidate_commit', '0' * 40), ('controller_challenge', '0' * 64)]:
    bad = query | {field: value}
    refuse(lambda: controller._exchange(1004, 'refresh-cache', bad, challenge, {'cache_plan_sha256': '5' * 64}),
           'Native request cannot substitute cache phase, plan, operation or exchange identity')
check(calls == ['5' * 64], 'Invalid cache requests cause no HTTP mutation')
print(f'core_release_forward_smoke: {checks} checks passed')
