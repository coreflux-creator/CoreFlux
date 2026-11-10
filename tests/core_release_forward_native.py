"""Exercise the real external forward controller, only in the disposable native fixture."""
import json
from pathlib import Path
import sys

from core_native_hold_fixture import controller_for, observer_for, FixtureStatus, fixture_only

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
        result = controller.forward_step(uid, action, initial['input'])
    print(json.dumps({'response': result, 'challenge': initial['challenge'], 'owner_uid': uid}, separators=(',', ':')))
except Exception as error:
    print(type(error).__name__ + ': forward phase refused or interrupted; inspect native state', file=sys.stderr)
    sys.exit(2)
