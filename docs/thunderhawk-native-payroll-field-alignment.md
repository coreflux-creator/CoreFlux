# Native payroll field ownership

The November 14 read-only installed inventory found 2,143 standard/payload/business-reference entries, no visible custom definitions and 253 fields without an explicit disposition. This is metadata coverage, not proof that every provider accepts every field.

The definitions cover the 39 outstanding `payroll_runs` fields and 21 `payroll_line_items` fields, with explicit units, native owners and provider destinations. The initial source comparison reduced unresolved decisions to 193 while preserving unrelated standard rules. They are now installed as part of job ownership release `ff88d483`; fresh read-only inventory at 13:49:58 UTC verified their source hashes and found 2,144 entries and 193 unresolved decisions. The extra entry is the private job ownership column. [Installed acceptance](../deploy/thunderhawk-job-source-acceptance-20261114.json).

Core's native calculation and PurePayroll's execution record are separate identities. The source-aware provider workflow receives reviewed original time and source reservations. Its returned observations are immutable, encrypted `payroll_provider_results` records. They do not overwrite native run totals, approve a native run, post a journal or establish cleared cash.

The field decisions preserve several distinctions needed for an exact comparison:

- Native amounts are cents and current computation requires explicit USD inputs. This does not fill missing currency on historical provider records.
- `pay_rate_cents` is annual salary or hourly compensation, depending on basis. For approved-time calculations it is zero because the original component rates are authoritative. Exporting that zero as a worker rate would be wrong.
- The native overtime-hour rollup includes both overtime and double time. The original earning components retain their separate codes, rates and amounts; an hours-only export cannot reconstruct them.
- Native calculation revisions and fingerprints bind the complete saved calculation to its independent review. They are distinct from provider input IDs, source reservations and provider result hashes.
- Gusto references remain Gusto references. They are not reused as PurePayroll IDs. Payment-rail references, accounting journal links and provider statuses each retain their own workflow.
- Source-time snapshots, payroll notes and provider error detail stay private. The dictionary enables no provider write, portal visibility, payment or payroll execution.

Evidence comes from the native compute, exact-review, source-earnings, accounting and Gusto workflows and the separate PurePayroll result reader. The existing field-ownership, time-contract and commercial-contract checks passed with the updated boundary expectations: 118, 164 and 643 checks. The local PHP runtime needed its existing multibyte extension enabled; no application behavior changed for that environment correction.

Source `f1050919` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43595020783). [Historical preparation evidence](../deploy/thunderhawk-native-payroll-field-preparation-20261114.json). The subsequent isolated installation and readback are complete. Remaining employment, compensation, placement, AP/AR, job and connector-state decisions remain open. Native tax calculations have not been certified by this metadata review. The metadata definitions enable no provider writes or financial execution and change no business values. Production and Arabella remain unchanged.
