# Quanta staff correction and submission acceptance

On November 12, 2026, Quanta revision `abd1d8277bb8566ad95f539cf1334241cda34d8a` was installed in the isolated Thunderhawk integration pilot. Staff can now open **Review week** from time entries or approvals oversight, inspect the worker and Core person/placement numbers, edit an unlocked duration entry, and explicitly submit the reviewed week. Approved and submitted weeks remain locked. A displayed-version check refuses stale edits; ambiguous save outcomes require a reload instead of an automatic retry. Assignment, source and rate provenance remain protected.

## Validation and installation

- [CI 43255407085](https://github.com/coreflux-creator/Quanta/actions/runs/43255407085) passed both the worker-portal build/test job and native MongoDB application job for the exact installed revision. The native test runner started the full application against real MongoDB and recorded zero external network attempts.
- Local checks passed 98 frontend tests across ten suites, including twelve new worker-week cases, and 353 application checks with ten skips. The local application run used the fake database; it is distinct from the native CI evidence. The frontend build passed with existing hook warnings outside the new page.
- The guarded installation changed four files and verified 182 backend and 82 frontend file fingerprints. It preserved all 1,788 existing business records, including fourteen time entries. The coordinator revision advanced once at startup. Other services and settings were unchanged.
- The current database backup restored all 71 collections and their indexes into an isolated, unserved verification database. The verification database was removed afterward. No full-application schema fixture ran on the shared pilot host. A hosted model probe checked the displayed-version contract without database writes.

The first backup attempt deliberately stopped when graceful application shutdown persisted 237 already queued API-usage events. It did not dump or restore a database or install code. Inspection proved all original usage events and the other 69 business collections unchanged, and service health recovered. That failed receipt remains retained. A new pinned backup ran only after the appended usage records and startup coordinator changes were verified. Its archive SHA-256 is `cca77eaf1ff0ab147bf35843aaa86103e3c6ed68582c1b0f4c95b5042f570c44`.

## Rendered source workflow

The native browser workflow used synthetic person **P-387**, placement **PL-902**, source entry `3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2` and timesheet `c1ebacce-1523-4f1f-b991-6c3b90bed1a5` for November 7–13. The invented time is not real work or a payroll obligation.

1. The administrator viewed the previously approved 90-minute week with editing and submission disabled, then unlocked it through approvals oversight with a correction reason.
2. **Review week → Edit hours** changed the existing November 9 duration to 120 minutes. The worker, assignment, entry ID, work date and notes remained unchanged.
3. The administrator acknowledged the review, entered a review note and submitted the week. The screen showed **Submitted**, two hours, with editing and further submission disabled.
4. After native administrator sign-out, the separate test approver signed in, opened the exact submitted timesheet, reviewed two hours and the existing audit trail, and approved it through the rendered screen.
5. The approved screen retained the prior submissions, approvals and unlock reasons. The approver signed out. The final source verifier passed at **16:24:46 UTC**.

The verifier confirmed unchanged source/assignment identity, retained original approval and rate-freeze history, the separate administrator and approver identities, and all other original records and indexes. The only appended records were nine audit events, two authentication sessions and one magic-link token; both new sessions were revoked. PurePay's records remained identical. Browser evidence is retained privately in `output/worker-week-browser-observations.json`; the public acceptance manifest records its fingerprint. An ordinary browser reload was needed to replace the cached prior frontend after deployment.

## Remaining acceptance

This closes the Quanta side of the rendered correction journey. Core still holds its previously approved **90-minute** revision, entry 16. Core's rendered correction review, independent reapproval, saved history and current return-status delivery remain next. Do not treat the 120-minute source approval as acceptance in Core or as payroll authorization.

The earlier source-only browser checkpoint was closed unchanged when the missing staff screen was discovered; it is retained separately and was not relabeled successful. The second checkpoint is one-shot and now `source_complete`; do not rerun preparation or completion. Deployment and backup helpers are likewise historical, pinned tools, not reusable deployment commands.

No production, Arabella, real payroll, payment or financial posting changed. The coordinated provider-host write window was released after verification. Field alignment, the remaining onboarding/document/lifecycle and merge journeys, JobDiva cohort/correction work, AWS origin/browser-upload acceptance and financial reconciliation remain open. The overall integration goal is active and incomplete.
