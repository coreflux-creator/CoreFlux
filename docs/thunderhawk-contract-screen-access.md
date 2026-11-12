# Contract viewing and explicit participant refresh

The Contract screen previously called reconciliation on GET. Merely viewing it
could create or update economic participants, operating cycles, and vendor links.
The economics API now reads without reconciliation. Missing or deleted placements
return not found. An editor can explicitly refresh participants; the placement
lock, graph update, required actor audit, and refreshed response share a transaction.
An audit or graph failure rolls back the refresh.

The API returns screen permissions using the same legacy and module checks as the
writers. General placement editing, financial editing, rate approval, commissions,
referrals, corporate details, and portal credential access remain separate rights.
Absent permissions and data for a different placement enable no editing controls.
Readers retain contract evidence and recipient names without upload or write forms.
JobDiva ownership and source reviews separately return their release/apply rights.
A refreshed downgrade removes selection; failed CSV refreshes and uncertain apply
responses require a new review instead of reusing an older token.

Validation added:

- Actual React components: reader/editor/financial permissions, missing permissions,
  downgrade, placement mismatch, visible recipient evidence, explicit refresh, and
  refresh success followed by a failed reload.
- Ownership and JobDiva reviews: denied/missing permission, refreshed downgrade,
  failed CSV refresh, and uncertain CSV submission.
- Native login and real API calls in the disposable CI database: unbuilt-contract
  GET with complete graph-row comparisons; explicit editor refresh; required-audit
  failure rollback; permission metadata; foreign-tenant refusals; same-session
  downgrade. Existing source apply, ownership conflict and replay checks remain.

Local component checks pass 25 tests across three suites; PHP syntax and the
dashboard hook check pass (existing warnings remain). Source
`1e95bf08b9724d57ca3706a2afa921ceb08ce4ee` passed all 11 jobs in
[CI run 43132449997](https://github.com/coreflux-creator/CoreFlux/actions/runs/43132449997).
The native session round trip passed 87 checks, including complete-row comparisons
for unbuilt-contract GET and rollback after a deliberately failed refresh audit.
The first candidate used the wrong vendor-table name and two old source-string
expectations; those test defects were corrected. One legacy smoke test now reads
its own checkout rather than an unrelated absolute `/app` path.

At 2026-11-12 01:15 UTC, the paired 12-file update was installed in isolated Core
staging only. Its 37 dependencies matched the expected runtime. All 34,400 rows
across 368 tables and the private configuration were preserved. The served screen
assets matched the release, and the temporary web verification endpoint was removed.

The deployed native-session verification passed 61 checks: read-only screen
permissions, denied refresh for readers, separation of financial editing,
cross-tenant refusals, and immediate downgrade/revocation. All 8,537 observed
business rows across 23 tables stayed unchanged. Synthetic actor 79 / membership 65
was retired with password cleared and grants removed; the private cookie was deleted.
The hosted checks deliberately submitted no valid business edit. Successful refresh
and required-audit rollback were verified in the disposable CI database.

Browser inspection of actual components against local synthetic data confirmed
reader, placement-editor and financial-editor controls and visible recipient names.
This was DOM/visible-text inspection: screenshot capture timed out, and no signed-in
operator workflow or pixel verification is claimed. The temporary tab and local
server were closed. See `deploy/thunderhawk-contract-screen-20261111.json` for the
file fingerprints, receipts, backup, evidence paths and remaining integration work.
Production, provider applications, Arabella's separate pilot and real financial
transactions were unchanged.
