# Combined Thunderhawk pilot upgrade

## Installed and native-session accepted — November 14, 2026

The isolated pilot now runs application `846e8620`. Installer `6e2ac9e0` passed
all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43576895462),
including 17 native separate-account channel checks and 35 populated database
upgrade/recovery checks. A fresh hosted preflight passed; operation
`ccfb4a0aba16f80e034763b042ed5d45` completed at **08:50:39 UTC**. It retained
private file/database backups, applied seven migrations, installed 149 files,
refreshed only the pinned PHP cache entries, restored web access and the unchanged
scheduler, and removed its diagnostic. [Installed evidence](../deploy/thunderhawk-time-payroll-installed-20261114.json).

Independent read-only verification at 08:50:47 UTC checked every installed file,
309 retained dependency fingerprints and a fresh runtime load. All original
378 tables and 35,304 rows were preserved. The resulting database had 382 tables
and 35,311 rows: four empty evidence tables and seven migration receipts were
added. Those counts precede the synthetic acceptance records described below.
Private configuration and the retained database backup matched their hashes.

A fresh isolated synthetic company completed **31 native-session checks through
20 HTTPS requests**: two real logins, time creation/submission, independent
approval, stale and repeated decision refusal, payroll source reservation,
calculation, independent approval of the exact saved calculation, and parent
company isolation. Four approved hours at 60 yielded gross 240.00 despite a
different compensation rate of 30. Existing records were preserved and the
accounting-event, journal and payment tables did not change. Payroll execution
and automatic posting were disabled for the fixture. The two accounts and
their company were retired, grants removed, credentials cleared and cookies
deleted. This is authenticated HTTP acceptance, not browser visual acceptance
or a provider round trip.

The first walkthrough used an incorrect nested field when checking the approval
response. A separate read-only inspection verified its saved independent
approval, fingerprint and absence of posting/payment. Its retired fixture was
not replayed. A fresh fixture with the corrected assertion passed all 31 checks;
no application correction was needed.

The complete Quanta/PurePay/PurePayroll round trip, remaining ownership/field and
financial lifecycle work, generic payroll template export and browser acceptance
remain open. Production and Arabella are unchanged; the full goal is incomplete.

## Hosted attempt restored before schema work — November 14, 2026

The exact installer at `25ba5241` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43575413820),
including **35 populated upgrade checks**, native backup and recovery after a partial
migration, 32 payroll-review checks and 22 approved-earnings checks. Its hosted
preflight passed for 149 application files, 309 retained dependencies and seven
migrations. This corrected an earlier preflight-only variable collision with the
application's database bootstrap; that earlier attempt never started maintenance.

The installation then stopped at the web-worker drain check, before backup or
schema changes. Its pre-schema recovery restored the original web routing and
scheduler and removed its diagnostic. Independent readback at 08:32:57 UTC
confirmed every application preimage, the private configuration and migration
ledger, with the same **378 tables and 35,304 rows**. The original operation is
terminal, `aborted_before_schema`; it must not be replayed.
[Recorded attempt](../deploy/thunderhawk-time-payroll-installation-attempt-20261114.json).

Native process inspection identified deployment UID 1003 and FPM UID 1009.
A short-lived, authenticated diagnostic also established that the web runtime
disables `posix_geteuid`, while native FPM status and targeted OPcache controls
are available. Both temporary inspection files were removed. Thus the earlier
same-owner diagnostic cannot run on this host; changing backup permissions is
not required or appropriate.

Correction `6e2ac9e0` supplies an inert diagnostic containing only fixed candidate
and recovery file hashes and the pinned diagnostic functions. It reads no private
files, bootstraps no application and exposes no database credential. Its caller
independently verifies the reported native process belongs to UID 1009 and the
expected pilot pool. A bounded status observation permits existing requests to
finish, while cache actions are never automatically replayed. A separate native
Apache/FPM fixture reproduces distinct owners, an unreadable private directory
and disabled POSIX access; it checks authentication, expiry, exact holds, candidate
and recovery refresh, and preservation of unrelated cached PHP. Its later
successful CI and installation are recorded above; this earlier aborted attempt
remains an immutable recovery receipt, not the successful installation.

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

## Earlier host preparation, before installation

Before the successful installation above, the prepared package and restored
maintenance attempt did not constitute an installed release.
A second read-only host inspection had found owner-preserving write access to the
pilot web files, native backup/restore tools, no database events and no other
active database connection at that instant. All nine observed master scheduler
lines reference production; none directly names this pilot. The later maintenance
attempt is recorded above; it did not reach the backup phase.

The successful operation subsequently completed maintenance and writer checks,
retained file/database backups, the guarded installation, cache refresh,
restored access and the native-session acceptance described above.
The schema helper does not claim transactionally reversible DDL or automatic
recovery from a partially completed migration.

The full person/assignment/onboarding/time/payroll-result round trip remains
required, along with the outstanding field, financial ownership, correction and
reconciliation acceptance in the main integration checkpoint. Production and
Arabella's separate accounting pilot are unchanged.
