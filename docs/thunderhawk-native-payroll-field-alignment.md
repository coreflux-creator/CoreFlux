# Native payroll field ownership

The November 14 read-only installed inventory found 2,143 standard/payload/business-reference entries, no visible custom definitions and 253 fields without an explicit disposition. This is metadata coverage, not proof that every provider accepts every field.

The candidate adds explicit definitions, units, native owners and provider destinations for the 39 outstanding `payroll_runs` fields and 21 `payroll_line_items` fields. A comparison against that saved installed schema found all 60 definitions present and reduced unresolved decisions to 193. Other standard field definitions, owners, dispositions, write policies and remote contracts were unchanged. The candidate is not installed.

Core's native calculation and PurePayroll's execution record are separate identities. The source-aware provider workflow receives reviewed original time and source reservations. Its returned observations are immutable, encrypted `payroll_provider_results` records. They do not overwrite native run totals, approve a native run, post a journal or establish cleared cash.

The field decisions preserve several distinctions needed for an exact comparison:

- Native amounts are cents and current computation requires explicit USD inputs. This does not fill missing currency on historical provider records.
- `pay_rate_cents` is annual salary or hourly compensation, depending on basis. For approved-time calculations it is zero because the original component rates are authoritative. Exporting that zero as a worker rate would be wrong.
- The native overtime-hour rollup includes both overtime and double time. The original earning components retain their separate codes, rates and amounts; an hours-only export cannot reconstruct them.
- Native calculation revisions and fingerprints bind the complete saved calculation to its independent review. They are distinct from provider input IDs, source reservations and provider result hashes.
- Gusto references remain Gusto references. They are not reused as PurePayroll IDs. Payment-rail references, accounting journal links and provider statuses each retain their own workflow.
- Source-time snapshots, payroll notes and provider error detail stay private. The dictionary enables no provider write, portal visibility, payment or payroll execution.

Evidence comes from the native compute, exact-review, source-earnings, accounting and Gusto workflows and the separate PurePayroll result reader. The existing field-ownership, time-contract and commercial-contract checks passed with the updated boundary expectations: 118, 164 and 643 checks. The local PHP runtime needed its existing multibyte extension enabled; no application behavior changed for that environment correction.

The prepared field map still needs its complete CI result and isolated installation/readback. Remaining employment, compensation, placement, AP/AR, job and connector-state decisions remain open. Native tax calculations have not been certified by this metadata review. No business data, schema, provider setting, production app or Arabella record changed.
