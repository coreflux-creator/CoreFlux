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

## Still required for the integration

The operational policy configuration is not mounted in Quanta and no pilot policy
has been enabled. Finish its reviewed, explicitly scoped API/UI, assignment selectors,
and handling of existing unbound time. The Core route editor still rejects overlapping
worker/worksite routes; its reviewed configuration must support assignment-specific
routes before offering that scenario to users. Finish PurePay/PurePayroll handling,
concurrent mutation cases and the distinct synthetic hosted offboarding round trip.
The full Core release and Thunderhawk financial reconciliation remain separate open
requirements. This change authorizes no payroll, payments, financial postings or
production cutover and leaves the Arabella pilot untouched.
