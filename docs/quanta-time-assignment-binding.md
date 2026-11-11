# Captured assignment identity on Quanta time

Implementation on `codex/quanta-profile-return`; not a production or pilot activation.
The frozen full-Core candidate remains `6b7841894cbfc818769de216f2719b0fbb3ab2e8`.

Quanta's source change returns a typed `core_assignment` on newly managed time.
It includes contract version `2026-11-11.1`, the captured policy and assignment
IDs/versions, local work date, worker/worksite IDs and canonical Core person and
placement references. The field is omitted for unmanaged legacy time, preserving
existing response and source-review fingerprints. Explicit null, malformed,
wrong-kind or private fields are not a substitute for a legacy record.

Core verifies that the recorded IDs derive from its current verified Quanta
workspace, that both canonical references belong to this Core installation and
their correct directory owners, and that the selected reviewed route points to
the same person and placement. Captured identity can distinguish two otherwise
matching routes. Two routes to the same captured placement still fail. Legacy
time still requires one unambiguous reviewed route. Current placement status,
work dates, worker identity and dimensions remain required.

Import component hashes include the canonical captured binding. The existing
transactional approval-evidence JSON stores the full binding with the source
approval; no new database column is needed. Refresh cannot remove or replace a
captured binding, even while Core time is pending review. Current-source checks
at approval/extraction validate the same identity against the original receipt.
The Quanta review return also uses the original captured assignment revision,
so a second assignment at the same worksite cannot claim that time.

The added directory query columns are excluded from evidence fingerprints for
legacy unbound time. This avoids invalidating existing reviewed deliveries solely
because the new source code can inspect ownership more precisely.

## Verification

- Quanta: 504 full-application checks passed with no attempted network calls or real
  database. Native approved/detail/source-state APIs and timesheet serialization
  carry the same binding; real scoped review publication/replay preserves history.
- Core: 33 assignment-binding checks pass, including the actual response captured
  from Quanta's native API. Existing integration (77), source approval (22), current
  source (15), review return (41), and metadata contract (79) checks pass locally.
- `tests/quanta_time_provenance_mariadb.php` adds real-transaction assertions for
  saved binding evidence, no duplicate receipt on replay, source change/removal,
  directory mismatch, and full rollback preserving original history. Its result
  must be established by Core CI; a local syntax check is not database acceptance.
- `tests/quanta_time_binding_fixture.php` reads a synthetic native Quanta packet
  on stdin. It makes no network or database calls. The paired-source receipt is
  `deploy/quanta-time-assignment-binding-source.json`.

## Reviewed time setup from Core

The paired Quanta source now implements reviewed configuration and native time
selectors (Quanta `9a62b00546a932a5346a0952aaf559faceeab747`). Core's Quanta
settings add **Assignment time setup** and an independent **Time setup recovery**
panel. Select a linked Core person and native Quanta worksite, review the shared
placements, preview the selection and explicitly confirm it. An empty selection
holds new time. Existing hours, approvals, rates, sign-in and native membership
are not edited by this operation.

Core requires integration administration, people/placement/time visibility and
`time.review` write permission. Sending is default off behind
`COREFLUX_QUANTA_TIME_ACCESS_DELIVERY` and the integration tenant restriction.
Quanta separately requires explicit `read:core_time_access` /
`write:core_time_access`, companion read permissions and an exact workspace in
`COREFLUX_TIME_ACCESS_TENANTS`. No keys or hosted flags are changed by this source
implementation.

Only canonical local person and placement IDs are accepted from the browser.
The service checks the verified company pairing, current directory ownership,
person link, full assignment metadata, reviewed context and provider versions.
It rechecks and locks local source and connection rows before claiming the
encrypted outbox request. Shared metadata must agree before it can be selected.
These SQL claim checks do not imply an atomic transaction spanning Core and
Quanta; concurrent native capture/approval and lifecycle work remains required.

An interrupted send cannot be sent again. The independent recovery list retains
the original request after source records disappear. Receipt recovery checks
the exact original request hash, selected identities, revisions and delivery
ID. Quanta can reconstruct a lost receipt from matching committed policy
history; Core never reapplies the policy during recovery. Unknown outcomes stay
held. A later current setup must be reviewed separately.

Initial activation (including an initial empty hold) refuses existing unbound
time. An existing policy can hold new time while legacy history needs review.
This is a prerequisite for a reviewed adoption workflow, not proof that legacy
time migration has been implemented.

Verification: `tests/quanta_time_control_smoke.php` exercises the strict wire
contract; `dashboard/tests/quantaTimeControl.test.mjs` covers confirmation,
empty holds, rollout/legacy gates, duplicate clicks, tenant/connection changes
and reload recovery. `tests/quanta_time_control_mariadb.php` exercises the real
SQL outbox, final source check, isolation and receipt recovery with synthetic
provider responses; it runs in the existing disposable MariaDB CI job. Hosted
acceptance and actual provider concurrency are separate requirements.

## Routes for overlapping placements

Migration `188_quanta_assignment_routes.sql` adds an explicit Quanta assignment
selector to each route. Existing rows retain an empty selector and their original
identity, timestamps and review fingerprints. The replacement unique index allows
the same worker/worksite/dimensions/start date for different captured assignments.
It still prevents duplicate legacy starts.

The route editor carries the assignment already recorded on source time and offers
only the matching canonical person/placement and directory. Names do not choose a
placement for captured time. The server verifies the paired workspace, linked
person, provider assignment and full shared metadata before saving. Before the
transaction writes, it rechecks local source and company ownership under locks.
Route saves and deletes serialize through worker identity rows. Overlapping legacy
routes and duplicate routes to one placement remain errors. A route's assignment
identity cannot be changed or cleared by editing it.

Unbound legacy entries never use an assignment-only route. Captured entries still
check the original typed references, worksite, dimensions and dates. This does not
adopt old unbound time or rewrite its approvals. A migrated empty selector is
excluded from historical evidence fingerprints, avoiding spurious conflicts.

Local verification includes route validation, actual native binding packets and
UI selection behavior. `tests/quanta_assignment_routes_mariadb.php` exercises
migration replay, transactional batch rollback, prior transaction snapshots and
two database connections competing for the writer lock. Its result must be
established by the exact committed CI run before staging acceptance.

## Still required for the integration

No pilot time policy has been enabled. Finish reviewed handling of existing
unbound time and the remaining kiosk/task/import entry paths. Deploy and validate
the new explicit assignment routes in the isolated pilot. Finish PurePay/PurePayroll handling,
concurrent mutation cases and the distinct synthetic hosted offboarding round trip.
The full Core release and Thunderhawk financial reconciliation remain separate open
requirements. This change authorizes no payroll, payments, financial postings or
production cutover and leaves the Arabella pilot untouched.
