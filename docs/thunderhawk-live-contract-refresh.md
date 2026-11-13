# JobDiva live contract refresh

The normal `assignment_contracts_batch` action now resolves mapped placements from their original source workspace into the current shared business directory. The browser still drains one mapping per request with the existing cursor and completion counters.

The service captures the current assignment link, worker identity, directory policies, field mappings, rate rows, commercial graph and related source evidence. It releases its transaction before querying JobDiva for the exact Start and financial detail. Before saving, it locks and compares that evidence again. A native edit, approved-rate change, source-link change or directory-policy change during the lookup rejects the stale result. A disabled placement import policy prevents the lookup itself.

Financial records must match the exact Start and candidate/job context. The normalized contract is rebuilt from those records and the exact Start, excluding related job/worker facets, then checked against the returned result. Missing exact bill or pay rates remain incomplete; related job prices and stale assignment aliases cannot fill them. An unverified lookup follows the existing source-verification review rules and cannot authorize financial projection. Saved roots are no longer marked verified merely because they have a mapping.

Corporate payee lookup is read-only. A new company and vendor are created only inside the protected canonical placement update. Company creation, rates, placement fields, retained mappings, mapping history and the required actor-attributed receipt commit together. A late receipt failure rolls them all back. Source-owned worker selections can be reconciled; Core-owned worker selections, native rate ownership, approved history, permanent person/placement numbers and other provider links remain protected. Archived placements restore only when explicit current contract evidence, worker availability and native lifecycle ownership permit it.

The native API uses the ordinary Core session, tenant selection and both permission layers. Its provider transport is synthetic in disposable tests; no provider is contacted. The transport override is server-installed test code, never a request parameter or alternate API URL. Test provider credentials use an ephemeral encryption key and are removed during cleanup.

## Acceptance

Final source `93fd151b3aa83cb2c4391b681cf767d209f4a6a9` passed all eleven jobs in [CI 43488642153](https://github.com/coreflux-creator/CoreFlux/actions/runs/43488642153): 35 live-contract database checks, 26 joined-source checks, 41 source-rate checks, 349 independent-connection checks, 953 native Core session/API checks and 516 placement contract HTTP checks. This includes the final pricing corrections. [Pinned source and observed results](../deploy/thunderhawk-live-contract-preparation-20261113.json).

Database checks cover source mismatch, stale evidence, native worker selection, approved rates, corporate-company creation, required audit rollback, replay and archived placement handling. Independent connections exercise committed and rolled-back competing policy, source, native placement and rate edits, plus competing saves. Signed-in HTTP checks cover permission denials, shared-owner source refresh, atomic rollback, retained IDs, cursor completion, replay, changes committed during lookup, unverified Starts and disabled source policy.

Initial HTTP testing found a missing encryption key in the disposable fixture before sync could run; the fixture now supplies a random temporary key to its own child server. A later worker-selection fixture omitted the required synthetic email; that fixture was completed. Neither correction weakens runtime validation or permission checks.

## Deployment and remaining work

This candidate is not installed. The isolated Core pilot remains on `4bc10bb0`; its last recorded read-only verification is separate from this source acceptance. Provider systems, production, Arabella's accounting pilot, real payroll, payments and accounting postings are unchanged.

Current-owner alignment reporting was subsequently completed and accepted in source `568cab29`; [its evidence and limits are recorded separately](thunderhawk-alignment-report.md). Reviewed duplicate consolidation with preserved provider pairs/permanent aliases, active-status maintenance, generic placement CSV, native job ownership/lifecycle, AP/current references, survivor/history review and stable outbound QuickBooks customer identity remain open. Guarded pilot installation and hosted acceptance are required. The wider fields, portal, onboarding, documents, original-time approvals/corrections, payroll results and reconciliation objective remains active.
