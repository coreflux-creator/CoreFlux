# Quanta time integration

The Quanta connector is intentionally disconnected on deployment. There is no
scheduled sync, webhook, or automatic tenant link. A CoreFlux tenant admin must
connect a Quanta API key, review worker/placement routes, preview source time,
and select the entries to import.

## Connection and scope

Use a Quanta key with `timesheets:read`, `workers:read`, and `worksites:read`.
CoreFlux only calls Quanta's documented GET endpoints on
`https://helloquanta.app/api/v1`; it does not write to Quanta. The key is
encrypted at rest. The connection screen shows the active CoreFlux workspace
and requires an explicit confirmation before a key can be saved. Replacing a
previous key also requires confirming that it belongs to the same Quanta
workspace, because Quanta's public v1 API does not expose a workspace identity
endpoint. Disconnecting removes the encrypted key without deleting imported
CoreFlux time.

## Review and import

- The initial preview is approved Quanta entries changed in the last 30 days.
  A submitted-plus-approved review mode and earlier dates are available.
- An entry is routed by its Quanta worker ID, worksite ID, dimension values,
  and work date to one effective-dated CoreFlux placement. No fuzzy match is
  committed automatically. An exact worker-email match can suggest a
  placement, but an admin must save it. A missing or overlapping route blocks
  import.
- Regular, overtime, double-time, and classified PTO hours remain separate
  atomic CoreFlux time rows. PTO without a supported vacation/holiday/sick/
  bereavement subtype is blocked rather than guessed. Missing classification,
  invalid dates, hours beyond daily limits, and a breakdown that disagrees
  with the total also block import.
- Import preserves Quanta source entry IDs and creates CoreFlux's normal
  person/week timesheet artifact. Rows enter `pending_review` even if Quanta
  approved them; CoreFlux approval and its rate checks still govern billing,
  AP, and payroll. CoreFlux week boundaries, not Quanta's, determine the
  weekly timesheet.
- Reimporting an unchanged source is a no-op. A changed source may update only
  its unapproved, unextracted CoreFlux row in the same placement/person/date.
  A removed hour type, changed identity/date, or any post-approval/downstream
  change requires an explicit correction in CoreFlux. A selected batch is
  transactional, so one invalid item cannot leave a partial import.

## Verification boundary

The signed-in Quanta workspace had no API key or time entries when this was
built. Unit/contract tests and a frontend build run without connecting it.
Quanta's OpenAPI publishes the endpoint and filter contracts but does not
define a time-entry response schema. Before any live import, connect the
correct Quanta workspace, inspect a preview containing real regular/OT/PTO
entries, and confirm that its response fields and dimension values match the
normalizer. Do not import while that preview contains unresolved rows.
