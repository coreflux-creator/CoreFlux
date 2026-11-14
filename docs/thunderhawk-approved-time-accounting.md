# Approved time and accounting delivery

This change is prepared for the isolated integration pilot. It is not a production release, an installed migration, permission to post real books, or completion of the integration.

## Approval evidence

Migration `203_time_approval_economics.sql` adds two private tables. Every new native approval retains the complete approved placement-rate row in `time_approval_rates`, keyed by original time workspace and entry, with its original placement/rate owner, source hash and economic time-facts hash. Public time and dimension responses retain a fingerprint and version, not the private pay-rate values. There is no overwrite or automatic historical backfill.

When the final entry completes a week, the same approval transaction records its complete internal-accounting request in `time_accounting_handoffs`. Failure to save either the rate evidence or the request rolls back the approval and its receipts. Individual, internal weekly and external weekly approvals use this common path. No journal or provider instruction is created by enqueueing.

## Delivery and recovery

The existing weekly dispatcher now consumes the retained request. It refuses to process inside an outer transaction, verifies the original accounting owner and approved facts, and uses archived rates even after master rates change. All segments and completion status share one transaction. A failed segment rolls back the entire delivery while retaining the failed request and attempt count. Exact retries use the existing accounting-event identity and cannot create an additional journal.

An older approval with a rate fingerprint can be replayed only if the exact hashed rate row still exists. Missing or changed evidence is held for review. A legacy aggregate journal blocks a new assignment-level posting until reconciled; it is not silently replaced or double-booked. Correction/reversal behavior still needs its separate implementation.

Reviewed native and Quanta corrections withdraw a pending/failed, unposted request in the same transaction as the source replacement. The old payload, actor, reason and cancellation date remain in history. The current approved remainder, or a later independently approved replacement, gets a new request. Owner locks serialize correction against delivery; posted accounting still requires the downstream reversal/correction workflow.

`GET /modules/time/api/accounting_handoffs.php?timesheet_id=...` requires native sign-in and `time.view` in the original workspace. It returns status and a review revision, never rate JSON or event payloads. Detailed processing errors also require accounting read access. `POST` requires `time.approve`, `accounting.je.post`, native request protections and the displayed revision. The service rechecks that revision under its delivery lock and records a successful operator retry receipt. These are internal-ledger actions, not payroll execution or payment-rail instructions.

PurePayroll input preparation and source reservation now read each entry's retained approval values. A master-rate edit does not reprice or invalidate an unchanged approved packet. Separate approvals that reused the same rate ID retain separate values. Missing/corrupt archives, changed original reviewers and changes to approved time still refuse export. Transactional reads lock the retained evidence before reserving sources; old hashed approvals still require the original unchanged source row. Provider packet/receipt formats and the held-review behavior are unchanged. This follow-up is source-only until its database fixtures and complete CI pass.

## Remaining work before installation and full acceptance

- Add the status/retry screen and the reviewed operational retry policy. This change provides the API and service; it does not install a scheduler or imply unattended recovery.
- Migrate the remaining Billing/AP generators, period bundles and native payroll readers to the per-entry archived rates. Their current rate-ID lookups are not sufficient. The staffing accounting emitter, source-accrual allocation and PurePayroll input readers are converted here.
- Complete original-workspace ownership across the remaining financial readers, weekly aggregate breakdowns, earning categories, rate units/currencies and multi-period amounts.
- Complete paired financial reversal/replacement, historical aggregate provenance, migrations and the guarded pilot upgrade; then repeat the signed-in Quanta/PurePay/PurePayroll end-to-end acceptance.

The synthetic database checks cover private evidence, later rate edits, missing/corrupt archives, late receipt rollback, nested-transaction deferral, interrupted delivery, second-segment failure, exact replay, original-company isolation, native permission/review checks and simultaneous retry attempts. Exact CI evidence is recorded in the acceptance checkpoint after the candidate finishes.
