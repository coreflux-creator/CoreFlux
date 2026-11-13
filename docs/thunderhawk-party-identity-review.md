# Reviewed JobDiva company and contact identities

The isolated Core pilot now lets an authorized operator compare an exact JobDiva company/contact with an existing Core record, confirm the identity, and recover the saved result after a lost response. The control appears on client/vendor details and their contacts. It requires both People write and Integrations admin access. This release does not add a source writeback or financial execution action.

Installed runtime `86bf59ea8d7d0956a4486fe53e0219e0bfdb7222` passed all eleven jobs in [CI 43364967814](https://github.com/coreflux-creator/CoreFlux/actions/runs/43364967814). The [release manifest](../deploy/thunderhawk-party-identity-20261113.json) records the installed file hashes, private rollback point, actual acceptance results and remaining work.

## Identity and ownership behavior

- A matching company name alone no longer establishes an identity in the assignment party resolver. Existing companies require an explicit link; source creation requires an exact provider identity. Legacy resolution paths and company merges still need separate work.
- The review preserves all existing Core business values, permanent numbers, unrelated provider links and existing exact mapping payloads. Newly adopted records hold their native fields while retaining source proposals for a separate field review. Omitted phone/email fields are not proposals to clear a value; explicit blanks remain distinct.
- Contacts require their exact source parent to be linked to the current Core parent. This action cannot move a contact to another company. Shared and isolated company directories use their current policy, while the source identity remains in the originating workspace.
- Tokens bind the reviewer, workspace, installation, connection, directory policy and compared records. Expiry, changes during provider reads, competing links, another source authority and intervening native edits are rejected. Required audit failure rolls back the link and ownership writes.
- A repeated operation returns its original receipt without provider access or another write. It cannot overwrite later edits or resurrect a deliberately removed link. The screen labels that result as a saved review rather than making a fresh claim about current connectivity.
- Same-record refresh preserves the displayed confirmation. Navigation cannot expose controls for a stale company response.

## Verification

The native database fixture passed **39 checks**, including exact identity conflicts, source/target changes, connection changes, actor/workspace isolation, shared-directory changes, receipt failure rollback, contact parent integrity, preserved other-provider mappings, omitted fields and replay after link removal. The native login/API suite passed **587 checks**; its new identity-route cases cover anonymous refusal, both required permissions, immediate downgrade, method/input validation and required connection. It does not substitute for a real-source identity round trip.

Ten targeted rendered-component tests and the production frontend build passed. The complete CI also exercised the current company-role schema, other integration contracts, standalone accounting packaging and guarded upgrade/recovery behavior.

The pilot installation replaced twelve files after verifying 351 other pinned dependencies. Its web-process check passed. Installed-library acceptance then passed **13 checks** using eleven connection-private temporary tables built from the actual hosted schemas. The first fixture attempt failed during temporary-table creation; the separately identified v2 fixture corrected that setup and completed. All temporary tables were removed; no permanent fixture record or provider call was created.

Independent verification after the fixture and browser checks found **363 runtime hashes correct and all 35,304 rows across 378 tables unchanged**. The pilot configuration, retired test accounts and absence of temporary credentials/probes were also verified. A fresh BrowserOS tab received both exact new JavaScript bundles, and preview/apply/status each returned 401 while signed out. That tab was closed. The signed-in hosted identity-review walkthrough remains unverified.

## Remaining integration work

The full goal remains active. This closes an explicit-linking gap, not company merge/alias handling or all source writers. Remaining work includes placement-chain/referral role ownership, legacy resolution paths, source-aware company merges, wider source cohorts and receiving contracts, remaining field dispositions, portal/lifecycle/document acceptance, normal AWS browser uploads and the unresolved time/payroll/financial provenance and reconciliation requirements. The latest metadata inventory remains 2,127 fields with 245 undecided dispositions; this release adds no schema fields. Production Core, provider systems and the separate Arabella accounting pilot were unchanged.
