# Atomic native commercial participant editing

Installed in isolated Core staging on November 11, 2026 from
`8f5f9dae9e1d69fe931182cfd6faacb183ca28bb`. All eleven jobs passed in
[release CI](https://github.com/coreflux-creator/CoreFlux/actions/runs/43121617042).
The integration goal remains active and incomplete.

The existing participant PATCH route now calls one native edit function under
the existing `placements.manage` permission. It locks the placement before the
participant, validates the edit, and saves participant values, ownership flags,
canonical placement/corporation summaries, commercial graph changes and a
required audit receipt in one transaction. An outer caller transaction is
preserved with a savepoint. An identical save returns success with
`changed: false`, without another graph change or audit.

The main `placement:bill_to` / `bill_to_client` participant now updates the
placement's client terms correctly. Additional receivables do not gain this
authority. Canonical contractor labor updates AP terms and paid-when-paid;
canonical C2C changes also update the corporation source row. Employee
frequency remains on payroll. Selecting a cycle keeps its cadence and anchor
aligned with the canonical placement schedule. JobDiva placement ownership is
merged with existing flags, and a later forced source refresh retains these
reviewed values while refreshing unrelated fields.

Invalid terms, malformed ownership, wrong-purpose or cross-workspace cycles,
unsupported fields and contradictory PWP controls are rejected. The source
workflow owns source-managed calculations and lifecycle; manual fee edits
remain supported. Clearing terms/cycles is not treated as implicit permission
to return ownership. That separate reviewed release workflow is still open.
Database errors return a retry message without exposing database details.

## Evidence

The first disposable run at `d4c90489` found that the placement before/after
audit used inconsistent snapshot shapes. The edit now uses the stable audit
snapshot on both sides. The original failure receipt remains available, and
that run cleaned up without changing any existing container.

The corrected disposable run passed 33 checks covering canonical AR and AP
summaries, source retention, explicit cycle/anchor selection, C2C and employee
paths, replay, validation, audit-failure rollback, graph-failure rollback,
tenant isolation, lock contention, stable identities and no financial posting.
The existing 16 stored-review, 28 commercial-ownership, 27 native placement,
30 lifecycle-ownership, 17 earlier ownership, 13 ending and 17 request checks
also passed, along with the existing scope and rate smoke checks. All 206
pre-existing containers were unchanged. Temporary containers, network and
volume were removed, and the coordination hold was released.

The contention test exposes uncommitted synthetic identities to a second
connection using READ UNCOMMITTED, then exercises the actual helper's locking
read. It waits on the parent lock and rolls back on timeout. This allows the
test to verify locking without committing fixture rows; it does not establish
the absence of every concurrent race elsewhere in the integration.

The two-file installation verified 34 dependencies, exact source preimages,
file ownership and private configuration, retained private rollback copies,
and checked PHP web-cache behavior. All 368 tables and 34,356 original rows
were preserved. The exact deployed functions then passed all 33 checks in
simulation tenant 2002. Synthetic business rows were rolled back and all
original rows independently fingerprinted again. Sequence gaps are allowed.
The temporary web probe was removed.

This is deployed-function acceptance. Native HTTP authentication/authorization
and a signed-in browser walkthrough of the new route have not been exercised
in this release. The screen bundle, provider runtimes, production application,
Arabella pilot and real payroll/payment/financial posting were unchanged.

Evidence and runtime hashes are indexed in
`deploy/thunderhawk-economic-party-edit-20261111.json`.

## Remaining scope

Reviewed participant/placement ownership release, native HTTP acceptance,
participant creation/removal and cycle-assignment transactions still need
work. Stored JobDiva review must lock and revalidate state through the complete
apply operation; its current stale-token checks do not prove that a later
concurrent edit cannot race. Generic automatic/batch results also need retained
decision reporting.

The original goal still includes cleared end-client chain handling, rate-field
ownership, new-placement graph failures, CSV edits, ordinary terminal
status/date invariants, activation replay, field/custom-field alignment,
portal/onboarding/document workflows and financial-result/billing/AP/AR
lineage and reconciliation. Stale field-dictionary verification labels must be
reconciled against the newer evidence before they can claim acceptance.
