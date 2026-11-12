# Reviewed commercial ownership release

The Contract tab now offers **Review JobDiva ownership** for JobDiva placements.
Operators can review current placement, participant and corporation values,
then return selected linked fields to JobDiva. Current business values remain
unchanged at release; a later JobDiva refresh can replace them.

Four groups keep related ownership together: client payment terms, client
billing schedule, contractor terms and paid when paid, and worker payment
schedule. Payment terms/PWP and cycle/cadence/anchor ownership are released
together. Unrelated Core-owned fields stay protected. The old placement-only
API refuses commercial-field release and directs operators to this review.

The review locks the placement and relevant participant/source/cycle rows.
Its token covers their current values and identities. Apply rebuilds that
review under the same locks, rejects changed state, and clears ownership and
records the required audit in one transaction or caller savepoint. An audit
failure rolls back the release. Replaying a consumed review makes no further
change. This checks current state, not an historical revision counter.

Only a verified canonical billing or worker/corporation participant can receive
source ownership. Manual participants, duplicate legacy participants, a
different source Start ID, or an inconsistent company/person/employment model
are visible but blocked pending source-link reconciliation. This does not yet
release additional chain, referral or commission participant ownership.

The screen requires selection and confirmation, consumes a submitted review,
and requires a fresh review after an uncertain or failed submission. A failed
screen reload after a successful release retains the known success. Responses
from an earlier placement are discarded after navigation.

## Verification and installation

Runtime commit `e0c1fdae81e91c79cf326fff80cb10dd6f642cce` passed all 11 jobs in
[CI run 43124234773](https://github.com/coreflux-creator/CoreFlux/actions/runs/43124234773).
Seven component tests exercise read-only display, false values, blocked groups,
cancellation, the selected-group/token request, consumed-review behavior,
reload failure, and navigation during pending reads/writes. Five existing
stored-contract review tests also passed. The dashboard build and hook lint
passed.

A new isolated database fixture passed 30 checks, including source refresh
after release, paired ownership, unchanged agreement values, malformed input,
stale placement/participant/cycle state, replay, audit rollback, tenant
isolation, competing connection locking, C2C source preservation and absence
of financial postings. Relevant prior native-edit, contract-review, commercial
ownership, lifecycle, ending and source-projection regressions also passed.
All 206 existing provider-host containers were unchanged; the temporary test
containers, network and volume were removed and the coordination hold ended.

On November 11, 2026 (New York time; installation receipt
`2026-11-12T00:08:36Z`), the paired eleven-file release was installed only in the
isolated Core staging application. It verified 36 dependencies, exact file
preimages/ownership and private configuration, kept a private rollback copy,
verified served screen assets and refreshed/checked the PHP web cache. All 368
tables and 34,356 original rows were preserved. The exact deployed functions
then passed the same 30 checks in simulation tenant 2002; all synthetic rows
were rolled back and every original row was fingerprinted again. Sequence
gaps are allowed. The temporary web probe was removed.

BrowserOS loaded the actual React component with explicit synthetic data and
a local mock API. The browser showed four grouped choices, current values,
false PWP as No, named schedules, a disabled manual participant group, and a
release button enabled only after selection. There was no horizontal overflow
at the observed viewport and no release request was made. Screenshot capture
timed out; this records DOM/interaction acceptance, not screenshot inspection.
It does not establish signed-in hosted HTTP authorization or a live-company
browser workflow.

Runtime hashes and evidence receipts are indexed in
`deploy/thunderhawk-commercial-release-20261111.json`. Provider applications,
production Core, the separate Arabella pilot and real payroll/payment/financial
posting were unchanged.

## Remaining integration scope

The integration goal remains active. Next contract work includes native HTTP
acceptance, participant creation/removal and cycle-assignment atomicity, and
locking/revalidation throughout stored JobDiva apply. Generic batch results
still need retained-value explanations. Additional participant ownership
release needs source-link reconciliation rather than blindly clearing flags.

Other open requirements include cleared end-client chains, rate-field
ownership, new-placement graph failures, CSV and lifecycle invariants,
activation replay, complete field/destination/custom-field alignment,
portal/onboarding/document workflows, and financial-result plus billing/AP/AR
lineage and reconciliation. Existing dictionary verification labels must be
checked against actual acceptance evidence. This release does not close those
requirements or authorize production cutover.
