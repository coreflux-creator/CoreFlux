# Original-workspace time submission

Row submission, selected-row submission and weekly submission now share the protected native time transaction. This prepares the isolated Thunderhawk pilot; it does not execute payroll, payments, provider requests or journal posting, and leaves Arabella separate.

## Behavior

- Directory ownership is read and locked in the transaction. Time stays in its original workspace even when people, placements, staffing or time catalogs are shared.
- The selected rows must still be eligible. Each mutable row retains its person, placement, hours, date, source and existing evidence. Its placement must remain current and cover the work date; the original period must be open and contain the date. A linked weekly header must belong to that person, contain the date and remain eligible for review.
- Positive hours, the worker's total for that day, current custom-category availability, extraction markers, actual invoice/AP lineage, downstream bundles and staffing accounting history are checked before submission changes any row.
- Generic submission accepts only the exact requested draft IDs, validates the whole set, and refuses malformed IDs rather than silently coercing or dropping them. Weekly submission can also resubmit rejected rows, while preserving independently approved siblings exactly. Existing pending siblings are validated without rewriting their source facts.
- Entry decisions, reconciled headers, artifact history and required audit receipts commit together. A missing receipt or late artifact failure rolls the whole operation back, including inside a caller's transaction. Rejected Quanta time can return to review without changing its provider facts; approval still requires its separate current-source check.
- Self-service uses the current persisted account email and exactly one person in the current People directory. It does not trust a stale session email or choose the first ambiguous match. This strengthens the existing email-based association; it is not a new durable account-to-person binding.
- Row-level management retains its existing Time write permission. Weekly management retains its Staffing administrator permission; Staffing write allows submission of one's own week. Platform-administrator access respects the resolved API context.

## Validation

Exact source `000c6beb` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43539728932): **710 independent-connection checks** (**56 new submission checks**), **953 native Core-session checks** and **747 placement-contract HTTP checks** (**40 new submission checks**). Temporary actor, server and private-session cleanup passed. [Pinned evidence](../deploy/thunderhawk-time-submission-preparation-20261113.json). The source is not installed.

Focused PHP syntax and time/workflow source checks passed locally. Earlier run `43538779531` was cancelled after correcting the fixture grants; run `43538963750` passed concurrency but stopped on a fixture string supplied to a numeric downstream-reference column. Both fixture errors were corrected, and neither earlier run is acceptance evidence. No provider, production, real financial or Arabella action was performed.

## Remaining integration work

Rejection, generic and weekly approval, client approval tokens and preapproved CSV imports still need the same current ownership, period, frozen rate/dimension and mandatory audit contract. In particular, generic approval resolves rates before its transaction; weekly approval reads an effective accounting owner without the protected scope; token response can select only the pending subset of its originally requested rows. Those paths need complete-set validation without retargeting identities or rewriting approved siblings.

Durable account/person binding, configured custom-category bucket/overtime/payable semantics, setup/period/CSV consumers, financial reversal/replacement and older aggregate provenance remain open. Required migrations, guarded full pilot installation and the signed-in end-to-end Thunderhawk integration audit remain required; this submission change does not establish completion of the overall goal.
