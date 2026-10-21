# Live Core accounting release readiness — October 21, 2026

The user's live invoice screenshot was in **charge d'Affaires**, while the
authorized operating pilot is **Thunderhawk**. Switching the existing Chrome
invoice tab to Thunderhawk immediately exposed its clients. The read-only
production inventory confirms 1,000 active/nondeleted client-role company
records for Thunderhawk. The picker shows 12 suggestions and supports search;
it is not a complete list of the directory. No invoice or client was created.

The existing live accounting dashboard works at
`https://www.corefluxapp.com/modules/accounting/bookkeeping`. It was opened and
verified. `/modules/accounting/overview` on the current live bundle renders
only the module header. The newer candidate has that overview route and a
fallback; commit `08c4eadc` also changes the main Accounting sidebar link from
journal entries to the overview. The updated accounting code remains absent
from production. Opening the old dashboard does not install that code.

## Verified release gap

- Current live frontend: `spa-assets/index-95sB_8ni.js`.
- Candidate frontend: `spa-assets/index-CKJg0DMH.js`.
- [Read-only production comparison](https://github.com/coreflux-creator/CoreFlux/actions/runs/39686986781)
  at candidate `e3fdb0a6f3db698357084add71b65fec7d3350b7` found 385 changed
  files and 4,590 new packaged files (including dependencies/tests). There are
  59 migrations without a matching record in either `_migrations` or
  `coreflux_migrations`; the second ledger does not account for the gap.
- The actual accounting schema lacks 28 required columns/tables/unique keys,
  including journal-void audit fields, credit applications, invoice delivery
  state, receipt journal/bank lineage and entity-scoped bank cash uniqueness.
  Its old tenant-wide bank cash-code unique key is incompatible with the new
  entity-scoped design. This is an actual schema gap, not just a stale ledger.
- All four new integration outbound/authority flags read false from deployed
  CLI configuration. Web/worker configuration still needs verification before
  production activation.
- Three applied historical migrations differ from the candidate, but their
  deployed files match their recorded successful hashes. These are not failed
  migrations. Reviewed differences: 007 adds an idempotent parent-column check;
  041 changes only a comment; 043 replaces ADD COLUMN IF NOT EXISTS with an
  information-schema guard. Preserve this provenance; do not silently relabel
  existing migration records as if the new source had already run.

## Production source and configuration

[Source fingerprint audit](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687312451)
identified seven runtime/SQL files different from both main and candidate.
Every normalized live fingerprint resolves to an existing local Git object:

| Path | Deployed Git blob |
| --- | --- |
| core/config.php | 296510537cedf7c66433479e922cd636023be9de |
| session.php | 098d9312e390785535cf3ba5ec8503507041e8e1 |
| modules/people/lib/companies.php | 90967191c03f82a2c8843834110db37ca2d5d8fa |
| modules/placements/lib/economics.php | 0757876c1b462785b82fee0c07d9df26f167174e |
| core/migrations/007_subtenant_provisioning.sql | 9d2477212bf669aec2d5be613ede9e22a4517dbb |
| core/migrations/041_csv_mapping_presets.sql | 8f8a853bc225dd2a96391c0fb6d0e7c16a2e4bdc |
| core/migrations/043_simulation_harness.sql | 09515e5b299f867e6b120009a5e3982690e082ca |

The company changes add canonical numbers and retain the earlier country fix.
The economics change preserves reviewed vendor identity/classification instead
of overwriting it. Session changes support tab-pinned tenant context. The live
configuration still contains legacy connection settings; the candidate reads
private configuration/environment instead. **Do not replace that file until
the existing DB and mail settings are preserved outside the webroot and the
candidate's CLI and PHP-FPM bootstraps are verified. Do not print their values
or copy them into Git.** Existing encrypted integration configuration and keys
must remain intact. This is a concrete activation prerequisite.

## Validation and remaining work

### Private connection gate completed

Candidate `fa8a1cfca8ece9b584a4f5c7a7da646a50761b99` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39689964412),
including 35 private-settings/HTTP checks on Linux. The subsequent
[production preparation](https://github.com/coreflux-creator/CoreFlux/actions/runs/39690256340)
passed both the real CLI and PHP-FPM read-only connection checks. Private
settings are preserved under `private_html/core-full-release-<candidate>/`;
the intended activation bridge was exercised in the private candidate copy.
The temporary public probe was removed, the live configuration hash stayed
unchanged, and all four new delivery/authority flags were false in PHP-FPM.
The saved CLI receipt intentionally still says `web_verification: false`;
the separate web receipt establishes successful PHP-FPM verification.

No runtime bridge was installed, no migrations ran, and the new accounting UI
is still not active in production. The preparation upload is retained at
`/tmp/core-integration-39690256340`. Local sanitized receipts are saved as
`core-production-settings-39690256340.json` in Temp. The first CI fixture run
failed because its SELECT-only account did not allow the GitHub database
container bridge; that disposable fixture was corrected and all checks passed.
No production settings were used in CI.

### Reviewed data effects of the pending migrations

The 59-file upgrade includes more than empty-table creation. The data-changing
statements have been reviewed in source; production row counts and the exact
maintenance/backup procedure still need to be bound to the final candidate.

- Stable person, company, client, vendor and placement references are backfilled
  from existing record IDs. Existing numbers and merge aliases survive; provider
  IDs are separate. Insert triggers cover future records. Platform export presets
  gain the reference columns while tenant-customized presets are preserved.
- Customer Deposits account 2300 is seeded only if absent. These migrations do
  not create a receipt, invoice, journal or payment. Untouched default invoice
  posting-rule labels change from "sent" to "issued" without changing IDs or
  tenant-customized rules.
- Bill creator attribution is recovered only from its exact CoreOne request and
  credential owner. Approval projections are repaired from existing recorded
  reviewer decisions; stale pending reviewer rows become superseded. This must
  be reconciled against workflow evidence, not treated as new approval.
- Legacy billing contacts acquire an entity only where their tenant has exactly
  one active entity. Multi-entity contacts stay unresolved for human review.
- Bank receipt payment metadata is repaired only where the existing posted
  journal and matched statement line agree. Amounts and allocations are not
  changed by that repair.
- Bank cash-code uniqueness moves from tenant to tenant/entity scope. A NULL
  entity retains scope zero. Journal-void fields and this index conversion have
  a populated-table rehearsal in the existing CI suite.
- Invoice links retain their stored hash while the legacy plaintext token column
  is dropped. A code-only rollback to a version requiring that column is unsafe;
  restore the matching database snapshot as well while writers remain stopped.

Three historical migration sources require explicit retention at release:
007_subtenant_provisioning, 041_csv_mapping_presets and 043_simulation_harness.
Their deployed bytes match the successful ledger entries. Preserve those exact
reviewed sources and ledger rows as release exceptions instead of letting the
web startup runner reapply changed historical files. Do not run either generic
migration runner against production until the exact pending set, these source
exceptions and writer maintenance have been verified together.

Private connection preparation is now implemented separately from activation.
`production_prepare_settings` requires CI success for the exact selected
revision. It verifies the known live configuration fingerprint, copies only
the existing database/SMTP constants into an immutable private directory,
and tests the actual candidate bootstrap with a read-only database query.
It then verifies PHP-FPM access through a short-lived token-protected endpoint
at the canonical Core domain and removes that endpoint in a `finally` block.
The probe returns only readiness booleans and the four integration delivery
flags; it exposes no settings or records. The candidate exercises the same
private-file bridge intended for activation. No bridge is installed into the
live runtime and no migration is performed. Existing or changed private content is never
overwritten. Files use 0640 and directories 0750 with the existing application
group; no connection values appear in receipts or logs.

The disposable MariaDB and HTTP rehearsal passes 32 checks locally, including a
SELECT-only account, special-character preservation, replay, wrong-password
failure, source-row preservation and path/configuration guards. CI additionally
checks POSIX permissions and symlink rejection. HTTP checks cover wrong tokens,
method/host/expiry guards, successful read-only access and sanitized bootstrap
failure. The production result is recorded above. Worker scheduling, exact
migration activation, backups and recovery remain unfinished release gates.

The package selection also now includes CLI cron workers and the updated
daily dunning script. The earlier full-Core package omitted those entrypoints,
including the tenant-scoped PurePay status poll and QBO receipt-recovery poll.
They must accompany their changed libraries. Their production fingerprints
and schedules require review before activation; packaging them does not enable
or run a worker. The first operations-only audit stopped on an incorrect vendor
table name without modifying data; it was corrected to `ap_vendors_index`.

The navigation fix passes 30 journal-UI checks, 55 site navigation checks and
the full dashboard build (61 existing lint warnings). The five tenant-static
checks pass. Candidate `e3fdb0a6` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687000156).
Workflow-only follow-up `2b675418a6cc0bcecc698da53ddb99b35ab36f02` also passed
[all eight jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687322749).
Its [isolated staging release](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687723345)
passed, including the accounting lifecycle, split receipts, credit applications
and invoice-delivery acceptance. Schema/source guards are ready and payroll
authority remains disabled. Production was skipped. The updated sidebar and
accounting overview are staged, not live.

The production inspection modes have no install/migration path. Their private
candidate packages remain under `/tmp/core-integration-39686512795`,
`/tmp/core-integration-39686720751` and `/tmp/core-integration-39686986781`;
retain the needed exact candidate and clean only verified redundant uploads.
Complete configuration preservation, the exact migration/activation procedure,
writer maintenance, verified file/database backups and recovery checks before
requesting the final production-cutover approval. No live records, migrations,
credentials, payroll, payment or invoice issuance were changed by this work.

Local evidence: `thunderhawk-live-invoice-clients.png` and
`thunderhawk-live-accounting-overview.png` (untracked). Full read-only reports
are saved under the local Temp directory as `core-production-preflight-<run>.json`
and `core-production-source-39687312451.json`. GitHub masks some numeric/hash
substrings in logs; resolve source objects using verified unmasked prefixes,
not masked strings as literal IDs.
