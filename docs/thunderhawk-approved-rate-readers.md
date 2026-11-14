# Retained approval rates in financial readers

This is integration-pilot source work. It has not been installed, and it does not authorize real posting, payroll execution or payments. Candidate `39a9f5c2` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43561565593). [Pinned evidence](../deploy/thunderhawk-approved-rate-readers-preparation-20261114.json).

## Changed behavior

Selected-time Billing and AP drafts, invoice suggestions, settlement extraction and automatic draft preparation, period pricing, and native payroll's extracted-time currency validation now read each time entry's retained approval evidence. A mutable placement-rate ID is not the price identity: two approvals using the same ID can retain different values. Missing archives, changed approval facts and foreign-workspace evidence stop processing. The existing strictly hashed legacy read is still available only while its exact original rate row exists.

The automatic historical backfill and mutable rate-ID batch loader were removed. Opening a period or invoice preview cannot manufacture historical prices from today's placement rate. Invoice suggestions continue returning only the existing public candidate fields; full internal approval rows and private rate records are not returned to the browser.

Selected Billing/AP time and period reads explicitly use the original time workspace. Missing selected rows cannot silently disappear from a mixed selection. Period inserts name their time tenant; ready-bundle updates name that same tenant and use the actual table columns. Period totals use each entry's billable/payable flags, so payable nonbillable time and PTO retain their pay. Referral payouts use the approved hourly referral value. Per-entry cent rounding matches the time-generated bill/invoice lines, including fractional-cent rates.

## Verified evidence

The disposable, fully migrated database fixture passed **40 checks** and calls the real AP and Billing builders and the real period preview/build functions. It verifies later master-rate edits, separate approvals sharing one rate ID, missing and repeated selections, original-workspace rejection, altered hours, missing archives, unresolved legacy prices, ready-bundle persistence/replay, and refusal before any bundle write when a source archive is missing. Its invented records are rolled back. Fixture-seeded archives do not substitute for the separate native HTTP approval tests.

The native payroll identity fixture passed **53 checks**. It retains the approved source currency after later master edits and separately refuses original foreign or absent currencies. It continues exercising reviewed employment identity, company isolation, extraction replay and numeric/legacy run references without provider execution. The **33 approved-economics checks** also cover paid nonbillable time, PTO, unpaid flags and flat hourly referral payouts.

## Work still required

- Native payroll gross calculation still uses employee compensation, and its accounting-accrual coverage still contains a live rate join. Those readers need the full retained-source earnings and clearing contract.
- Client/vendor contract terms and additional economic charges still have rate-ID/current-graph lookups. Money values alone do not complete recipient or financial-owner alignment.
- Period close/build serialization, consumed-bundle correction/replacement, the separate bundle accrual hook, weekly/daily rounding across accounting paths, earning-category defaults, rate units, currencies and multi-period allocation need their remaining acceptance.
- Required migrations, guarded pilot installation and the full signed-in Quanta/PurePay/PurePayroll round trip remain open, along with the broader field/reference and financial correction work in the integration checklist.

Production, provider runtimes and Arabella are unchanged. This checkpoint is not complete integration acceptance.
