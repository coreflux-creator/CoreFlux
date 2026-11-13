# Reviewed JobDiva active-status restoration

This replaces the historical date-only maintenance command. It is a status repair for an existing exact JobDiva assignment, not a payroll approval, source import, or worker reactivation.

Status: source `07fd66c3` passed all eleven jobs in [CI 43497660171](https://github.com/coreflux-creator/CoreFlux/actions/runs/43497660171). The canonical database suite passed **69 restoration checks**. The independent-session suite passed **398 checks**, including **29 new restoration and actual CLI checks**. Existing native Core session/API (953), placement-contract HTTP (521), alignment (30), live-contract (35), joined-source (26) and source-rate (41) checks also passed; native HTTP cleanup was verified. [Pinned evidence](../deploy/thunderhawk-active-restoration-preparation-20261113.json).

No hosted installation or live restoration has been performed. Read-only verification at 2026-11-13T20:05:34+00:00 confirmed installed pilot `4bc10bb0`, 367 pinned files, 378 tables and 35,304 rows unchanged; configuration and temporary-access cleanup remain intact.

## Operator flow

Run the command from the installed application, using the original JobDiva workspace and the actual Core reviewer's user ID:

```text
php scripts/repair_jobdiva_active_status_regression.php preview WORKSPACE_ID ACTOR_ID PLACEMENT_IDS
```

Review every returned placement, its retained provider ID, current status, approved rate IDs, blockers and projected active count. All selected rows must be eligible. The command needs current placement management permission; a supplied actor ID does not grant access. The preview rolls back and does not change business data or audit history.

For an accepted review, retain a unique request UUID and run:

```text
php scripts/repair_jobdiva_active_status_regression.php apply WORKSPACE_ID ACTOR_ID PLACEMENT_IDS REVIEW_TOKEN REQUEST_UUID EXPECTED_RESTORE_COUNT EXPECTED_ACTIVE_TOTAL "Reviewed reason"
```

The selection, counts, token and reviewer must match the review. A changed assignment, worker, approval, company directory, recipient, schedule, source record, permission or active roster requires a fresh review. A failed batch restores nothing. A retry with the same request returns the saved receipt without reversing subsequent native changes; changed instructions under the same request ID are rejected.

## Evidence and ownership

- Original-workspace source link, current independently resolved business directories, retained permanent placement number, and no competing JobDiva assignment identity.
- Source-owned lifecycle, unarchived `pending_start` or `ended` status, no actual end, and dates spanning today.
- Available current worker; source-owned worker selection must still agree with the exact candidate link.
- Connected source, successful observation in the latest sync, observed within the preceding 24 hours. Refresh JobDiva first when this evidence is stale.
- Existing assignment mirrors must agree with the retained Start and cannot carry a conflicting ended/on-hold status or closed financial detail.
- Raw assignment detail rebuilds the stored contract and establishes actual start, approved billing and salary, and no actual end or closure. An `active` display label alone is insufficient.
- Exactly one existing approved rate at both the original start and today. No rate is created, approved or changed by restoration.
- Current economic readiness, billing recipient, payee and operating schedules. Missing setup is reported for review; this command does not repair it implicitly.

Each status change and the batch receipt must commit together. Provider links, permanent numbers, Core-owned fields, approved financial history, timesheets, payroll runs, payments and ledger records are retained.

The reconciliation deployment workflow now requires a reviewer. Without a review token it runs preview only; its old count-only invocation cannot write. Applying requires the separately reviewed token, exact counts, request UUID and reason. This document does not authorize running that production workflow.

## Observed validation corrections

The first database run exposed a comparison between an original stored root and one containing newly added identity metadata. Restoration now validates every financial row against the exact retained Start tuple and rebuilds the contract, without treating the metadata itself as a source edit. The duplicate fixture initially used the stable mapper, which correctly retained the original pair; its first direct-insert replacement then violated the canonical one-link-per-internal-record constraint. The accepted fixture models the actual legacy risk: raw and prefixed forms of one provider Start linked to two distinct Core placements. Both a stale review and a fresh attempt are refused. These corrections did not weaken the identity checks.

Accepted database cases include stale and conflicting source evidence, native lifecycle ownership, inactive workers, missing/overlapping rate approval, disabled payroll profiles, missing billing clients, retired companies, disabled cycles, missing permanent numbers, changed permissions, wrong counts, late receipt failure, exact replay and a later native hold. Independent connections prove that committed changes reject the old review, rolled-back changes permit the unchanged review, and concurrent identical requests save one receipt. The actual CLI proves preview has no writes, apply restores the reviewed row, and retry returns the saved result.
