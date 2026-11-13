# Reviewed People availability

Scope: preparation for the authorized isolated Thunderhawk pilot. This change is not installed on the hosted pilot or production. Arabella remains separate.

The old JobDiva alignment workflow inferred that a person should become inactive when it found historical placements but no current placement. That could retire someone deliberately kept on the bench, and the old check treated an active placement with a past end date as absent. The workflow now discovers people for a separate review. It does not change their availability automatically.

## Operator behavior

JobDiva settings offers **Review People availability**. Each person appears with their permanent Core person number, current availability and latest recorded placement end. Every choice starts at **Keep current**. An operator can select **Bench** or, with the additional People administration permission, **Inactive**. Saving requires a reason, People management and integration management access. Read access permits inspection without saving.

Only explicitly selected changes are applied. Directory availability is separate from employment status and sign-in access: the review does not terminate employment, revoke portal access, change assignment history or execute payroll. The current ordinary assignment projector requires an active person, so a later assignment for someone inactive or on the bench remains held for explicit readiness/reactivation review; this change does not silently reactivate them.

## Identity, consistency and receipts

- Candidate mappings remain in the original JobDiva workspace. People and placements are resolved through current directory-sharing rules, with matching directory ownership required.
- Raw or prefixed candidate IDs and legacy display IDs are preserved. Competing candidate links, quarantined links, missing or aliased permanent numbers and conflicting candidate display IDs require identity review first. Other providers' links remain unchanged.
- Candidate-only people are excluded. Any undeleted draft, pending, active or on-hold placement blocks retirement, including placements referenced from another operating directory. A past end date alone cannot turn an active assignment into an absent assignment.
- The review token covers actor, current directory policies, source-link metadata, person facts, permanent numbers and placement history. Changes invalidate an unapplied review. The protected transaction locks that evidence and validates the entire selected batch before updating anyone.
- Per-person audit records retain the original workspace, directory owner, actor, provider pair, person number, reason and before/after availability. A required batch receipt commits with all selected updates. Failure of that final receipt rolls the entire batch back.
- A request ID recovers its original committed result without replaying updates or overwriting later native edits. Reusing it with a different actor, owner or decision is refused. Native permissions are checked again on every request, including receipt recovery.
- The UI freezes an uncertain save and retries its original body and request ID. Refresh discards pending choices and requires a new review.

The existing limit is 5,000 source candidate links per review and 500 selected changes per save. The response reports total coverage and explicitly flags a truncated preview. Larger-workspace paging remains a UI limitation; no unseen records are changed.

## Verification and release gate

Behavioral component tests cover default choices, selected-only submission, permission capabilities, stale refresh and lost-response retry. Canonical database, native HTTP and independent-connection fixtures cover source isolation, changed evidence, live assignments, identity conflicts, atomic late-audit rollback, durable replay and concurrent commit/rollback. These fixtures use synthetic data and make no provider calls or financial executions.

Source `d840514a` passed all eleven jobs in [CI 43474040385](https://github.com/coreflux-creator/CoreFlux/actions/runs/43474040385), including 42 availability database checks, 293 independent-connection checks, 953 native Core session/API checks and 471 native placement contract HTTP checks. Five behavioral component tests passed. Native synthetic accounts were retired, private session files removed and temporary servers stopped. [Pinned source and completion evidence](../deploy/thunderhawk-people-availability-preparation-20261113.json) records the exact checks and the read-only hosted baseline.

This source change does not establish hosted acceptance. The remaining legacy report, rate draft, joined-index, duplicate-consolidation and maintenance paths still require review before the combined pilot installation; the wider integration acceptance remains open.

Next implementation finding: `jobdivaMappingRepairSourceRateDrafts` joins placement owner to source workspace and delegates to `placementsEnsureDraftRateFromSourcePayload`. That helper uses ambient scope for both source mappings and business rows and catches writer failures as an unsuccessful repair. It is also called by `placementsAutoApproveDraftRates`, so correcting only the alignment action would leave the normal activation path inconsistent. Both paths need the original source workspace, fresh directory ownership, exact retained candidate/Start identity, protected rate writes and a required audit. Repair must keep approval a separate permission-controlled operation.
