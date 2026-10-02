# CoreAccounting on the CoreFlux accounting core

## Decision

CoreAccounting is a workflow surface over the existing CoreFlux ERP accounting engine. It must not maintain a second ledger, invoice balance, bank reconciliation, or financial database. The local SQLite interaction prototype remains on a separate branch and is not part of this implementation or a production migration path.

| Concern | Canonical owner |
| --- | --- |
| Authentication, tenant and permissions | `core/api_bootstrap.php`, tenant context and module RBAC |
| Journal posting, reversal, periods and dimensions | `modules/accounting/lib/accounting.php` and `core/posting_engine/process.php` |
| Invoices, payments and AR | `modules/billing` |
| Bills, payments and AP | `modules/ap` |
| Bank feed and reconciliation | `modules/accounting/api/bank_statements.php` and `reconciliations.php` |
| Financial statements and aging | `modules/accounting/api/reports.php` and `standard_reports.php` |

## First slice: invoice to cash

- Accounting navigation leads to the native invoice workflow. A completed invoice approval posts through the existing Billing endpoint; a configured policy still controls approval.
- Sending requires a posted journal entry in the API, not just in the UI. Posting an invoice that already links to a posted journal returns that entry on retry.
- The invoice shows its journal link and tenant-scoped unmatched deposits from compatible bank accounts. A user explicitly applies an exact receipt, a smaller partial receipt, or opens the bank split screen for larger and multi-invoice deposits.
- For both direct and split receipts, the cash journal, Billing payment allocation, and bank-line match commit as one transaction. Pay-when-paid release runs after commit.
- No CoreAccounting-specific financial table or parallel balance calculation was added.

## Gate before live use or CoreOne packaging

Run the full flow against an isolated MySQL ERP tenant: draft, approval policy, journal posting, sending, exact and partial receipts, split across invoices, retry, failed-allocation rollback, reversal, tenant/entity isolation, and agreement among AR aging, GL, trial balance, cash flow and bank reconciliation. The local PHP installation used for this slice lacks the MySQL PDO driver, so source checks and the frontend preview do not satisfy this gate.

After that gate, build AP on the same modules, then define a CoreOne-facing API/package boundary around the canonical owners. Deployment may be separate; ledger authority may not.
