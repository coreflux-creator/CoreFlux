# Stored JobDiva contract ownership review

The later [native commercial edit release](thunderhawk-native-commercial-edit.md)
addresses participant PATCH atomicity and canonical bill-to propagation. The
remaining-scope list below records what was open at this review release.

Published to isolated Core staging on November 11, 2026 from
`bd9c6817612ea45f23f79b1780e3e01b4de0a35e`. All eleven jobs passed in
[release CI](https://github.com/coreflux-creator/CoreFlux/actions/runs/43119568597).
This advances the Thunderhawk integration; the full goal remains incomplete.

Stored assignment preview now shows each current Core value, JobDiva proposal,
and handling decision. A differing Core-managed field is labelled **Retain Core
value**. An unowned difference remains a source proposal subject to contract
validation and rate approval. Blank values and false values stay distinct.
Malformed ownership metadata blocks the affected row for review.

The selected-row confirmation states that Core values will be retained where
JobDiva differs. The result includes retained-field counts and expandable
current/source details per placement. The stored reconciliation audit receives
the same retained differences. A failed or uncertain apply clears the selection
and requires a successful new preview before another attempt. A failed refresh
after successful apply also prevents blind replay; cancelling confirmation
sends no apply request.

Stored apply now uses a savepoint when called inside an existing transaction,
so it cannot commit its caller's other changes. Existing checks invalidate the
preview token when placement ownership or participant ownership changes before
apply. They do **not** establish protection against a concurrent change after
token validation and before the write transaction. That race remains open.

## Verification and release

The disposable database run at `af3efd1118fe99a1ff538ca8d10315da9a30edc1`
passed all 16 stored-review checks, 28 commercial-ownership checks, 27 native
placement-edit checks, 30 lifecycle-ownership checks, 17 earlier ownership
checks, 13 ending checks and 17 request checks. The prior commercial ownership
gap was reproduced before restoring the candidate. All 206 existing containers
were unchanged, and the temporary test resources were removed. Backend source
and the database fixture were unchanged between that run and the released
commit. Later changes corrected a stale static UI assertion and misleading
confirmation/result wording; the frontend was rebuilt from the release commit.

Five React interaction tests cover the real reconciliation component with an
isolated API fixture: retained/proposed values, confirmation and result details,
cancellation, failed apply followed by refresh, and failed refresh after a
successful apply. All 19 canonical projection smoke assertions passed. A local
synthetic page rendered from the actual field-review component was inspected
in BrowserOS, including retained dates/terms, unowned city, false and blank
values. This is component-level browser evidence, not a signed-in native HTTP
workflow walkthrough.

The paired release installed two PHP files, two JSX source files, two built JS
assets, three HTML entry points and the service-worker version. It verified
34 dependencies, exact target preimages, private configuration, file ownership,
PHP cache behavior and served asset fingerprints. The previously served bundle
was pinned to its recorded build, and only the two review JSX inputs changed
relative to that build. Older archived JSX files on the host were not treated
as evidence of the served screen. Private rollback copies were retained.

Installation preserved all 368 database tables and 34,356 existing rows. The
exact deployed preview/apply functions then passed all 16 review checks in
simulation tenant 2002. Synthetic rows were enclosed in an outer transaction
and rolled back; independent before/after fingerprints preserved every
original row. Auto-increment gaps are allowed. The temporary web probe was
removed. No provider runtime, production application, Arabella pilot, real
payroll, payment or financial posting was changed.

The machine-readable evidence, hashes and original receipts are indexed in
`deploy/thunderhawk-contract-review-20261111.json`.

## Remaining integration work

- Lock and revalidate reviewed state across the complete apply operation;
  expose retained decisions in generic automatic/batch reconciliation and
  verify the signed-in native HTTP workflow.
- Make native economic-party edits atomic with placement/corporation summaries
  and required audit. The API's AR propagation still checks `end_client`, while
  the graph creates `bill_to_client`; reviewed participant ownership release
  also needs acceptance.
- Verify explicitly cleared end-client chain records, rate-field ownership,
  cycle anchors, new-placement graph failures, CSV edits, ordinary terminal
  status/date invariants and activation replay.
- Reconcile stale field-dictionary verification labels against the newer
  evidence; complete destination/custom-field alignment, portal access,
  onboarding/documents, financial-result attribution and billing/AP/AR
  reconciliation.
