# Quanta time integration

The Quanta connector is intentionally disconnected on deployment. There is no
scheduled sync, webhook, or automatic tenant link. A CoreFlux tenant admin must
connect a Quanta API key, link worker identities, review placement routes,
preview source time, and select the entries to import.

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
  Earlier dates are available. A `timesheets:read` key must never expose
  submitted or draft entries through this connector.
- A Quanta worker ID must be explicitly linked to one canonical CoreFlux person
  before any placement route or import. Exact email can suggest a person, and
  multiple suggested links can be saved together. A stored link cannot silently
  move to another person. Unlink requires removing routes first and is blocked
  if imported time depends on the worker.
- An entry is routed by its Quanta worker ID, worksite ID, dimension values,
  and work date to one effective-dated CoreFlux placement. No fuzzy match is
  committed automatically. Only placements for the linked person are available,
  and a unique placement with an exact worksite/client name may be suggested,
  but an admin must save the
  route. A missing or overlapping route or a person mismatch blocks import.
- Regular, overtime, double-time, and classified PTO hours remain separate
  atomic CoreFlux time rows. Quanta's approved-entry response supplies each
  component in whole minutes; CoreFlux verifies their sum against
  `duration_minutes` before converting to decimal hours. `VAC` maps to
  vacation. PTO without a supported vacation/holiday/sick/bereavement subtype
  is blocked rather than guessed. Missing classification, invalid dates, hours
  beyond daily limits, and a breakdown that disagrees with the total also
  block import. Quanta PTO can have no worksite; that requires an explicit
  worker/empty-worksite/empty-dimensions route to a placement before import.
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

On September 28, 2026, an Arabella key with the three read scopes successfully
read `/workers`, `/worksites`, and `/timesheets`, but production Quanta returned
HTTP 403 for `/time-entries`. A Quanta preview build now tests an approved-only
read path under `timesheets:read`. Its tested response wraps rows in `data` and
cursor fields in `pagination`, and includes `worker_id`, `worksite_id`,
`work_date`, `duration_minutes`, classified minute fields, `pto_type`,
`timesheet_status`, `dimension_values`, and `updated_at`. CoreFlux's connector
matches that tested shape, but this is not a production verification: the
Quanta change must be reviewed and published, the existing Arabella key must
be re-probed, and an actual approved entry must be previewed before import.
Catalog connectivity alone does not imply that hours can be imported. Do not
import while a preview contains unresolved rows.

## Direction of authority

Quanta owns clock events, timesheet submission, and its source approval state.
CoreFlux owns placements, rates, accounting dimensions, and downstream billing,
AP, and payroll decisions. Import never writes an approval or correction back
to Quanta. Quanta documents write endpoints, but the tenant API-key screen only
offers read scopes; a bidirectional workflow requires separately verified
write credentials, conflict rules, and an agreed source of truth before it can
be enabled. Running Connecteam-to-Quanta and Connecteam-to-CoreFlux time feeds
for the same activity would risk duplicate time, so only one ingestion path
should be active for each source clock.
