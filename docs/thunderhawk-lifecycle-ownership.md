# Source lifecycle conflicts and reviewed ownership release

Verified in isolated Core staging on November 11, 2026. Runtime source
`b48af8abc895df4d28646c70975d5378834f2e77` passed all eleven jobs in CI run
`43109514659`, then ten exact files were installed together. The full integration
goal remains open. Production, Quanta, PurePay, PurePayroll and Arabella were not
changed by this release.

The separate JobDiva terminal-status census now locks the existing placement
before checking its identity and ownership. A Core-owned status, planned end
date or actual end date prevents the entire source closure, with a reported
conflict reason. Source-owned closures still reconcile the commercial period.
Invalid dates, malformed ownership and commercial-graph failures leave the
placement unchanged. Replaying an already closed source record adds no audit
or graph changes.

Returning a field to JobDiva now locks the placement and checks the ownership
and values reviewed on screen. A stale review is refused. When Core owns both
the closed status and actual end date, they must return together. The screen
explains that its next JobDiva refresh may change the closure. Ownership release
does not itself change the current status or dates. The ownership change and
its recorded audit entry commit together; an unavailable audit rolls back the
change. A repeated release is a no-op.

The existing final-candidate disposable process was observed to completion,
without restarting it. It reproduced the old terminal-census defects, then
passed 30 lifecycle checks, 17 earlier ownership checks, 13 ending checks and
17 request checks. It removed its private resources and preserved all 206
existing containers. The separate PurePay chat's coordination hold was released
after the authoritative result and absent process were checked.

The deployed screen bundle was traced to source `6c83324b`. Only
`PlacementDetail.jsx` changed among screen inputs between that build and this
release. The old bundle's hosted hashes were verified before replacement. The
release added the new hashed JavaScript assets, changed the existing service
worker's cache version, and updated the HTML entry points with the backend.
Old hashed assets were retained. Hosted HTTP responses matched the new screen
asset hashes, and the temporary PHP cache probe was removed.

Installation preserved all 368 database tables and 34,356 existing rows,
unchanged dependencies, private configuration and file ownership. The deployed
functions then passed all 30 lifecycle checks, including contention, stale
review, audit-failure rollback, tenant isolation and replay. Synthetic records
were enclosed in an outer transaction and rolled back. Independent before/after
fingerprints preserved all existing rows; sequence gaps from rolled-back
inserts are allowed. These are deployed-function and served-asset checks, not
new native HTTP or rendered browser acceptance.

Evidence is recorded in `deploy/thunderhawk-lifecycle-ownership-20261111.json`.
The earlier ownership report remains historical evidence; this release closes
its terminal-census and explicit ownership-release gaps. Ordinary and bulk
placement edits still require concurrency review. Other forced-source fields,
complete custom-field alignment, portal/onboarding/document lifecycle and
financial reconciliation remain requirements of the full integration goal.

The subsequent release in `thunderhawk-native-placement-edits.md` moves
ordinary edits, bulk edits and activation into one locked transaction. The
remaining source-contract authority, CSV, lifecycle/date and native HTTP/browser
acceptance boundaries are recorded there.
