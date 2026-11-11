# Quanta assignment lifecycle: current evidence and remaining work

## Installed November 11 pilot correction

Quanta source `e47e1693824be67a7fb3e65e126b813e33f89bdb` is installed only in the
existing isolated provider pilot. Closing or revising one assignment now leaves
its onboarding history readable while disabling submissions for that case.
Other current assignments remain usable. Current worker, identity, person-link
and workspace checks remain required; document upload/transfer continues to
require the exact current publication and an open assignment.

An existing Core time-review receipt remains bound to the original assignment
revision when an end date, closure or title change still admits that work.
Both original and current date boundaries apply. Cancellation, an end date
before the work, changed source approval/hours or unverifiable history still
invalidate the review. New historical time uses the current revision. Native
time, approval and financial records are never rewritten by this correction.

The new lifecycle checks reproduced six failures before the correction. All 436
full-application checks passed with an in-memory database and zero attempted
provider connections. The 23 onboarding and 14 assignment-portal rendered
checks passed, and the frontend build completed with existing lint warnings.
The full-app test runner also now carries its shared contact JSON fixtures into
the isolated test directory.

The bounded installer preserved all existing business collections and indexes,
retained a private rollback copy, kept scheduled work disabled and verified
every other container's identity/start time. Native shutdown appended 235
previously buffered API usage events; existing usage records stayed exact.
Independent verification fetched and hashed all 54 served frontend files,
verified installed runtime bytes and used an ephemeral native worker token to
read the existing pilot: three approved onboarding tasks and twelve current
Core time-review receipts remained visible. Worker access to workspace reports
was denied. All 65 collections stayed unchanged during those verification
reads; no token was persisted. This is API acceptance, not a fresh browser
password/MFA or full hosted closure round trip.

Evidence: `deploy/thunderhawk-assignment-lifecycle-20261111.json` and
`deploy/install_thunderhawk_assignment_lifecycle.py`. The same isolated Quanta
container now started at `2026-11-11T01:25:40.991316817Z`. PurePay/PurePayroll
source, public edge and protected services were independently rechecked after
this intentional pilot restart and remained unchanged.

## Operational lifecycle still incomplete

The source audit found that Quanta Core assignment metadata and native worksite
access remain separate. `coreflux_contract.update_assignment` changes the
versioned assignment only. `routers/time._assigned_worksite_ids` and clock-in
use native `worksite_assignments`; they do not enforce the Core assignment's
dates. Core's `quantaRouteFor` already rejects imports outside the placement's
start/end dates and admits valid historical work for active or ended placements.

Do not treat the installed history correction or the earlier worker-contact
disable/reenable test as complete offboarding. Next work must establish the
assignment-specific operational access contract, cover every applicable time
capture route, preserve worked time and valid historical approvals, handle
overlapping/native assignments, and demonstrate the Core-to-provider closure
on a distinct synthetic assignment. Do not end PL-864 merely to test and undo:
the existing lifecycle intentionally makes ended/cancelled assignments terminal.
Removing a worker's entire membership would also remove unrelated access and
is not an assignment-specific offboarding implementation.

The original integration goal, source/financial reconciliation, remaining
onboarding/time/payroll exchange, full-candidate activation and release gates
remain open. Frozen full-Core candidate `6b7841894cbfc818769de216f2719b0fbb3ab2e8`
is unchanged. No real payroll, payments, financial postings, Arabella pilot
changes or production cutover occurred.
