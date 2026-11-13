# Placement identity and retained financial history

This is part of duplicate-placement consolidation, not completion of that workflow or the Thunderhawk integration. The preceding access-link reply made no integration progress. This continuation revalidated the clean source checkpoint `31107ebbcedf791fd2568e6eea1b0f295141d6cc` and its successful eleven-job CI run `43499045930`, then implemented the historical readers below.

## Behavior

`modules/placements/lib/identity.php` resolves an authorized physical placement or stored permanent number into the original row and its current canonical row. A merge requires the original row to be archived, its permanent number to carry an alias timestamp, and the current target and its own permanent number to remain active and canonical in the same placement directory. Missing numbers, partial aliases, alias chains, conflicting active aliases and foreign or unavailable targets fail. Ordinary archive does not become a merge. Successive merges use the existing registry's flattened aliases; provider mappings are never rebound by these readers.

Historical PurePayroll input preparation retains the approved time entry's physical placement, frozen rate, permanent number and delivered provider assignment link. It does not substitute the survivor's rate, dates, employment type or assignment. The current placement number participates separately in local review evidence, so consolidation invalidates an unsent pre-merge review while preserving the provider packet's original identities. A new current assignment/link still uses the existing canonical-only operational reader.

Reviewed PurePay financial-line attribution similarly retains the original physical placement and number. It includes optional `target.current_placement` context for an alias. The field dictionary describes that context without granting write authority. Existing encrypted association snapshots remain immutable and readable; the alias does not permit silently reassigning an already-associated source line to the survivor.

## Verification

Local syntax checks passed for all changed PHP files. Existing checks passed: 56 payload-field-index, 643 commercial-field-contract and 20 isolated PurePay endpoint checks. Database acceptance is pending the exact runtime commit's CI run; do not interpret fixture presence as a passed result.

Added canonical-schema checks exercise original/current identity, number lookup, isolation, archive versus merge, missing or conflicting identity, flattened successive merges, preserved provider mappings/rates and rollback. Expanded real-MariaDB payroll and financial-observation fixtures exercise original assignment/rate retention, missing historical links, review invalidation and immutable association history. Independent-process checks wait on actual InnoDB locks during merge, survivor archive and a successive merge, for both commit and rollback.

The installed pilot was read-only verified at `2026-11-13T20:23:12+00:00`: 367 file pins, 378 tables and 35,304 rows match the installed baseline. Configuration is unchanged; temporary reviewer access, credentials and probes remain retired/absent. This work has not been deployed and performs no real payroll, payment or financial posting.

## Remaining consolidation work

Implement the explicit survivor/field-choice review and atomic merge receipt; replace the old automatic duplicate cleanup and mapping deletion paths. Adapt source refresh and remaining operational consumers to retained source identities with explicit ownership. Complete old-number/history navigation, native HTTP and hosted acceptance. The existing duplicate repair must not be used as the reviewed consolidation workflow. The larger integration requirements and pilot upgrade remain open in `thunderhawk-integration-remaining-acceptance.md`.
