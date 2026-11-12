# Native one-time items and settlement claims

Placement one-time charges now save their recipient, amount, date, currency, Core ownership and required audit in one transaction under the placement lock. Tenant scope comes from the shared placement graph, while authorization remains with the authenticated workspace. Deleted or foreign placements, mismatched recipients and invalid amounts cannot receive items.

Each save carries a unique request identifier. Retrying the same request returns the original item without another audit or duplicate charge. Reusing the identifier for different values is a conflict; replaying a removed item cannot restore it. Separately requested identical charges remain possible. The screen preserves an uncertain request in the tab's session storage, including across reloads, and retries that exact request. A confirmed save updates the visible item immediately even if the surrounding contract cannot refresh.

Contract currency is checked against the rate applicable on the item's date. A future approved rate does not change the currency of an earlier item. AP credits and payroll deductions still use their respective workflows; margin-only adjustments remain separate from settlement.

Removing an item preserves its voided history and commits the required audit together. Source-managed items cannot be removed through this native action. Removal and all economic-item obligation claims use the same placement lock. Claims reject a removed item, changed amount/currency, wrong destination or inactive recipient. A second invoice, bill or payroll reference cannot replace an existing claim. Exact claim replay retains the original obligation and does not regress its state, including a paid state. Releasing the original obligation permits subsequent removal. Other obligation source types retain their existing behavior.

## Evidence

The disposable database fixture passed 49 checks, including AR/AP/payroll claims, native create/remove replay, source refresh, audit rollback, parent-lock contention, invalid inputs and unchanged financial records. The native HTTP suite passed 155 checks, including real cookie authentication, permission downgrade/revocation, tenant isolation, item create/remove, audit failure and repeat requests. It retired its synthetic actor and removed its server/session resources. Eighteen screen tests cover the contract permissions, corporate editor and one-time item recovery, including overlapping submit events. The frontend build completed.

Final runtime commit `de351369d2b04e9b4c76071c993a661cbed0350c` passed all 11 jobs in [CI run 43148470546](https://github.com/coreflux-creator/CoreFlux/actions/runs/43148470546), including 49 database checks and 155 native HTTP checks. An earlier fixture used the wrong argument shape for its synthetic payroll participant; it was corrected, and the failed candidate was never deployed. The final screen additionally prevents overlapping submissions before a rerender.

The isolated installation receipt is dated `2026-11-12T03:34:48Z`. It verified 11 installed files, 41 matching dependencies, the PHP web runtime and exact served screen assets, preserved private configuration, retained a private rollback copy and removed its temporary probe. The hosted verification receipt dated `2026-11-12T03:34:59Z` reached terminal success with all 49 checks passed. Both operations verified all 34,455 original rows across 368 tables unchanged; the regression rolled back every synthetic row. The active screen bundle is `index-CHmo4T_t.js` with secondary bundle `index-C9Kqh6OI.js`. Exact hashes and receipt locations are recorded in `deploy/thunderhawk-native-items-20261111.json`.

No production, provider, Arabella or real financial execution is included in this release. A signed-in operator workflow and real document-to-item reconciliation are not yet proven by these checks.

## Remaining integration work

Native operating-cycle creation/assignment still needs ownership and atomicity alignment. Shared schedule editing, corporate private-record reassociation, source-owned mapped commercial fields, batch and end-client/rate behavior, CSV/lifecycle/activation replay, complete provider field alignment and exchange, portal/onboarding/document acceptance, and billing/AP/AR/payroll result lineage and reconciliation remain within the full integration goal.
