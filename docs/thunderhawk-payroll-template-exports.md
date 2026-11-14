# Native payroll template exports

## Source correction — November 14, 2026

The configurable payroll dataset previously selected nonexistent employee, pay-period,
hour, money and bank columns. It could not export the current native calculation.
The dataset now reads the Payroll module's canonical run, period, line and earning
records under the same run lock used by native computation. Sealed calculations
must still match their saved fingerprint; approved runs must retain the exact
approved revision. Historical unsealed runs are explicitly labelled unverified.

The active company's current People-directory ownership controls employee reads.
A missing or foreign employee blocks the export instead of dropping a line or
exposing another company's employee. Person numbers come only from the saved
employment link and Core number registry. Employee numbers are separate; an old
template requesting an external employee ID must be changed because this dataset
has no provider-specific mapping.

The new **Payroll calculation audit** preset retains all earning components,
precise source rates and multipliers, source entry references, the approved time
receipt, source fingerprint, calculation revision/fingerprint and review status.
Overtime and double time are separate; money uses exact cents and hours retain
four decimals. Original source values survive later changes to the rate card.

A template projecting approved-time hours must also retain gross pay, earnings
basis/details and source/calculation evidence. This covers custom copies as well
as the existing Gusto preset. Net-only reports remain available. The guarded
export validates and buffers the file before download headers or bytes are sent,
and renders the exact mapping it validated rather than reading a second version.

Bank instructions are not captured in the payroll calculation seal. Bank-field
templates therefore return an explicit explanation; they do not silently pull
today's employee bank details or output a misleading blank payment file. A reviewed
payment-instruction snapshot, allocation handling, provider execution and cash
reconciliation remain separate unfinished work. This correction does not claim
to complete those workflows or the full Thunderhawk integration.

## Validation and installation

Local validation passed 17 payroll export checks, 182 existing export-template
checks, CSV escaping/round-trip checks, PHP syntax and the changed component's
lint check. The database fixture now exercises the real native computation and
governed CSV service, mixed source rates, fractional hours, parent/shared-directory
ownership, immediate isolation changes, foreign-run refusal, changed earnings,
independent calculation approval, competing computation locks and failure with
no partial output or success audit.

Application `6773f17f` passed all eleven jobs in [CI 43579273318](https://github.com/coreflux-creator/CoreFlux/actions/runs/43579273318).
The actual database job passed 40 native earnings checks and 34 exact-calculation
review checks, including all the new export paths, rollback and contention cases.
Its synthetic records were rolled back. The 17 focused export checks also passed
in that environment, and the complete frontend build and other release lanes passed.

A fresh read-only pilot preflight matched all 458 installed pins and private
configuration. The prepared package contains 16 files and retains 447 dependency
pins; its only new migration adds the audit export preset. Current provider and
tenant settings were not changed. [Pinned release evidence](../deploy/thunderhawk-payroll-export-preparation-20261114.json).

Guarded installation and hosted export acceptance are still pending. The isolated
pilot remains on its recorded November 14 installed application. No production,
Arabella, provider, payroll execution or payment state was changed.

The isolated installer is `deploy/install_thunderhawk_payroll_export.php`, invoked
through `deploy/run_thunderhawk_payroll_export_install.py`. It is bound to the
accepted application commit, 16-file package, 447 retained pins and the exact
one-statement preset migration. It uses the established owner-preserving file
replacement, scoped maintenance, native web-pool drain and cache refresh controls.
It captures private file and native SQL backups while all database writers are
held. The preset and migration ledger insert commit together under native table
locks; existing rows are compared before activation. Explicit recovery verifies
every original value and the unchanged new preset, then removes only that preset
and migration receipt while retaining native locks. It does not restore over
business records. A recorded operation is observed by its existing handle and
never automatically repeated after an observation timeout. New deployment tooling
must pass the complete CI suite, including the populated migration/recovery test,
before it can be used on the pilot.

The first deployment-tool rehearsal (`87eddfb5`) stopped in CI, before any hosted
operation. The scope check required the recovery DELETE to explicitly identify
the platform preset. MariaDB also required the duplicate-existence subquery to
use its own locked table alias. The installer now derives that single alias from
the hash-pinned migration, retains every value and predicate, locks the alias,
and journals both the original and executed statement fingerprints. The canonical
migration file and its ledger fingerprint are unchanged. This follows the
[native table-lock alias requirement](https://dev.mysql.com/doc/refman/8.0/en/lock-tables.html).
