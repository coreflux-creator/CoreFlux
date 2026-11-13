# Exact assignment billing-link repair

## Behavior

The old catalog-maintenance command could reassign placements to the lowest-numbered active client for a company, reactivate or retire clients, and remove company roles using provenance heuristics. Those unused maintenance writers have been removed. Multiple retained CL numbers and an absent historical audit are not sufficient grounds to change an identity or lifecycle decision.

`scripts/repair_staffing_client_catalog.php WORKSPACE_ID` now produces a read-only catalog review. It resolves Companies and Placements independently and reports missing companies, multiple retained clients, active assignments whose client needs review, and placeholder names. Exit 1 indicates review is needed or an operation failed; it does not mean records were changed. Only the explicit `--apply-source-links` option invokes the exact JobDiva billing-link repair. Client status and company roles remain native review decisions.

The repair service is also used by the existing authorized JobDiva integration API and ordered repair workflow. Each candidate is re-read in its own transaction or caller savepoint:

1. Lock current workspace, Companies and Placements policies. Preserve the original connected workspace on provider mappings.
2. Lock the exact source mapping and current placement; parse native field ownership before any company, client or mapping side effect.
3. Hold a Core-owned `client_id`. Reject malformed ownership or conflicting source identities. The conflict lookup is restricted to the accessible placement directory.
4. Require the stored assignment to match the mapped source ID. Read related mirrors with current locks; failed reads cannot silently fall back to older evidence.
5. Require an exact assignment billing-company ID. A name alone cannot choose a different client. Resolve company aliases without moving the retained provider binding.
6. Reuse an exact client binding without updating its profile or lifecycle. If no binding exists, use the ownership-controlled client bridge; multiple retained clients require review.
7. Change only the placement's client reference and update timestamp. Commit the required before/after audit with all company/client/mapping/number changes. Audit failure rolls the operation back.

An identical retry returns `already_linked` without a business-row mutation. Skipped results distinguish Core ownership, missing exact billing evidence and source records needing review. Billing repair never changes the work-recipient company or initiates time, payroll, payments or accounting postings.

## Verification and release status

Source `b5967882` is pushed and **all eleven jobs** passed in [CI 43395077504](https://github.com/coreflux-creator/CoreFlux/actions/runs/43395077504). The native job passed **47 full-schema billing repair checks**, **102 independent-connection concurrency checks**, **832 signed-in/login/API checks**, and the existing **23 billing-role checks**. Empty installation, standalone packaging, actual writer observation, web maintenance, guarded upgrade and paired recovery also passed. [Pinned evidence](../deploy/thunderhawk-client-link-repair-preparation-20261113.json).

The tests cover each supported shared/isolated directory combination, native holds before side effects, malformed ownership, exact source identities, competing source links, errored source holds, multiple retained clients, source retry, required-audit rollback of a newly created company/client/mapping/number graph, the actual read-only CLI, authenticated permissions and repair failure/retry responses. Independent sessions verify current ownership and directory changes, merge-first/repair-first ordering, commit/rollback and older repeatable-read snapshots. A merge after a repair correctly preserves the client number while moving that client to the survivor; the test does not incorrectly require that safe merge to fail.

Earlier verification caught and corrected missing email fields in synthetic fixtures, a conflict lookup missing an explicit placement-directory join, the isolated HTTP router's absent repair route, and stale/line-ending-sensitive source assertions. No live business data was used to work around those failures.

Read-only verification at 2026-11-13T08:31:53+00:00 confirmed the installed pilot still matches runtime `4bc10bb0`: 367 pinned files, 378 tables and 35,304 rows unchanged, unchanged private configuration, and temporary reviewer access/credentials/probes absent. This candidate is **not installed**. No provider runtime, production, Arabella or real financial execution changed.

## Remaining integration gates

This is one correction within the full Thunderhawk integration goal. It does not establish completion of the ordinary placement source/native write paths or of the overall repair workflow.

- `jobdivaSyncUpsertPlacementInTransaction` still resolves a billing company/client before reading existing native placement ownership; its placement queries still use the source workspace directly. The outer projector also resolves person/end-client identities before the placement write. Fresh independent owners and owned-role decisions must cover that entire operation and all related rates, participants, mappings and postconditions.
- Native placement creation can resolve company roles outside a transaction when using an existing person. Current company/client references and the required audit must be protected together through creation and editing.
- Several alignment-report and older repair queries still join placement ownership to the source workspace. The report must accurately reflect independently shared directories, and its guidance must distinguish billing clients from work recipients.
- The remaining AP/current-reference writers, native job ownership/shared-source contract, survivor-side source review and retired-number/history navigation are still release gates for the pending company merge/client work.
- Outbound QuickBooks invoice routing still resolves a saved client name; reviewed stable customer identity must replace that dependency before rename/merge behavior can be treated as complete. No outbound invoice was attempted.
- Hosted acceptance and a guarded pilot installation remain required after those writers are corrected. Broader field alignment, portal/onboarding/documents, time/approval corrections, payroll results and financial reconciliation remain governed by the full acceptance checkpoint.

The separate Arabella accounting pilot and protected historical payroll remain untouched.
