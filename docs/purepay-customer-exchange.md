# Core client and PurePay customer exchange

Core can review an existing staffing client and create its local PurePay customer profile through a dedicated Core connection. The existing client number and PurePay customer ID remain separate. Creating a profile does not link QuickBooks, issue an invoice, change banking details, or execute payroll/payments. The existing reviewed Core record-link workflow remains the mapping used by placement exchange.

## Identity and field ownership

The source is the authorized `staffing_clients` directory selected by the Placements module. Its immutable reference contains Core installation UUID, directory tenant ID, entity type `client`, and canonical `CL-` number. PurePay derives `external_source=coreflux` and `external_customer_id=<UUID>:<directory>:client:<CL-number>`; callers cannot supply provider IDs or override that pair. Core shares legal name (falling back to display name), optional primary contact email, and optional primary contact phone. The review shows exactly those fields.

An existing delivered Core link is verified before looking for a new customer. The provider must also check its durable reference link first, then exact external identity. Legacy linked customers with null external IDs keep their original IDs and values. Dangling, ambiguous, cross-company, or conflicting identities hold the operation. Name matches are not identity evidence.

## Scoped contract agreed October 26, 2026

Version `2026-10-26.1` is advertised as `customer_input_contract_version` by the existing Core context endpoint. The read hint does not invalidate an unchanged company binding. Adding the `write:customer_inputs` capability does require renewed company/access review.

- `GET /api/v1/integrations/coreflux/customers/by-reference` takes the four reference fields. It requires a dedicated Core key with `read:coreflux`.
- `POST /api/v1/integrations/coreflux/customers` requires the additional `write:customer_inputs` permission and an Idempotency-Key. Body: `{contract_version, reference, name, email, phone}`. All extra fields are rejected by the provider.
- Response: `{contract_version, organization_id, reference, customer: {id, organization_id, name, email, phone, external_source, external_customer_id}, identity_basis}`. Basis is `core_link` or `external_identity`; nullable legacy fields stay null. Organization comes only from the authenticated provider key. Reads/recovered existing records return 200; newly created records return 201. Identical retries preserve the original response and status.

Core has no fallback to general customer APIs or the separate AP credential. Missing/mismatched contract advertisement prevents transport and hides the customer panel. Creation also requires a verified company binding, explicit provider capability, Core `placements.manage`, reviewed confirmation, and the environment switch `COREFLUX_PUREPAY_CUSTOMER_DELIVERY=1`. The switch defaults off. Source reads and receipt recovery retain their separate permissions.

## Review, delivery and recovery

The native Core connection panel accepts a client number and shows the provider company, exact contact projection and existing customer. A new operation stores one encrypted reviewed payload, key, source revision and connection binding in `integration_deliveries` as `customer.create`. Core commits its exclusive claim before provider IO. Changes to source, company or access invalidate that review. Repeated sends cannot dispatch an attempted request again.

After a successful create response, Core performs a fresh exact lookup before accepting the customer ID. This avoids treating an idempotent replay snapshot as current state. Provider identity, local outcome and audit are verified; a local save/audit failure leaves the original request held for recovery. History is recovered by reviewing the same canonical client number.

Customer-specific errors use `detail.code`. Core recognizes the agreed payload-conflict, legacy-key, in-progress, unresolved, external-identity-exists/ambiguous/not-found, connection-change and link-pending/unresolved codes without changing generic PurePay POST-409 behavior. Unknown/malformed responses remain uncertain. A known lookup 404 means only that no record is currently visible: an earlier creation may still complete. It never clears an attempted delivery or permits a new-key write. Read-only recovery can operate while creation is disabled and after a reviewed key rotation. The UI pins requests to the reviewed Core company and offers the existing record-link review after the customer is confirmed.

## Release status and acceptance

The latest combined customer candidate is
`a83819fe86d25f1d83f7aabfeb079d251df435f5`. Review reproduced two cases where
QuickBooks linking succeeded after a native deletion/merge had already acquired
the customer, allowing removal of the newly linked record. QuickBooks selection
and saved binding now share Core's durable native-ID authority, check the original
Mongo row, and recheck mapping changes before writing. The corrected suite passed
236 backend checks, including nine new race/identity-change checks and the existing
13 Core PHP checks, with zero external network attempts. Test databases stopped.

The unchanged frontend and scope code retains its prior 65 interface/handler,
12 scope and build passes. A verified 32-file source-only hosted review packet is
prepared. It contains no credentials or runtime staging/publication action and has
not been run on the hosted preview. The separate sign-in release/restoration must
finish before its shared environment is used. Customer publication, reviewed key
capability and the actual cross-application customer round trip remain open.
Evidence: `deploy/purepay-customer-qbo-lifecycle-20261026.json`.

The subsequent native lifecycle increment `508894f` protects Core-linked customer
IDs through delete, merge and undo, including simultaneous requests and historical
links. It uses durable company/native-ID claims, retains uncertain operations for
reconciliation, and restores removed IDs only through their original merge. Native
merge/undo updates are company-scoped, and malformed restoration snapshots are held.
Blocked actions show readable messages without announcing success or removing rows.

This increment passed 137 backend checks and 23 interface/handler checks. An isolated
combination with the peer's exact single-login source `480cf14964c3c377ebcc9a7134739376e8841595`
is recorded as `57ee8f7bf91aaa2e95c0126ceb2974e3d7cf095d`. It passed 227 backend checks
(including 13 actual Core PHP checks), 65 interface/handler checks and the complete
frontend build. All 25 non-server sign-in files and four changed server definitions
are preserved. External network attempts were zero; owned Mongo processes stopped.
Shared hosted source and credentials were not changed. The peer's final published
baseline, review, hosted acceptance and demo-key capability remain required.
Evidence: `deploy/purepay-customer-lifecycle-and-login-20261026.json`.

The isolated combined customer candidate `66827504473b93b8d31841fa02d6636b0ce1f209`
now combines the dedicated Core routes with pending general customer candidate
`7930546` on coordinated source `84042fde`. Tests reproduced a general-create
duplicate beside a legacy Core link and a general lookup returning a conflicting
record. Both paths now share Core reference ownership. New Core external IDs must
be canonical; historical opaque IDs remain readable. A legacy Core-only link is
reported with its existing ID without relabeling its null external fields.

Combined acceptance passed 117 backend tests (including 13 actual PHP transport
checks), 18 mounted interface tests, 12 key-scope checks and the frontend build.
External network attempts were zero and both owned disposable Mongo processes were
stopped. This is local acceptance, with no shared-preview or production change.
Native deletion/merge protection for legacy Core-only links, reconciliation with
the independent unified-sign-in update, review, hosted acceptance and the demo-key
capability remain open. Evidence: `deploy/purepay-customer-compatibility-20261026.json`.

The matching provider routes and capability are implemented in isolated PurePay candidate `d86ac2b2c2a43a33f95494811cb1899de02a13ff`, based on the coordinated live source `84042fde01d47f4e4ea2e40fcc23f9b54528cc71`. Fifty-eight backend/contract checks passed, including the actual app with disposable MongoDB and existing exchange fixtures. The actual Core PHP client passed 13 loopback HTTP checks against that provider app. Seven mounted UI tests, 12 key-scope checks and a frontend build passed. No external network attempt occurred. Evidence: `deploy/purepay-customer-provider-candidate-20261026.json`.

The provider preserves legacy links and coordinates creation with normal Core client linking through a durable reference authority. Its future generic external-identity API candidate must respect that same authority for the reserved Core namespace; that separate pending candidate is not included here. The reviewed patch has been handed to the PurePay chat for preview coordination. It is not installed or published, and does not establish hosted or actual Thunderhawk acceptance. No production key, financial entry or cutover has been authorized by these tests.

Core commit `81779d0d8ec1831140019f0a78e394d76890d0a7` passed all nine CI jobs (40345346110) and is installed in isolated staging (40345947763). The runtime flag is verified false. Thirty-two contract checks, 49 database checks and nine focused UI checks passed. Evidence: `deploy/purepay-customer-core-staging-20261026.json`.

Read-only production preflight 40346520387 found 77 pending migrations and a reviewed plan retaining three deployed migration variants, with no unresolved migration-plan blockers. Nine runtime/migration files differ from both the comparison base and candidate; fresh fingerprints match known repository history. This is provenance, not permission to replace them. Fresh backup/recovery, web/worker controls and cutover authorization remain required. Evidence: `deploy/core-production-preflight-20261026.json`.

Validation covers identity namespace separation, request/response field boundaries, scoped company ownership, held response-loss recovery, source/access drift, legacy link adoption, duplicate identity recovery, audit rollback, UI confirmation, company switching and repeated clicks. The database suite uses disposable MariaDB with an in-process provider fixture; it cannot prove real PurePay route behavior. Hosted provider implementation and cross-application acceptance remain required before activation.

Relevant checks: `tests/purepay_customer_contract_smoke.php`, `tests/purepay_customer_inputs_mariadb.php`, `dashboard/tests/pureCustomerInputReview.test.mjs`, and `dashboard/tests/purePayCoreContracts.test.mjs`.
