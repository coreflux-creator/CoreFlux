# Reviewed JobDiva CSV reconciliation

Scope: source preparation for the authorized isolated Thunderhawk pilot. This change does not install a hosted release, contact a provider, change production or Arabella, or execute real financial operations.

## Behavior

CSV preview and apply now resolve current People, Placements, Companies, Staffing and Accounting directories inside the same protected transaction used by stored assignment reconciliation. Apply recomputes the complete preview and compares its token before changing any row. Original-workspace provider links remain separate from shared business records. Changes to source mappings, worker lifecycle, company identity, ownership, rates or directory policy invalidate a pending review.

Saved JobDiva placement and person pairs remain physically unchanged, including legacy `jd:` prefixes. Existing display numbers are retained. A new assignment receives its own stable Core number, links an existing exact candidate and uses the current accounting setup. Names and email addresses do not establish person identity; a child's import cannot borrow a parent's provider mapping. A conflicting assignment/candidate/job identity requires explicit review.

The CSV mapping screen now accepts a JobDiva End Client Company ID. Exact existing company mappings follow reviewed company aliases without rewriting those provider pairs. An unlinked source company can be created only with its explicit source ID and name; a Core namesake requires identity review. A name-only row can retain an unchanged, already linked company but cannot silently replace it or create a new identity. Core-owned end-client choices remain authoritative.

The planner retains canonical Core ownership, including commercial participant overrides and protected rate fields. Approved rates remain unchanged; source corrections create or update only a draft. Core-owned dates are used when normalizing assignment status. Linked corporate tax/document presence prevents an unreviewed worker-type change. Malformed ownership blocks the affected row instead of being treated as empty ownership.

Sparse CSV evidence supplements the saved provider snapshot rather than discarding its contract facets. All selected changes, numbers, mapping updates, draft rates, derived commercial participants and required audit records commit together. An audit failure on a later row rolls back earlier rows too. Audit attribution retains the acting workspace, authenticated operator, shared directory owner and before/after graph. An identical fresh preview converges to unchanged records; an old token cannot repeat a committed change.

Invalid text encoding is rejected before preview. Review serialization throws on invalid evidence rather than allowing different reviews to collapse to the same token.

## Verification

The canonical database fixture exercises shared-directory identity, prefixed provider pairs, retained display numbers, original-workspace isolation, namesake refusal, sparse updates, explicit rate basis, participant ownership, approved-rate history, stale policies/source/candidate/company/rate evidence, malformed ownership, corporate private-record protection, Core-owned lifecycle dates, late-audit batch rollback, creation and replay. The earlier reduced-schema rate-basis CSV checks now run in that complete fixture so they exercise the protected application path.

Independent native database sessions test concurrent directory changes, provider metadata updates, company merges and duplicate applies, each with commit and rollback outcomes. Authenticated native requests exercise the actual CSV preview/apply route, read-only denial, parent/child source isolation, changed sharing, audit failure rollback, preserved IDs and history, source-workspace audit attribution and replay. Fixtures check unchanged accounting, payroll, payment and time data and clean up their synthetic access.

Source `0f802858` passed all eleven jobs in [CI 43466088592](https://github.com/coreflux-creator/CoreFlux/actions/runs/43466088592): **38 CSV assignment directory checks**, **215 independent-connection checks**, **953 native Core session/API checks** and **440 native placement contract HTTP checks**, plus the existing 27 stored-assignment, 20 transaction, 17 contract-review, 32 source-rule and 40 full-projection checks, installation, packaging and guarded upgrade/recovery. The targeted local CSV smoke suite passed 32 checks, including invalid-encoding and non-serializable-fingerprint refusal. Synthetic HTTP actors were retired, private session files removed and owned servers stopped. [Pinned source and results](../deploy/thunderhawk-csv-assignment-preparation-20261113.json).

This candidate is **not installed**. Read-only verification at **2026-11-13T16:42:37+00:00** confirmed installed runtime `4bc10bb0`, all 367 pinned files and 35,304 rows across 378 tables unchanged, preserved configuration and removed temporary access/probes. Source checks do not establish hosted acceptance.

## Remaining integration work

The older alignment/report/repair workflow still directly updates lifecycle and source records using a single tenant ID; some audit failures are swallowed and automatic duplicate consolidation can rehome provider mappings. Its report, source normalization, draft-rate repair, lifecycle repair, duplicate review and maintenance-script entry points need the same current-directory, ownership, exact-identity and required-audit treatment. The generic placement CSV importer is a separate path and remains part of the current-reference audit.

Native job ownership/lifecycle provenance, AP/current-reference writers, survivor/history review, stable outbound QuickBooks customer identity and guarded hosted installation/acceptance remain open. The wider field, portal, onboarding, document, time/approval, payroll-result and financial reconciliation requirements remain in the integration checklist. The full goal remains active and incomplete.
