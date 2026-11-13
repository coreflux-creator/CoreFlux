# Stored assignment reconciliation across shared directories

Scope: source preparation for the authorized isolated Thunderhawk pilot. No hosted installation, provider writes, production cutover or real financial execution occurs in this change. Arabella remains separate.

## Behavior

Stored preview and apply use the signed-in source workspace for saved JobDiva assignments and configured fields. People, placements, companies, clients, jobs and accounting setup use their current business-directory owners. Every entry point resolves those owners inside a protected transaction, including ordinary preview, explicit apply and automatic stored replay. A parent directory does not borrow a child's JobDiva source merely because the business record is shared.

An exact saved provider pair identifies a retained placement or person even when its displayed number came from an older spreadsheet. Existing raw and `jd:`-prefixed pairs remain physically unchanged. Multiple, conflicting or competing source identities require review. Sparse assignment evidence can resolve an existing exact candidate without requiring a repeated name; it cannot create a person from missing identity information or reactivate an inactive worker.

Preview tokens cover original-workspace source evidence and configured fields, fresh directory policies, physical provider links, displayed identities, candidate lifecycle and the current commercial graph. Changed evidence invalidates a pending save. Malformed ownership produces a blocked row with an explanation, including for archived placements, rather than stopping the entire preview.

Core-owned fields and approved rates retain their protections. Restoring an archived assignment uses the canonical lifecycle writer and requires current source evidence with no conflicting Core lifecycle decision. The reconciliation layer no longer clears an archive before checking those conditions. Audit records retain the acting source workspace, actual placement owner and complete before/after shared graph.

All selected rows and required audits commit together. A late failure rolls back earlier rows too. Automatic replay revalidates each current saved graph and uses that same protected apply path; a stale inventory is not authority to write. Repeated projection preserves person and placement numbers. Concurrent applications of the same reviewed token commit one result; after a committed change the other request must refresh its preview.

## Verification

The standalone database checks cover shared-directory preview, original-workspace configuration, sparse candidate evidence, retained prefixed identities, stale policy/source/person/mapping/display evidence, competing links, Core-owned fields, approved rates, lifecycle restoration, full-batch audit rollback and repeated automatic replay. The fixture rolls back its invented rows and verifies unchanged financial and time tables.

Independent native database sessions exercise concurrent Companies/Placements policy changes, source mirror updates, company merges and duplicate stored apply requests, with both commit and rollback outcomes. The signed-in API checks exercise the actual reconciliation endpoint, child-source routing, parent isolation, read-only denial, token mismatch, required audit rollback and original-workspace audit attribution.

The first run exposed an incorrect exception type in the new candidate ownership check: invalid ownership escaped the row-level preview. The implementation now catches the specific ownership-validation error and retains unexpected failures. The archived lifecycle check has the same treatment. Older static assertions were updated to require preservation of the physical provider pair and actor after the writer changed; the native database and API checks verify the resulting behavior.

Source `087a6522` passed all eleven jobs in [CI 43462583153](https://github.com/coreflux-creator/CoreFlux/actions/runs/43462583153): **27 stored-assignment directory checks**, **20 protected-transaction checks**, **17 stored contract-review checks**, **193 independent-connection checks**, **953 native Core session/API checks**, and **429 native placement contract HTTP checks**, along with the existing 32 source-rule directory and 40 full-projection checks, installation, packaging and guarded upgrade/recovery. Native synthetic actors were retired, private session files removed and owned HTTP servers stopped. [Pinned source and result evidence](../deploy/thunderhawk-stored-assignment-directories-preparation-20261113.json).

This candidate is **not installed**. Read-only verification at **2026-11-13T16:25:30+00:00** confirmed installed runtime `4bc10bb0`, 367 pinned files and all 35,304 rows across 378 tables unchanged, preserved configuration and removed temporary access/probes. Source verification is not proof of hosted acceptance.

## Next release gates

The CSV fallback still builds its plan before its write transaction, conflates the source workspace with the placement owner, and canonicalizes retained external numbers. It also resolves people through parent-workspace mappings/email and accepts malformed ownership as empty. These need the same original-workspace, exact-identity and transaction protections; a source-owned company cannot be adopted solely by name.

Legacy alignment/report/repair writers, native job ownership and lifecycle provenance, AP/current-reference writers, survivor/history review and stable outbound QuickBooks customer identity remain open. Complete these paths, then install the pinned candidate and exercise the hosted native workflow in the authorized pilot. The wider field, portal, onboarding, document, time/approval, payroll-result and financial reconciliation requirements remain in the integration acceptance checklist; the full goal remains active and incomplete.
