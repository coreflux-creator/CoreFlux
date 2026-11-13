# Native placement references and ownership

## Result

Ordinary placement creation now saves its selected worker, billing client, work-recipient company, staffing job, legal entity, economic graph and required audit within one transaction. Inline person creation uses that same transaction. Failure to save the commercial graph or audit rolls back newly created people, companies, permanent numbers and placement data together.

Native overview, bulk, status/promotion and approval-contact edits use fresh directory policies before locking the placement. Companies, Placements, Staffing and Accounting retain their independently configured owners. A shared placement cannot acquire a job, company or accounting entity private to one viewing workspace. Existing person references retain the application's current placement-directory contract; this change does not introduce an independently shared People-to-Placement relationship.

New references must identify current records. A new billing choice must be active and resolve to a current company. A selected worker must be present and not retired; the legal entity must be active. Fractional and boolean record IDs are rejected. A merged company number, or a name that belongs to a retired/merged company, cannot silently recreate or restore that company. A retained client number follows its reviewed company relationship.

Native creation and edits establish Core ownership even before a provider is attached. Mapping-table links protect JobDiva placement identity changes even when the retained external number lacks the older `jd:` prefix, including when another source workspace owns the link to a shared placement. The native response exposes only the link's presence; it does not disclose another workspace's provider identifiers. The overview and editor use that presence to show ownership badges and reviewed handback controls. Existing prefix-based compatibility remains.

Placement detail, list/search and audit views resolve job names from the Staffing directory. Audit views resolve company names from the Companies directory. Identical native edits reuse the current result without duplicate mutation or audit.

## Verification

The new native HTTP fixture exercises real login, session, permissions and placement endpoints across shared/shared, shared/isolated and isolated/isolated Companies/Placements configurations. It covers creation with existing and inline workers, independent job reads/search, permanent numbers, inactive and malformed reference refusal, mapped legacy identities, exact retry, required-audit rollback, company merge/stale forms, policy changes and absence of payroll/payment/journal writes. Its cleanup removes its synthetic business records and access.

Independent MariaDB connections cover policy commit/rollback, merge-first and native-write-first ordering, changed merge reviews and old repeatable-read snapshots. Existing native edit tests verify ownership, actual later JobDiva refresh, explicit paired handback, failed activation and audit rollback. Four additional rendered cases cover mapped legacy numbers, boolean link indicators, older prefixes and unlinked native placements; all 19 tests in that component suite passed locally. Hook lint and the rebuilt interface passed.

The initial verification identified two obsolete source assertions and a fixture that sorted the permanent-number table by a nonexistent single-column ID. They were corrected to match the shared transaction service and the actual composite key. These were disposable-fixture corrections; no live business records were used to bypass a failure.

Source `27c474e5` passed all eleven jobs in [CI 43442455634](https://github.com/coreflux-creator/CoreFlux/actions/runs/43442455634): **953 native login/session/API checks**, **118 independent-connection checks**, **46 native placement edit checks**, the existing **23 billing-role checks**, standalone installation/packaging, actual writer observation, maintenance, guarded upgrade and paired recovery. The native HTTP fixture retired its synthetic actor, stopped its server and removed its private session files. [Pinned source and verification evidence](../deploy/thunderhawk-placement-native-references-preparation-20261113.json).

Read-only verification at **2026-11-13T14:29:51+00:00** confirmed the installed pilot still matches runtime `4bc10bb0`: 367 pinned files, 378 tables and 35,304 rows unchanged, with configuration preserved and temporary reviewer access/credentials/probes absent. This candidate is **not installed**.

## Remaining release requirements

The full integration remains incomplete. This correction does not replace the ordinary JobDiva assignment projector, CSV importer or legacy reconciliation operation.

- The ordinary source projector resolves people and work-recipient companies before it reads existing placement ownership. The placement writer can canonicalize an existing external number and resolve a billing company/client before its later ownership read. Refactor the complete transaction around the original source workspace and independently resolved business owners, including rates, participants, field maps, source bindings and postconditions. Validate held choices before related-record side effects and preserve original provider pairs.
- Placement CSV and legacy reconciliation still need that same ownership/reference contract. Review lifecycle and ownership-release paths for fresh policies as well; their row locks alone do not establish the full scope contract.
- Alignment report/repair queries, AP/current-reference writers, native job source ownership, survivor-side source review and historical-number navigation remain open. Outbound QuickBooks invoice routing still needs stable customer identity instead of a saved client name.
- Guarded pilot installation and signed-in hosted acceptance remain required for the pending company-merge/client/placement release. Wider field alignment, portal/onboarding/documents, time/approval corrections, payroll results and financial reconciliation remain governed by the full acceptance checkpoint.

Production Core, the separate Arabella accounting pilot, provider runtimes and real payroll/payment/posting execution were unchanged by this source work.
