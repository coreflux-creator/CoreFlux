# Client source identities and field review

The incoming JobDiva and QuickBooks customer paths now use the original connected workspace and resolve the Placements and Companies directory owners separately. Exact provider bindings retain their client and company identities when names change. A missing, foreign or conflicting company does not fall back to name matching. A new source with an existing namesake requires explicit identity review; several retained clients for one company require an exact client binding.

JobDiva's company import and assignment import share this contract. A source-created company with a client role creates a source-owned client with the exact JobDiva company ID. Failure in that bridge rolls back the enclosing company import. QuickBooks uses its existing customer binding and creates a source-owned company/client only when there is no unresolved namesake. The vendor adapter is unchanged by this work.

## Ownership and operator behavior

Migration `200_client_source_ownership.sql` adds client field ownership and observations. Legacy clients start protected by Core. New source clients can receive source-controlled updates; actual native edits claim the changed fields. Client billing terms and agreement dates remain separate from company-wide terms and agreements.

Company ownership also applies to shared profile fields. A client projection cannot overwrite a company field that Core controls. Omitted source values preserve existing values; an explicit blank can clear an unowned contact field. A null QuickBooks status is absent evidence, not a deactivation. An active billing placement holds an incoming client deactivation for review.

A second provider or workspace retains separate proposals without gaining authority over the first source's profile. Client review exposes only observations from the active workspace. A reviewed company merge preserves the client number, follows the surviving company and protects the retained client from its old source. Provider bindings remain attached to their original records.

The Clients screen has a separate **Review incoming values** panel. An editor selects a source, selects fields and confirms the observed values. Applying them once keeps those fields controlled by Core on both client and company. Later refreshes from either provider cannot reverse that choice. Refreshing or changing the selected provider clears the selection and confirmation. A stale or failed save leaves the selected values visible.

Company-level source review remains a distinct ownership choice: its confirmation explicitly releases selected company fields to continued updates from the named provider. It now identifies QuickBooks correctly and checks the active workspace's current company directory. Sharing the directory does not expose another workspace's source IDs or authorize release of that workspace's field authority.

Client source review, company/client changes, source binding and required audit commit together. A lost acceptance response recovers its original receipt without repeating the edit. A changed field selection cannot reuse the receipt. Current locking reads prevent an older repeatable-read transaction snapshot from missing a committed receipt or accepting a source proposal that changed after review.

## Verification and release status

Source `f695e935` passed all eleven jobs in [CI 43391281128](https://github.com/coreflux-creator/CoreFlux/actions/runs/43391281128): **80 client-source database checks**, **83 independent-connection checks**, **814 native session/API checks**, the existing 44 merge and 34 alias checks, 23 billing-role checks, empty installation, packaging and guarded upgrade/recovery. Eight targeted rendered tests passed locally. [Structured evidence](../deploy/thunderhawk-client-source-preparation-20261113.json) retains the exact source fingerprints and verified fixture cleanup. The native database suites cover independent directory owners, exact replay, Core clearing and closure, canonical company edits, source rename, secondary-source acceptance, omitted versus blank values, active-placement lifecycle holds, source privacy, namesake refusal, migration replay and whole-graph audit rollback. Separate processes and native InnoDB lock metadata exercise source writes before and after merges/native edits, both commit and rollback, plus receipt and stale-review recovery from older transaction snapshots.

The authenticated HTTP fixture uses real login/session/permission checks for source review and acceptance, validates required-audit rollback and receipt recovery, and verifies that ordinary client details exclude private source state. Rendered tests cover selected values, confirmations, provider labels, refresh, stale rejection and retained client edits.

The first native run exposed the older company-to-client path creating a manually owned client before its exact source binding; that path was corrected rather than changing the identity requirement. Older source-text checks were updated to follow the shared writer. A separate billing-role fixture lacked the new ownership column and required audit/history tables; it now applies their canonical migrations. A later GraphQL smoke failure exposed two test servers potentially receiving the same free port; the fixture now reserves distinct ports together.

This is **source preparation, not an installed release**. The pilot still runs `4bc10bb0`. Read-only verification at `2026-11-13T08:16:39+00:00` checked 367 runtime fingerprints, all 35,304 rows across 378 tables and unchanged configuration. Temporary reviewer access, credentials and probes remain removed. No provider mutation, production deployment, Arabella change or real financial execution occurred.

## Remaining work

- Replace or constrain the old repair CLI's direct status/role updates and minimum-ID client relinking; those are not proof of ownership-controlled lifecycle repair. The unused direct catalog-update helper also needs retirement or controlled delegation.
- Hold fresh directory policies, current source bindings and native placement overrides throughout mapping repair and every remaining placement/AP/current-reference write. The existing mapping repair joins mappings and placements through the same raw tenant and reads placement overrides before its final write; the new client service alone does not fix that caller.
- Complete native job source ownership and the shared-source placement contract. Demonstrate operator resolution for unresolved QuickBooks namesakes and multiple retained clients.
- Audit financial consumers against the retained IDs. `qboResolveCustomerRef()` still resolves an invoice's saved client name to a current client before finding the provider mapping; inbound identity protection alone does not establish correct outbound invoice routing after a rename or merge. No outbound invoice or financial posting was attempted here.
- Complete company-survivor source review, retired-number/history navigation, guarded schema/runtime installation and the signed-in hosted merge/client/source walkthrough.
- Continue the full field dictionary, wider source cohorts, portal/lifecycle/onboarding/document workflows, AWS browser upload, original approval/correction provenance, payroll results and financial allocation/reconciliation acceptance. These requirements are unchanged by the client work. The complete integration goal remains active and unproven.
