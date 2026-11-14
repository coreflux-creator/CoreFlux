# Incremental time and period alignment

The November 14 refreshed cross-system test has reached a concrete intake defect. It has **not completed the updated round trip**. Evidence: [source capture and native Core diagnostic](../deploy/thunderhawk-updated-roundtrip-period-conflict-20261114.json).

## Verified state

Read-only inspection matched all 463 current Core application/dependency files. Existing P-387 / PL-902 is active. Core time 17 remains approved and reserved for the prior $100 held draft; its original rows were preserved. The new retained-rate reader refuses to use that older approval as fresh source because it lacks the original rate hash. No hash or historical approval was fabricated.

The existing Quanta worker and assignment received a fresh **75-minute** synthetic entry for **November 14, 2026**, using native capture, submission and independent approval. Entry `5e72afa5-07b9-4681-bcc0-7e46379a205c` belongs to source timesheet `5bcf1d14-89c9-419b-a807-40d9c875003c`. All 18 checks passed, including exact capture replay, changed-request rejection, stale-week rejection and persisted separate actors. All original provider records, 181 runtime files, configuration and the entire PurePay snapshot were preserved during capture. Both temporary native sessions were revoked. No new provider payroll was created.

Core's native preview found the exact source, worker and placement, reported **Ready for CoreFlux review**, and retained 1.25 hours. Native import then returned **409**: `Week of 2026-11-09 is already approved; reopen it before import`. The five-request diagnostic passed 22 checks of that refusal, company isolation, preservation and cleanup. Actor 114 / membership 102 was retired and its credentials/cookie cleared. All existing Core rows and the observed time, reservation, provider-result, accounting and payment tables were unchanged. There is no partial Core import.

## Actual calendar mismatch

| Record | Observed period |
| --- | --- |
| New Quanta source week | November 14–20, Saturday–Friday |
| Core destination week | November 9–15, Monday–Sunday |
| Existing Core timesheet 8 | Approved; two hours, with source time 17 already reserved |
| Pilot payroll schedules 3 and 10 | Biweekly, anchored November 9 |

The Core pilot has no `tenant_staffing_settings` row, so its seven-day week uses the Monday default. Source approval weeks, Core time-review weeks and payroll periods are different concepts; their dates must not silently be substituted for one another. Changing the current default would not itself migrate or reconcile the existing approved week and generated payroll periods.

The existing `quantaPreviewRows` only checks source/identity/import history. It does not inspect the destination week's approval state before advertising readiness. The commit path performs that check later. Its suggestion to reopen is also insufficient here: the native reopen path refuses an approved week, and the prior source has a held payroll reservation. Removing the guard or editing old approval facts would not be a valid fix.

## Required implementation and continuation

1. Make preview and commit resolve the same authorized destination/calendar constraints. A blocked destination must not be labelled ready. Preserve no-op replay of already imported source.
2. Implement the reviewed incremental/supplemental-time path for additional source arriving after a Core week was approved. Retain original entry approvals, financial intent and reservations; require independent approval and retained economics only for new source. Account for the existing week-level accounting-intent guard rather than weakening it or recomputing historical rates.
3. Reconcile the intended time calendar with provider source weeks and payroll schedule periods. Any effective calendar change must retain historical periods and reject conflicting generated/consumed work. Do not rewrite earlier headers merely to make this fixture fit.
4. Continue **this same saved 75-minute source**, rather than recapturing it or bypassing date validation, through Core review, returned Quanta status, source-aware PurePayroll input/reservation and a held draft/result. Verify replay, changed-request refusal, company isolation and exact source amount.

The source is left approved in Quanta and unimported in Core. Its note explicitly identifies invented work with no real payroll payable. No real payroll, payments, financial postings, production cutover or Arabella changes occurred. This diagnostic is native HTTP evidence, not visual browser acceptance. The full integration goal remains active.
