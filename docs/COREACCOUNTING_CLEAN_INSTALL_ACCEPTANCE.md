# CoreAccounting clean-install acceptance

This is a staging-only acceptance sequence for a **new, disposable Cloudways application and database**. Do not point these commands at the populated CoreFlux staging database or any production database. Save the new app/database identity and the result of each command with the release evidence.

1. Provision a separate Cloudways application, database user, and empty database. Give the app its own `core/db.local.php` and unique data-encryption key. Keep all credentials out of Git and the acceptance log. Verify that its database name differs from the existing staging and production databases.
2. Deploy the exact CoreAccounting branch release to that app. Install Composer dependencies, then run the guarded `deploy/finalize_coreaccounting_qa_webroot.php` with `COREFLUX_ENV=staging`, `--confirm-disposable-staging`, an exact `--webroot` and matching `--expected-webroot`, and a new absolute `--private` directory outside `public_html`. Its parent must already exist. The finalizer moves root notes, Composer/build metadata, raw UI source, GraphQL and legacy source into that private location while preserving the compiled SPA, PHP APIs and Composer runtime. Do not use it on a production installation. Cloudways Hybrid Stack can cache static files before Apache reads `.htaccess`; do not treat a deny rule alone as proof that a file is private. Purge the application's site cache and verify direct requests to the removed source paths return 404 while `/index.html` and its current JS/CSS return 200. Confirm the app's `COREFLUX_ENV=staging` configuration and its public origin before opening it in a browser.
3. In the new app root, inspect and bootstrap only if the database reports `existing_tables: 0`:

   ```sh
   COREFLUX_ENV=staging php deploy/bootstrap_coreaccounting.php --inspect
   COREFLUX_ENV=staging php deploy/bootstrap_coreaccounting.php --confirm-empty-staging --database=NEW_DATABASE_NAME
   COREFLUX_ENV=staging php deploy/bootstrap_coreaccounting.php --verify-schema
   ```

   The schema check must report 26 tables, 152 columns, 11 financial unique keys and no missing requirements. Stop on any error. Schema changes are not transactional in MySQL; a partial install requires a **new** empty disposable database, not a retry against the partly populated one.
4. Set the `COREFLUX_INITIAL_*` tenant, administrator, entity, fiscal-year and password environment values for invented staging identities, then run `COREFLUX_ENV=staging php deploy/provision_coreaccounting_tenant.php --confirm-first-tenant --database=NEW_DATABASE_NAME`. Verify one tenant, one active administrator, a legal entity, fiscal periods, system accounts, posting rules and a migration ledger with no failed hashes. Do not log the password.
5. Seed an independent simulation tenant, then run invoice, bill, payment and bank scenarios once and again with the same seed. The second pass must create no duplicate posted financial event:

   ```sh
   COREFLUX_ENV=staging SIM_TENANT_ID=999 php scripts/ci_seed_sim_tenant.php --tenant-only --require-new
   COREFLUX_ENV=staging php sim/runner.php --scenario=ar_invoice_happy_path --seed=42 --tenant=999
   COREFLUX_ENV=staging php sim/runner.php --scenario=ap_bill_happy_path --seed=42 --tenant=999
   COREFLUX_ENV=staging php sim/runner.php --scenario=ap_payment_canonical_lifecycle --seed=42 --tenant=999
   COREFLUX_ENV=staging php sim/runner.php --scenario=treasury_bank_feed_categorize --seed=42 --tenant=999
   COREFLUX_ENV=staging php sim/check_accounting_snapshot.php --tenant=999 --entity-code=SIM
   ```

6. Sign in through the normal CoreFlux login as the first staging administrator. A second human reviewer must be added before claiming maker/checker acceptance: the first-tenant AP policy is intentionally not a self-approval bypass. Exercise draft, review, posting, partial collection, bank matching, bill payment, reversal, aging and statements through the installed UI. Re-run the financial snapshot after those actions.

This gate passes only with the command outputs, a balanced snapshot, a served-release hash, and the signed-in source-to-report walkthrough from the **new** database. Static schema tests and a populated staging installation do not substitute for it.

## Standalone runtime after staging acceptance

The staging sequence above continues to use `COREFLUX_ENV=staging` and log-only delivery. A later standalone CoreAccounting app must explicitly set `COREFLUX_ENV=coreaccounting`, provide its own server-local `core/db.local.php`, set `COREFLUX_STANDALONE_DATABASE` to that app's exact database name, and set `COREFLUX_PUBLIC_ORIGIN` to a bare HTTPS origin. Startup refuses a missing or mismatched database identity or an absent/insecure origin, even on a Cloudways default hostname. This mode is not a second ledger: it runs the existing CoreFlux/CoreOne source documents and accounting tables in the standalone app's database.

Standalone mode does not inherit the ERP's SMTP credentials or sender. Direct SMTP fallback requires its own `COREFLUX_ACCOUNTING_SMTP_HOST`, `COREFLUX_ACCOUNTING_SMTP_USER`, `COREFLUX_ACCOUNTING_SMTP_PASS`, and `COREFLUX_ACCOUNTING_FROM_EMAIL`; it refuses a copied `SMTP_*` constant or a different caller-supplied From address. The Resend/outbox path accepts only `COREFLUX_ACCOUNTING_RESEND_API_KEY`, `COREFLUX_ACCOUNTING_FROM_EMAIL`, and optionally `COREFLUX_ACCOUNTING_FROM_NAME`; tenant sender resolution uses those same accounting settings, and the provider refuses an override with a different From address. A copied ERP key or sender is ignored, and missing dedicated credentials fail visibly. A controlled recipient test is still required. These guards do **not** remove the legacy credentials from the existing ERP release, rotate them, prove a hosted clean install, or authorize live customer mail or production deployment. Coordinate secret externalization and rotation before switching a real tenant to standalone mode.
