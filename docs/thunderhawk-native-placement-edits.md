# Atomic native placement editing

Verified in isolated Core staging on November 11, 2026. Runtime source
`813671eee5ad2539933dcbe359c2050fda93c751` passed all eleven jobs in CI run
`43112314687`. Three PHP files were installed after exact preimage, private
configuration, file ownership and 31 dependency checks. This release did not
change the screen bundle or any provider runtime. The full integration goal
remains open.

Ordinary PATCH, bulk status, bulk field editing and explicit activation now
call one native edit helper. It locks the current placement before validating
ownership, resolving company links, changing values or approving rates. Values,
ownership flags, company creation, rate promotion, activation readiness,
commercial graph updates and required audit receipts share one transaction.
An exception rolls back the edit without rolling back an outer caller's work.
Bulk operations finish each placement transaction before reporting its result.

The helper retains existing ownership and refuses malformed ownership instead
of treating it as empty. Selecting an unchanged source-owned field still claims
that field for Core. Replaying an unchanged, already-owned ordinary field edit
adds no record, commercial-party or audit changes. Normal native editing cannot
change a JobDiva placement's existing source assignment, person or source job
identity; those require review through the identity-linking workflow.

Draft promotion retains financial-approval permission checks. Rates are approved
for active transitions and eligible pending/on-hold promotions, inside the edit
transaction. Moving a draft to a terminal status does not itself approve rates.
Activation readiness evaluates the proposed placement values. A failed
activation rolls back the approved rate as well as the status, ownership and
audit changes. Successful promotion retains its normal rate-approval behavior.

The disposable database fixture passed 27 native edit checks, 30 lifecycle
ownership checks, 17 earlier ownership checks, 13 placement-ending checks and
17 request checks. Its private containers, volume and network were removed;
all 206 pre-existing containers were unchanged. The coordination hold with the
PurePay chat was released after the original fixture process returned success.

The initial CI candidate's database fixture passed, but three source-location
checks still looked for company resolution and readiness in the API file.
They now follow those responsibilities into the shared native edit helper.
The runtime and real database fixture were unchanged between that candidate
and the all-green release.

Installation preserved all 368 database tables and 34,356 existing rows and
removed its temporary PHP cache probe. The exact deployed functions then
passed the same 27 native edit checks. All synthetic business rows were rolled
back, and independent before/after fingerprints preserved every existing row.
Auto-increment gaps from rolled-back inserts are allowed. The fixture uses
real legacy RBAC permission checks in its own PHP process to exercise synthetic
admin rate promotion; it changes no account role or hosting setting. This is
deployed-function acceptance, not new native HTTP or browser acceptance.

Evidence and runtime fingerprints are recorded in
`deploy/thunderhawk-native-edit-20261111.json`.

The next source-ownership gap is concrete: four source-contract reconciliation
callers enable `force_source_contract`, whose canonical writer can still remove
ownership for classification, dates, clients, cycles and payment terms. Its
economic options can also carry source payment terms into the graph. That
authority needs an explicit review boundary. CSV editing, ordinary terminal
status/date invariants, activation replay, native HTTP and rendered controls
still require acceptance. The remaining field/custom-field, portal/document
and financial-reconciliation requirements remain open as well.

No production cutover, real payroll, payments, financial postings or changes to
the separate Arabella pilot occurred.
