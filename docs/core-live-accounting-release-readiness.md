## Quanta preview credential connected to isolated Core - October 23, 2026

Cloudways sign-in is restored. The existing user-created Acme preview key was
verified from Core, transferred over host-verified SSH, and stored with Core's
existing AES-256-GCM field encryption in simulation tenant 999. The private
staging configuration now selects the reviewed Quanta preview URL. No key was
written to chat, repository, workflow logs or an unencrypted credential file.

Both CLI and the actual PHP web process resolved Acme Construction workspace
0598d4ef-3075-4289-9714-c93f904aa85d and verified approved-time access. The temporary
web probe required its token (anonymous 404), returned 200 and was removed.
Document intake and payroll authority remain off. The new connection is not yet
workspace-confirmed; no remote record was written. PurePay key creation remains
pending. This is authenticated demo connectivity, not Thunderhawk round-trip
acceptance. Receipt: `deploy/quanta-preview-connection-20261023.json`.

## Quanta key creation corrected; preview routing staged - October 23, 2026

Quanta's preview returned HTTP 500 because its API-key persistence model omitted
three separately selected onboarding scopes already supported by the UI and API.
Unpublished review 4f7bca6c277876ff62e99389a4f736ee0a11ea5b corrects that model.
All 111 full-application checks passed; existing records/configuration/shared
HEAD and index were preserved. The user retried Create successfully. Its one-time
credential is retained privately pending transfer to isolated Core.

Core runtime 163a08e524454fc5d6b4c1bc390fb572e0540d17 now supports the exact
reviewed Quanta preview origin only in staging. All nine CI jobs (40049443874)
and full staging acceptance (40049649900) passed. Ten packaged files changed,
no migrations were pending, and the permanent integration identity was retained.
The setting itself is not yet configured and no provider key is installed.
Document intake and payroll authority remain disabled. The receipt is
`deploy/quanta-preview-endpoint-staging-20261023.json`.

PurePay's stopped review backend was resumed after verifying source/configuration;
all 20,596 records across 112 nonempty collections were unchanged. Its review
mode keeps background jobs, migrations and scheduled operations off. The existing
Meridian demo key form remains prepared but unsubmitted. Cloudways sign-in expired
and Google sign-in stalled; user completion is pending. Neither provider candidate
was published and the actual Thunderhawk round trip remains outstanding.

## Division alignment staged and verified - October 23, 2026

Runtime 58b9d732dbb172f2cf310ae9e8ba0c81cbb0bb3e passed all nine CI jobs
(40045657064) and full isolated staging installation/acceptance (40045911533).
Thirteen packaged files changed, no migrations were pending, and the permanent
Core integration identity was retained unchanged. Private local settings are
excluded from package replacement; the prior S3 acceptance is not being claimed
as a new test. The private file/database backup is recorded in
`deploy/thunderhawk-division-staging-20261023.json`.

Native onboarding passed all 17 HTTP checks; accounting lifecycle, split receipt
and correction, 61 credit checks, and log-only invoice/statement delivery passed.
Document intake remains disabled before provider/storage IO and payroll authority
remains false. This stages the branch-division correction described below; live
Core runtime and the actual provider round trip remain unchanged/unverified.
The user-directed remaining sync alignment continues independently of employer/WC.

## Fresh production inspection and source alignment - October 23, 2026

Read-only run 40044783352 succeeded for b247f8ee2171b0b36e56c153ee3abf90f3768e43.
The deployed bundle remains index-95sB_8ni.js. Candidate packaging changes 413
files and adds 4,689 (including dependencies); 99 production-source paths differ
from both comparison base and candidate. The reviewed migration plan remains
source-review-ready: 68 pending, 248 retained applied, three deployed historical
sources retained, no plan blockers. Production still has 30 accounting schema
gaps and the incompatible bank-account uniqueness constraint. All eight CLI
outbound/authority flags are false; production storage is still local.

Compact non-secret receipt: deploy/core-production-preflight-20261023.json.
Use the private unmasked /tmp/core-integration-40044783352/production-preflight.json
for any later exact comparison. Masked GitHub values are not execution identities.
No activation occurred. This inspection predates the subsequent JobDiva division
fallback patch and does not replace final candidate binding, verified writer
pause/drain, matching backup/recovery or explicit cutover authorization.

Core sign-in is now restored. PL-864's source-backed branch was saved separately
as a Core override; employer/WC remain unresolved while the rest of the sync
alignment continues per the user's instruction. The complete current checkpoint
is at the top of docs/thunderhawk-core-operating-pilot.md.

## Quanta webhook contract correction - October 23, 2026

The latest unpublished PurePay review is d942c386a008455e4c5a80e478e253aceb9efa6d
on codex/coreflux-quanta-webhooks-20261023, above setup review
aca0895ce9c42d376183b2d3435e3ed6582ddeb0. The eight-file review uses a private
Git index. Shared release 9cd71c83, working source/index, prior review refs and
protected configuration remain unchanged. No deployment or settings save ran.

The actual Quanta dispatcher uses X-CH headers and signs timestamp + '.' + raw
canonical JSON with HMAC-SHA256. The old PurePay receiver read different headers
and accepted unsigned notifications if no secret was configured. The candidate
requires the signing secret and a matching configured workspace ID; rejects
missing/invalid/stale signatures, foreign workspaces, mismatched event headers,
malformed/ambiguous JSON and streamed bodies over 256 KiB before receipt/import
effects; and preserves review/select and automatic-sync policy. The settings
screen explains that missing signing configuration blocks notifications. A
background task is reported as queued, not already synced.

All 39 checks passed against the full hosted app with synthetic in-memory data
and zero external socket attempts. This includes signed retries in reviewed mode
without worker/time/payroll writes or another company's receipt changes. The
complete 448-file frontend compiled without warnings in a private source copy.
The unchanged sender produced the golden signature fixture. These tests establish
authentication and reviewed-mode behavior, not live automatic-import delivery or
exactly-once execution; the existing background sync/catch-up mechanism remains.
Evidence is in PurePay-current-source/verification/quanta-webhook-*-20261023.*.

A fresh read-only live check still found two Thunderhawk Quanta administrators
and no workers. PurePayroll remained connected in review/select mode with auto
catch-up off, no imported workers/time, and 33 employees not in Quanta; the signing
secret and workspace ID were unset. Those settings were not changed. The Core
pilot still redirected to login in the accessible Chrome session. The existing
questions for release settings, pilot employing entity/business unit/WC class,
Core access and private document storage remain pending. Shared-directory JobDiva
projection is not a blocker for Thunderhawk's verified tenant-2 directory.

Core's staged runtime remains 9f656c59a256514bb5ddbca6a2b51e17ed085857. Quanta's
latest unpublished review remains a0ff1371274f35c84a831fd61958b0a9cab4ee92.
Next: resolve the existing operational prerequisites and demonstrate the actual
Thunderhawk round trip; do not substitute more unrelated refactoring for it.

## Quanta upload recovery checkpoint - October 23, 2026

Quanta's current unpublished hosted review is
`a0ff1371274f35c84a831fd61958b0a9cab4ee92` on
`codex/coreflux-onboarding-document-recovery-v2-20261023`, based on the prior
document review `acee0f1a5592ccb2bb46a965fe50671b393076b9`. Local mirror:
`31419ecd3ff4902af6086f2e9b4a628c1fe3095b`. Every saved source matches the local
UTF-8 candidate. The earlier retained recovery ref `6d9844ca` is superseded
because its frontend transfer had an encoding mismatch.

The full hosted application passed 165 backend checks and frontend compilation
with zero external socket attempts and no live database. Local checks passed
164 backend cases, including native Core PHP attestation/document boundaries,
and 12 mounted portal cases. Shared development HEAD, source, index and protected
configuration were preserved; no checkout, deployment or permission change.
Receipts live in Quanta-source/verification/onboarding-document-recovery-v2-*
20261023.json (acceptance and review).

Each Quanta upload request and physical GridFS attempt is durably recorded before
IO. Completed copies are verified before reuse; a lost write acknowledgement or
failed receipt checkpoint can recover the original copy. A lease expiry permits
a new physical ID without overwriting an interrupted writer. Late/partial writes
remain private and attributable, with one selected file and one case receipt.
Missing or altered selected copies cannot be recreated silently. The worker can
refresh and resume the original request with the same file, and read safe transfer
history even after upload is disabled or the publication is withdrawn.

This completes the new-upload recovery counterpart to Core's staged reservation
and atomic vault import. Legacy untracked files and retention/purge policy remain
open, along with malware scanning and actual provider-to-private-S3 acceptance.
Core staged runtime remains `9f656c59a256514bb5ddbca6a2b51e17ed085857`; this
checkpoint changes documentation only. Production is unchanged.

The available Chrome session still redirects PL-864 to Core sign-in after the
latest user response. Existing questions about release settings, PL-864 legal
entity/business unit/WC class and the intended private S3 account/bucket remain
unanswered. The Thunderhawk live round trip remains incomplete. No real payroll,
payment, financial posting, document purge or production cutover occurred.

# Live Core accounting release readiness — October 21, 2026

## Durable document transfer recovery - October 23, 2026

Core runtime `9f656c59a256514bb5ddbca6a2b51e17ed085857` passed all nine
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39888945027).
[Isolated staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39889272095)
also passed for this exact revision, including 17 native onboarding HTTP checks,
accounting lifecycle, split receipts, 61 credit checks and log-only delivery.
The retained-file HTTP history read works without provider or storage access.
Bundle: `index-DE24O-6a.js`.

Migration 173 adds a tenant/case/person/worker-receipt transfer record committed
before file IO. It reserves one physical key and binds the receipt, descriptor,
provider connection and storage namespace/encryption configuration. Rotating S3
credentials does not change storage identity; changing the bucket or KMS key does.
Retries freshly validate provider authority, then verify and reuse the reserved
copy. Conditional writes never overwrite a file. A lost object-write response is
reconciled through exact stored-byte verification. Missing or altered previously
verified copies are held for reconciliation; retries do not replace them.

The stored-file checkpoint commits separately from business registration.
Registry/document/case/history/transfer registration still commit together; a
business rollback leaves the verified copy attributable and reusable. Interrupted
source fetches and failed file checkpoints keep the original reservation. New
Core copies can therefore be traced even when the final import fails. This does
not make physical storage and SQL one distributed transaction.

The People onboarding panel can read retained transfer history directly from
Core, including when Quanta is unavailable. It shows unconfirmed, prepared and
registered states without file paths, signed URLs or storage/binding fingerprints.
The read requires the current case and directory scope. No file is deleted or
approved by this recovery/history workflow. Retention/purge policy and Quanta's
own unreferenced GridFS attempts remain separate open work.

Native MariaDB checks now pass 104 document-intake assertions, including committed
reservations visible to an independent connection before physical writes,
source-fetch failure, lost PUT acknowledgement, checkpoint interruption, altered
or missing objects, backend changes, migration replay, tenant/directory isolation
and concurrent import. All 110 UI checks pass, including provider-independent
history and stale case-response rejection. Local storage checks pass 39 assertions.
The packaged S3 SDK checks namespace/encryption identity and conditional encrypted
writes without network IO. UI proof: `quanta-document-recovery-synthetic-20261023.png`.

Live intake remains disabled. Core S3/KMS configuration and real object access
are unverified; the user has been asked which existing AWS account/bucket to use
or whether setup is still required. The existing Core sign-in, release-setting
confirmation and PL-864 field questions remain pending. No provider candidate,
protected setting, production release, access grant or financial execution changed.
The previous checkpoint below remains valid for the initial import implementation;
its untracked-Core-copy caveat is superseded for the new reserved-write path.

## Quanta document vault intake - October 23, 2026

Core runtime `4ae64ebef77506621d0dc94504405d4112fdd37a` implements the
previously open document-to-vault step. All nine
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39886695711)
passed. [Isolated staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39886917779)
also passed for that exact runtime: 16 native onboarding HTTP checks, accounting
lifecycle, split receipts, 61 credit checks and log-only delivery. The new route
rejects browser-supplied storage identity and stays disabled before provider or
storage IO. Deployed bundle: `index-Cf-okM0C.js`.

The employer selects an authenticated Quanta document receipt. Core rechecks the
worker, person, placement, workspace binding and delivered requirement before
retrieval, after physical storage, and under the final database locks. Bounded
PDF/PNG/JPEG bytes must match receipt size, type and SHA256. The private storage
write uses a generated person-owned path and refuses overwrite. The original
Unicode filename is retained as display metadata. Registry entry, person document,
onboarding evidence, case revision and durable import receipt commit together.
An identical retry returns the committed document without another file transfer;
concurrent commits produce one registered document and one history event.
Imported evidence remains submitted for separate employer review. No signature,
document classification, worker activation or payroll authority is inferred.

Native MariaDB acceptance passes 53 document-intake checks, including changed
worker/directory/connection/placement authority, withdrawal, altered stored bytes,
transaction rollback and overlapping requests. The existing real-storage vault
suite now passes 30 checks. Local checks pass 55 bounded-transfer checks, 33
vault-input checks, 33 storage checks and nine mounted portal UI checks. The
packaged AWS SDK verifies encrypted conditional byte-upload requests with no
network IO. Three native PHP-to-Quanta HTTP cases pass PDF/PNG/JPEG transfer with
scope and identity revocation. Dashboard build and all 108 mounted UI checks pass.
Synthetic UI evidence: `quanta-document-intake-synthetic-20261023.png`.

Document intake remains default-off with exact Core tenant and Quanta workspace
allowlists, explicit document-management authority and a separately scoped
provider capability. HTTP intake additionally requires configured private S3
storage and KMS. Production CLI inspection still reports local storage and no
bucket/KMS key; web configuration and real object access remain unverified.
Quanta's hosted document review remains unpublished at
`acee0f1a5592ccb2bb46a965fe50671b393076b9`; its runtime was not changed here.
The prior read-only adapter notes below describe the earlier checkpoint.

Physical storage and SQL are not a distributed transaction. Failed, uncertain or
concurrent attempts can leave private unreferenced objects. Reconciled cleanup,
malware scanning and an actual provider-to-Core-vault live transfer remain open.
No production activation, provider publication, new access grant, real payroll,
payment or financial posting occurred. The existing release-settings confirmation
is still pending. Core redirected PL-864 to sign-in again; a fresh login was
requested. Its employing legal entity, business unit and WC class remain unconfirmed.

## Person document vault fixes staged - October 22, 2026

Runtime 56bc0b53004055e5d7ae87aa17ce0e99b06ed98b passed all nine
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39880381963)
and [isolated staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39880650289).
The People vault now binds uploads and registration to the current directory
owner and exact person. It verifies stored bytes before registration, preserves
idempotent retries, rejects stale ownership or retired objects, and commits
document/registry/resume changes together. Metadata edits cannot reassign
identity or storage. The browser upload response and packaged S3 loading/signing
contract are repaired, including bucket-owner-enforced compatibility.

CI passed 30 input/ownership checks and 27 MariaDB checks against actual local
stored files, alongside the existing 60 onboarding and 29 portal-exchange checks.
The installed AWS SDK passed signing checks without object IO. Staging passed
14 native onboarding HTTP checks, accounting lifecycle, split receipts, 61 credit
checks and log-only delivery. This is not hosted S3 or live Thunderhawk acceptance.

The [read-only production storage inspection](https://github.com/coreflux-creator/CoreFlux/actions/runs/39880275057)
on d40f7364 reports a local driver and no configured S3 bucket or KMS key in CLI.
Web configuration and real object access remain unverified. Full Core browser
document upload requires configured private S3 storage; no local fallback was
enabled. Quanta worker file upload/transfer is still open. Production was not
activated, and provider candidates remain unpublished.

The existing Live/Test setting confirmation remains pending; both browser forms
are preserved unsaved. PL-864 still needs its legal entity, branch/business unit
and WC class. The user's admin tab displayed a cached workspace, then redirected
to login when placements loaded. No usable Core session is claimed. These
release and pilot requirements remain open; the integration goal is active.


## Core-to-Quanta onboarding exchange staged - October 22, 2026

Core runtime ce7d2b3bca55f98a96d893084628ebb06de4ea56 passed all nine
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39876630812)
and [isolated staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39876938832).
The People onboarding screen now reviews and publishes exact requirements,
recovers uncertain acknowledgements, and imports authenticated Quanta worker
attestations as submitted evidence for a separate employer decision. Current
workspace, person, assignment, source and task versions are checked before
exchange. Replay preserves the original receipt even after employer review.
Outbound publication defaults off. Private documents remain in Core's vault;
portal file upload/transfer and hosted object verification are still open.

The database fixture passes the existing 60 onboarding checks plus 29 new portal
delivery/import checks. Twelve Core UI checks, 23 pure contract checks and the
actual PHP-to-Quanta HTTP bridge pass. Native staging's 14 HTTP onboarding checks,
accounting lifecycle, split receipts, 61 credit checks and log-only delivery pass.
No new migration was needed for this exchange. Payroll authority remains off.
The local onboarding-exchange-synthetic-20261023.png demonstrates the new screen
with synthetic records, not live Thunderhawk data.

Quanta's unpublished hosted review is
7bdfa656c34143a469119178618ddf514c800332 on
codex/coreflux-onboarding-20261023, above 8f67fc33892a9060c1c35de03bc9b84753896637.
It captures 17 runtime/test/harness files. Actual full-application startup and
120 checks passed with memory-only data and zero network attempts; the complete
frontend compiled successfully. Local API suites pass 91 checks and portal UI
suites pass 13. Shared source, HEAD, index and protected configuration were
preserved; this review was not checked out, pushed or published. Receipts are
saved in Quanta-source/verification/onboarding-acceptance-20261023.json and
onboarding-review-20261023.json; the matching local mirror is f9fc567.

The ce7d2b3b [read-only production inspection](https://github.com/coreflux-creator/CoreFlux/actions/runs/39877910889)
completed without activation. Production still serves index-95sB_8ni.js; staged
Core uses index-D2UJYu_-.js. The report found 411 changed files and 4,673 added
packaged files. Its seven reported outbound/authority controls were false.
Follow-up 4e7513a58c20bdec839caab94edc10ae3d934c3d adds the omitted onboarding
control to both CLI and web release reports, with a real HTTP regression check.
It passed all nine [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39878325180),
including actual HTTP verification of all eight controls. The fresh
[production preflight](https://github.com/coreflux-creator/CoreFlux/actions/runs/39878329714)
also passed: all eight inspected CLI controls are false, migration source
review is ready, and activation is neither authorized nor performed.
Production still has 30 accounting schema gaps and the incompatible bank
uniqueness constraint. The private unmasked inspection receipt is retained
at /tmp/core-integration-39878329714/production-preflight.json. Masked
GitHub log fragments must not be used as execution identities. The staged
business runtime remains ce7d2b3b; 4e7513a5 changes release reporting only.

The live Thunderhawk round trip is not complete. Provider publication, reviewed
live scopes/company binding, and PL-864's legal entity, branch/business unit and
WC class still require resolution. A fresh Core check returned to sign-in after
the user's login reply; the session is not assumed usable. The previously
prepared Live/Test setting forms remain unsaved pending their existing user
confirmation. PurePay source/configuration was unchanged this turn; its other
chat acknowledged the release coordination update. Production maintenance,
writer drain, backup/recovery and owner coordination remain separate release
gates. Arabella remains separate. See docs/staffing-onboarding.md.



## Core onboarding staged - October 22, 2026

Runtime 209fd027c963fae9de800ad8b310303c01547349 passed all nine CI jobs
([39872618074](https://github.com/coreflux-creator/CoreFlux/actions/runs/39872618074))
and isolated hosted staging acceptance
([39872941045](https://github.com/coreflux-creator/CoreFlux/actions/runs/39872941045)).
The native HTTP walkthrough passed all 14 checks through temporary synthetic
maker/reviewer sessions: person and draft placement creation, case/retry,
requirements, confirmation, submission, stale-review rejection, review, history
and revision. Person/placement activation stayed unchanged. Accounting lifecycle,
split receipts, 61 credit checks and log-only delivery also passed. Payroll
authority remains disabled.

Core now has employer-reviewed, placement-linked onboarding cases in the People
record. An empty case is never complete. Confirmed requirements, current evidence,
explicit decisions/waivers and append-only history determine readiness. Source
setup changes, document changes and expiry invalidate prior completion. The
MariaDB fixture passes 60 checks, including two genuinely overlapping retries;
six UI behavior checks pass. A local synthetic visual review is retained in
onboarding-review-synthetic-20261022.png. Hosted document-object verification is
not claimed: the hosted walkthrough uses attestation, while document version and
access cases use an injected storage-head fixture in MariaDB.

Native acceptance exposed old installation gaps. Placement migration 009 repairs
missing graph-source columns without remapping cycle IDs or changing negotiated
values. People migration 009 preserves both document-table generations without
inventing person or storage links. Core migration 172 makes the existing shared
storage registry part of canonical installation and release verification. These
repairs are applied in staging; production has not been migrated.

The matching read-only production preflight
([39872962766](https://github.com/coreflux-creator/CoreFlux/actions/runs/39872962766))
completed at 23:42 UTC for the same 209fd027 runtime. Production still serves
index-95sB_8ni.js; the candidate uses index-DD7X6JD2.js. It found 411 changed files,
4,667 added packaged files, 67 pending migrations, 30 accounting schema gaps
(including the two shared storage tables) and the existing incompatible bank
uniqueness constraint. Migration source review is ready with no blockers;
activation_authorized and activation_performed are false. All seven outbound and
payroll-authority flags remain false in the inspected CLI configuration. The
unmasked receipt is retained privately at
/tmp/core-integration-39872962766/production-preflight.json; masked log values are
not valid execution identities. Production maintenance, writer drain, matching
backup/recovery and owner coordination remain outstanding.

The Core task/evidence workflow is implemented. Quanta portal publication, worker
submission exchange, provider publication and real Thunderhawk round-trip
acceptance remain open. PurePay and Quanta review refs and unsaved Live/Test forms
remain unchanged. PL-864 was observed Active with legal entity, branch/business
unit and WC class still blank; this chat did not change it. A later fresh Core
reload redirected to sign-in. Neither the session nor the missing setup is
assumed resolved by this synthetic walkthrough. See docs/staffing-onboarding.md.


## Employee setup read staged and companion reviewed - October 22, 2026

Core runtime b7579c33d83fc60db09130a53e2659df77a4fa18 passed all nine CI jobs (39865016278) and isolated staging installation/acceptance (39865683398). No migrations were pending; payroll authority remains disabled. The added read-only employee setup view requires exact confirmed person links and separately reviewed capability. It reports native indicators without private values or onboarding/payroll approval. The 71-check MariaDB link fixture includes eight new access, identity and concurrent-change cases.

PurePayroll hosted review aca0895ce9c42d376183b2d3435e3ed6582ddeb0 on codex/coreflux-setup-20261022 adds 11 source/test files above 7dbab9b0. Twenty full-app route cases passed using candidate overlays, memory-only data and zero network attempts. A complete 448-file frontend compilation passed without warnings. Shared source, HEAD, index, configuration and the prior review ref were preserved; no checkout, service restart or publication occurred. Existing development preview still runs the earlier source.

Core live sign-in is restored in Thunderhawk. PL-864 now displays Active; legal entity, branch/business unit and WC class are still blank. This chat did not change its status or fill those fields. Quanta native checklists are clock-in tasks, and PurePayroll detailed new-hire onboarding is SurePayroll-specific; neither establishes generic completed onboarding. Full task/evidence review, portal delivery and real Thunderhawk round-trip acceptance remain open. Both prepared Live/Test corrections are still unsaved pending the existing confirmation question. See docs/purepay-employee-setup-exchange.md for contract and evidence details.



## Current release checkpoint — October 22, 2026

The host-maintenance library now separates successful release activation from
recovery. `prepareActivation()` retains the exact candidate routing with its
revision and manifest fingerprint while the web and scheduler remain held.
`activate()` reopens that candidate routing and restores the reviewed scheduler;
`restore()` reopens the original routing only after matching data/file recovery.
A persisted recovery request prevents a stale activation retry from reopening a
rolled-back release, including releases whose routing bytes did not change.
All replacement paths retain the original UID, GID and file mode.

For `f83349ec70717f0464b3229a7446247b54ae7d5c`, the
[native-host/Apache CI job](https://github.com/coreflux-creator/CoreFlux/actions/runs/39861995816/job/120114736001)
passed 47 host checks at 22:04 UTC, including changed manifests, corrupted
candidate files, concurrent scheduler/routing edits, repeat activation, explicit
recovery, and fresh-process completion from a simulated candidate-installed,
scheduler-held boundary. The boundary fixture deliberately writes that partial
state; it does not claim a forced crash between the adapter's native calls.
The existing Apache rehearsal and 22 local maintenance-preparation checks pass.
All nine [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39861995816)
also passed for this revision. This deployment-library change was pushed; it
does not replace the currently staged business runtime or activate production.

This remains a library with no production activation entry point. The caller
must verify the whole release, database state, required approval and writer
drain before activation. It does not resolve the live UID mismatch, inventory
other scheduler owners, make two native replacements atomic, or establish a
production backup/restore procedure. The nine live schedules were not changed.
The production file owner and scheduler owner require a coordinated hosting
procedure; merely running the existing combined adapter as the file owner does
not establish control of the deployment account's separate scheduler.

The updated accounting experience remains absent from production. Core runtime
ae411200940fbc52e400d559eca8e44668b083e1 passed all nine
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39853929574)
and [isolated staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39854380249)
on October 22 at 21:08 UTC. Sixteen changed files were installed and verified;
no migrations were pending. Accounting lifecycle, split receipts, 61 credit
checks and log-only delivery passed. Source guards are ready and payroll authority
remains disabled. This staged runtime includes the exact current Quanta source
checks before approval, settlement and payroll-input delivery. Migration 170 was
applied by an earlier staging release. No production cutover occurred.

A fresh [read-only production inspection](https://github.com/coreflux-creator/CoreFlux/actions/runs/39857631281)
completed October 22 at 21:31 UTC against candidate
7c4a3c9d4c90a3d93bc7dfd88b7791dd86c546d5 (documentation follows the same runtime).
It found 410 changed files, 4,655 added packaged files, 63 pending migrations,
28 accounting schema gaps and one incompatible key. The reviewed migration plan
keeps 248 applied migrations and three exact deployed historical sources, with
zero source-history blockers. Production still serves index-95sB_8ni.js; the
fresh candidate build is index-DnJ_wGxU.js. All seven new outbound/authority flags
are false in the live CLI configuration. Activation was not performed.

The private candidate and full unmasked receipt remain at
/tmp/core-integration-39857631281/production-preflight.json. GitHub masks parts of
the plan hash and some numeric values; do not use masked log values as execution
identities. The source review is ready, but activation is not authorized. The
production file-owner execution/recovery procedure, writer maintenance/drain,
matching final backup, exact candidate/recovery binding and cutover authorization
remain open. Earlier inventory counts and fingerprints below are historical.

The user's live invoice screenshot was in **charge d'Affaires**, while the
authorized operating pilot is **Thunderhawk**. Switching the existing Chrome
invoice tab to Thunderhawk immediately exposed its clients. The read-only
production inventory confirms 1,000 active/nondeleted client-role company
records for Thunderhawk. The picker shows 12 suggestions and supports search;
it is not a complete list of the directory. No invoice or client was created.

The existing live accounting dashboard works at
`https://www.corefluxapp.com/modules/accounting/bookkeeping`. It was opened and
verified. `/modules/accounting/overview` on the current live bundle renders
only the module header. The newer candidate has that overview route and a
fallback; commit `08c4eadc` also changes the main Accounting sidebar link from
journal entries to the overview. The updated accounting code remains absent
from production. Opening the old dashboard does not install that code.

## Verified release gap

- Current live frontend: `spa-assets/index-95sB_8ni.js`.
- Candidate frontend at this inspection: `spa-assets/index-BV7etMTV.js`.
- [Read-only production comparison](https://github.com/coreflux-creator/CoreFlux/actions/runs/39686986781)
  at candidate `e3fdb0a6f3db698357084add71b65fec7d3350b7` found 385 changed
  files and 4,590 new packaged files (including dependencies/tests). There are
  59 migrations without a matching record in either `_migrations` or
  `coreflux_migrations`; the second ledger does not account for the gap.
- The actual accounting schema lacks 28 required columns/tables/unique keys,
  including journal-void audit fields, credit applications, invoice delivery
  state, receipt journal/bank lineage and entity-scoped bank cash uniqueness.
  Its old tenant-wide bank cash-code unique key is incompatible with the new
  entity-scoped design. This is an actual schema gap, not just a stale ledger.
- All four new integration outbound/authority flags read false from deployed
  CLI configuration. Web/worker configuration still needs verification before
  production activation.
- Three applied historical migrations differ from the candidate, but their
  deployed files match their recorded successful hashes. These are not failed
  migrations. Reviewed differences: 007 adds an idempotent parent-column check;
  041 changes only a comment; 043 replaces ADD COLUMN IF NOT EXISTS with an
  information-schema guard. Preserve this provenance; do not silently relabel
  existing migration records as if the new source had already run.

## Production source and configuration

[Source fingerprint audit](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687312451)
identified seven runtime/SQL files different from both main and candidate.
Every normalized live fingerprint resolves to an existing local Git object:

| Path | Deployed Git blob |
| --- | --- |
| core/config.php | 296510537cedf7c66433479e922cd636023be9de |
| session.php | 098d9312e390785535cf3ba5ec8503507041e8e1 |
| modules/people/lib/companies.php | 90967191c03f82a2c8843834110db37ca2d5d8fa |
| modules/placements/lib/economics.php | 0757876c1b462785b82fee0c07d9df26f167174e |
| core/migrations/007_subtenant_provisioning.sql | 9d2477212bf669aec2d5be613ede9e22a4517dbb |
| core/migrations/041_csv_mapping_presets.sql | 8f8a853bc225dd2a96391c0fb6d0e7c16a2e4bdc |
| core/migrations/043_simulation_harness.sql | 09515e5b299f867e6b120009a5e3982690e082ca |

The company changes add canonical numbers and retain the earlier country fix.
The economics change preserves reviewed vendor identity/classification instead
of overwriting it. Session changes support tab-pinned tenant context. The live
configuration still contains legacy connection settings; the candidate reads
private configuration/environment instead. **Do not replace that file until
the existing DB and mail settings are preserved outside the webroot and the
candidate's CLI and PHP-FPM bootstraps are verified. Do not print their values
or copy them into Git.** Existing encrypted integration configuration and keys
must remain intact. This is a concrete activation prerequisite.

## Validation and remaining work

### Invoice workspace clarity and exchange inventory follow-up

The candidate invoice editor now shows the active workspace beside its title
and explains that the initial client suggestions contain up to 12 records;
typing searches the directory. Both create and edit routes pass the actual
session context. This addresses the ambiguity in the reported screenshot,
but does not change tenant scope, create clients or activate the release.

The downloadable staffing field dictionary now includes 38 source-backed
payload mappings across 31 source fields: Quanta shared identities and
assignments, PurePay identity links, and PurePayroll approved-time inputs with
their frozen rates and persisted Quanta provenance. References retain their
instance, directory owner and entity type; bill-to clients remain distinct
from end-client companies. Multiple destinations remain separate. Verification
is explicitly source-level, with hosted acceptance pending. Native employee
provisioning, commercial assignment updates, portal access/onboarding and
settlement attribution remain explicit workflow gaps. The inventory cannot
enable delivery or change provider records.

Local validation: 126 record-number/field-contract checks, 51 disposable
record-number MariaDB checks, 117 delivery MariaDB checks (including advertised
Quanta paths against the actual sender), PHP syntax checks and the dashboard
production build passed.
The build has existing hook/style/bundle-size warnings and no lint errors.
The live session expired before placement detail verification; the sign-in
request remains pending. No production activation occurred.

### Exact migration review plan

The read-only production preflight now emits a deterministic `migration_upgrade_plan`
bound to the candidate revision, full file manifest, both migration ledgers and
the exact deployed migration bytes. Its order matches the canonical runner:
natural core-file order, then natural module-file order. Each file is classified
as pending, already applied, a reviewed deployed source to retain, or blocked.
The three historical exceptions require their reviewed Git blobs and a matching
successful ledger record. No record is rewritten to pretend the candidate SQL ran.

Failed history, missing deployed proof, unknown changes, unreviewed dual-ledger
history and unreviewed recorded migrations absent from the package block source review.
Legacy-only history requires explicit reconciliation because the automatic
runner reads the canonical ledger. A saved review becomes invalid if the revision,
manifest, deployed SQL or either ledger changes. The planner executes no SQL;
`source_review_ready` is not production approval or migration readiness. Backup,
writer maintenance, recovery, a guarded executor and cutover approval remain open.

The first exact [production preflight](https://github.com/coreflux-creator/CoreFlux/actions/runs/39702452549)
confirmed 60 pending migrations, the same 28 accounting schema gaps and one
incompatible key. The three historical source exceptions matched. It also
identified 13 differing dual-ledger versions and 18 recorded paths absent from
the package. The [read-only provenance audit](https://github.com/coreflux-creator/CoreFlux/actions/runs/39702950574)
confirmed all 18 paths are already absent from the live source tree. They are
retired history, not files removed by this release. All 31 recorded checksums
resolve uniquely to Git versions (47 SQL blobs inspected); the old versions in
the secondary ledger are preserved alongside the canonical successful history.

`deploy/full_core_migration_history.json` binds each reviewed path, recorded
checksum, normalized checksum and source Git blob. It allows only these exact
older/retired records. A changed hash, failed canonical record, differing retired
legacy record or reappearing retired file still blocks review. Retired SQL is
neither resurrected nor scheduled; neither ledger is rewritten. The production
preflight retains its complete receipt privately beside the uploaded candidate,
so masked GitHub logs are not used as literal migration paths or hashes.

The local fixture passes 41 checks using actual historical sources, older and
retired ledger scenarios, changed source/ledger scenarios and CRLF preservation.
Linux CI also checks symlink refusal. Candidate `4e601ac3` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39702461616).
The reconciled plan passed the fresh [production preflight](https://github.com/coreflux-creator/CoreFlux/actions/runs/39703479716)
for `3b11735550d8c941f660e87beefaa40370a80a43`: 248 migrations already applied,
three reviewed deployed sources retained, 60 pending, 18 retired ledger entries
preserved and 13 reviewed earlier legacy versions preserved. Source review has
zero unresolved blockers. The 6,745-file package has its full unmasked receipt at
`/tmp/core-integration-39703479716/production-preflight.json`; preserve this private
candidate and receipt. The plan hash is
`677a939300edfcbacbaad974e80e7540f3ef328add30a849e6feb1802c4ee876`.
All eight [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39703490212)
passed for that same revision. No migration or activation occurred; the live
schema still has 28 gaps and one incompatible key, and all four delivery/authority
flags remain false. This completes source-history reconciliation, not the
backup, writer maintenance, guarded execution, recovery or cutover gates.

### Private connection gate completed

Candidate `fa8a1cfca8ece9b584a4f5c7a7da646a50761b99` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39689964412),
including 35 private-settings/HTTP checks on Linux. The subsequent
[production preparation](https://github.com/coreflux-creator/CoreFlux/actions/runs/39690256340)
passed both the real CLI and PHP-FPM read-only connection checks. Private
settings are preserved under `private_html/core-full-release-<candidate>/`;
the intended activation bridge was exercised in the private candidate copy.
The temporary public probe was removed, the live configuration hash stayed
unchanged, and all four new delivery/authority flags were false in PHP-FPM.
The saved CLI receipt intentionally still says `web_verification: false`;
the separate web receipt establishes successful PHP-FPM verification.

No runtime bridge was installed, no migrations ran, and the new accounting UI
is still not active in production. The preparation upload is retained at
`/tmp/core-integration-39690256340`. Local sanitized receipts are saved as
`core-production-settings-39690256340.json` in Temp. The first CI fixture run
failed because its SELECT-only account did not allow the GitHub database
container bridge; that disposable fixture was corrected and all checks passed.
No production settings were used in CI.

### Upgrade execution rehearsal and recovered hosting access

`deploy/full_core_upgrade_executor.php` now executes an exact reviewed plan on
explicitly named disposable MariaDB databases only. It parses every pending file
before execution, preserves quoted comments/semicolons, serializes competing
executors, rechecks ledger and maintenance state, and stops on the first error.
Before the first statement of each newly started migration, it records a durable
incomplete marker. Success replaces only that marker, never earlier history.
Private flushed/fsynced journals contain statement hashes and status, not SQL
or row data. A failure or process loss keeps recovery required; changing the
journal filename cannot bypass the incomplete database record.

The parser fixture covers all 60 reviewed pending files (442 statements) and
passes 21 checks. Populated MariaDB rehearsals pass 34 local checks, including
committed DDL before failure, maintenance loss, a child process exiting after
DDL, stale/replayed plans, concurrent history changes and unchanged existing
ledger/source rows. Linux CI also checks journal permissions. This is neither
a full populated-production-data rehearsal nor a backup restore demonstration.
There is deliberately no production executor entry point yet: real writer
quiescence, matching recovery evidence and cutover approval must be wired first.
Candidate `bb5f9cc9d19fbac9748f3443a6835b2e4dfe4134` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39705330504),
including 35 MariaDB executor checks on Linux. No hosted runtime was activated
for this rehearsal-only increment.

Cloudways access was recovered through the existing saved sign-in. Server
`1516771` has daily off-site backups scheduled at 09:23 UTC, one-week retention,
and local backups disabled; its displayed off-site size was 7 GB. CoreFlux app
`5829812` at `corefluxapp.com` listed October 19, 2026 09:28:12 UTC as its latest
restore point. The first application-only backup stopped showing as active but
produced no newer restore point. Cloudways notifications explicitly report
recent backup failures for insufficient disk space (October 21 at 08:39 UTC)
and another low-space alert at 19:27 UTC. The absence of an active operation
was not treated as backup success. Local evidence is
`core-backup-no-new-restore-point.png` and `core-backup-disk-alert.png`.

Once the application controls unlocked, both Basic and Advanced Cron Job
Management for app 5829812 showed no jobs. The application scheduler inventory
is now verified empty; the nine SSH-user entries remain active. This does not
rule out an external scheduler or prove that existing work has drained.
Evidence is `core-application-cron-empty.png` (untracked).

The [read-only storage audit](https://github.com/coreflux-creator/CoreFlux/actions/runs/39707023520)
found 1,971,568,640 bytes free and eight superseded source uploads occupying
about 5.50 GB. The [verified cleanup](https://github.com/coreflux-creator/CoreFlux/actions/runs/39707329532)
reclaimed those copies and reported 7.0 GiB free. It checked each completed
workflow's exact revision/outcome, ownership, canonical paths, all manifest
file hashes, absence of added files/symlinks, and the archive checksum before
removal. Four diagnosed old staging failures were included; their failure
logs were retained locally. Manifests/archive checksums are retained privately
under `/tmp/core-upload-cleanup-39707329532`. Configuration package 39690256340,
current reviewed candidate 39703479716 and its full preflight receipt, and
separately stored deployment/database backups were preserved.
The application-only Core backup after this cleanup succeeded. Cloudways now
lists **October 22, 2026 01:50:47 UTC** (October 21 at 21:50:47 Eastern) as the
latest restore point and Last Backup Date for app 5829812. This is files-and-
database backup evidence, not just a stopped progress indicator. Screenshot:
`core-backup-completed-20261022.png` (untracked). It was taken while business
writers were active; the final cutover snapshot still requires writer maintenance.

No restore, backup-policy change, server-wide backup or production activation
was performed. A pre-upgrade backup during verified writer maintenance and a
controlled recovery rehearsal remain required.

### Maintenance preparation and Apache rehearsal

`deploy/full_core_maintenance.php` prepares a temporary web rule and exact
scheduler changes; it does not install either or authorize production changes.
Every active cron line requires a reviewed line number, hash and pause/keep
decision. Entire paused lines become comments, preserving shell syntax,
environment variables, unrelated jobs, line endings and the original file.
Changed scheduler bytes, missing reviews, a retained target-root job and a
changed recovery copy fail. Restoration refuses to overwrite concurrent edits.
Indirect/relative jobs and alternate scheduler owners still require inventory;
this pure transformation cannot discover them or stop running workers.

The web rule returns 503 before root/API/dashboard handlers, with inherited
parent rules and a static message. The original configuration stays in the
future adapter's private recovery receipt. The [Apache inheritance behavior](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html#rewriteoptions)
was checked against actual Apache/PHP, including the repository's nested
`.htaccess` files: 17 held requests, no PHP handler counter changes, byte-exact
configuration restoration and resumed GET/POST. The preparation fixture passes
17 checks. All nine [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39706922811)
passed for 1140eef760262615c07aa1e8cc41a68da520b1c0.
The storage/cleanup workflow follow-up d60973622022c07bccc60802050e36d937c79010
also passed all nine [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39707337001).

That initial increment was disposable HTTP acceptance. The next staging-only
adapter is `deploy/rehearse_core_staging_maintenance.php`. It accepts only the
fixed synthetic staging app/database, verifies tenant 999 and log-only mail,
retains an original configuration and hashed receipt outside the webroot, and
uses three token-protected probes with no application/bootstrap/database calls.
It verifies baseline, held and restored GET/POST behavior, checks hashes before
replacement/restoration, and removes its exact temporary probes. Ordinary
exceptions run cleanup; process loss can be recovered with the explicit
`--recover-staging-web <operation>` mode against the same private receipt.
There is no production target override. This tests web ingress only: no scheduler
is paused and no running financial worker is killed or declared drained.

The first [hosted rehearsal](https://github.com/coreflux-creator/CoreFlux/actions/runs/39708794705)
passed baseline, all six held requests and all six restored responses, then
stopped on an incorrect assumption that every removed probe URL returns 404.
The [read-only inspection](https://github.com/coreflux-creator/CoreFlux/actions/runs/39709119021)
proved the original configuration was restored, the guard absent and all three
files removed. The API router returns its normal 400 for a missing single-
segment API path; root and accounting-module paths return 404. Its JSON trace
ID changes on every request. The final check now captures the actual absent
GET/POST response for a never-created sibling in each route and compares it
with the probe URL after removal,
normalizing only the validated request ID on that exact known router error.
Unexpected success, redirects or other 400 errors still fail. Preparation now
passes 22 checks, and all nine [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39709416804)
passed for d6c146ba95b3e4b159ce93e24b356d84aa5d7fc7.

The first response-baseline run (39709764216) stopped before guard installation
because a newly created probe did not return the expected handler response.
Inspection 39710054078 again confirmed original configuration and no probe files.
The absent controls now use separate never-created sibling paths so they do not
prime a negative file/cache lookup for a file about to be created; normal-probe
failures now include method/path/status/body hash for precise diagnosis. This
does not relax the 503 hold or the restored-handler checks.

The corrected [hosted rehearsal](https://github.com/coreflux-creator/CoreFlux/actions/runs/39710272928)
passed for `f0c41bca099d515255dea992821ed195072ae8f3`, after all nine
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39710035013)
passed. It blocked six GET/POST requests, verified six restored handler
responses, and verified six absent-file responses after removing all probes.
The original configuration hash was restored. The private result explicitly
records `production_changed: false`, `scheduler_changed: false` and
`business_writers_drained: false`. This closes the hosted staging web-ingress
rehearsal; it does not close production scheduler pause, writer drain, file
ownership preservation, final snapshot binding or activation.

### Native scheduler and file recovery adapter

`deploy/full_core_maintenance_host.php` implements Linux host primitives under
an exclusive private operation lock. It saves exact scheduler bytes, the web
configuration and its UID/GID/mode before changing either. Every active job
requires its reviewed line hash and pause/keep decision. Recovery validates
both current states before restoring either, refuses concurrent edits or
corrupted recovery copies, and supports a new process recovering an interrupted
operation. An exception does not silently resume writers. This is a library,
with no production execution entry point and no claim that workers are drained.

The dedicated Docker fixture uses an unprivileged user, a real native crontab,
and an original web-file group different from the user's primary group. It
passed **24 checks**, including scope, byte/UID/GID/mode restoration, 0644 and
0664 modes, empty installed scheduler, private receipts, lock contention,
concurrent changes, corruption and actual child-process exit/recovery. The
[CI run](https://github.com/coreflux-creator/CoreFlux/actions/runs/39711830944)
passed all nine jobs for `d65b12adc4bc23cedc645d75aafb33d6514c17ff`.
Earlier fixture runs exposed a native crontab client
truncating a long input filename. The adapter now supplies the verified private
file on stdin; it never shortens the recovery location or prints job commands.
Failure diagnostics stay private. Native crontab and file replacement do not
provide a cross-file compare-and-swap; the adapter checks immediately before
and after each operation and retains recovery material on failure.

The read-only [production audit](https://github.com/coreflux-creator/CoreFlux/actions/runs/39711314199)
found `.htaccess` owned by UID 1004/GID 33 with mode 0664 and one link; the
deployment CLI runs as UID 1003/GID 33. The webroot and live configuration also
belong to UID 1004. All nine active scheduler lines contain the Core root.
The [identity check](https://github.com/coreflux-creator/CoreFlux/actions/runs/39711612315)
confirmed the CLI cannot run a noninteractive identity command as that file
owner. A later read-only comparison (39711831712) also found that Cloudways'
existing listed application login is not the file owner. No credentials or
permissions were changed. The Cloudways Web Rules form offers only 301, 302
and internal rewrites; its empty form was closed without saving.

The current primitive deliberately refuses a file owned by another UID instead
of changing ownership during an atomic replacement. Resolve the actual
production file-owner execution/restore procedure before wiring activation.
Do not treat group write access, one empty database-connection snapshot, or
the successful disposable scheduler test as production writer quiescence.
The nine production schedules are still active. Final candidate/snapshot
binding, worker drain and explicit cutover authorization remain required.
The reviewed changed-file list includes root `.htaccess`. The final release
must keep the maintenance rule installed while replacing runtime files, then
activate the reviewed candidate routing when reopening. The primitive's
`restore()` restores the original routing for recovery; it is not a successful
release activation step. Recovery after database changes must use the matching
database/files snapshot before restoring ingress or scheduled jobs.

### Native database recovery rehearsal

`tests/full_core_upgrade_recovery_mariadb.php` creates a fresh disposable MariaDB
database containing two synthetic tenants, exact decimal balances, Unicode and
binary data, canonical/secondary migration records, a view and an audit trigger.
The real native dump is captured before running the exact-plan executor through
committed DDL and a row update to an intentional later SQL failure. The fixture
verifies the incomplete marker and committed effects before recovery.

After checking the exact created database identity and dump hash, the test
recreates that disposable database and restores with the native MariaDB client.
It verifies the full original row/column/ledger/view snapshot, removal of the
interrupted upgrade's extra table, and actual execution of the restored trigger.
The failed-upgrade journal remains intact and the restored history reproduces
the original review-plan hash. Credentials are removed; synthetic dumps and
receipts remain private. This passed 14 Windows checks and 15 Linux checks
(including private file permissions). All nine [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39708564991)
passed for dbb1cef3669842cfb28162b6ade837adf13dc651.

This verifies native recovery mechanics, not restoration of the production
Cloudways backup or all 387 live tables. Production writer drain, final
maintenance-window snapshot, exact candidate/recovery binding and the production
activation adapter remain unfinished.

### Reviewed data effects of the pending migrations

The refreshed 60-file upgrade includes more than empty-table creation. The data-changing
statements have been reviewed in source; production row counts and the exact
maintenance/backup procedure still need to be bound to the final candidate.

- Stable person, company, client, vendor and placement references are backfilled
  from existing record IDs. Existing numbers and merge aliases survive; provider
  IDs are separate. Insert triggers cover future records. Platform export presets
  gain the reference columns while tenant-customized presets are preserved.
- Customer Deposits account 2300 is seeded only if absent. These migrations do
  not create a receipt, invoice, journal or payment. Untouched default invoice
  posting-rule labels change from "sent" to "issued" without changing IDs or
  tenant-customized rules.
- Bill creator attribution is recovered only from its exact CoreOne request and
  credential owner. Approval projections are repaired from existing recorded
  reviewer decisions; stale pending reviewer rows become superseded. This must
  be reconciled against workflow evidence, not treated as new approval.
- Legacy billing contacts acquire an entity only where their tenant has exactly
  one active entity. Multi-entity contacts stay unresolved for human review.
- Bank receipt payment metadata is repaired only where the existing posted
  journal and matched statement line agree. Amounts and allocations are not
  changed by that repair.
- Bank cash-code uniqueness moves from tenant to tenant/entity scope. A NULL
  entity retains scope zero. Journal-void fields and this index conversion have
  a populated-table rehearsal in the existing CI suite.
- Invoice links retain their stored hash while the legacy plaintext token column
  is dropped. A code-only rollback to a version requiring that column is unsafe;
  restore the matching database snapshot as well while writers remain stopped.

Three historical migration sources require explicit retention at release:
007_subtenant_provisioning, 041_csv_mapping_presets and 043_simulation_harness.
Their deployed bytes match the successful ledger entries. Preserve those exact
reviewed sources and ledger rows as release exceptions instead of letting the
web startup runner reapply changed historical files. Do not run either generic
migration runner against production until the exact pending set, these source
exceptions and writer maintenance have been verified together.

Private connection preparation is now implemented separately from activation.
`production_prepare_settings` requires CI success for the exact selected
revision. It verifies the known live configuration fingerprint, copies only
the existing database/SMTP constants into an immutable private directory,
and tests the actual candidate bootstrap with a read-only database query.
It then verifies PHP-FPM access through a short-lived token-protected endpoint
at the canonical Core domain and removes that endpoint in a `finally` block.
The probe returns only readiness booleans and the four integration delivery
flags; it exposes no settings or records. The candidate exercises the same
private-file bridge intended for activation. No bridge is installed into the
live runtime and no migration is performed. Existing or changed private content is never
overwritten. Files use 0640 and directories 0750 with the existing application
group; no connection values appear in receipts or logs.

The disposable MariaDB and HTTP rehearsal passes 32 checks locally, including a
SELECT-only account, special-character preservation, replay, wrong-password
failure, source-row preservation and path/configuration guards. CI additionally
checks POSIX permissions and symlink rejection. HTTP checks cover wrong tokens,
method/host/expiry guards, successful read-only access and sanitized bootstrap
failure. The production result is recorded above. Worker scheduling, exact
migration activation, backups and recovery remain unfinished release gates.

The package selection also now includes CLI cron workers and the updated
daily dunning script. The earlier full-Core package omitted those entrypoints,
including the tenant-scoped PurePay status poll and QBO receipt-recovery poll.
They must accompany their changed libraries. Their production fingerprints
and schedules require review before activation; packaging them does not enable
or run a worker. The first operations-only audit stopped on an incorrect vendor
table name without modifying data; it was corrected to `ap_vendors_index`.

### Operations inventory and backup capacity

The [operations audit](https://github.com/coreflux-creator/CoreFlux/actions/runs/39691360210)
confirmed MariaDB 10.11.18, 387 InnoDB tables, six views and 19 triggers, with
no stored routines or database events. Estimated data is 6.31 GB plus 1.41 GB
of indexes; the largest two tables are `entity_sync_history` and
`integration_payload_field_index`. No application rows were removed. A normal
uncompressed local backup does not fit in the available space, so an off-server
backup/recovery option must be verified or a bounded compressed backup rehearsed.

The SSH user's crontab has nine Core entries covering eight scripts: QBO token
refresh, inbound sync (including a second full-sync schedule), outbound sync,
two-way sync, payment polling, health alerts, PurePay health and PurePay payment
status. No explicit new delivery/authority flag is enabled in those lines.
This is an inventory, not proof that all schedulers are paused; the application
owner's separate scheduler still requires verification. There were no other
database connections at the instant of inspection, which is not a maintenance lock.

The [verified upload cleanup](https://github.com/coreflux-creator/CoreFlux/actions/runs/39691272402)
removed only completed reproducible packages 39686512795, 39686720751 and
39686986781 after matching their successful workflow revisions, canonical paths,
ownership and manifest identities. This reclaimed about 2.06 GB; the subsequent
audit reported 3.24 GB free. Configuration preparation 39690256340 and private
release backups were retained. The historical inspection reports remain saved
locally. Cloudways was signed out at that checkpoint; access has since been
recovered and the backup inspection is recorded above. No restore or production
activation has been attempted.

Candidate `208f61a32d741c3f40e9480548fa34a79be907eb` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39691285740).
The local Windows package build could copy and hash-check all three updated
worker entrypoints but could not finish because Composer is unavailable there.
The [hosted full-package build and staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39691594867)
then passed for that same revision, including lifecycle, split receipts, credit
applications and invoice delivery. The source guards remain ready, payroll
authority remains disabled and production was skipped. The incomplete local
package `core-release-worker-check-7e885f1ec4d54160944d862eb9ed705b` remains
in Temp because automatic approval review blocked its removal with only
"blocked by policy" as the reason. It contains source/build files, not a live
database backup. No alternate deletion path was attempted.

The subsequent allocation-receipt runtime `9337bfff654bbea05503115db78f4a8d63d38cfa`
passed all eight [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39698994420)
and [hosted staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39699288911).
The full package contains 6,734 files. Accounting lifecycle, split receipts,
credits and log-only delivery passed again; schema/source guards were ready,
payroll authority was disabled and production was skipped. This is the current
staged runtime at that checkpoint; the production backup, upgrade and activation
gates above remain.

The navigation fix passes 30 journal-UI checks, 55 site navigation checks and
the full dashboard build (61 existing lint warnings). The five tenant-static
checks pass. Candidate `e3fdb0a6` passed all eight
[CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687000156).
Workflow-only follow-up `2b675418a6cc0bcecc698da53ddb99b35ab36f02` also passed
[all eight jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687322749).
Its [isolated staging release](https://github.com/coreflux-creator/CoreFlux/actions/runs/39687723345)
passed, including the accounting lifecycle, split receipts, credit applications
and invoice-delivery acceptance. Schema/source guards are ready and payroll
authority remains disabled. Production was skipped. The updated sidebar and
accounting overview are staged, not live.

The production inspection modes have no install/migration path. The superseded
private candidate packages were reclaimed as recorded above; retain the current
configuration-preparation package and all needed recovery artifacts.
Complete the exact migration/activation procedure,
writer maintenance, verified file/database backups and recovery checks before
requesting the final production-cutover approval. No live records, migrations,
credentials, payroll, payment or invoice issuance were changed by this work.

The subsequent payroll-result candidate adds Core migration 167 for encrypted
provider observations. Include it in the reviewed upgrade plan and refresh the
pending-migration/schema inventory before cutover; the earlier numeric counts
above describe the prior audited candidate, not this additional table.

Runtime `27b4d7292f59f2698c47d214917c144667e1a9d7` is now the current
staged candidate. All eight [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/39701131724)
passed, followed by [staging acceptance](https://github.com/coreflux-creator/CoreFlux/actions/runs/39701429307).
The 6,739-file package applied migration 167 and passed the accounting lifecycle,
split receipts, credits and log-only delivery checks. Schema and source guards
were ready, payroll authority stayed disabled and production was skipped.
This does not establish provider hosted acceptance or close the production
backup, maintenance, upgrade and activation gates.

Local evidence: `thunderhawk-live-invoice-clients.png` and
`thunderhawk-live-accounting-overview.png` (untracked). Full read-only reports
are saved under the local Temp directory as `core-production-preflight-<run>.json`
and `core-production-source-39687312451.json`. GitHub masks some numeric/hash
substrings in logs; resolve source objects using verified unmasked prefixes,
not masked strings as literal IDs.
