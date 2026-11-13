# Current directories and concurrent assignment projection

Scope: source preparation for the authorized isolated Thunderhawk pilot. No hosted installation or provider writes occur in this change. Arabella's standalone accounting pilot remains separate.

## Behavior

Economic graph reconciliation now begins its transaction before resolving the current placement owner and that owner's company and people directories. It locks the placement, referenced companies and derived source rows through the rebuild. An older transaction snapshot cannot supply obsolete corporation, chain, referral or commission rows. Standalone participant and vendor writes also validate the current company directory under their write transaction. Participant display reads resolve the current directory rather than a process cache.

The intrinsic placement owner still determines the shared graph's catalog; a viewer cannot retarget that graph to the viewer's private companies. A policy change can make an existing reference invalid, in which case reconciliation fails and rolls back its whole rebuild. Newly imported records use the new directory. Retained provider mappings and historical identities are not migrated automatically.

## Verification

Source `91969a41` passed all eleven jobs in [CI 43452111484](https://github.com/coreflux-creator/CoreFlux/actions/runs/43452111484): **39 projection checks**, **28 graph-owner checks**, **143 independent-connection checks** and **953 native session/API checks**, plus installation, packaging and guarded deployment/recovery. Native test access, private sessions and the owned test server were cleaned up. [Pinned source and result evidence](../deploy/thunderhawk-derived-economics-preparation-20261113.json). One older mock database needed transaction support after reconciliation moved its reads inside a transaction; the corrected smoke check passes both locally and in CI. This is source verification, not hosted release acceptance.

The expanded independent-process fixture executes the real full JobDiva projector while another MariaDB connection commits or rolls back Companies/Placements policies or a reviewed company merge. It covers both operation orders, preserved provider IDs, a stale merge review after a newly committed assignment, a completely rolled-back assignment, and an old repeatable-read snapshot. A separate C2C rebuild test changes merged recipients and chain/referral terms after the reader's snapshot, then checks the current resulting parties.

The latest read-only installed-pilot check at `2026-11-13T15:21:00+00:00` matched all 367 pinned runtime files and 35,304 rows across 378 tables. Configuration, retired test access and removed temporary credentials/probes were also checked. Installed runtime remains `4bc10bb0`.

## Next integration gates

Reviewed rule handback still conflates the placement owner and original source workspace in `placementSourceRuleStoredSource` and `placementSourceRuleProject`. It also assumes the displayed external ID is `jd:<Start>`, although reviewed legacy IDs must be retained. The next change must resolve the exact saved provider pair, preserve the original workspace for mirrors/configuration/history, and use the locked business owners for rule rows and configured mappings. Its review token must cover source identity and current directory policies; stale review and duplicate-request behavior require native database and concurrent checks.

Related provenance gap: the derived graph still labels its source by the displayed `external_id` prefix. A mapped JobDiva placement retaining a spreadsheet external number therefore needs its economic source provenance resolved from the saved provider pair too. Add an explicit legacy-number/provenance regression alongside the handback change; preserving the displayed number alone does not verify that provenance.

The existing wider release gates remain: CSV/legacy and AP/current-reference writers, native job ownership and lifecycle provenance, survivor/history review, stable outbound QuickBooks customer identity, followed by guarded pilot installation and hosted acceptance. Field alignment, onboarding/documents, time/approval and financial-result reconciliation still require the full integration acceptance audit. The overall goal is active and incomplete.
