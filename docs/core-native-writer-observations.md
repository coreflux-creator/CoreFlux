# Native writer observations

`core_native_hold_observer.NativeHoldObserver` composes fresh observations for an
already held operation. It does not establish maintenance, authorize a release,
or make the forward-release CLI available. The existing database transfer API
accepts this observer through `Coordinator(..., observe=...)`.

Each observation combines the active owner's receipt, a separate authenticated
peer-owner receipt, an authenticated HTTPS FPM status response, and independent
native process/scheduler observations through both SSH accounts. The observer
endpoints run before acquiring the release lock so they can inspect the active
owner without re-entering that owner's lock. Every response is bound to fresh
challenges, the operation, candidate, runtime and a 15-second observation window.

The owner-private configuration must pin `writer_policy_path` and
`writer_policy_sha256`; the hold binding uses that same digest as
`writer_inventory_sha256`. Policy version 1 binds:

- The Core root, operation, candidate, host boot and scheduler registry digest.
- Both users' exact empty timer, running-service and queued-job observations.
- The native root SSH listener and FPM master PID, parent, UID and start identity.
- Each SSH account name, the Core pool name, and any explicitly reviewed,
  unrelated retained process with its full identity and a reason.
- Any registry-read or unavailable-at-queue limitation, with platform evidence.
  Process visibility failures and unavailable timers/services cannot be waived.

Worker command fingerprints come from the coordinator's pinned invocation,
including the private configuration digest. They are not embedded in the policy,
which would create a circular policy/configuration fingerprint dependency.
FPM worker PIDs come from actual FPM status, not process-name matching. Native
database transfer allowances come from the exact command builder and require
the timeout process to descend directly from the active owner, with the native
client directly beneath it. Unknown processes keep the operation held.

Registry observations follow ordinary unit-file links and pin their content.
Masked `/dev/null` units and verifiably absent direct link targets have explicit
states; unreadable parents and unresolved chains remain failures. Existing
unrelated services and platform certificate jobs are not changed.

Validation includes adversarial PHP policy tests, Python composition tests,
actual PHP 8.2 execution, and read-only observations on both hosting accounts.
The default native upgrade/recovery fixture retains its prior writer adapter.
The `--native-writers activate recover` variant instead runs the real observer
inside a network-isolated systemd container on a disposable GitHub runner. It
uses actual user managers, empty scheduler observations, SSH sessions and FPM
process identities. A harmless unreviewed application-user process must prevent
database baseline capture before the normal activation/recovery flow proceeds.

The first real-observer CI attempt timed out during startup without diagnostic
output; it did not establish acceptance. The diagnostic rerun found that Docker's
export retained the temporary root directory's runner ownership and mode 0700,
so native tmpfiles rejected root-to-child ownership transitions and user runtime
directories could not connect to the system bus. The fixture now sets only that
exact exported root to root:root mode 0755 and explicitly starts the native basic,
bus and login services. Boot diagnostics remain enabled. Passing this variant
and rehearsing the full application candidate remain required before treating
this as production release acceptance.
