# Native payroll approved-time accruals

## Source accepted, not installed — November 14, 2026

Exact candidate `8006adbc` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43565875451), including
**31 real payroll-accrual database checks**, **65 payroll source-identity checks**,
**944 independent-connection checks**, **953 native-session checks** and
**917 native HTTP checks**. Temporary-access and fixture cleanup passed.
[Pinned evidence](../deploy/thunderhawk-native-payroll-accrual-preparation-20261114.json).
It is not installed in the hosted pilot or production. Validation used disposable
records only; no real payroll, payment or accounting posting was executed.

Payroll posting now obtains each employee's person identity from the saved
employment link in the original company's current People directory. Matching
email addresses and account IDs no longer establish that identity. Shared
People directories are supported without moving the payroll run to the parent.
Missing links and duplicate employee lines refuse posting. Later contact edits
do not redirect a saved identity; retirement does not erase historical linkage.

Extracted time is read in the original payroll company. Each complete source is
validated against its retained approval facts and rate evidence. Wage and burden
amounts use that approval, including its engagement, currency and hourly unit,
and preserve the staffing emitter's rounding of individual cost components.
Later rate, currency, unit or assignment changes cannot reprice those amounts.
The consumer recognizes both numeric and older explicit `payroll:run#` references.

Clearing also requires an active posted staffing journal, the matching legal
entity and original assignment dimensions, and a recorded payroll liability
that supports the source amount. Failed events, missing/reversed journals,
changed liability amounts, overlapping assignment/segment events and legacy
aggregate coverage require reconciliation. They cannot silently become new wage
expense. Legacy aggregate and assignment events cannot both clear one source.
Pending or failed approved-week accounting requests must finish before payroll
posts. A new retained approval without its handoff/source journal also refuses,
so payroll cannot recognize new expense while the delayed time handoff later
recognizes the same labor. Changing the saved accounting owner requires review.

The payroll run, employee lines, retained time and existing accruals stay locked
through journal creation and linking. A mandatory link failure rolls back the
event, journal and run update together. Nested callers retain their own
transaction. Exact replay uses the original event/journal; a reversed payroll
journal cannot be reported as a successful replay.

The native time-release action now locks and checks each original payroll run
before changing its source stamps. Only uncomputed drafts without employee
earnings, accounting history or attached placement earnings can release time.
Computation, approval, payment and workflow history prevent blind release even
if someone has changed the status label back to draft. The original source
references and operator reason are saved atomically; audit failure restores the
source stamps. Discovery is rechecked under source locks, and competing payroll
writers must finish before release proceeds.

## Required remaining work

This is one part of the full Thunderhawk integration. Native hourly gross still
needs retained source earnings, explicit precision/provenance, and protected
compute overrides and state transitions. Salary treatment must remain explicit.
Shared accounting-owner propagation, whole-source financial provenance and
paired posted corrections remain open. Generic Billing/AP source release and
period-bundle correction/serialization are separate unfinished paths.

The earlier remaining requirements also remain: full field/reference alignment,
portal/onboarding and document acceptance, callback and financial-result
reconciliation, required migrations and guarded pilot installation, and the full
signed-in Quanta/PurePay/PurePayroll round trip. This document does not establish
completion or authorize a production cutover. Arabella remains separate.
