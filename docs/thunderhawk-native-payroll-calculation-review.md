# Independent review of the saved payroll calculation

## Source accepted, not installed — November 14, 2026

Exact candidate `846e8620` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43570265181),
including **32 actual payroll-review database checks**, the earlier 22 native
source-earnings checks, 36 arithmetic checks and 65 payroll source-identity checks.
The broader suites passed 944 independent-connection, 953 native-session and
917 native HTTP checks, with fixture and temporary-access cleanup verified.
[Pinned evidence](../deploy/thunderhawk-native-payroll-calculation-review-preparation-20261114.json). All 530 local
interface tests and the frontend build passed. This source and Payroll migration
011 are not installed. The last verified hosted pilot remains `4bc10bb0`.

## Implemented behavior

Computation saves a fingerprint of the complete calculation: original company,
run and pay period; creator and calculator; revision; employee identities,
hours, earnings basis and source receipts; all earning, tax and deduction
components; payment method; and payroll totals. Runtime status, incidental
notes and artifact/workflow IDs are excluded so normal review projections do
not change the calculation's identity. New reviews carry the revision and
fingerprint, and the run detail displays its calculation version.

The native approval action requires the revision and fingerprint displayed by
the reviewer. It cannot search for or create a newer pending review after the
displayed one has been cancelled or rejected. Recomputing creates a distinct
review even when the amounts are identical. The shared workflow engine locks
the payroll run and calculation before the workflow, checks the same fingerprint
and current review ID, and requires a signed-in, eligible, routed independent
reviewer. Approve, reject and comment are the available payroll decisions;
skip, delegation and escalation cannot substitute for payroll approval.

A mutation to an earning, tax, deduction, source receipt, payment method,
period/pay date or total invalidates the saved review. Older computed records
without retained calculation evidence must be recomputed; the migration does
not fabricate approval history. A completed decision stores the exact approved
revision and fingerprint on the run. New sealed calculations must still match
that approval before the accounting reader accepts them. Historical revision-zero
records retain their existing reconciliation path and are not newly certified.

Workflow decisions and payroll projections share a transaction. Nested callers
receive a savepoint, so a late payroll-line failure restores workflow status,
step-action receipts, run approval and line state without committing or rolling
back unrelated caller work. Direct subject projection also requires the matching
workflow state and recorded independent decision. Source values and provider IDs
are preserved; this change does not perform payroll execution or deployment.

## Required remaining work

Hosted signed-in acceptance and the complete integration round trip remain
required. Historical payroll import/replacement, period and effective-compensation
semantics, legacy categories, the generic tenant-template payroll dataset,
remaining financial owner/client/vendor/charge alignment, complete financial
corrections and provenance, period/Billing/AP source handling, and remaining
field/reference, portal/onboarding/document/callback reconciliation are open.
Payment and provider-execution routes require their own lifecycle acceptance;
the calculation review tests do not establish those outcomes.

Payroll migrations 010/011, time-approval migration 203 and the newer prepared
source still require guarded pilot installation. No production cutover, real
payroll, payment or real-company financial posting was performed. Arabella's
accounting pilot remains separate and unchanged.
