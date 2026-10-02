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

## Connecting the local UI to the canonical backend

The Vite dashboard is only a frontend. Its development server forwards `/api/*`, `/core/api/*`, `/modules/{module}/api/*`, the existing `/login.html` page, session/login/logout/tenant-switch requests, and static `/assets/*` to the CoreFlux PHP backend. Ordinary `/modules/*` screens remain in the local SPA. The browser never connects to MySQL directly. There is no CoreAccounting-specific sign-in or identity store.

For an isolated hosted staging installation, set `COREFLUX_BACKEND_ORIGIN=https://<staging-host>` in `dashboard/.env.local`, then run `npm run dev` from `dashboard`. Leave `VITE_API_BASE` unset so all requests use the same-origin proxy and session cookie. The proxy strips the upstream cookie domain for local development and rejects CoreFlux production/tenant domains unless `COREFLUX_ALLOW_PRODUCTION_PROXY=1` is explicitly set. The default backend remains `http://127.0.0.1:8080` when no origin is configured.

Staging must have its own PHP deployment, MySQL database, migrations, encryption key and test tenant. Verify login and invoice-to-cash against that staging tenant before making the CoreAccounting UI available for operational use.

## Hosted staging status (October 2026)

- Cloudways application **CoreFlux Staging** (application ID `6707602`) was created as a blank Custom PHP app on the existing CoreFlux server. Its default URL is `https://phpstack-1516771-6707602.cloudwaysapps.com/`.
- Cloudways provisioned a separate database for the app; its identity was checked against the production app and differs. No production files, database records, or credentials were cloned into staging.
- A unique 32-byte data-encryption key was generated for staging and kept with its database settings in the server-local, gitignored `core/db.local.php` (mode `640`, group `www-data`). Neither value is stored in this repository.
- `core/config.php` requires explicit database settings from gitignored `core/db.local.php` for the Cloudways default hostname, known custom staging hostnames, and CLI runs marked `COREFLUX_ENV=staging`. Missing settings fail closed instead of selecting the legacy production fallback.
- Staging selects the log-only mail driver and does not register Resend; direct legacy SMTP calls are blocked. Do not copy production `core/config.local.php` or enable outbound integrations on this host.
- The branch was deployed to the staging app, with stage-only database settings in a server-local, gitignored file. A synthetic test tenant (ID `999`) and synthetic administrator were created; no production users or business records were copied. `dashboard/.env.local` points the local Vite proxy at this staging origin. The staging login page loads, and unauthenticated `/session.php` requests return HTTP 401 both directly and through the proxy, confirming the local UI reaches the hosted PHP app.
- The canonical core migrator completed with zero errors after the required People, Placements, Time and Staffing module base migrations were applied. The older `deploy/run_migrations.php` sequence does **not** bootstrap a blank installation by itself: it starts with an AI migration that assumes base tables exist. Record that gap before offering one-click provisioning.
- A business-integrity audit on the synthetic tenant reported zero critical or error findings. The full simulation suite is **not green**: its first pass posted test journals before missing module columns were fixed; rerunning the same fixed-ID scenarios then replayed idempotency keys and failed expected-new-journal assertions. Use a fresh isolated tenant for the next full run. This is partial verification, not proof of the invoice-to-cash gate above.
- The first authenticated login exposed clean-install omissions in the tenant and module catalog schema. The additive `150_tenant_login_base_columns.sql` migration now supplies those base columns and tables when absent; existing installations retain their tables. The staging database's newly created module tables were aligned to its existing tenant-module collation.
- The local staging login defaults to password because staging mail is intentionally disabled. A rejected test login through the Vite proxy showed an explicit credential error. The synthetic administrator subsequently logged in successfully (HTTP 200, tenant `999`) both on the hosted app and through the local Vite proxy. Authenticated account, invoice, income statement and balance sheet requests returned structured HTTP 200 responses through that proxy.
- The development branch's dashboard bundle was built and published to the hosted staging app. Its authenticated root page references the new `index-CsvA5rx9.js` asset, which returns HTTP 200. The local Vite UI is available at `http://127.0.0.1:5176/` and proxies only to this staging backend.
- A synthetic invoice was created as a draft, approved, and posted to the canonical ledger. Posting again returned the same journal. A $75 CSV bank receipt was applied as a partial payment against the $125 invoice; a second $50 receipt closed it. The invoice ended `paid` with two allocations and zero due. Both bank lines ended `matched`, each linked to a distinct posted cash journal. The second CSV replay inserted zero lines and reported one duplicate.
- The first bank CSV import exposed a clean-install schema bug: `scopedUpdate()` finalized the parent import using `updated_at`, which the original bank-import table lacked. It had already inserted a bank line before failing. Migration `151_bank_import_updated_at.sql` adds the column idempotently; `bankRecImportCsv()` now owns a transaction when called outside one, so parent metadata and lines commit or roll back together. The one synthetic, partially imported staging record was repaired in place before matching. The repaired importer succeeded on the second receipt.
- The trial balance, income statement, balance sheet, and indirect cash-flow endpoints returned no data warnings after the receipts. Cash flow reconciled to the GL ($125 change, $0 difference), and the balance sheet reported balanced. The synthetic tenant already contains earlier simulation journals, so its full statement balances are not a clean invoice-only baseline; use a fresh tenant for full cross-report agreement.
- The authenticated API flow is verified. The local preview now forwards `/login.html` to the staging backend's existing CoreFlux sign-in page, and a browser sign-in reached the normal CoreFlux workspace with the accounting navigation present. A visual walkthrough of the accounting workflow itself is still outstanding. The staging login note is kept outside the repository at `C:\Users\kunal\.codex\secrets\coreflux-staging-login.txt`; do not commit it.
- Still required before operational use: a fresh-tenant full simulation run; sending and collecting with staging-safe mail; larger/multi-invoice receipt, failure rollback, reversal, and tenant/entity-isolation cases; complete report and aging reconciliation; and a repeatable clean-database bootstrap. Do not enable outbound mail or production integrations here.
