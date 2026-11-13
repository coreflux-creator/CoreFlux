# Reviewed JobDiva active-status restoration

This replaces the historical date-only maintenance command. It is a status repair for an existing exact JobDiva assignment, not a payroll approval, source import, or worker reactivation.

Status: source implementation and local command checks prepared; canonical database and independent-session acceptance pending. No hosted installation or live restoration has been performed.

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
- Raw assignment detail rebuilds the stored contract and establishes actual start, approved billing and salary, and no actual end or closure. An `active` display label alone is insufficient.
- Exactly one existing approved rate at both the original start and today. No rate is created, approved or changed by restoration.
- Current economic readiness, billing recipient, payee and operating schedules. Missing setup is reported for review; this command does not repair it implicitly.

Each status change and the batch receipt must commit together. Provider links, permanent numbers, Core-owned fields, approved financial history, timesheets, payroll runs, payments and ledger records are retained.

The reconciliation deployment workflow now requires a reviewer. Without a review token it runs preview only; its old count-only invocation cannot write. Applying requires the separately reviewed token, exact counts, request UUID and reason. This document does not authorize running that production workflow.
