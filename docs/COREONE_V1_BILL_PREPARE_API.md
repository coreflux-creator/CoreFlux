# CoreOne v1 bill preparation contract

CoreOne prepares payables in the existing CoreFlux AP module. This route creates a **pending-approval bill only**. AP retains approval, posting, payment, corrections and vendor controls; this route cannot initiate any of them. It does not create a journal entry.

## Access

Issue an entity-bound service credential through authenticated `POST /api/coreone_credentials.php` with `scopes: ["bills:prepare"]`. The issuer needs Accounting integration administration and `ap.bill.create`. A credential with only this scope cannot post a journal, draft an invoice or read financial reports. Existing credentials do not gain the scope automatically. The token is returned once, expires within 1-90 days, and can be revoked through `DELETE /api/coreone_credentials.php?id=N`.

Pass `Authorization: Bearer <token>`. The token fixes the workspace, legal entity and base currency. Browser cookies and caller-supplied tenant or entity IDs cannot redirect this route.

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

`po_number`, `notes_internal` and each `gl_expense_account_code` may be omitted. All other fields are required. Supported vendor types are `1099_individual`, `c2c_corp`, `w9_business`, `utility` and `other`. Dates use `YYYY-MM-DD`, and due date cannot precede bill date. Quantities and prices allow at most four decimal places; the rounded bill total must be positive. Line item types follow the AP module's existing list. An explicit GL code must be an active, postable debit-side asset or expense account, not a bank-linked cash account or a protected subledger control account. With no code, the existing AP posting workflow chooses its default expense account later. This is a bill preparation contract, not an account-coding or payment authorization.

A new bill returns HTTP 201 with `{bill, idempotent_replay: false}`. The `bill` contains the canonical AP ID and internal reference, current status/balances, legal entity, journal ID (null initially), and saved lines. `GET /api/coreone/v1/bills.php?source_record_id=...` returns the current bill for that source ID and entity. An exact `POST` retry returns HTTP 200 with the same bill and `idempotent_replay: true`. Reusing a source ID with changed intent or from another entity, or submitting the same active vendor bill number for the same entity, returns HTTP 409. Invalid input returns 422; missing/revoked credentials return 401; a valid token without `bills:prepare` returns 403.

The source ID and intent hash are mapped to the AP bill in the same transaction as bill creation. This mapping is provenance/idempotency, not a second payable or ledger. A voided or edited bill retains its source ID; a retry returns the current document, not a replacement. A new business bill requires a new source ID. Duplicate vendor bill numbers are currently checked for manual bills within one legal entity, under AP's tenant numbering lock; imported and other AP sources require their own duplicate-policy audit before this can serve as a universal bill-ingestion guarantee.

## Next gates

- Review source-owned approval, posting, payment and correction machine contracts; preserve AP's two-person controls. Do not use general journals to stand in for those actions.
- Add explicit credit/discount bill-line treatment and a typed catalog of permitted expense/capitalization accounts before claiming broad QuickBooks parity.
- Verify the clean-database installer on a disposable isolated database, then complete second-user and non-synthetic multi-entity acceptance plus historical financial audits before production service credentials.
