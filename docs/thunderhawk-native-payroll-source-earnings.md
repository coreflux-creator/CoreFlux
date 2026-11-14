# Native payroll earnings from approved time

## Source accepted, not installed — November 14, 2026

Exact candidate `aab5c933` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43568369321).
[Pinned evidence](../deploy/thunderhawk-native-payroll-source-earnings-preparation-20261114.json). The new disposable
database suite passed 22 checks, the exact-arithmetic suite passed 36 checks,
and the local interface suite passed all 528 tests, including four new rendered
payroll checks. The frontend build passed. This source and Payroll migration
010 are not installed. The last verified hosted pilot remains `4bc10bb0`.

## Implemented behavior

Native hourly payroll uses each extracted entry's retained approval facts and
archived rate. It preserves distinct approvals sharing a mutable rate ID,
original overtime/double-time multipliers and paid-leave earning categories.
Nonpayable entries remain in the source receipt but earn no wages; a run with
only such entries cannot fall back to profile default hours. Missing or changed
approval evidence refuses computation. Employee identity uses the reviewed
person/employment link and the original payroll company's current People
directory, with identity/source locks during computation.

Hours, rates and multipliers use four-place decimal arithmetic. Components are
rounded once per original timesheet/placement/dimension segment, with remaining
cents distributed by largest remainder. This prevents component-level rounding
from adding a cent to the segment's wage amount. Stored components retain the
original base rate, multiplier, source entry IDs and dimension fingerprint. The
employee line retains the complete source receipt and its SHA-256. A mixed or
fractional rate is not represented by a fabricated whole-cent rate.

Compensation still declares whether the employee is hourly or salaried and
supplies pay frequency. An hourly source run does not use a later compensation
rate or currency to reprice the retained USD hourly source. Explicit salary
and compensation-only hourly behavior remain available. Supplemental bonuses
and commissions accept nonnegative whole cents. Request overrides cannot
replace approved hours, introduce another employee or inject internal compute
fields. Tax calculations are unchanged by this work.

Computation locks the run before source/context reads and destructive recompute,
requires the displayed computation revision, and refuses approved, paid or
ledger-linked runs. The revision advances only on successful persistence.
The API creates the independent review request in the same transaction as the
new earnings. A failed earning insert or review-request insert restores the
previous line IDs, earnings, totals, revision and workflow state. Savepoints
preserve callers' transactions. An actual second connection verifies that a
competing compute waits on the run lock and leaves original records intact.

Pay stubs display the full original rate and multiplier. The native audit CSV
includes earnings basis, retains four-place hours and leaves its single-rate
cell blank for source earnings. Dedicated Gusto hours-only CSV, preview and
submit paths refuse these source runs because they would allow provider-side
repricing. Ordinary compensation runs retain their existing Gusto path. The
source-aware PurePayroll input/reservation workflow remains separate and
unchanged; no provider request or real payroll execution was performed here.

The readiness screen validates approved hourly earnings and includes explicitly
routed source recipients. People compensation, tax and banking reads use their
directory owner. Native run detail and pay-stub identity joins also resolve
that owner while retaining the original payroll company.

## Required remaining work

This is source-computation acceptance, not completion of the payroll lifecycle
or the full integration. Independently approving the exact displayed computation
through every native/generic workflow entry point, successful review/recompute
turnover, period/effective-compensation semantics, legacy category handling and
historical import replacement still need dedicated acceptance. The generic
tenant-template payroll exporter uses a separate legacy dataset and needs its
own canonical field/category and source-rate review. It is not covered by the
dedicated Gusto guard or native audit CSV checks.

Original-company financial ownership, client/vendor contracts and additional
charges; complete financial provenance and paired corrections; period
serialization, consumed-bundle corrections and Billing/AP source release;
remaining field/reference, portal/onboarding, documents, callbacks and financial
result reconciliation; required migrations, guarded installation and the full
signed-in Quanta/PurePay/PurePayroll round trip remain open. Production cutover
and real financial execution are not authorized by this source preparation.
Arabella's accounting pilot remains separate and unchanged.
