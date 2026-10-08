# CoreAccounting clean-install acceptance

This is a staging-only acceptance sequence for a **new, disposable Cloudways application and database**. Do not point these commands at the populated CoreFlux staging database or any production database. Save the new app/database identity and the result of each command with the release evidence.

1. Provision a separate Cloudways application, database user, and empty database. Give the app its own `core/db.local.php` and unique data-encryption key. Keep all credentials out of Git and the acceptance log. Verify that its database name differs from the existing staging and production databases.
2. Deploy the exact CoreAccounting branch release to that app. Confirm the app's `COREFLUX_ENV=staging` configuration and its public origin before opening it in a browser.
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
