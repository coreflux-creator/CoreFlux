# Corporate payee ownership in the Thunderhawk pilot

The C2C participant chosen through Core's Add participant action must agree with the placement's corporate source record and AP vendor. JobDiva may refresh unrelated fields, but it must not replace that choice, reclassify its C2C model, or resurrect a payee removed in Core.

## Implemented behavior

- Native corporate selection reuses the canonical participant ID and retains provider mappings. It records Core identity ownership on the placement and participant.
- The source writer, stored review and reviewed apply use the same ownership decision. Stored apply can retain a valid Core payee even when a proposed JobDiva company has no saved evidence.
- The ownership review displays the payee, Core company number, employment model and active state. A reviewed release changes ownership only. A later separately reviewed source apply can change the payee.
- Terms, paid-when-paid and payment schedules retain their separate ownership. Recreating a removed corporate source row uses retained Core terms when those have not been released.
- Corporate tax and document links prevent identity replacement, removal or source handoff that would detach or misassign those records. This is a refusal pending review, not a completed document reassociation workflow.
- Company-specific address/contact values are cleared when the company changes, so the old company's details do not attach to the new identity.

## Verification and release

The database fixture exercises canonical source import, native replacement, source refresh, employment-model conflict, stored review and apply, stale reviews, atomic audit failure, tenant isolation, private-record retention, explicit release, removal/restoration, replay, and unchanged financial records. Every synthetic database change is enclosed in an outer rollback. Component tests cover displayed identity and the separate confirmation.

Source 7b9d2020450ad333c1d7358120d3235ca8eb79a9 passed all 11 jobs in [CI run 43141432547](https://github.com/coreflux-creator/CoreFlux/actions/runs/43141432547), including 37 corporate identity database checks and the existing 111 authenticated contract checks. The latter retired its synthetic actor, stopped its test server and removed its session files. All 10 ownership-review component tests passed.

The first candidate exposed a preview/apply mismatch: apply still tried to resolve an unused provider company when retaining a Core payee. The correction uses the reviewed Core evidence consistently; the failed candidate was never deployed.

The 11-file paired backend/screen update installed in isolated Core staging at 2026-11-12 02:33:11 UTC, after 37 dependency hashes matched. The web runtime and served asset bytes were verified. The release preserved 368 tables, all 34,455 original rows, private configuration and file ownership; its temporary web probe was removed. The 37-check rollback-only regression then passed against the installed runtime at 02:33:29 UTC and again preserved every original row.

The frontend was built from f3988375 with only CommercialOwnershipReview.jsx changed from the prior verified build ad29b32d; the later backend correction did not change frontend inputs. Exact source, asset and receipt hashes are in deploy/thunderhawk-corporate-identity-20261111.json. These checks do not claim a signed-in operator browser acceptance test or a provider-app release. Production, Arabella, real payroll, payments and financial postings were unchanged.

## Remaining integration work

The separate corporate-details API still needs the same parent lock, ownership and required-audit behavior; its older legal-name edit must not leave a different company binding behind. A reviewed workflow for reassociating private corporate records is also unfinished. Native schedule editing and one-time items still need atomic source ownership. Full field/destination alignment, provider exchange, portal/onboarding/document workflows and financial-result reconciliation remain part of the original integration goal.
