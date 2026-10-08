# CoreOne v1 bill preparation contract

CoreOne prepares payables in the existing CoreFlux AP module. Preparation creates a **pending-approval bill only**. A separately scoped action can request the existing human AP review. AP retains the approval decision, posting, payment, corrections and vendor controls; neither machine action creates a journal entry.

## Access

Issue an entity-bound service credential through authenticated `POST /api/coreone_credentials.php` with `scopes: ["bills:prepare"]`, `scopes: ["bills:request_approval"]`, or both. The issuer needs Accounting integration administration and `ap.bill.create` for either scope. The scopes do not imply each other and neither grants a journal post, invoice draft, financial report, human approval decision, payment or correction. Existing credentials do not gain the new scope automatically. The token is returned once, expires within 1-90 days, and can be revoked through `DELETE /api/coreone_credentials.php?id=N`.

Pass `Authorization: Bearer <token>`. The token fixes the workspace, legal entity and base currency. Browser cookies and caller-supplied tenant or entity IDs cannot redirect this route.

The user who issues the service credential is recorded as the creator of each bill it prepares. AP's existing two-eye check therefore prevents that issuer from approving the bill. Existing source-linked bills with a known credential issuer receive that attribution through migration `159_coreone_bill_issuer_attribution.sql`; an issuerless credential should be revoked and replaced before operational use.

## Prepare

`POST /api/coreone/v1/bills.php` with JSON:

```json
{
  "schema_version": 1,
  "source_record_id": "coreone:bill:2026-0001",
  "vendor_name": "Example Vendor",
  "vendor_type": "w9_business",
  "bill_number": "EV-100",
  "received_at": "2026-10-03",
  "bill_date": "2026-10-02",
  "due_date": "2026-11-01",
  "currency": "USD",
  "tax_rate_pct": "0",
  "po_number": "PO-17",
  "notes_internal": "Consulting services",
  "lines": [
    {
      "item_type": "other",
      "description": "Advisory service",
      "quantity": "2",
      "unit": "hour",
      "unit_price": "12.50",
      "gl_expense_account_code": "6990",
      "is_1099_eligible": false
    }
  ]
}
```

`po_number`, `notes_internal` and each `gl_expense_account_code` may be omitted. All other fields are required. Supported vendor types are `1099_individual`, `c2c_corp`, `w9_business`, `utility` and `other`. Dates use `YYYY-MM-DD`, and due date cannot precede bill date. Quantities and prices allow at most four decimal places; each quantity and price must be positive, and every rounded line total must be at least one cent. Item types follow the AP module's existing list **except `discount`**: the current AP posting path debits each line and cannot represent a discount safely, so a discount line fails with 422. Enter a net payable price until explicit credit/discount treatment exists. An explicit GL code must be an active, postable debit-side asset or expense account, not a bank-linked cash account or a protected subledger control account. With no code, the existing AP posting workflow chooses its default expense account later. This is a bill preparation contract, not an account-coding or payment authorization.

A new bill returns HTTP 201 with `{bill, idempotent_replay: false}`. The `bill` contains the canonical AP ID and internal reference, current status/balances, legal entity, journal ID (null initially), saved lines, `approval_workflow_id` (null initially), and `approval_status` (`not_requested` initially). `GET /api/coreone/v1/bills.php?source_record_id=...` returns the current bill for that source ID and entity to either bill-scoped credential. An exact preparation retry returns HTTP 200 with the same bill and `idempotent_replay: true`. Reusing a source ID with changed intent or from another entity, or submitting the same active vendor bill number for the same entity, returns HTTP 409. Invalid input returns 422; missing/revoked credentials return 401; a valid token without `bills:prepare` returns 403.

The source ID and intent hash are mapped to the AP bill in the same transaction as bill creation. This mapping is provenance/idempotency, not a second payable or ledger. A voided or edited bill retains its source ID; a retry returns the current document, not a replacement. A new business bill requires a new source ID. Duplicate vendor bill numbers are currently checked for manual bills within one legal entity, under AP's tenant numbering lock; imported and other AP sources require their own duplicate-policy audit before this can serve as a universal bill-ingestion guarantee.

## Request human review

`POST /api/coreone/v1/bills.php?action=request_approval` requires `bills:request_approval` and JSON containing only `schema_version: 1` and the prepared bill's `source_record_id`. It does not accept a reviewer choice, decision, posting instruction or payment instruction. The active AP policy must resolve at least one active, independent reviewer; the bill must have an accountable creator and no conflicting earlier review. The action atomically starts the canonical AP workflow and reviewer rows. Notifications are attempted only after commit and are best-effort; the saved workflow is the source of truth.

The first successful request returns HTTP 202 with `{bill, approval_requested: true, idempotent_replay: false}`. An exact retry while review is pending, or after its normal approval/posting, returns HTTP 200 with the same workflow and `approval_requested: false`. Poll `GET` for `approval_status`, `approval_workflow_id`, the AP bill status, and eventual `journal_entry_id`. A missing or cross-entity source returns 404; an unrouteable policy, creatorless bill, void bill, or conflicting prior review returns 409; unsupported fields or actions return 422; a credential without request scope returns 403. A completed bill approved outside this workflow is not reported as a successful request replay.

## Verified staging handoff

The isolated simulation-company acceptance in `tests/accounting_coreone_bill_lifecycle_staging.php` calls the actual bearer endpoints, verifies that the pending bill appears in ERP AP, checks exact-retry, scope and cross-entity boundaries, and confirms that no journal exists before human approval. A rollback-only policy change proves that no independent reviewer leaves no workflow or approval rows. The test then proves that the credential issuer cannot self-approve, a separate AP reviewer can approve and post, and CoreOne's source-ID lookup returns that same posted bill and canonical journal. The final ledger and income, balance-sheet and cash-flow reports balance without inventing a payment. All one-day test credentials are revoked afterward. This is a service/API rehearsal with synthetic data, not a signed-in two-human browser rehearsal or a production-history audit.

The same hosted test also submits a zero-price machine bill, a positive `discount` machine line, and a zero-price ERP manual bill. All fail with 422 before a bill or source mapping is saved; a valid bill still completes the human review and post flow. These guards apply to manual preparation, not to every historical, imported, recurring or time-generated AP source.

## Next gates

- Review any source-owned posting, payment and correction machine contracts; preserve AP's two-person controls. The verified handoff uses a human AP reviewer and the existing AP post endpoint, not CoreOne machine decision/post authority. Do not use general journals to stand in for those actions.
- Add explicit credit/discount bill-line treatment and a typed catalog of permitted expense/capitalization accounts before claiming broad QuickBooks parity.
- Verify the clean-database installer on a disposable isolated database, then complete second-user and non-synthetic multi-entity acceptance plus historical financial audits before production service credentials.
