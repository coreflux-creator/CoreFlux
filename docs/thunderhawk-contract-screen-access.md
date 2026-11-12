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

Local component checks pass 25 assertions across three suites; PHP syntax and the
dashboard hook check pass (existing warnings remain). CI and isolated deployment
acceptance must be recorded separately. This is not production or live financial
workflow acceptance.
