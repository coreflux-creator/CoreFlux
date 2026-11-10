# Native writer observations

`core_native_hold_observer.NativeHoldObserver` composes fresh observations for an
already held operation. It does not establish maintenance or authorize a release.
The database transfer and explicit forward-step APIs accept this observer through
`Coordinator(..., observe=...)`.

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
client directly beneath it. An exited direct transfer child may remain as a
Linux zombie while the owner awaits the observation. The classifier requires
state Z, empty command evidence, exact application UIDs and the active transfer's
direct parent before treating that child as unable to write. A living process,
an exited sibling or a child outside an active transfer remains forbidden.
Unknown process diagnostics retain identities and fingerprints, never arguments.

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
bus and login services. FPM logs use a local file because its Docker configuration
reopens stderr, which cannot reopen the systemd journal socket. Boot diagnostics
remain enabled.

The actual native-observer activation and paired-recovery scenarios passed in
GitHub Actions run 42879881955 at commit 39a4116c64254df665d46f21e90ab3cc7b272967;
all eleven jobs passed. The run also exercised the unknown live-process refusal.
The policy smoke suite has 33 checks, including exited-child, live-empty-command
and foreign-parent cases. This validates the disposable native fixture. The
full application-candidate rehearsal remains a separate gate before production
release acceptance.

## Explicit external forward steps

`core_release_coordinator.py forward-step` executes one named phase through the
same pinned SSH owner worker used for backup and early-abort recovery. The native
owner remains authoritative for durable ordering, intent, file ownership and
database identity. Before a forward step the controller independently reads both
owners; pending actions or a recovery/abort direction stop progress. Peer receipts
come from those fresh authenticated inspections, not caller-supplied receipt files.
Completed phases cannot be replayed. After a lost reply, inspect the native state
and use the verified receipt to choose the next action; never repeat a mutation
because a timeout elapsed.

The CLI requires `--owner`, `--phase`, the existing pinned coordinator configuration,
and a separate `--step-config` with `--step-config-sha256`. That private JSON pins
`version: 1`, `owner_uid`, `phase`, `operation`, `candidate_commit` and exact phase
`input`. Held phases additionally
specify `hold_binding_path`, `maintenance_url` and `maintenance_token`. The binding's
actual bytes must match the requested `guard_sha256` or `binding_sha256`. Keep this
file outside source control: its token is private. Receipts record its fingerprint,
not its contents. Every invocation requires a new `--output` path and writes a
durable request before a native action.

The same fingerprinted step configuration can set integer `timeout_seconds` for a
measured full-package phase. File preparation, application, verification and the
database upgrade permit 30–7200 seconds; database inspection permits up to 3600;
cache refresh permits up to 600; other phases retain a 120-second ceiling. Omitting
the field preserves the existing timeout. This affects only the selected phase,
not SSH inspections, the 15-second writer-witness freshness window, the maintenance
token's expiry, or durable interruption/replay rules. A timeout still requires
fresh state inspection; a larger budget is never a reason to repeat an uncertain
action. Rehearsal timing must establish the operational budget before deployment.

The phase order is: hold both owners; prepare both file backups; inspect and capture
the application database; bind the verified encrypted backup with both file-backup
receipts; upgrade the application database; apply and verify each owner's files;
refresh the reviewed candidate PHP cache; explicitly activate application routing;
resume the master scheduler; complete the application release. The controller does
not automatically choose or advance these steps, stage packages, assemble the
reviewed hold policy or create a backup binding. Those preparation artifacts must
be present and pinned before their dependent phases. Database transfer remains the
separate encrypted capture/restore API.

The disposable native-writer fixture now exercises the external forward controller
through this sequence, including database upgrade and exact cache invalidation.
It deliberately loses the final response after native file verification, recovers
the completed receipt via fresh inspection and verifies replay refusal. The paired
recovery fixture also runs its forward preparation through this controller. These
tests use synthetic data and private loopback transport, not the live Core release.
