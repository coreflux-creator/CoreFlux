# JobDiva assignment projection across Core directories

This is source preparation for the authorized isolated Thunderhawk pilot. It does not install a release, change provider runtimes, affect Arabella, or execute payroll, payments or financial postings.

## Implemented behavior

The ordinary assignment projector now runs identity resolution, person/company/client/job projection, placement and child writes, source bindings, configured fields and postconditions in one transaction or savepoint. A failed required stage returns an explicitly rolled-back result with no successful record IDs or mapping count. Projection audit evidence must save with the business changes.

The original JobDiva workspace remains the owner of provider links, source configuration and source history. Fresh locked module policies select the directories that own People, Placements, Companies, Staffing and Accounting records. Aligned shared People/Placements and isolated directories are supported; inconsistent directory combinations require review before mutation. Rates, chains, commissions, referrals, informational participants, custom values and workflow checks follow the placement owner while source lookups retain the original workspace.

Existing placement identity and field ownership are read before related-record side effects. Exact stored mappings and raw/prefixed Start IDs identify a placement without rewriting its retained external ID or permanent number. Conflicting mappings and multiple claims fail for review. A spreadsheet similarity match is evidence for review, not permission to adopt a placement. Core-owned person, client, work recipient and job choices prevent source helpers from creating or binding replacement identities. An explicitly cleared work recipient remains a held choice.

Person intake uses the common People/Placements owner and preserves retired people for reviewed reactivation. A shared directory cannot silently borrow a person or job already linked to another JobDiva workspace. Configured custom values recheck the current directory policies, including after a sharing change in the same process.

Intake and adjacent identity binding now retain the same candidate snapshot. This prevents unchanged imports from alternating between a full assignment snapshot and a candidate snapshot in person history. Client source observations add an audit record only when their business values or observed source state change.

## Verification and corrections

The disposable canonical-schema fixture exercises shared and isolated imports, independent Staffing ownership, original-workspace mappings, configured/custom field routing, stable-number replay, Core-held related identities, retained legacy IDs, retired-worker refusal and changed-policy refusal. Forced late mapping, required-audit and participant-cleanup failures must roll back the complete graph and its source history. Financial tables are compared before and after.

Initial database checks found two real replay defects: alternating candidate snapshots and unconditional no-change client observation audits. Both were corrected. The broader suite also exposed obsolete source-text assertions and a custom-only mapping test that unnecessarily ran economic reconciliation. Custom-only changes now skip economic reconciliation; financial-target failures still fail the projection. Source classification, rate units and country preflight remain ahead of identity mutations.

Source `7c7e0f94` passed all eleven jobs in [CI 43449383568](https://github.com/coreflux-creator/CoreFlux/actions/runs/43449383568), including all **34 new projection database checks**, the existing native session/API and independent-connection suites, installation, packaging and guarded upgrade/recovery. [Pinned evidence](../deploy/thunderhawk-jobdiva-projection-preparation-20261113.json). This is source verification, not hosted release acceptance. Installed pilot runtime remains `4bc10bb0`; read-only verification at `2026-11-13T14:51:27+00:00` confirmed 367 file hashes, 378 tables and 35,304 rows unchanged, with configuration preserved and temporary access/probes absent.

## Remaining work

- Add concurrent source-policy/merge coverage before declaring the complete source lock contract verified. Existing native concurrency coverage does not establish this complete source path.
- Audit cached company-directory reads inside derived economics and the remaining standalone field-map/ownership-handback callers. The complete writer audit is not satisfied by the ordinary projector alone.
- Complete placement CSV, legacy reconciliation and alignment/report/repair scope contracts. Add reviewed placement identity linking rather than returning to heuristic adoption.
- Complete native job profile ownership and source lifecycle provenance; preserve explicit Core choices while giving source-owned changes their reviewed path.
- Finish AP/current-reference writers, survivor-side source review, historical-number navigation and stable QuickBooks customer routing.
- Perform the guarded pilot installation and signed-in hosted acceptance after the remaining release gates pass. The wider field, onboarding, document, time/approval, payroll-result and financial reconciliation requirements remain in the full Thunderhawk acceptance checkpoint.

The full integration goal remains active and incomplete.
