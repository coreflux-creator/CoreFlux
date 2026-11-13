# Client profile and CSV identity alignment

Native client create, edit, bulk edit, closure and CSV import now use one atomic save service. The service resolves the current Placements and Companies directory owners separately and holds those policies and referenced records until the company, client and required audit have committed. A shared client cannot acquire a company private to only one workspace. Existing CL and CO numbers and existing source IDs stay attached to their original records.

## Operator behavior

- Client detail and list responses include an edit revision covering the client, company and sharing policy. Edits, closure and each selected bulk row require that revision. A concurrent company edit, source observation, merge or sharing change rejects an old form; the form retains the entered values for review.
- Native shared profile changes become Core-owned company fields. Explicit contact clearing is retained. Full forms and CSV exports often repeat unchanged client values: those copies do not overwrite a company's newer profile or the reviewed surviving company's name after a merge.
- Client payment terms and MSA fields remain client-specific. Changing client billing terms does not alter company-wide vendor terms or attest to a company-wide agreement. Payment terms require a whole number from 0 to 365; malformed IDs and invalid value types are rejected instead of coerced.
- An active placement linked through its billing `client_id` prevents closing or inactivating that client. The check does not depend on the placement's separate end-client company.
- A failed required audit rolls back company values, company field ownership and the client together.

## CSV behavior

The import resolves an explicit client number first, then an existing source-system/external-ID pair. Conflicting identities are rejected. A new source ID cannot silently adopt an existing namesake client; an explicitly supplied client number can add a first source pair, but cannot replace a retained one. Omitting source columns preserves the existing pair. These legacy source columns do not create a provider connection, change an integration mapping or grant another system write authority.

An existing native client needs the update-existing option. An exact source-keyed feed can be replayed without creating a duplicate client. Replay preserves record identity; it is not a claim that CSV imports have a durable, exactly-once operation receipt. The existing source pair remains intact if a later CSV omits it.

Preview and commit use the same business resolver. Commit resolves and locks current records again. By default any failed row rolls back the whole batch, including companies created by earlier rows. The explicit skip-invalid option commits only independently successful rows. The import audit participates in the same transaction as its client/company writes.

## Verification

The native HTTP fixture exercises real authentication, permissions and endpoints with three independent directory configurations: Companies/Placements shared/shared, shared/isolated and isolated/isolated. It also rejects the shared-client/private-company combination. It covers number display, stale forms, exact source identity replay, audit rollback, active-placement closure, batch failure and skip-invalid behavior. A fresh CSV replay after merging retains the original CL and uses the survivor without rewriting its profile.

The separate-process MariaDB fixture observes real lock waits and tests both commit and rollback. Native stale forms are rejected after a merge. CSV resolves the retained client after a concurrent merge commits. A client edit or import that commits invalidates a waiting stale merge review; a rolled-back edit permits the reviewed merge.

Two rendered client tests cover retaining unsaved form values on rejection and sending per-row revisions for bulk editing and closure. Source checks cover route permission/delegation and CSV schema wiring. Database and HTTP evidence, rather than those source-text checks, establish save behavior.

The first native run stopped because a synthetic worker fixture lacked its required email. After correcting the fixture, candidate `8a2e819d` passed the live business graph suite, including 783 native session/API checks, 39 independent-connection checks, 44 merge checks and 34 alias checks. Four older source checks still expected inline CSV writes; they were updated to follow the shared service.

The final candidate `b5d5587d7a9befa8c79361c1f3afc0f98aa3bb53` passed all eleven jobs in [CI 43385160350](https://github.com/coreflux-creator/CoreFlux/actions/runs/43385160350), including **789 native session/API checks and 49 independent-connection checks**, the existing merge/alias suites, empty installation, packaging and guarded upgrade/recovery. [Preparation evidence](../deploy/thunderhawk-client-native-preparation-20261113.json) records exact source fingerprints and verified fixture cleanup. Local checks also passed: five tenant-leak checks, 29 client/economics checks, relevant route/CSV source checks, PHP syntax and source whitespace checks.

Read-only verification at `2026-11-13T07:18:42Z` confirmed the installed pilot's 367 runtime fingerprints, all 35,304 existing rows across 378 tables and configuration unchanged. Temporary reviewer access, credentials and probes remain removed. No hosted installation or provider exchange occurred in this continuation.

## Remaining integration gates

This is a source preparation, not a pilot installation. The generic source bridge `staffingClientEnsureForCompany` still needs correction: it assumes the client owner also owns Companies, falls back from an invalid company reference to name matching, can select a different client by company/name, and can overwrite client state. Its `sync_company_patch` path bypasses company field ownership. The JobDiva assignment/projector and mapping-repair callers must retain the source workspace while using independently owned directories and preserve explicit native decisions.

QuickBooks inbound customers additionally discard an existing mapping when names differ and then fall back to name matching; their existing source IDs and reviewed Core values must be preserved. Incoming observations must use the applicable source-ownership/review contract, rather than calling the native CSV editor or marking source values as Core decisions. Native/CSV success does not establish source-refresh convergence.

The remaining placement/AP/current-reference writers, source proposal review and apply-once behavior, retired-number/history navigation, complete hosted merge walkthrough, wider field/portal/onboarding/document/time/payroll-result/financial reconciliation acceptance and final requirement audit remain open. Production, Arabella and real financial execution are outside this source change.
