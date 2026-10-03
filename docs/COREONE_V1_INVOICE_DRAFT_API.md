# CoreOne v1 invoice draft contract

CoreOne uses the existing CoreFlux Billing invoice and accounting core. This route creates a **draft document only**; it cannot approve, send, collect or post it. Those actions remain in the source-owned Billing workflow so AR and GL cannot diverge.

## Access

Issue an entity-bound service credential through the existing authenticated `POST /api/coreone_credentials.php` endpoint with explicit `scopes: ["invoices:draft"]`. Issuance requires Accounting integration administration plus the permission for each requested scope: Billing draft for `invoices:draft`, JE post for `journals:write`, and financial report view for `reports:read`. The token is returned once, expires within 1-90 days, and can be revoked with `DELETE /api/coreone_credentials.php?id=N`. Earlier credentials retain only `journals:write` and `reports:read`; they do not gain invoice access.

Pass the token as `Authorization: Bearer <token>`. Browser cookies, caller-supplied tenant IDs and caller-supplied entity IDs cannot authorize or redirect this route. The token fixes the workspace, legal entity and base currency.

## Create

`POST /api/coreone/v1/invoices.php` with JSON:

```json
{
  "schema_version": 1,
  "source_record_id": "coreone:invoice:2026-0001",
  "client_name": "Example Client",
  "issue_date": "2026-10-03",
  "due_date": "2026-11-02",
  "currency": "USD",
  "tax_rate_pct": "0",
  "po_number": "PO-17",
  "notes_external": "Consulting services",
  "lines": [
    {
      "catalog_item_id": 12,
      "description": "Advisory service",
      "quantity": "2",
      "unit": "hour",
      "unit_price": "12.50",
      "taxable": false
    }
  ]
}
```

`po_number`, `notes_external` and `catalog_item_id` may be omitted. All other fields are required. Custom lines need no product or placement. When a catalog item is supplied, it must be active in the credential's workspace. Prices, descriptions and taxability are explicit snapshots rather than mutable catalog defaults. Dates use `YYYY-MM-DD`; due date cannot precede issue date. Monetary quantities/prices allow at most four decimal places. The request must have a positive rounded total.

A new draft returns HTTP 201 with `{invoice, idempotent_replay: false}`. The `invoice` contains the canonical Billing ID and number, current status/balances, legal entity, journal ID (null for a draft), and saved lines. `GET /api/coreone/v1/invoices.php?source_record_id=...` returns the current document state for that source ID and entity. An exact `POST` retry returns HTTP 200 with the same invoice and `idempotent_replay: true`. Reusing a source ID with changed intent or from another entity returns HTTP 409; invalid input returns 422; missing/revoked credentials return 401; a valid token without `invoices:draft` returns 403.

The source ID and intent hash are mapped to the Billing invoice in the same transaction as draft creation. That mapping is provenance/idempotency, not a second receivable or ledger. A voided or edited draft retains its original source ID; a retry returns the current document instead of creating a replacement. Consumers must use a new source ID for a new business invoice.

## Next gates

- Review approval, sending, posting and payment/receipt machine contracts separately. Never create these effects through general journals.
- Verify the clean-database installer on a disposable isolated database.
- Complete second-user approval, non-synthetic multi-entity acceptance and historical financial audits before production credentials or operational reliance.
