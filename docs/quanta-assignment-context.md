# Reviewed assignment reporting fields

Core publishes branch/business unit, department, service line, cost center, workers’ compensation class and work arrangement in the additive Quanta assignment contract `2026-11-12.1`. The existing person, placement, company, client, vendor, worker and assignment identifiers stay unchanged.

The operator previews the six current and proposed values before sending. An existing value being removed is labelled **Clear value**; a field never shared by an older assignment is labelled **Not shared**. Values missing in Core remain explicit nulls. These fields are reporting context only: they do not assign worksite membership, grant portal access, approve time, set rates or execute payroll.

The complete `assignment_context` object has six required keys. Each value can be null; the object itself cannot be null. Omitting the object preserves the prior value and legacy delivery hashes. Updates use the assignment’s existing conditional version and durable receipt. Core refuses placement publication to a provider that does not advertise the new capability. Existing closed-assignment rules still apply.

Time routing and time-access setup compare operational assignment details separately from reporting context. An older or ended assignment does not need a reporting backfill to retain historical routing; identity, worksite, status, dates and version safeguards remain in force.

Quanta exposes the six fields in scoped assignment details and appends them to the existing CSV columns. Exports retain the same authorization, tenant filtering and formula escaping. The change does not introduce organization identities from text labels or infer a missing workers’ compensation classification.

Validation covers request replay and body conflicts, stale versions, explicit clearing, legacy omission, cross-company refusal, unchanged native access/time data, worker-scoped detail/export, and the Core review screen. Deployment and hosted acceptance are recorded separately; source implementation alone does not establish that the pilot is updated.

Request confirmation compares object contents using the same canonical, type-preserving hash as the encrypted delivery envelope. Object key order is not a change; missing keys, changed values and changed scalar types remain refusals. Complete request preparation runs before metadata approval and claim, then again at the IO boundary. A local validation failure therefore leaves an unattempted envelope available for review; an interrupted provider attempt still requires receipt reconciliation.
