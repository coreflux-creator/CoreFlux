# Reviewed time corrections and original history

The native timesheet detail now links authorized operators to a review of an approved entry and to its original/replacement history. This is preparation for the isolated Thunderhawk pilot. It does not publish to production, submit payroll, move money or post journals. Arabella's accounting pilot remains separate.

## Operator behavior

- Review displays the original worker, assignment, date, hours, category, source, description and recorded approval. Worker and placement identity cannot be edited through a correction.
- The replacement needs an explicit reason. Its date, hours, category, custom category and description can be changed within current placement, period and daily-hour rules.
- Save requires the exact reviewed revision. Changed original time, header, worker or placement context requires a new review. The revision is not an authorization credential; normal sign-in, workspace and permission checks remain mandatory.
- The original row retains its source, approval, rate and dimension evidence and gains a replacement link. The replacement is draft with no inherited approval, rate or dimension authority. The original and replacement weeks are reconciled in the same transaction as the required audit receipt.
- Original/replacement history is available with read access, including after placement retirement. It checks every relationship in the original workspace and refuses ambiguous predecessors, cycles, changed identities and missing/foreign replacements.
- The UI conceals stale responses from a different workspace or entry, prevents duplicate in-flight submissions, preserves the draft on a stale-review refusal and links successful saves to the replacement week and complete history.

## Downstream boundary

Quanta-origin entries use their existing source-revision review, including entries whose import mapping is absent. Extracted, closed-period and downstream-ready entries remain protected.

The ordinary correction guard additionally checks actual invoice and bill lines and staffing accounting events in the original workspace and its immediate parent. `time_entry` line references are entry IDs; older `time` references are downstream bundle IDs. Bundle membership is read from its stored entry IDs rather than comparing unrelated numeric identities. Prepared or consumed bundles containing the original also block an ordinary replacement: they retain calculated amounts and may already have accrual history even when consumption flags are absent. No financial history is reversed or rewritten by this feature.

That boundary does not implement a complete financial correction lifecycle. Paired reversal/replacement and downstream reservation/document reconciliation remain required before correcting time that has already entered those workflows. Secondary entries on older aggregated lines whose only surviving linkage was an extraction flag still require a separate provenance repair; this change does not invent missing lineage.

## Evidence

Exact source `a2241e51` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43536532220): **654 independent-connection checks** (**24 new correction races**), **953 native Core-session checks** and **707 placement-contract HTTP checks** (**29 new correction cases**), with temporary actor/server/session cleanup verified. Local checks passed 48 rendered UI cases, focused ESLint, the 6,134-module frontend build and the relevant time/workflow checks. [Pinned evidence](../deploy/thunderhawk-time-correction-review-preparation-20261113.json). This source is not installed.

Read-only verification at `2026-11-14T00:39:52+00:00` found the installed pilot still on `4bc10bb07791ceb7b2867f8dde28ce539cb5fd51`, with 367 pinned files, 378 tables and 35,304 rows unchanged. Configuration and temporary-access cleanup were preserved. This candidate is not installed.

## Remaining time alignment before pilot upgrade

Generic and weekly submission now use the shared protected path described in [submission acceptance](thunderhawk-time-submission.md). Rejection, approval, client tokens and preapproved CSV still need the same current ownership/period, frozen rate/dimension and required audit contract. Token response currently selects only the remaining pending subset; generic approval resolves rates before its transaction. Self-service now reads the persisted account email and refuses ambiguous/currently unavailable People mappings, but a durable account-to-person binding remains separate unfinished work.

Custom category ownership and configured bucket/overtime/payable semantics, period/setup/CSV consumers, downstream financial correction/provenance, remaining current-reference readers, required migrations, guarded pilot upgrade and the full signed-in integration round trip remain open. The overall integration is incomplete.
