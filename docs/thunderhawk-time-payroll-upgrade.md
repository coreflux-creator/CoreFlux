# Combined Thunderhawk pilot upgrade

## Prepared application release — November 14, 2026

The application package is bound to `846e8620`, which passed all eleven
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43570265181).
It contains 140 changed server files and nine browser/deployment files. The
four browser assets come from that run's verified build, including the latest
payroll review screen. Another 309 installed dependency fingerprints are retained.
The old browser assets remain available for existing sessions.

A read-only inspection at 07:30:23 UTC confirmed the existing isolated pilot:
367 recorded runtime fingerprints, 378 tables, 35,304 rows and 345 migration
records, with unchanged private configuration. None of the 140 server-file
preimages is unexpected. There are seven pending migrations and no recorded
migration errors. Ten apparent old migration-hash differences are solely LF/CRLF
line endings; none requires replay or a ledger rewrite.

The seven migrations cover active company-name uniqueness, client source
ownership, issued external-review facts, imported time evidence, retained time
economics/accounting handoffs, approved-source payroll earnings, and the exact
payroll calculation review identity. Existing payroll history receives default
legacy classification and no invented source receipts or approval seals.

## Populated upgrade rehearsal

Upgrade tools `8f943974` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43572151001),
including **29 populated upgrade database checks**, the 32 payroll review checks,
22 source-earnings checks, 65 identity checks, 944 independent-connection checks,
953 native-session checks and 917 native HTTP checks. Synthetic cleanup passed.
[Pinned preparation evidence](../deploy/thunderhawk-time-payroll-upgrade-preparation-20261114.json).

The first rehearsal
correctly exposed MariaDB's refusal to create new tables under an existing
explicit table-lock set; no hosted upgrade was attempted.

The revised procedure prepares only the four absent, empty evidence tables,
then takes native locks covering those tables and the existing database. It
rechecks the original rows after locking before applying any changes to existing
tables. Independent writers must remain blocked through implicit-commit DDL.
The rehearsal includes populated companies, an existing external approval token,
approved payroll, maximum legacy decimal hours, null earning hours, and unrelated
records. A forced partial failure must retain the completed migration receipt,
an explicit incomplete marker, original business values, and refusal to rerun
the operation as if it had never started.

## Installation still required

No application files, schema, private settings or provider records have changed
on the hosted pilot. The prepared package is not an installation receipt.
A second read-only host inspection found owner-preserving write access to the
pilot web files, native backup/restore tools, no database events and no other
active database connection at that instant. All nine observed master scheduler
lines reference production; none directly names this pilot. No maintenance hold
or backup was started.

The installation still needs its current maintenance and writer observations,
retained file/database backups, a guarded installation entry point, cache refresh,
restored access, and signed-in acceptance of the resulting integration.
The schema helper does not claim transactionally reversible DDL or automatic
recovery from a partially completed migration.

The full person/assignment/onboarding/time/payroll-result round trip remains
required, along with the outstanding field, financial ownership, correction and
reconciliation acceptance in the main integration checkpoint. Production and
Arabella's separate accounting pilot are unchanged.
