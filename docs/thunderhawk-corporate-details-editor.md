# Reviewed corporate details editing

The separate C2C corporate-details editor now uses the placement's shared graph scope while retaining the authenticated user's corporate permissions. Saves lock the parent placement, require a current review for an existing record, and commit company resolution, corporate metadata, AP vendor, economic participant, ownership and audit together.

The screen sends only edited values, the displayed legal name and the review token. Unsubmitted details remain unchanged. A confirmed save response supplies the next view and token directly. An uncertain save or stale review requires an explicit successful reload; a rejected field can be corrected without discarding the rest of the form.

Changing the corporate identity resolves the new company and vendor while reusing the canonical participant ID. The former company's address/contact values are cleared. Existing provider mappings are preserved. Private tax and document bindings prevent identity replacement until those records receive a separate review.

Core retains this corporate payee and its existing payment setup during source refreshes. Custom schedules retain their IDs and anchors. Returning identity, terms or schedule ownership remains a separate contract review. Custom corporate field mappings also honor this ownership, including inactive manual participants, and report retained fields as skipped. A generic name mapping cannot rename an already-bound corporation independently of its canonical company review.

EIN writes use the existing encryption key and only return the last four digits. Empty input preserves the existing encrypted value; equivalent formatted input is an unchanged save. Neither the corporate save audit nor the participant terms audit copies the plaintext EIN or ciphertext. Document references must belong to this placement and workspace.

## Verification

The disposable database fixture exercises partial updates, retained custom cycles, stable company/provider/participant identities, malformed and stale input, encrypted EIN round trips and replay, document scope, source refresh and custom mapping retention, audit rollback, parent-lock contention, deleted/model/tenant restrictions and unchanged financial records. The native HTTP suite exercises the actual corporate routes with login, permission changes, tenant refusals, successful partial saves, replay and required-audit failure.

Runtime commit `ae78441336fd0edd2f3d1341a04c92fff78a7dce` passed all 11 jobs in [CI run 43144952965](https://github.com/coreflux-creator/CoreFlux/actions/runs/43144952965): 39 corporate database checks, 130 native HTTP checks and 11 contract-screen component checks. The native HTTP fixture retired its synthetic actor, stopped its owned server and removed its private session files. An earlier candidate failed because its synthetic source payload omitted the required assignment status; that fixture was corrected before deployment. The editor also uses confirmed API responses instead of assuming the generic reload hook propagates failures.

The isolated staging installation completed at `2026-11-12T03:06:04Z`. It verified 11 installed files and 38 dependency fingerprints, retained the previous files privately, verified the served screen assets and PHP web runtime, preserved private configuration, and removed its temporary verification endpoint. All 34,455 original rows across 368 tables were unchanged. The subsequent hosted regression completed at `2026-11-12T03:06:17Z`, passed all 39 checks, rolled back its synthetic records and again verified every original row. Existing private encryption configuration was used without replacing its key.

The rebuilt screen uses `index-NwkK_JZ3.js` and `index-Bbk7cNJw.js`, built from `8aca42a23d42ccdf63a32e65bb98baf270415198`; the final runtime commit adds the custom mapping guard without further frontend changes. Evidence is recorded in `deploy/thunderhawk-corporate-editor-20261111.json`. This release changed only isolated Core staging. Production, provider apps, Arabella, real payroll, payments and financial postings were untouched. A signed-in operator walkthrough has not yet been claimed.

## Remaining goal work

Private-record reassociation needs a reviewed operator workflow. Native cycle configuration/assignment and one-time items still need atomic ownership. Automatic batch behavior, source-owned mapped commercial field alignment, end-client chains, rates, lifecycle/CSV/activation replay, full field/destination alignment, Quanta/PurePay/PurePayroll exchange, portal/onboarding/document acceptance and financial lineage/reconciliation remain in the original integration scope.
