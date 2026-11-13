# Source rule review across shared directories

Scope: source preparation for the authorized isolated Thunderhawk pilot. This change does not install a hosted release, contact a provider or execute financial operations. The separate Arabella accounting pilot remains unchanged.

## Behavior

Reviewed chain, recruiter commission and referral replacement now separates the acting JobDiva workspace from the placement's business-data owner. It reads saved provider evidence and configured field mappings from the original workspace, writes rule rows to the current shared placement owner, and records the required receipt in the acting workspace. Current People, Companies, Staffing, Accounting and Placements sharing policies are resolved inside the protected transaction. A parent viewer cannot borrow a child's connection merely because its placement is visible in the shared catalog.

A retained person or placement number from an older spreadsheet no longer prevents source review. The exact saved JobDiva pair identifies the worker and Start. Existing raw and `jd:`-prefixed placement mappings stay physically unchanged. Missing, conflicting or competing identities require review; names are not substitutes for provider IDs. Derived economic participants use that saved Start for their JobDiva provenance even when the placement's display number is retained.

The review token covers the source identity, current directory policies, saved source data, configured field mappings, current rule rows, recipients and linked records. Changed evidence invalidates a pending save. The operator still explicitly chooses which compatible rule IDs to preserve. Related portals, documents, consumed commissions and separately managed economic settings retain their existing protections.

Exact retries return the durable receipt and make no new writes. A historical receipt does not claim the rules still follow JobDiva after a subsequent Core edit or removal of the saved source link. Transaction rollback covers rules, economic participants, ownership and audit together.

## Verification and observed corrections

The first CI run exposed a dependency loaded only incidentally by a previous import. Direct source-rule requests and independent worker processes could therefore fail before reviewing a placement. `sync.php` now loads the write-context helper explicitly. The tenant query check also caught an insufficiently scoped person-conflict lookup; it now joins through the accessible person and its current directory owner while checking competing provider links.

A later regression fixture had an unrelated displayed JobDiva Start while its saved link used `101`. The new provenance guard correctly refused reconciliation. That fixture now uses a consistent source identity and explicitly proves that a conflicting Start cannot rewrite the economic graph.

The standalone database fixture exercises shared-directory chain, commission and referral handback; original-workspace configuration; stale source, mapping, policy, person and display evidence; missing/competing placement links; prefixed provider IDs; required receipts; preserved rates, record numbers and histories; and unchanged financial/time tables. Independent MariaDB workers cover concurrent policy updates, source mirror changes, company merges and duplicate requests, with both commit and rollback outcomes. Authenticated native requests additionally cover login, RBAC, foreign-workspace denial, legacy numbers and shared child-workspace routing.

Source `6c91f4b9` passed all eleven jobs in [CI 43458020981](https://github.com/coreflux-creator/CoreFlux/actions/runs/43458020981): **32 shared source-rule directory checks**, **40 full projection directory checks**, **171 independent-connection checks**, **24 company-role checks**, **953 native Core session/API checks** and **418 native placement contract HTTP checks**, plus installation, packaging and guarded upgrade/recovery. The contract HTTP tests explicitly cover the child source-workspace path, parent denial, read-only denial, changed sharing, original-workspace audit and no-write receipt replay. The synthetic actor was retired, private session files removed and the owned HTTP server stopped. The new synthetic worker fixture initially omitted its schema-required email; the corrected fixture uses a non-deliverable test address and passes the actual native route.

[Pinned source, results and cleanup evidence](../deploy/thunderhawk-source-rule-directories-preparation-20261113.json). Source verification does not establish hosted acceptance. Read-only verification at **2026-11-13T16:04:34+00:00** confirmed installed runtime `4bc10bb0`, all 367 pinned files and 35,304 rows across 378 tables unchanged, with configuration preserved and temporary access/probes absent.

## Remaining integration work

The ordinary stored-assignment reconciliation endpoint still resolves its source workspace through the placement owner, and its planner's fresh directory context is presently supplied only by this rule-review path. Its full preview/apply/restore/audit flow, CSV and legacy alignment writers, native job ownership/lifecycle provenance, AP/current-reference writers, survivor/history review and stable outbound QuickBooks customer identity remain release gates. These must be completed before the pending company/source changes are installed and exercised in the authorized pilot.

The wider field, portal, onboarding, document, time/approval, payroll-result and financial reconciliation requirements remain in the integration acceptance checklist. This change does not redefine or complete that objective.
