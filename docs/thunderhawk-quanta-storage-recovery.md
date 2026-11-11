# Quanta pilot storage recovery

On November 11, the existing isolated Quanta pilot database was backed up after
briefly draining its web process. The same unchanged application was restarted
before the restore rehearsal. No new container or externally accessible service
was created. The restore used a new unserved database on the existing isolated
Mongo service; that temporary namespace was removed after verification.

All 66 collections, 1,324 records and their indexes matched the consistent source
snapshot. The original 12 time entries, application files and other services
were preserved. An independent read verified the archive fingerprint, restored
snapshot, private permissions, unchanged 179 runtime files, temporary namespace
removal and healthy web service with background work held. The 90,126-byte archive
stays in the existing root-private pilot directory; no database contents or keys
are included in this repository.

The first attempt stopped before creating an archive because the mongosh cursor
was not synchronously iterable. The web service recovered. The corrected reader
was tested read-only before the successful second attempt. The receipt is
`deploy/thunderhawk-quanta-storage-backup-20261111.json`; the guarded reusable
helper is `deploy/backup_thunderhawk_quanta_storage.py`.

This proves a logical database backup and restoration, not a production recovery
guarantee. The original backup was taken while Mongo was standalone. The storage
conversion and application installation recorded below subsequently passed.
At this original backup checkpoint, the application was `12ad6396` and
operational time ownership was disabled.

## Transaction storage conversion, November 11

`upgrade_thunderhawk_quanta_storage.py` verified that the original database still
matched the restored backup, then drained only Quanta's web process. It converted
the existing Mongo service into single-member replica set `corepilotquanta`,
using the same image, named data volume, private internal network and 640 MiB
memory limit. Its bounded oplog is 128 MiB. No database port was published and no
additional service was created. Mongo's container was recreated, as expected.

The procedure actually returned the service to its original standalone command,
verified every original record and index, and then returned it to the replica
set. The existing unchanged web application restarted successfully. Through its
installed Python driver, an unserved temporary database proved that two writes
were invisible before commit, visible after commit, and unchanged after an
aborted update/delete transaction. That temporary database was removed.

All 66 collections, 1,324 records, 12 time entries and 179 application files were
preserved. Other service identities and start times were unchanged. A separate
read-only verifier rechecked the full database snapshot, live primary, internal
network, original volume, absence of temporary databases and healthy held web
runtime. The receipt is `deploy/thunderhawk-quanta-storage-upgrade-20261111.json`.

The original Compose file is backed up in the private receipt directory. Exactly
one command line in its Quanta Mongo service was changed; the resolved Compose
configuration differs only at that command. This deliberately updates the normal
Compose file instead of relying on an optional overlay that a later restart
could omit. The HTTPS Compose file and web environment were unchanged. The
conversion follows [MongoDB's documented conversion procedure](https://www.mongodb.com/docs/v7.0/tutorial/convert-standalone-to-replica-set/).
Five local boundary checks cover preserving unrelated services and refusing a
missing, duplicate, changed or already-converted target.

This is a single-host pilot, not redundant production storage. Future rollback
must preserve newer writes and the application's coordination requirement; the
pre-conversion dump must never be blindly restored over changed business data.
The one-time converter intentionally refuses the now-upgraded host on replay.

## Accepted application installed, activation held

The complete application from Quanta commit `22b8170c` passed native CI run
`42982131790`: 224 application/startup checks plus 11 storage checks, with real
MongoDB and zero external network attempts. All 181 runtime hashes were verified
against the accepted source and the disposable CI containers were removed.

`install_thunderhawk_quanta_commit_runtime.py` subsequently installed its 30
changed files into the existing isolated web container. The original files and
manifest are retained privately. All 66 existing collections, 1,324 records and
their indexes remained identical. Startup added only three empty Pace schema
collections. An independent check verified all 181 served runtime files, unchanged
sign-in page, original records, healthy held service and unchanged other services.
The receipt is `deploy/thunderhawk-quanta-commit-runtime-20261111.json`.

That installation initially kept `COREFLUX_TIME_COMMITS` off. The activation
below is separate; the operational time tenant allowlist remains empty.

## Coordinated writes enabled in the isolated pilot

Before activation, a fresh backup and independent restore check verified all
69 current collections, 1,324 records, 12 time entries and 181 runtime files on
the upgraded replica set. The backup helper now accepts either the original
standalone or this exact single-member pilot replica set, and still requires
coordination and operational time to be off in its original invocation. The
later explicit coordinated-backup mode is described below.

`activate_thunderhawk_quanta_commits.py` changed only the isolated web service's
`COREFLUX_TIME_COMMITS` setting to `1`. The existing web service was recreated
with the same image, volumes, hostname, limits, ports and other environment
values; the Mongo and all other services retained their identities and start
times. Startup added exactly one coordinator guard at revision 1. All original
records and indexes remained identical, and all application files were unchanged.

The installed application's actual coordinator and driver then passed a hosted
probe in a fresh unserved database: two-record commit; record and guard rollback
after failure; a competing writer waiting until an earlier decision committed;
refusal of stale assignment eligibility; and refusal after losing the mode flag.
No external network connection was attempted. The temporary database was removed.
An independent read verified the source records, exact startup guard, runtime
files, settings, healthy held web process and unchanged other services.

The activation receipt is `deploy/thunderhawk-quanta-commit-activation-20261111.json`.
Its recovery path can remove only an unused startup guard at revision 0 or 1 while
every original collection is exact. Nine local boundary cases cover refusing
later writes, changed records, extra guard rows and a preexisting guard. The
successful activation did not exercise that failure-recovery path on this host.
After business writes, preserve the guard and enabled mode; do not restore the
pre-activation Compose file as an ordinary rollback.

Operational time exchange is still disabled. Historical associations,
explicit time permissions, synthetic lifecycle and full financial-result
reconciliation remain separate release requirements. No real payroll, payment,
financial posting or production cutover is authorized by this evidence.

## Manual save recovery installed in the pilot

Quanta source `a6def184ea7657b077b163f4cf048a35db2b524c` passed
[native CI 43051032619](https://github.com/coreflux-creator/Quanta/actions/runs/43051032619):
242 full-application/startup checks and 11 transaction checks. The actual shared
manual-entry form passed 27 React checks. A production frontend build completed
with pre-existing repository lint warnings; strict warning-as-error mode is not
claimed to pass.

A fresh backup used `--backup-isolated-coordinated-quanta` with the exact web
container ID. It restored all 70 collections and 1,325 rows in an unserved
namespace, then removed that namespace. All 1,324 business records and indexes
were unchanged. The one operational coordinator row advanced from revision 1
to 2 only because the existing web process restarted. Seven boundary checks
verify that this exception cannot hide another guard write or shape change.

`install_thunderhawk_manual_capture.py` installed five backend files and six
frontend files into the same containers. It retained the existing HTML shell,
branding and unrelated static assets, changing only its application bundle/style
references. A private code rollback copy and the verified database archive are
retained. No database dump was restored over the pilot. Startup added only an
empty `manual_time_receipts` collection and advanced the guard from 2 to 3.
Eleven boundary checks refuse changed business data, later coordinator writes,
unreviewed schema, expiring receipts or newly created receipt records.

The installed application's worker duration and admin punch routes were then
exercised with synthetic accounts in a fresh **unserved database**. Repeating a
save returned its original entry; changing the same request conflicted;
cancellation before a delayed request prevented creation; cancellation after
a save recovered its receipt; disabling the identity denied recovery. Exactly
two fixture entries, two creation audits and three receipts were observed.
There were no external network attempts, and the temporary database was removed.
This is installed-route evidence, not a completed browser-to-Core payroll round trip.

An independent read verified all 182 backend files, 79 frontend files, served
bundle/style/page hashes, 1,324 original business records and 12 original time
entries. The pilot receipt collection remains empty. The same web container
now started at `2026-11-11T15:58:07.260187309Z`; Mongo is unchanged. All 185 other
container identities, states and start times matched the fresh baseline, which
included the separately coordinated PurePay public release. The receipt is
`deploy/thunderhawk-quanta-manual-capture-20261111.json`.

The older Core delivery `78c85f45` did not complete all release checks: its first
native writer fixture crashed in FPM, and attempts 2/3 failed resolving Ubuntu
package mirrors before the fixture could run. This pilot's specific evidence
does not clear that separate Core release requirement. Operational time exchange,
historical associations and full lifecycle/financial-result acceptance remain open.

The delivery at `79f4068d` also passed ten of eleven jobs, but native writer run
`43054334643` stopped before fixture execution: the rolling runner could not
resolve the Ubuntu package servers for `systemd-container`. The native job now
uses the explicitly named Ubuntu 24.04 image and refuses an incomplete package
index update. Its actual scheduler, process, pool, activation and recovery checks
are unchanged. This is a controlled runner-environment change, not a confirmed
DNS repair or a waiver of the earlier FPM failure. A passing complete native
rehearsal is still required.

## Source-read snapshot installation — November 11, 2026

Source `dd5d9d15a8a59d08fb7031c90bf6236d71e7c8d6` passed 297 full-application
checks and 11 transaction checks in Quanta run `43057613283`. A fresh coordinated
backup restored all 71 collections and 1,325 records into a temporary unserved
database with exact records/indexes. The backup restart advanced only the
existing startup guard from revision 3 to 4.

`install_thunderhawk_source_snapshot.py` then installed three backend files in
the existing pilot web container. All 79 frontend files, Compose settings,
storage and other services stayed unchanged. Its boundary tests refuse changed
business rows, schema/index changes, extra guard rows and later writes before
any file rollback. The installation advanced the startup guard from 4 to 5,
added no collections, and preserved all 1,324 original business records and 12
time entries. Eleven local preservation checks passed. Rollback refusal is
covered by those checks; this successful installation did not exercise its
failure recovery branch on the host.

The installed full application was exercised in a new unserved temporary
database. Five HTTP surfaces (list, detail, approved feed, approved-state and
Core review context) plus direct source observation and portal review status
were each paused after an initial read. A separate correction committed while
the reader remained paused. Each response retained matching old hours/approval
or old review status, and a fresh read reflected the correction. The seven
checks made no external calls and removed their temporary database.

Independent verification matched all 182 backend files, all 79 frontend files,
served assets, records/indexes and guard revision 5. It also matched all 187
other container identities/states/start times against the refreshed baseline,
including the partner's separately published holiday-payroll service. The same
pilot web container started at `2026-11-11T16:32:04.812073194Z`. The service hold
was released after verification. Evidence is recorded in
`deploy/thunderhawk-quanta-source-snapshot-20261111.json`.

Operational ownership and historical associations remain disabled. The broader
reader/lifecycle audit, independent approval and Core/PurePay round trip,
financial reconciliation and production acceptance remain separate requirements.
Core run `43057182731` passed ten jobs but its named Ubuntu runner also failed
resolving package servers before the native writer fixture could execute; that
release requirement remains open, with no identical rerun started.
