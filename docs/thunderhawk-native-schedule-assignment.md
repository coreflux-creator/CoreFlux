# Reviewed placement schedules

Core schedule selection now commits the placement's cadence, anchor date, operating schedule, derived payroll pointer, canonical payee selection, ownership and required audit together. A changed selection requires a review of the current placement, participants and available schedules. A stale review cannot replace a later decision. Retrying an already-applied selection confirms its current state without another write or audit.

Native creation validates explicit anchors and offset ranges. A payroll schedule and its execution-cycle bridge are created in the same transaction as the operating schedule and optional placement assignment. A failed review, graph reconciliation or required audit rolls back the whole operation. Repeating the same native name and definition reuses its original identity; a conflicting definition or existing external/source schedule is not silently adopted. No payroll periods or runs are created.

An active payroll bridge must agree with the selected operating schedule's frequency, anchor and settlement offset. Selecting a schedule without a bridge clears the previous compatibility pointer. It does not prove that payroll execution is ready; provider scheduling and execution readiness remain separate acceptance work.

The ordinary Contract frequency control keeps an existing custom schedule when its frequency is unchanged. Selecting a different standard frequency uses the standard definition, rather than an arbitrary custom schedule with the same frequency. Reconciliation refuses to silently replace an unavailable Core-owned operating schedule. JobDiva can still refresh unrelated fields. A secondary AP schedule on a W2 placement does not claim the worker's primary payroll frequency.

The existing screen remains the Contract workflow with per-participant frequency controls. This change does not introduce an additional custom-schedule screen. Shared schedule editing through PATCH, independent source-ownership release for secondary AP schedules, provider execution readiness, and operator workflow acceptance remain incomplete.

## Validation and release

Runtime commit `9f15524949334f99311d4a9c4a3215bafbc5dcbb` passed all 11 jobs in [CI run 43151119984](https://github.com/coreflux-creator/CoreFlux/actions/runs/43151119984). The disposable database fixture passed 49 schedule checks, and the authenticated native HTTP suite passed 182 checks. HTTP coverage verifies permission removal, foreign-workspace denial, duplicate requests, stale reviews and assignment-audit failure after payroll bridge creation. Its synthetic actor was retired and its server/session resources removed.

The isolated installation receipt is dated `2026-11-12T03:58:16Z`. Five runtime files were installed with 49 matching dependencies, a private rollback copy, preserved configuration and PHP web-runtime verification. The frontend assets and their previously recorded source-input baseline were unchanged. The temporary web probe was removed.

The hosted verification receipt dated `2026-11-12T03:58:34Z` reached terminal success with all 49 schedule checks passed. Installation and hosted verification each preserved all 34,455 original rows across 368 tables. Every synthetic fixture row was rolled back. Exact hashes and receipt locations are recorded in `deploy/thunderhawk-native-schedules-20261111.json`.

The full integration goal remains active, including complete field/destination alignment, corporate private-record reassociation, source-owned mapped commercial fields, automatic batch and end-client/rate behavior, CSV/lifecycle/activation replay, portal/onboarding/documents and financial-result lineage/reconciliation. This release does not authorize production cutover or real payroll, payments or financial postings.
