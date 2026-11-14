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

PurePayroll input preparation and source reservation now read each entry's retained approval values. A master-rate edit does not reprice or invalidate an unchanged approved packet. Separate approvals that reused the same rate ID retain separate values. Missing/corrupt archives, changed original reviewers and changes to approved time still refuse export. Transactional reads lock the retained evidence before reserving sources; old hashed approvals still require the original unchanged source row. Provider packet/receipt formats and the held-review behavior are unchanged. The final source and database fixtures passed CI; hosted installation and acceptance remain open.

## Remaining work before installation and full acceptance

- The weekly detail now has an accounting-status card, accepted in source but not installed. It reads status on open/refresh and advertises an explicit whole-week ledger action only when the server confirms both time-approval and accounting-post permission. A placement-filtered or historical view cannot initiate whole-week posting. Failed/uncertain responses discard the old review and require a fresh status read; duplicate clicks cannot send concurrent attempts. No scheduler or automatic retry is installed. Hosted UI acceptance remains open.
- Migrate the remaining Billing/AP generators, period bundles and native payroll readers to the per-entry archived rates. Their current rate-ID lookups are not sufficient. The staffing accounting emitter, source-accrual allocation and PurePayroll input readers are converted here.
- Complete original-workspace ownership across the remaining financial readers, weekly aggregate breakdowns, earning categories, rate units/currencies and multi-period amounts.
- Complete paired financial reversal/replacement, historical aggregate provenance, migrations and the guarded pilot upgrade; then repeat the signed-in Quanta/PurePay/PurePayroll end-to-end acceptance.

The synthetic database checks cover private evidence, later rate edits, missing/corrupt archives, late receipt rollback, nested-transaction deferral, interrupted delivery, second-segment failure, exact replay, original-company isolation, native permission/review checks and simultaneous retry attempts. Final candidate `4ebb0390` passed all eleven CI jobs in run 43559123793, including 944 concurrent, 953 native-session and 917 native HTTP checks. The PurePayroll reader and operator screen have their own final evidence in `deploy/thunderhawk-approved-time-accounting-preparation-20261114.json`; this is not an installed-runtime or complete-integration receipt.
