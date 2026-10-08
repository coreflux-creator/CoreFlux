# CoreAccounting MVP release gates

CoreAccounting is an independent product surface on the **existing CoreFlux/CoreOne accounting core**. Invoices, bills, bank transactions, approvals, subledger links and posted journals remain in their current source modules and shared ledger. This checklist is an acceptance ledger, not a claim of production readiness. Detailed evidence and decisions live in `COREACCOUNTING_COREONE_ALIGNMENT.md`.

| Gate | Current evidence | Release status |
| --- | --- | --- |
| Canonical journal, AR/AP and legal-entity ownership | Synthetic two-entity invoice, bill, partial collection and payment lifecycles balance; CoreOne documents land in existing Billing/AP source tables. | **Staging passed; real-history tie-out open** |
| Bank receipt, split allocation and correction | Synthetic split deposit ties one bank line to source payments; reversal restores invoice balances and reopens the line. Cross-entity bank use is refused. | **Staging passed; real feed/concurrency open** |
| Basic financial and operational reports | Income statement, balance sheet, cash flow, trial balance, GL detail, aging and scoped CSV acceptance run on the shared ledger. | **Staging passed; historical opening balances open** |
| AP and AR maker/checker | Synthetic independent reviewers approve source documents; issuer self-approval and machine-only decision/post authority are refused. | **Synthetic passed; two-human rehearsal open** |
| Invoice delivery and collection | Isolated log-only invoice and statement outboxes verified with PDFs and entity sender settings. | **Real-provider and recipient-controlled test open** |
| CoreOne machine contracts | Entity-bound invoice draft and AP bill preparation/review request use source IDs, exact-retry checks and canonical source modules. | **Staging passed for these scopes; broader contracts open** |
| Payable line integrity | ERP/CoreOne manual preparation and AP bill CSV preview/commit refuse unapprovable zero/negative/sub-cent lines; CSV also checks stored precision and quantity-times-price. | **Staging passed for these paths; full credit/discount treatment and other AP sources open** |
| Invoice CSV line integrity | Preview and commit check stored quantity/rate precision, cent precision, quantity-times-price, tax, positive document net, item type, tenant-owned catalog item and active revenue account. Import/export retained those fields; a service-plus-discount invoice passed separate human review and posted once as balanced net AR/revenue. | **Synthetic staging posting passed; customer identity, delivery and other AR sources open** |
| Clean installation | Read-only schema verifier found 26 tables and 152 required columns on populated staging; installer refused that populated database before DDL. | **From-empty install and replay not yet run** |
| Period close and opening cutover | Close/cutover controls exist but have not been accepted against real historical books. | **Open** |
| Payroll compliance and bank rails | Synthetic payroll computation/accrual and cash-journal controls tested; no tax, filing or real payment-rail certification. | **Outside this basic accounting release; must remain gated** |

## Next acceptance sequence

1. Create a separate disposable staging app/database and run the guarded empty-database bootstrap, schema contract and invoice-to-cash/bill-to-payment replay. Never clear or reuse the populated simulation database.
2. Complete a signed-in, two-human browser walkthrough of the everyday invoice, bill, bank and report tasks. Capture concrete friction and fix it before sign-off.
3. Reconcile non-synthetic opening balances, AR/AP source lists, bank statements, entity ownership and historical source links to an approved cutover date.
4. Verify controlled provider delivery to approved test recipients; keep payment initiation and payroll compliance behind separate gates.

## Decision register

- One ledger and one set of source-owned financial documents; standalone does not mean a second accounting database.
- An entity-bound service key may prepare documents and request review only under its explicit scopes; a human reviewer owns approval and posting.
- Manual AP discounts are refused until a credit-side treatment, tax behavior, source correction and report tests are defined. A positive `discount` label must never silently debit expense.
- An AP bill CSV line must carry positive quantity and unit price at no more than four decimal places. For an amount-only vendor charge, use quantity `1` and the charge as unit price; subtotal and total are derived or checked in preview. One invalid line blocks the entire bill, including when other bills are imported with `skip_invalid`.
- Invoice CSV uses its own signed-line rules: positive quantity and an explicit rate at stored four-decimal precision; explicit subtotal, tax and total amounts must use cents; subtotal must match quantity times rate. An untaxed negative discount line is allowed only within a net-positive invoice. These checks apply to preview and commit and cannot turn one partially invalid invoice into a saved draft.
- Imported invoice CSV lines accept an explicit supported item type; otherwise a negative subtotal becomes `discount` and a nonnegative manual line becomes `other`. A negative line cannot be mislabeled as labor or another positive type. An optional catalog item ID must resolve to an active item in the same workspace; its type and revenue account are inherited when omitted from the line. An explicit or inherited revenue account must be active, postable and revenue-classified. Ordinary line-level CSV export carries the stored type, catalog ID and account code. CSV client-company identity and the source-to-collection path still need acceptance.
- Synthetic staging tests are useful engineering evidence but do not replace real-history reconciliation or human operational acceptance.
