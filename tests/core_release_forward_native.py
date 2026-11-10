"""Exercise the real external forward/recovery controller in the disposable native fixture."""
import json
from pathlib import Path
import sys

from core_native_hold_fixture import controller_for, observer_for, FixtureStatus, fixture_only
from core_release_coordinator import RECOVERY_INPUTS

base = Path(sys.argv[1])
fixture_only(base)
uid = int(sys.argv[2])
initial = json.loads(sys.stdin.buffer.readline())
controller = controller_for(base)
try:
    action = initial['action']
    if action == 'inspect-control':
        result = controller.call(uid, action)
    else:
        if (base / f'guard-{uid}.json').is_file():
            controller.observe = observer_for(controller, base, uid, upgrade=action == 'upgrade-database')
            controller.invalidate = FixtureStatus(base).invalidate
        if len(sys.argv) > 3 and sys.argv[3] == '--lose-result':
            native_command = controller._command
            controller._command = lambda owner: [sys.executable, str(Path(__file__).with_name('core_release_coordinator_native.py')),
                                                  '--drop-response', *native_command(owner)] if owner == uid else native_command(owner)
            # Drop only the actual phase result, after ordinary preflight inspections.
            call = controller.call
            def lose_phase(owner, phase, *args, **kwargs):
                saved = controller._command
                if phase != action:
                    controller._command = native_command
                try:
                    return call(owner, phase, *args, **kwargs)
                finally:
                    controller._command = saved
            controller.call = lose_phase
        # PHP's empty associative array is encoded as []; the real CLI takes a JSON object.
        data = {} if initial['input'] == [] else initial['input']
        step = controller.recovery_step if action in RECOVERY_INPUTS else controller.forward_step
        result = step(uid, action, data)
    print(json.dumps({'response': result, 'challenge': initial['challenge'], 'owner_uid': uid}, separators=(',', ':')))
except Exception as error:
    # This fixture contains only synthetic data; retain controller refusal details for diagnosis.
    print(type(error).__name__ + ': ' + str(error), file=sys.stderr)
    if error.__cause__:
        print(type(error.__cause__).__name__ + ': ' + str(error.__cause__), file=sys.stderr)
    sys.exit(2)
