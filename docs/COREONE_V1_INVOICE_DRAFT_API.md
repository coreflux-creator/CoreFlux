# CoreOne v1 invoice draft and approval-request contract

CoreOne uses the existing CoreFlux Billing invoice and accounting core. This route creates a **draft document** and can **request human approval** through the existing Billing workflow. It cannot make the approval decision, send, collect or post the invoice. Those actions remain with their source-owned controls so AR and GL cannot diverge.

## Access

Issue an entity-bound service credential through the existing authenticated `POST /api/coreone_credentials.php` endpoint. Use explicit `scopes: ["invoices:draft"]` to create drafts, `scopes: ["invoices:request_approval"]` to request approval, or both. Issuance requires Accounting integration administration plus Billing draft permission for either invoice scope. The other scopes retain their existing permission checks. The token is returned once, expires within 1-90 days, and can be revoked with `DELETE /api/coreone_credentials.php?id=N`. Earlier credentials retain only their original scopes; they do not gain approval-request access.

Pass the token as `Authorization: Bearer <token>`. Browser cookies, caller-supplied tenant IDs and caller-supplied entity IDs cannot authorize or redirect this route. The token fixes the workspace, legal entity and base currency.

## Create

`POST /api/coreone/v1/invoices.php` with JSON:

```json
{
  "schema_version": 1,
  "source_record_id": "coreone:invoice:2026-0001",
  "client_name": "Example Client",
  "client_company_id": 42,
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

`po_number`, `notes_external`, `client_company_id` and `catalog_item_id` may be omitted. All other fields are required. A supplied client company ID must be a live company in the workspace's client catalog and match `client_name`; otherwise the draft is rejected without creating a document. Without an ID, Billing resolves or creates the client by name. Custom lines need no product or placement. When a catalog item is supplied, it must be active in the credential's workspace. Prices, descriptions and taxability are explicit snapshots rather than mutable catalog defaults. Dates use `YYYY-MM-DD`; due date cannot precede issue date. Monetary quantities/prices allow at most four decimal places. The request must have a positive rounded total.

A new draft returns HTTP 201 with `{invoice, idempotent_replay: false}`. The `invoice` contains the canonical Billing ID and number, client company ID, current status/balances, legal entity, journal ID (null for a draft), workflow instance ID/status (null before routing), and saved lines. `GET /api/coreone/v1/invoices.php?source_record_id=...` returns the current document state for that source ID and entity; either invoice scope can read it. An exact draft `POST` retry returns HTTP 200 with the same invoice and `idempotent_replay: true`. Reusing a source ID with changed intent or from another entity returns HTTP 409; invalid input returns 422; missing/revoked credentials return 401; a valid token without the action's scope returns 403.

The source ID and intent hash are mapped to the Billing invoice in the same transaction as draft creation. That mapping is provenance/idempotency, not a second receivable or ledger. A voided or edited draft retains its original source ID; a retry returns the current document instead of creating a replacement. Consumers must use a new source ID for a new business invoice.

## Request Human Approval

`POST /api/coreone/v1/invoices.php?action=request_approval` with JSON:

```json
{"schema_version":1,"source_record_id":"coreone:invoice:2026-0001"}
```

The source ID must already map to a Billing invoice in the credential's legal entity. A draft must have a matching Billing/People Graph approval policy resolving to an active workspace user with Billing approval rights. A new request returns HTTP 202 with `{invoice, approval_requested: true, idempotent_replay: false}` and a pending `workflow_instance_id`. Repeating it returns HTTP 200 with that same workflow and `idempotent_replay: true`. `GET` exposes the canonical invoice status and latest workflow status for polling. A signed-in human approver makes the decision through Billing's existing approval action and separation-of-duties checks; CoreOne cannot supply an approver or decide for one.

A missing policy or actionable approver, a void invoice, or a previously ended workflow on a still-draft invoice returns HTTP 409. A source ID from another entity returns 404. Already approved/sent/paid invoices return their current state without creating another workflow. Requests are atomic with the source mapping and Billing workflow; this route never posts a JE. The shared migration `155_people_graph_approval_runtime.sql` supplies the policy/rule, actor-link and audit tables required by the existing named-user approval route on clean installs. Role, team, relationship and responsibility routing need additional People Graph schema and acceptance tests.

## Next gates

- Verify the approval-request route on isolated hosted staging, including a signed-in second human approver and an operator-friendly way to configure the policy. Do not provision a production service credential before this review.
- Review sending, posting and payment/receipt machine contracts separately. Never create these effects through general journals.
- Verify the clean-database installer on a disposable isolated database.
- Complete second-user approval, non-synthetic multi-entity acceptance and historical financial audits before production credentials or operational reliance.
