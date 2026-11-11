# Review historical time without rewriting its source

Assignment time setup now has an existing-time review flow. Its inventory comes
from the connected Quanta worker and worksite; the operator reviews the person
number, placement number, date, imported hours and current approval evidence.
Core constructs the association from its own imported components and published
review. The browser never supplies assignment ownership or approval evidence.

The action uses a separate encrypted `time.legacy` delivery. A fresh source
snapshot precedes sending. The claim locks the current connection, directory
ownership, worker links, placement routes, canonical identities and imported
time rows, then recomputes the local proof. The provider compares its original
context and version again. Existing source entries, approval receipts, routes,
rates and financial extraction fields are not changed by linking.

Core requires integration management, people/placement/time view and time-review
permission. Sending remains behind `COREFLUX_QUANTA_TIME_ACCESS_DELIVERY` and the
integration tenant allowlist. Quanta additionally requires its explicit write
scope and tenant rollout. These are existing operational controls, not new
authority granted by metadata synchronization.

Unconfirmed requests survive reload. Recovery uses the encrypted original
request, company/directory ownership and connection binding. A provider receipt
or committed association history can confirm the outcome after the original
person, placement or source disappears. Recovery never publishes a second link.

Validation:

- Quanta's full-app native suite: 158 checks, no outbound networking. The actual
  synthetic API packet is committed in `tests/fixtures/quanta_legacy_time_native.json`.
- `php tests/quanta_legacy_time_fixture.php < tests/fixtures/quanta_legacy_time_native.json`:
  24 cross-language proof, request, receipt and field-dictionary checks.
- `node --test dashboard/tests/quantaLegacyTime.test.mjs`: 10 interaction checks,
  including confirmation, duplicate clicks, uncertain delivery, unavailable
  source recovery, company changes and stale connection responses.
- `tests/quanta_legacy_time_mariadb.php` exercises actual database claim locks,
  concurrent writers, ownership changes, replay and recovery in disposable CI.
- `tests/quanta_profile_permissions_http.php` covers the new actions through
  native login/session and permission checks. Database/HTTP CI results must be
  checked on the committed head before release; local PHP has no MySQL driver.

All eleven CI jobs passed on Core candidate `6c83324b`, including 25 historical
MariaDB checks and 214 native permission/session checks. Quanta `12ad639` is now
installed in the same isolated pilot process; four backend files changed, while
the frontend and existing business records/indexes were preserved. Independent
verification checked 54 served frontend files and 66 unchanged collections.

Core runtime `6c83324b` is now installed in the existing isolated web pilot.
The file-only release changed seven backend files and six dashboard files,
preserved ownership, and applied no migration. Independent verification checked
all installed files, six served frontend files, three original routes, the
migration ledger and all 367 business tables against the pre-install snapshot.
All twelve historical snapshots retain the original Core review proof hashes.

A synthetic reviewer then signed in through the native login handler and used
the installed HTTP API to review all twelve entries: 80 imported hours for
October 5–18 and the separate nine synthetic hours on October 19 and 26.
The twelve encrypted local previews remain unattempted. Repeated review reused
the same delivery; foreign-company access, missing review permission, altered
receipt hashes and unconfirmed sending were rejected. Confirmed sending also
remained blocked by the disabled rollout. The actor was retired, all temporary
module grants revoked, the session logged out and its private cookie removed.
This is native HTTP acceptance, not a completed browser walkthrough.

The original HTTP preservation check stopped on the four permission-denial
audit rows, which its append allowlist omitted. An independent read-only check
attributed every new row to the reviewer or twelve previews, and verified every
pre-existing row unchanged. No review or provider delivery was repeated to
resolve that evidence gap. Quanta's 65 non-key collections remained exact;
the API-key count, indexes, accepted permissions and scope revision are intact.
Its whole-key fingerprint changed consistently with native last-use tracking;
the older whole-record hash does not prove a field-only historical comparison.
Deployment and both verification outcomes are retained in
`deploy/thunderhawk-core-legacy-time-20261111.json`. Earlier provider installation
evidence remains in `deploy/thunderhawk-quanta-legacy-time-20261111.json`.

Operational time activation stays disabled; no historical association has yet
been published. Cross-document source, capture, approval and lifecycle races
still need acceptance before enabling managed time. Then complete reviewed
association delivery, recovery and the managed capture/approval round trip.
Synthetic offboarding uses a separate assignment, never ending and reopening
PL-864. PurePay/PurePayroll authority-contract adoption and complete financial
reconciliation remain open.
