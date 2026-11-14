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

Full CI/database validation and installation of this correction are pending.
The isolated pilot remains on its recorded November 14 installed application;
no production, Arabella, provider, payroll execution or payment state was changed.
