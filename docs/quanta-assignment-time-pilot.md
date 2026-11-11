# Isolated Quanta assignment-time update

On November 11, 2026 (UTC), Quanta source
`4354556f72d9e034f1b4207809b04c592fbe9f5f` replaced the earlier `e47e169`
runtime in the existing isolated provider pilot. It includes assignment-bound
time capture, approval/export guards, native assignment choices and the
administrator time-eligibility review. Its operational configuration remains
disabled: no company was added to `COREFLUX_TIME_ACCESS_TENANTS`, no policy was
created and no existing entry was adopted or rewritten.

The full application's 540 checks and the later 168 focused checks both match
all 178 current backend source hashes, with no attempted provider connections.
The frontend was rebuilt from the same tested source without public source maps.
The installer package contained 24 changed backend files and 54 frontend files.
It preserved the private browser policy, checked the entire existing backend
against the reviewed baseline, and required the exact container and start time.

The actual installer was also rehearsed against temporary files and simulated
services. Nine cases cover success, stale container identity, already-enabled
policy, changed backend, incorrect archive digest, unexpected business-record
change, unexpected policy activation, startup failure and incorrect served HTML.
Failure after replacement restores the old bytes, ownership and timestamps;
new files are removed. Successful installation uses fresh Python-file timestamps
so a same-length change cannot accidentally reuse an old bytecode cache.

Only the existing isolated Quanta web process restarted. Its container ID stayed
`f4988aa4a83d4b6106c205ecf9c1e8f63d0a975a9da846f3269450f2ed8a7d11`;
the new start time is `2026-11-11T04:00:28.628576052Z`. No new container or
background worker was started. A private rollback copy remains available.
All existing collections and indexes stayed exact; startup created only the
empty `coreflux_time_access` collection. No usage-log rows were appended.
Other services retained their identities, states and start times.

Independent verification fetched and hashed all 54 served frontend files,
checked installed runtime bytes and called native APIs with ephemeral tokens
for existing isolated worker and administrator identities. It established:

- The original three approved onboarding tasks and twelve current time-review
  receipts for P-358 / PL-864 remain readable.
- Worker access to workspace reports, administrator time setup and another
  worker's choices is denied.
- Without a policy, the worker's assignment selector remains unmanaged and does
  not invent an assignment or grant access.
- The administrator can read the review context, which identifies twelve
  existing unbound entries and reports configuration disabled.
- A valid configuration request is refused by the disabled rollout guard,
  without creating a policy or delivery record.
- All 66 collections remain unchanged after these verification requests;
  no token was saved, and other services remain unchanged.

This is hosted runtime/API acceptance with configuration held. It is not a
browser login/MFA test or a completed time-activation/offboarding round trip.
The first hosted negative request used the wrong context field name and was
rejected by schema validation; the corrected contract then reached and proved
the disabled configuration guard. Neither request changed business records.

Evidence is retained in `deploy/thunderhawk-assignment-time-20261111.json`,
`deploy/install_thunderhawk_assignment_time.py` and
`tests/quanta_time_pilot_installer_rehearsal.py`.

Core source `26812ae869d07c0319fb1b18e4efc48221552ff9` separately passed all
eleven jobs in CI run 42955201527, including its assignment-specific route
migration, independent database writer contention, source conflict, batch
rollback and native permissions tests. Those Core changes and migration 188
were installed in the existing isolated Core pilot at 04:23 UTC on November 11.
The installer preserves file ownership and fences database writers while the
route schema changes. Its recovery helper passed twelve real MariaDB cases,
including contention before and after schema changes and after restoration;
all eleven jobs in CI run 42958101611 passed for `c819527c`.

Independent hosted verification at 04:32 UTC checked 21 installed files, six
served frontend files, the migration ledger and all 367 original business
tables. All three legacy routes remain exact apart from the new empty selector;
all twelve original Core-to-Quanta time-review proofs still validate unchanged.
Operational time configuration is disabled in both applications. The first
Core time-setup read returned 403 because its dedicated provider key lacked
the separately selected `read:core_time_access` permission. A read-only
inspection found 44 completed provider delivery receipts associated with that
key; replacing it would change the identity used to retrieve those receipts.
No credential or scope was changed during the Core deployment.

Quanta `d8c82eb59389cb85f766dc00f56cbc2671943d7f` then added an administrator
permission editor, stale-review protection and atomic per-key change history.
It also corrected the saved-key model, which had rejected the new time-setup
permissions even though the picker and API scope registry offered them.
Seventeen native permission cases, 168 integration cases and three interface
cases passed; the frontend built successfully. The isolated update preserved
all existing business records and other services. Shutdown appended only 55
already-buffered usage events. Independent verification checked all 54 served
frontend files and the unchanged 66 collections before the permission change.

The existing synthetic pilot administrator used the native permission API to
add only `read:core_time_access` to the original pilot key. Its identity and all
44 completed delivery receipts remain exact. The key's atomic history and one
activity event record the change; no other collection changed. At 04:43 UTC,
Core's independent verifier successfully read the provider's time-setup context,
identified twelve unbound historical entries, and again confirmed all original
review proofs and 367 Core tables unchanged. No operational time policy was
activated. The deployment and permission evidence is retained in
`deploy/thunderhawk-quanta-key-permissions-20261111.json`.

Core installation and independent evidence are in
`deploy/thunderhawk-core-assignment-time-20261111.json`, with the exact installer
and verifier beside it. The frozen full-Core candidate
`6b7841894cbfc818769de216f2719b0fbb3ab2e8` remains unchanged.

Remaining work includes reviewed handling of existing
time, concurrent native mutation checks, remaining time-entry selectors, the
reviewed PurePay candidate and PurePayroll authority, distinct synthetic
offboarding, full-Core release acceptance and Thunderhawk reconciliation.
No production cutover, real payroll, payments, financial postings or Arabella
pilot changes were performed.
