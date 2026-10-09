# CoreAccounting package QA 3 acceptance

Date: 2026-10-09. This is evidence for an isolated, disposable Cloudways app, not authorization to release CoreAccounting to a real company. CoreAccounting uses the existing CoreFlux/CoreOne accounting modules and ledger; this QA app has its own test database so financial experiments cannot affect the ERP.

## Clean install and accounting

Cloudways app `6718294` (`CoreAccounting Package QA 3`) and database `hxyjsupwbz` began with zero tables. An anonymous Reports API request returned 401 and left the database empty. Verified archive `03105f63` was installed with app-specific Apache settings and a private database file. Guarded CLI bootstrap installed 22 prerequisites and 281 canonical migrations, verified 27 required tables, 166 columns and 13 financial/security unique keys, then provisioned one invented tenant, administrator and legal entity. Four invoice, bill, AP-payment and bank scenarios passed twice for independent simulation tenant `999`; replay created no additional journals.

The final migration audit matched all 281 installed files with zero drift. The simulation tenant retained five posted journals, AR `$2,500`, AP `$1,500`, assets `$1,525` equaling liabilities `$1,500` plus equity `$25`, and a zero cash-flow/GL difference. A further replay of all four scenarios created zero journals and passed 36 assertions. The event registry still warns that deprecated `billing.invoice.sent` should be renamed to canonical `ar.invoice.issued`.

## Package and public boundary

The first outside-in scan **failed**: 451 legacy or Composer static files were served outside the intended 17-file set. The package build also revealed three dashboard icon references absent from Git. The source now derives active icon paths from the served bundle, supplies those three assets, moves unused static files out of a standalone webroot, and supports an exact-identity private Composer move. It recognizes that the shared ERP's manifest link is disabled in standalone mode while refusing to retire a manifest used by the active app. The failed builds are not acceptance evidence.

The corrected release is commit `69555ec4d3bac08a96c7380affc3ca96cd2659ac`. CI run `37879132016` passed all eight jobs, including extracted-package verification and a separate empty-database install. Archive SHA-256: `90decccf9fffb5754837dbe380ba33f79e9ac36617cacfecddaca9f47aa11bea`. External manifest SHA-256: `31a781657cb2b2828e4269f50d83a41d4da458ad82618853cf6488d54b0c3e03`. The host verified all 5,663 extracted files against that manifest, with no missing, extra or changed files and 25 expected public files.

Before the six-file update, the first installed archive still matched its package bytes except the host `.htaccess`; the three replaced scripts were backed up privately. The new archive was overlaid without deletion, and a checksum-only check found no remaining difference in its files other than `.htaccess`. The standalone finalizer's dry-run and real run agreed on 180 entries moved to a private archive. Composer dependencies were moved outside `public_html`, leaving an autoload shim. Cloudways cache was purged for **this app only**.

The post-purge outside-in audit checked 359 remaining non-PHP paths and 610 retired paths from the static and vendor archives: exactly 25 intended files returned 200, 290 protected paths returned 403, and 654 current or retired paths returned 404. Unexpected served files, missing expected files, byte mismatches, unexpected statuses, stale retired URLs and transport errors were all empty. Direct requests to the unrelated People API and signup returned 404; a signed-out accounting Reports API returned 401; the repaired time icon returned 200.

## Signed-in review and remaining gates

The first invented tenant loaded Overview, Reports, Income Statement, Invoices, the direct invoice form, Bills, Banking review, Entries and the new journal form without visible API errors. The invoice form accepts a custom fixed-fee line without placement or time; navigation retains colored icons. This company intentionally has no posted financial records, so its statement shows zero and the bank list is empty. This was a focused navigation check, **not** a two-person operational rehearsal or real-history tie-out.

The package still needs guarded post-upload host configuration, static finalization, Composer privatization and cache purge; those steps are not one atomic installer. Real-provider recipient delivery, non-synthetic company reconciliation, independent human approval checks, production private settings, and rotation of legacy credentials remain open. Production and populated staging were not changed.
