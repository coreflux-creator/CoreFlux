# JobDiva assignment source and lifecycle repair

Scope: source preparation for the authorized isolated Thunderhawk pilot. Hosted installation and the complete legacy repair release remain open. This work makes no provider writes, production or Arabella changes, payroll execution, payments or financial postings.

## Source verification

The assignment-source repair now selects provider links in the original workspace and resolves the current shared business directories. Before a provider lookup, it captures the exact mapping, placement, worker lifecycle, assignment mirror, connection metadata and directory policies in a rollback-only protected transaction. The remote read runs with no database transaction held. Apply locks and compares that evidence again; a concurrent change requires a fresh review.

Saved provider pairs remain physically unchanged, including legacy `jd:` prefixes and retained Core display numbers. Another workspace's mapping, competing identities and mismatched returned Start/candidate/job context cannot authorize a write. Exact financial detail is retained separately from the newly verified Start; cached job/person enrichment cannot replace assignment identity. The separate source mirror is not rewritten by this repair.

An empty or failed lookup is never deletion evidence. Prior source evidence may be used only when it remains eligible and was observed in the latest completed sync; using that evidence does not manufacture a new observation timestamp. A newly returned, exact terminal state supersedes the older active observation. A returned identity/context conflict stays in review.

Archived restoration requires a current live lifecycle matching the retained placement dates/status, an active existing worker, no actual end date, and no Core-held lifecycle field. A status/date transition goes through the existing complete stored-assignment review. Exact terminal archival checks current ownership and dependent time, document, pipeline and financial records through the accessible placement, including references held by another module owner. Source quarantine remains visible when a business row cannot be archived.

Every changed source result, history row and lifecycle mutation shares a required audit in the same protected transaction. The receipt records the original workspace, current directory owner, actor, retained provider pair and before/after evidence. A fresh identical verification makes no duplicate history or audit; an old captured review cannot repeat a committed change. Each source link is an independent repair transaction, and the batch reports failures explicitly.

## Placement lifecycle repair

The existing stale-placement repair action now selects exact original-workspace source links rather than inferring authority from titles or display numbers. Preview and apply resolve current scope and ownership under the protected transaction. Core-held status, start/end dates, actual end date or archive state prevent an automatic transition. Missing, ambiguous or quarantined source evidence is retained for review.

The repair changes only an active placement's supported lifecycle transition, retaining its current dates and all commercial/profile fields. Its status, derived commercial graph and required audit commit together. Failed graph reconciliation or audit rolls back the whole record. The native endpoint retains its integration-administrator permission; read-only, anonymous, out-of-scope and revoked access are covered by HTTP acceptance. Repeated repairs converge without another transition or audit.

## Verification

Canonical disposable database checks cover shared directory identity, preserved prefixed provider pairs, unchanged display numbers and approved rates, stale policies/source/worker/ownership, returned identity conflicts, required-audit rollback, archived restoration constraints, explicit terminal evidence, downstream references across directories, unchanged financial tables and replay. Independent database sessions exercise concurrent policy, mapping, ownership and worker changes, plus competing source/lifecycle repairs with both commit and rollback outcomes. Native authenticated requests exercise lifecycle preview/apply, permissions, ownership changes, changed sharing, rollback, actor attribution and replay.

Source `3ca8a7a1` passed all eleven jobs in [CI 43470034776](https://github.com/coreflux-creator/CoreFlux/actions/runs/43470034776): **46 source/lifecycle repair checks**, **263 independent-connection checks**, **953 native Core session/API checks** and **452 native placement contract HTTP checks**, plus the existing 38 CSV, 27 stored-assignment, 20 transaction, 17 contract-review, 32 source-rule and 40 full-projection checks, installation, packaging and guarded upgrade/recovery. Local identity smoke passed 42 checks, alignment smoke 72 and the existing People/placement lifecycle smoke 31. The synthetic native actors were retired, private session files removed and owned servers stopped. [Pinned source and verified results](../deploy/thunderhawk-assignment-repair-preparation-20261113.json).

This candidate is **not installed**. Read-only verification at **2026-11-13T17:04:27+00:00** confirmed installed pilot `4bc10bb0`, all 367 pinned files and 35,304 existing rows across 378 tables unchanged, preserved configuration and removed temporary access/probes. Source tests do not establish hosted acceptance.

## Remaining release gates

The full older repair workflow is not yet ready to install. Its alignment reports still contain single-tenant assumptions. Source-People retirement, draft-rate repair, joined-index refresh and duplicate consolidation require the same exact identity/current-directory/required-audit treatment. Duplicate consolidation still deletes or rewrites provider links; it needs reviewed survivor handling with preserved provider pairs and permanent number aliases. The separate active-status maintenance script also remains to be aligned.

The People field contract distinguishes directory availability from placement lifecycle. The old retirement query treats `source=jobdiva` or a `jd:` display number plus the absence of a live placement as authority to inactivate an active/bench person. That is insufficient evidence for the next implementation: preserve a deliberate Core bench/availability choice, require exact original-workspace candidate identity and an explicit reviewed lifecycle decision, and retain the person and employment/portal history.

The generic placement CSV importer, native job ownership and lifecycle provenance, AP/current-reference writers, survivor/history review, stable outbound QuickBooks customer identity, guarded pilot installation and hosted acceptance remain open. The broader field inventory, portal/onboarding/documents, time/approval, payroll-result and financial reconciliation requirements remain in the full integration checklist. The goal remains active and incomplete.
