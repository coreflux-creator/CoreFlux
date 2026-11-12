# Native staffing and held-payroll round trip

On November 12, 2026, the existing synthetic Thunderhawk person **P-387** and placement **PL-902** continued from the completed Quanta onboarding and time-correction journey into PurePay and PurePayroll. This is the isolated company 2002. The separate Arabella pilot and real payroll remain outside this test.

## What the operator completed

| Step | Observed evidence |
| --- | --- |
| Worker and onboarding | The existing Quanta worker and assignment retained their provider IDs. The earlier native worker upload, independent Core approval and returned employer decisions are documented in `thunderhawk-native-onboarding-roundtrip.md`. |
| Approved time | Corrected Quanta entry `3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2` became approved Core time 17, two hours on November 9. The earlier one-hour and 1.5-hour revisions remain superseded. Creation and approval used separate actors. |
| Person | Core delivery 110 was reviewed in PurePayroll's People from Core interface. The operator created one inactive employee, then used the native employee form to set the synthetic start date, New Jersey, hourly pay of $50 and record-only payment method before activation. |
| Placement | Core delivery 111 was reviewed in PurePay's Placements from Core interface, including the commercial terms. The native draft assignment was activated after employee setup. Existing customer `cus_e25f880f86` was retained. |
| Identity receipts | The operator reviewed and confirmed the employee and assignment matches in Core. No existing employee or assignment ID was replaced. |
| Payroll input | Core delivery 114 sent the two approved hours and frozen rate snapshot 23 for November 9–15. The native reservation and sharing workflow held the source hours. |
| Held draft | In PurePayroll, the operator reviewed one employee and $100 gross, selected November 20 as the pay date and created one held draft. Calculation remains manual, payments record-only, and approval/funding/payment execution unavailable. |
| Returned result | Core displayed and saved the draft observation, including the original person, placement, employee, assignment and source-time references. The source component reconciles as 2.00 × $50.00 = $100.00. Reloading the Core page recovered the original input receipt and one saved observation. |

The employee ID is `emp_core_ddf206bef1e2b7a511b6b445ca9b49bbc65d56c9dc79e0617bf125c155c1606f`; the assignment ID is `asg_core_aff5699724d86834b6f810edaa9a763e3e35821fc42699223ac2492075258bab`. The held run is `run_core_54cb6e8156e6bfd5386a133ed5ea4d41c015aab8f45d25aeb0813cb090d35938`.

The input receipt is `cpi_06d372dcd75e8d388c33479be0933bc9ff35489f7d0d07b763d2f45d7b7bde55`; its reservation is `cpa_4a0bfee5f022202d92f0b09a87d9423e859c337d65c2e514cc9a3fcf09fc27dc`. The saved provider observation hash is `f0f92dce958fe1d9ae5564ce382a2f9598c68baa24fdd8fb0dd79fc82a2fad73`.

## Replay and company isolation

The provider's native API recovered the original draft request and idempotency key. Exact replay returned the same held run. A changed request under the original key, a replacement key for the allocated source, and a second draft review were refused with 409. An unowned input returned 404. The verification preserved all 83 business rows then present across 24 non-authentication collections; its temporary verification session was revoked.

The deeper Core check found a genuine legacy permission defect: a user's home-company role could be applied to a company with no membership. The source correction requires verified company membership or a verified unmigrated home-company role. Unknown users, unavailable evidence, pending/revoked memberships and missing selected personas fail closed. Explicit global-administrator access is retained. Twenty-five native MariaDB checks passed, alongside 24 local SQLite cases and the existing 21- and 64-check resolver suites.

Core runtime `410670ea02dfa5760ed4dfbcd3b176ff254e00e2` passed all eleven jobs in [CI 43315023462](https://github.com/coreflux-creator/CoreFlux/actions/runs/43315023462). The guarded one-file pilot installation completed at 22:02 UTC, preserving all 35,087 then-current rows across 378 tables, the migration ledger, configuration and file ownership. The web process verified allowed pilot access, denied foreign-company access and denied unknown identities/personas. Its temporary probe was removed and the exact prior file remains in the private rollback directory.

The installed Core service then passed five checks: exact saved-result replay, the $100 source calculation, altered-component refusal, foreign-delivery refusal and no foreign-company payroll grant. It preserved all 35,087 rows. The native page recovered the same held result and one saved observation after the update. Following native sign-out, test actor 102 was deactivated, its membership/grants retired and credentials removed. The complete exchange preserved all 35,040 original Core rows; only the documented new delivery, reservation, result and test-access/audit records were added. Exact evidence and installed fingerprints are in [the release manifest](../deploy/thunderhawk-operational-roundtrip-20261112.json).

## Provider preservation exception

Exact preservation of the provider's pre-exchange records is **not yet accepted**. Two existing isolated payment rows, `pmt_87cf14ddb4` and `pmt_274cf7d401`, gained the cached invoice label `Sep_26` from an empty value. Each label matches its exact, unchanged pre-existing payable. Their other fields, including amounts, status and identifiers, are unchanged. Seventy other original business records match after excluding only the dedicated key's last-used timestamp and the pilot reviewer's last-login timestamp. Authentication collections are reported separately.

PurePay's existing `_enrich_payments` helper persists missing labels during normal reads and uses unscoped linked-record lookups. The coordinated PurePay chat confirmed that behavior remains in the public backend and did not modify these pilot rows. The observed values and source explain the discrepancy, but exact request-level causation has not been independently established. The preservation checker retains a failure for those two business-row differences. The coordinated source correction `a69981c912e97a8ba8d5a9bbce54b10756ee57cd` passed 76 tests, including 18 new behavioral cases. It has not been ported to or deployed in the pilot; no public deployment or record repair is claimed here. The separate payment-status synchronization called by some GET routes also remains open and prevents claiming those routes are globally mutation-free.

## Operational limits and remaining work

The draft remains held, with its source reservation retained. No real payroll, payment or financial posting was executed. Zero tax amounts in this manual synthetic draft do not establish real payroll-tax correctness. The isolated setup allowlist was extended only to this new synthetic employee; all other services and the provider source files were preserved during that configuration update.

The full integration goal remains incomplete: the provider read-side correction and preservation recheck, wider field ownership and cohort acceptance, JobDiva original approval/correction/outbound evidence, normal Core browser document upload, financial allocation/provenance and the remaining portal/lifecycle/merge acceptance still require evidence. This round trip proves the named synthetic journey, not a production cutover or completion of the full integration.
