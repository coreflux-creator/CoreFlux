# Protected stored JobDiva review

Previously, stored assignment apply validated a review, loaded the selected
graphs, and resolved subcontract companies before beginning the graph-write
transaction. A concurrent source or Core edit could occur in that gap, and a
company resolution could persist even if the later projection failed.

The review now validates and writes within one dedicated serializable
transaction. Its normal reads retain shared/range locks through company
resolution, canonical graph projection and audit. Field-map configuration is
included in the review token, and mapping caches are flushed at review
boundaries. The native HTTP caller is the only application caller found; it
starts with no transaction. Protected nested calls use savepoints. An arbitrary
pre-existing transaction is rejected unchanged because it may already hold an
older snapshot at a weaker isolation level.

The isolation choice follows the database's locking-read semantics; see the
[MySQL transaction isolation reference](https://dev.mysql.com/doc/refman/26.7/en/innodb-transaction-isolation-levels.html).
The fixture additionally verifies the actual deployed database behavior with
two connections rather than treating that reference as acceptance evidence.

Stored review uses saved source evidence. A known company link or stored
company mirror can resolve a subcontractor; an exact saved corporation ID/name
can also establish its canonical company inside the transaction. Missing
evidence produces a refresh request. Provider calls are refused before
authentication while a stored-review scope is open. Normal source discovery
outside that scope retains its existing fetch behavior.

Some older graph helpers catch database errors. To prevent a swallowed
transaction-ending error from allowing later individual writes to commit,
the review temporarily disables autocommit and records a sentinel savepoint.
Completion must still find that savepoint. If the original transaction was
lost, subsequent implicit writes are rolled back. The connection's original
autocommit setting is restored, and its session isolation default is unchanged.
The native API returns a plain busy/changed-record message for database
conflicts rather than SQL error details.

## Evidence

Runtime `07a1b49fc2b6e52bf2ba0a1668b55667159bd13b` passed all eleven jobs in
[CI run 43126850272](https://github.com/coreflux-creator/CoreFlux/actions/runs/43126850272).
The first candidate passed 17 checks; an additional review identified the
transaction-loss case, and the expanded candidate passed all 20 before release.

The new checks cover competing writes after an ordinary SELECT, refused
provider fetch, changed source evidence, nonclassification field-map changes,
rollback of graph writes and new company identities together, missing and
complete subcontract evidence, nested savepoints, transaction loss followed by
an implicit write, session restoration and absence of financial posting.
The contention check reads the already-committed simulation tenant row and
attempts a no-op update from a second connection; it is not relying on an
uncommitted fixture INSERT to produce the lock.

The disposable run also passed the existing 30 commercial-release, 33 native
commercial-edit, 16 contract-review, 28 contract-ownership, 27 native-placement,
30 lifecycle, 17 earlier ownership, 13 ending and 17 request checks, plus
existing source-scope and rate checks. Both candidate runs removed their own
containers, network and volume, and preserved all 206 existing containers.
The provider-host coordination window was released after terminal cleanup.

The four-file correction was installed only in isolated Core staging at
`2026-11-12T00:28:53Z` (November 11 in New York). The installer verified 34
dependencies, exact file preimages/ownership and private configuration, kept
private rollback copies, and checked the new transaction scope through PHP-FPM.
All 368 tables and 34,356 original rows were preserved. At
`2026-11-12T00:29:07Z`, the exact installed code passed all 20 checks in simulation
tenant 2002. Synthetic business writes were rolled back, including writes in
the simulated lost-transaction case. All original rows matched afterward;
sequence gaps are allowed. The temporary web probe was removed.

Runtime hashes and evidence receipts are indexed in
`deploy/thunderhawk-stored-review-transaction-20261111.json`.

## Boundaries and remaining work

This closes the identified validation-to-write gap in stored assignment apply.
It is not a stress test of every writer or lock schedule. The HTTP workflow
remains bounded to twenty assignments per page. Source-company availability
should also be shown during preview, with a clear source-refresh action, so
missing saved evidence is visible before apply.

Native HTTP authorization has subsequently passed the deployed-pilot check,
and the disposable native-session round trip covers successful commercial
edits, source apply, ownership release and replay. See the acceptance update in
`docs/thunderhawk-commercial-ownership-release.md`. The signed-in browser
workflow still needs acceptance. The screen bundle, provider applications, production Core, Arabella
pilot and real payroll/payment/financial posting were unchanged.

The full integration goal remains open: additional participant/source-link
reconciliation, participant creation/removal and cycle-assignment atomicity,
retained-value reporting in automatic batches, cleared end-client chains,
rate ownership, new-placement failures, CSV/lifecycle invariants and activation
replay. Complete field/custom-field alignment, portal/onboarding/documents,
and financial-result/billing/AP/AR lineage and reconciliation acceptance also
remain. Verification labels in the field dictionaries must reflect actual
evidence rather than automatically inheriting this transaction result.
