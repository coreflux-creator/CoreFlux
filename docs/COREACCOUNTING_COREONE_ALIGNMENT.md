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
- A business-integrity audit on the synthetic tenant reported zero critical or error findings. The initial simulation run exposed missing module columns and fixed-ID scenario collisions. Those findings led to tenant-scoped generated IDs and a fresh-tenant run described below; they were not evidence of a clean bill-to-cash flow by themselves.
- The first authenticated login exposed clean-install omissions in the tenant and module catalog schema. The additive `150_tenant_login_base_columns.sql` migration now supplies those base columns and tables when absent; existing installations retain their tables. The staging database's newly created module tables were aligned to its existing tenant-module collation.
- The local staging login defaults to password because staging mail is intentionally disabled. A rejected test login through the Vite proxy showed an explicit credential error. The synthetic administrator subsequently logged in successfully (HTTP 200, tenant `999`) both on the hosted app and through the local Vite proxy. Authenticated account, invoice, income statement and balance sheet requests returned structured HTTP 200 responses through that proxy.
- The development branch's dashboard bundle was built and published to the hosted staging app. Its authenticated root page references the current `index-DSqIOfCK.js` asset, which returns HTTP 200. The local Vite UI is available at `http://127.0.0.1:5176/` and proxies only to this staging backend.
- A synthetic invoice was created as a draft, approved, and posted to the canonical ledger. Posting again returned the same journal. A $75 CSV bank receipt was applied as a partial payment against the $125 invoice; a second $50 receipt closed it. The invoice ended `paid` with two allocations and zero due. Both bank lines ended `matched`, each linked to a distinct posted cash journal. The second CSV replay inserted zero lines and reported one duplicate.
- The first bank CSV import exposed a clean-install schema bug: `scopedUpdate()` finalized the parent import using `updated_at`, which the original bank-import table lacked. It had already inserted a bank line before failing. Migration `151_bank_import_updated_at.sql` adds the column idempotently; `bankRecImportCsv()` now owns a transaction when called outside one, so parent metadata and lines commit or roll back together. The one synthetic, partially imported staging record was repaired in place before matching. The repaired importer succeeded on the second receipt.
- The trial balance, income statement, balance sheet, and indirect cash-flow endpoints returned no data warnings after the receipts. Cash flow reconciled to the GL ($125 change, $0 difference), and the balance sheet reported balanced. The synthetic tenant already contains earlier simulation journals, so its full statement balances are not a clean invoice-only baseline; use a fresh tenant for full cross-report agreement.
- The authenticated API flow is verified. The local preview forwards `/login.html` to the existing CoreFlux sign-in page; there is no new CoreAccounting login. A browser sign-in reached the normal CoreFlux workspace. The staging login note is kept outside the repository at `C:\Users\kunal\.codex\secrets\coreflux-staging-login.txt`; do not commit it.
- The signed-in walkthrough covered the paid invoice, two matched bank receipts, source-linked journals, income statement, balance sheet, cash flow, AR/AP aging, and bill/payment worklists. A paid invoice no longer offers Void, and source-owned journals no longer offer generic edit/delete/reverse actions. Direct API attempts to void the paid invoice or delete/reverse its billing journal returned HTTP 409 without changing the books. The Journal Trace pane now reads canonical posted events; the clean-install HTTP 500 from the legacy journal table is fixed.
- The synthetic simulation runner originally posted invoice and bill events without filling the source document's `journal_entry_id` or `entity_id`; its cleared AP payment only changed the bill's status, leaving no payment allocation. This made aging disagree with the GL and hid the documents in entity-scoped worklists. Future runs now link documents and the cleared AP payment to the canonical journal and entity. Simulation-only, dry-run-first repair commands corrected four existing document links and one payment on tenant `999`. The repair commands refuse non-simulation tenants. A new simulation invariant checks posted source links and payment allocations; it passes on staging with zero mismatches.
- After that repair, AR aging shows $2,500, AP aging shows $2,250, the AP Bills page shows two open bills totaling $2,250 plus one paid bill, and the AP Payments page shows its $2,400 cleared payment. The separate $125 invoice remains paid with zero due. Cash flow ties to the GL with a $125 net change and zero difference. These are synthetic data checks, not a production reconciliation.
- Fresh-tenant staging verification (final simulation tenant `1002`, entity `7`): all five accounting scenarios passed on first run and replay. First run produced six balanced posted journals; replay produced zero new journals. AP due and its GL control account both equal $2,250; AR due and its GL control account both equal $2,500. Income reports $2,500 revenue, $5,500 expense, and $(3,000) net income. The balance sheet balances (assets $(750) = liabilities $2,250 plus equity $(3,000)), and indirect cash flow reconciles a $3,250 cash outflow with zero difference. Negative cash is expected because this synthetic pack has no opening balance. The treasury scenario created a real bank-statement line and verified it was matched to the posted cash journal, including on replay. Tenant `999` still passes the source-link audit. Tenant `1001` retains the earlier event-only treasury test for comparison.
- The scenario seeder now offers `--tenant-only --require-new` for an explicitly non-production environment, refuses to overwrite an existing tenant, and assigns tenant-local entity/calendar/period IDs. The runner resolves logical bill, invoice, payment and entity identifiers to records scoped to the simulation tenant; reruns preserve paid bills and reuse posted events. The scenario's bank-side entries now post to Cash (`1000`), not Clearing Accounts (`1010`). `sim/check_accounting_snapshot.php` is the repeatable report and subledger audit for this pack.
- Still required before operational use: bank CSV import and UI reconciliation against that matched-line state; sending and collecting with staging-safe mail; larger/multi-invoice receipts, failed-allocation rollback, coordinated reversal/refund, and tenant/entity-isolation cases; and a repeatable clean-database bootstrap. The invoice scenario still uses the accepted but deprecated `billing.invoice.sent` event alias; move the production emitter and posting rule together to `ar.invoice.issued` in a separately verified change. Do not enable outbound mail or production integrations here.

## Decisions to revisit

| Decision | Reason | Revisit when |
| --- | --- | --- |
| Keep CoreAccounting on the ERP's canonical accounting, Billing, AP and Treasury modules | CoreOne can embed or call the same book of record; there is no second ledger to reconcile. | Define CoreOne API/package boundary. |
| Run new workflow tests only in simulation tenants on the isolated staging database | A seeded scenario must never move or overwrite another tenant's records. | Automate fresh-database provisioning and teardown. |
| Resolve scenario labels to generated database IDs | IDs are global implementation details; source references and entity scope are the business identity. | Extend the simulator to actual bank lines and multi-entity flows. |
| Treat repeat posting as observed, not newly created, journal output | Idempotent retries are a successful business outcome; the audit separately records new entries. | Add failure-injection and reversal/retry tests. |
