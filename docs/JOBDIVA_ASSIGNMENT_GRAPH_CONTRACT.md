# JobDiva Assignment Graph Contract

This contract defines how JobDiva records project into CoreFlux. It is based on
the live JobDiva Assignment Dashboard, Start detail, Bill detail, and Pay detail
workflows. Payload field names are evidence; the business objects and their
relationships are authoritative.

## Root identity

- A JobDiva Start/Assignment is one CoreFlux placement.
- The JobDiva Start ID is the placement's external identity.
- A candidate, employee, job, contact, application, submission, offer, or
  candidate-job pair must never create placement identity.
- A JobDiva Job is a staffing job/role and is linked through
  `placements.staffing_job_id`.
- A JobDiva Candidate/Employee resolves to one `people` row and is linked
  through `placements.person_id`.
- Billing companies, end clients, subcontractor corporations, referral
  vendors, and chain vendors resolve through the canonical company/vendor
  graphs. Staffing is a consumer of those identities.

## Economic routing

| JobDiva object or value | CoreFlux destination | Downstream owner |
| --- | --- | --- |
| Start/Assignment | `placements` | Staffing, time, billing, AP, payroll |
| Candidate/Employee | `people` | People graph, payroll |
| Job/Requisition | `staffing_jobs` | Staffing jobs graph |
| Billing company/end client | `companies`, staffing client bridge, placement AR party | Company graph, AR |
| Bill rate and unit | `placement_rates` receivable rate | Billing and margin |
| Billing frequency | AR operating cycle | Billing |
| Client payment terms | Placement AR economic party | AR collections |
| Employee pay record | Placement payroll party and pay rate | Payroll |
| Subcontract pay record | Placement AP party and vendor pay rate | AP |
| Subcontract corporation | Canonical company/vendor plus placement AP party | Vendors and AP |
| Subcontract payment terms | Placement AP party | AP due-date policy |
| Paid-when-paid term | Placement AP party PWP policy | AP release gating |
| Referral vendor and fee | Canonical company/vendor, referral, AP party | AP and margin |
| Chain vendor/MSP/VMS fee | Placement client-chain row and economic party when payable | AP and margin |
| Discounts, adders, loads, and recurring fees | Rate/economic components | Billing, AP, payroll, margin |
| Timesheet | `time_entries` linked to placement and person | Billing, AP, payroll |

## Worker classification

- `Hourly Employee` and `Salaried Employee` route labor payment to Payroll.
- `Subcontract` routes labor payment to AP and requires a canonical vendor
  recipient. The contractor corporation is the primary labor payee.
- W-2 and C2C are mutually exclusive placement classifications. C2C corporate
  details must not remain attached to a W-2 placement.
- Classification comes from the Start/Pay relationship. Candidate-level flags
  may enrich the person but cannot override assignment-level economics.

## Mapping behavior

- Tenant field mappings enrich a resolved canonical object; they do not decide
  which object exists.
- Placement mappings may write placement fields and placement-owned economic
  records after the Start, person, job, companies, and payees are resolved.
- Mapping a source path must apply to the current record immediately and to all
  later records for the tenant.
- Protected identity fields cannot be overwritten by field mappings.
- Source-specific raw rows remain audit evidence and are not competing
  operational graphs.

## Reconciliation safety

- Empty, unavailable, or inconsistent exact-lookup responses are not proof that
  a JobDiva assignment was deleted.
- Repair may archive a placement only when JobDiva echoes the same Start ID with
  an explicit terminal/non-assignment lifecycle result.
- A stored Start snapshot can restore an archived placement only when it is
  structurally valid and was observed in the latest completed JobDiva sync.
- Stale snapshots remain quarantined for review and cannot resurrect old or
  fabricated placements.
- A successful complete assignment sync establishes the current source
  observation set. Repair projects and reconciles that set; it must not invent
  placements from jobs, people, or employee reports.

## End-to-end invariant

For every active placement, CoreFlux must be able to answer:

1. Who performs the work?
2. Which JobDiva Start and staffing job authorize it?
3. Who is billed, at what rate, how often, and on what payment terms?
4. Who is paid through Payroll or AP, at what rate, how often, and on what
   payment terms?
5. Which vendors, referrers, fees, discounts, and paid-when-paid gates affect
   settlement and margin?
6. Which approved time entries produced each AR, AP, and payroll transaction?
7. Which source payload, mapping, approval, and audit event produced every
   operational value?

An active placement is not transaction-ready until those required relationships
are resolved for its classification.
