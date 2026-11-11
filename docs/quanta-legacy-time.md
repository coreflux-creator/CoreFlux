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

Core's web pilot remains on `26812ae8`. Its current native imports and connection
were used with the exact candidate contract staged privately to read all twelve
new provider contexts. Every original review proof matched. No association was
published; original key identity and read-only time permissions remain intact.
The deployment and read verification are recorded in
`deploy/thunderhawk-quanta-legacy-time-20261111.json`.

Operational time activation stays disabled. Next install the accepted Core web
candidate and validate its native review flow. Cross-document source, capture,
approval and lifecycle races still need acceptance before enabling managed
time. Synthetic offboarding uses a separate assignment, never ending and
reopening PL-864.
