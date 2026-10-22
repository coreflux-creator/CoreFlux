# Quanta time integration

This document describes the CoreFlux time-import connector. The October 20
[Thunderhawk operating pilot](thunderhawk-core-operating-pilot.md) expands the
target to people, assignments, onboarding, portal reporting, and PurePay /
PurePayroll integration. The current candidate also implements separately gated,
reviewed outbound worker preparation, person links and assignment metadata.
Those additions are described below and in the pilot plan; they do not mirror
all fields or approve/correct source time in both systems.

## Reviewed outbound candidate — October 22, 2026

The existing inbound worker creation/linking flow now requires a separate
comparison review. An encrypted review binds the worker and proposed person to
the current verified connection, directory and reviewer. Migration 168 stores a
local receipt and original source provenance atomically with the person number
and link. Receipt recovery does not depend on Quanta being available and cannot
recreate a later removed link. Contact changes or connection/directory drift
require a new review. Linking never overwrites existing Core contact fields or
provider IDs. This candidate still requires hosted pilot acceptance.

The dedicated Core contract verifies Quanta's workspace and capabilities before
review. Canonical person and placement references include the Core installation
and directory owner. Existing provider IDs are preserved. Metadata and worker
delivery have separate environment flags, both off by default.

Worker preparation shows the current and proposed contacts before confirmation.
A new worker is disabled and receives no login identity, credentials, invitation
or worksite access. Existing linked workers accept conditional name/phone changes;
email collisions require identity review. Core stores the confirmed provider
receipt and its local worker link together. Interrupted sends remain held until
the original scoped receipt is verified; history remains available without a
working provider preview. Neither a missing receipt nor a new preview permits an
automatic retry. Native activation and onboarding remain separate in Quanta.

Core's reviewed workflow passes local database, browser and component checks.
The Quanta worker companion is still local, awaiting Emergent sign-in and source
save; the earlier assignment portal is saved but its authenticated Thunderhawk
acceptance remains open. These code and synthetic checks do not establish a live
Thunderhawk round trip. Consult the pilot document for exact release receipts.

The Quanta connector is intentionally disconnected on deployment. There is no
scheduled sync, webhook, or automatic tenant link. A CoreFlux tenant admin must
connect a Quanta API key, link worker identities, review placement routes,
preview source time, and select the entries to import.

## Connection and scope

Use a Quanta key with `timesheets:read`, `workers:read`, and `worksites:read`.
Time import calls Quanta's documented GET endpoints on
`https://helloquanta.app/api/v1`; the import itself does not write to Quanta. The key is
encrypted at rest. The connection screen shows the active CoreFlux workspace
and requires an explicit confirmation before a key can be saved. Replacing a
previous key invalidates the workspace verification. The candidate's dedicated
Core contract exposes workspace identity and version/capabilities; Core requires
explicit verification before reviewed exchange. Disconnecting revokes that
binding without deleting imported Core time or historical identity lineage.

## Review and import

- The initial preview is approved Quanta entries changed in the last 30 days.
  Earlier dates are available. A `timesheets:read` key must never expose
  submitted or draft entries through this connector. Quanta enforces this on
  the server on `/time-entries/approved`; CoreFlux does not send status or
  tenant override parameters and still checks each returned row's approval
  status. Quanta's existing `/time-entries` route retains its prior contract
  for clients with `read:entries`.
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
HTTP 403 for `/time-entries`. A Quanta preview build is adding
`/time-entries/approved` under `timesheets:read` without changing the existing
entry-reader contract. Its tested response wraps rows in `data` and
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
to Quanta. At the September 28 verification, the inspected tenant API-key screen
offered read scopes only. That observation is not a claim about every current
Quanta integration: the reviewed PurePay source also contains worker writes,
time approvals, and other Quanta operations. CoreFlux still needs verified
write credentials, conflict rules, and field ownership before its outbound
workflow can be enabled. Running Connecteam-to-Quanta and Connecteam-to-CoreFlux time feeds
for the same activity would risk duplicate time, so only one ingestion path
should be active for each source clock.
