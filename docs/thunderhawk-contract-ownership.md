# Core commercial ownership during JobDiva reconciliation

The later [stored contract review release](thunderhawk-contract-review.md)
adds operator preview/result handling and records its separate verification.
This document retains the scope and evidence of the earlier backend release.

Verified in isolated Core staging on November 11, 2026. Runtime source
`467c3c8412dceac3fe3924c320593f878f1bf04e` passed all eleven jobs in CI run
`43115925800`. Four PHP files were installed after exact source preimage,
private configuration, file ownership and 30 dependency checks. The screen
bundle and provider runtimes were unchanged. The full integration goal remains
open.

Source-contract reconciliation no longer removes Core ownership of placement
classification, dates, client identities, cycles or payment terms. The same
protection applies to the explicit payment-term, paid-when-paid and cycle
flags on active commercial participants. The main bill-to party can protect
the canonical billing summary; an additional receivable participant or an
inactive party cannot acquire that authority.

Existing placement reconciliation now uses the retained canonical dates and
end-client identity for its child writes. A source employment model that
conflicts with a Core-owned model cannot write its rates, payee, referral,
commission or source allocation records into the retained model. Unrelated
source-owned fields continue to refresh. Failure to reconcile an existing
placement's commercial graph rolls back its enclosing source transaction.

The projector translates canonical ownership into the contract proposal's
display fields. Retained Core terms are excluded from failed-write retries,
and differing retained values are returned in its `ownership_conflicts`
metadata. This is an internal result contract: the reconciliation API's
aggregate results and rendered screen still need to expose these decisions.
No claim of completed operator conflict review is made by this release.

The final committed disposable test reproduced the old ownership loss by
substituting the earlier writer and economics library, then restored the
candidate and passed 28 commercial-ownership checks. It also passed the
active bill-to scope checks, 36 rate smoke checks, 27 native edit checks,
30 lifecycle ownership checks, 17 earlier ownership checks, 13 placement
ending checks and 17 request checks. Temporary containers, network and volume
were removed. All 206 pre-existing containers were unchanged, and the
coordination hold was released after terminal cleanup.

The first test versions exposed fixture mistakes: the staffing-client helper
returns `client_id`, corporation rows use `placement_id`, and source rate
examples require explicit units and currency. Those inputs were corrected;
the original failure receipts remain available. An older source smoke checked
for the original source-date variable; it now verifies that updates use the
retained canonical date under the employment-model guard.

Installation preserved all 368 database tables and 34,356 existing rows and
removed its temporary PHP cache probe. The exact deployed functions then
passed the same 28 commercial checks. Synthetic business rows were enclosed
in an outer transaction and rolled back; independent before/after fingerprints
preserved every original row. Auto-increment gaps from rollback are allowed.
This is deployed-function acceptance, not a new native HTTP or browser test.

Evidence and runtime fingerprints are recorded in
`deploy/thunderhawk-contract-ownership-20261111.json`.

The next work includes showing retained values in reconciliation review and
making native commercial-party edits atomic with their placement/corporation
summaries and audit. The native economics API still checks the older
`end_client` role when propagating AR terms, while the graph creates
`bill_to_client`. Reviewed participant ownership release, explicit end-client
clearing, source-owned rate fields, cycle anchors and new-placement graph
failure handling also require acceptance. Native HTTP/rendered controls, CSV
editing, terminal status/date invariants, activation replay, full field and
custom-field alignment, portal/document workflows and financial reconciliation
remain part of the original goal.

No production cutover, real payroll, payments, financial postings or changes
to the separate Arabella accounting pilot occurred.
