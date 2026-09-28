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
built. On September 28, 2026, an Arabella key with the three read scopes
successfully read `/workers` and `/worksites`, but Quanta returned HTTP 403
for `/time-entries`. The connection probe now checks `/timesheets` separately
from entry-level import access. CoreFlux disables time preview and import
when entry access is denied; catalog connectivity does not imply that hours
can be imported. Quanta's OpenAPI publishes endpoint and filter contracts but
does not define a time-entry response schema. Before any live import, obtain
entry-level read access, inspect a preview containing real regular/OT/PTO
entries, and confirm its fields and dimensions match the normalizer. Do not
import while that preview contains unresolved rows.

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
