# Integration Quality Charter

Every external integration that writes to a third-party system MUST implement the full primitive set below. This is the **non-negotiable baseline** — onboarding a new provider means filling out the entire row, not picking line items.

The charter exists because we kept hitting the same class of "we thought it worked, it actually didn't" bugs: wrong field names rejected by the vendor → fixed → silent draft-state mismatch → fixed → next thing. Patterns deserve patterns.

## The primitives

| # | Primitive | What it does | Why it's required |
|---|-----------|--------------|-------------------|
| 1 | **Schema vendored** | `spec/<provider>_(openapi\|schema).json` checked into the repo | We never write payload code from memory or 2-month-old reading of the docs |
| 2 | **Contract smoke** | `tests/<provider>_payload_contract_smoke.php` walks every mapper, asserts every emitted field is in the schema and every required field is present | Catches wire-shape drift before it stalls the outbox |
| 3 | **Freshness smoke + refresh tool** | `tests/<provider>_spec_freshness_smoke.php` + `tools/refresh_<provider>_spec.sh` | Catches schema drift introduced by the vendor before it bites us |
| 4 | **Account-mapping fallback** | When destination_links has no row, resolver consults `accounting_account_mappings` (operator-set) before failing | Solves the "outbox stuck on first push because nothing's linked yet" case |
| 5 | **Post-push verification** | After every create, re-GET the resource and assert downstream status matches our expectation. Stamps `pushed_unverified` (procedural) or `posted_unverified` (adapter) on mismatch. | Catches silent state-mismatch (e.g. Jaz quietly filing JEs as drafts) — the wire was correct, the lifecycle wasn't |
| 6 | **Full vendor error-surface** | The vendor's raw response body is captured on a typed exception (`*ApiException::$raw[body]`) and persisted to audit / outbox / failure-queue rows so the operator sees what they sent | Without this, "Invalid request body" is unactionable |
| 7 | **Health-panel surfaces it** | Provider appears in `IntegrationsHealthPanel` with a per-primitive coverage breakdown and a roll-up `charter` score pill | One glance and the operator knows the integration's compliance state |

## Provider status (live source of truth: `/api/admin/integrations_health.php`)

| Provider | #1 | #2 | #3 | #4 | #5 | #6 | #7 | Charter score |
|----------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:-------------:|
| **Jaz**       | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **7/7** |
| **QBO**       | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **7/7** |
| **ZohoBooks** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **7/7** |
| **Mercury**   | ✅ | ✅ | ✅ | n/a (no CoA) | ✅ | ✅ | ✅ | **6/6** |
| **Plaid**     | 🟡 | 🟡 | n/a (read-only) | n/a | n/a | 🟡 | n/a | **3/4** |
| **LayerFi**   | n/a — SDK enforces shape | n/a | n/a | n/a | n/a | n/a | n/a | n/a |

## Post-charter polish layer (additive, applies once 7/7 is hit)

The 7 primitives keep the integration *correct*. The polish layer below keeps it *operable* at scale. Onboarding a provider doesn't gate on these, but they're expected for any provider that handles real production traffic.

| Polish | What it does | Status by provider |
|--------|--------------|--------------------|
| **Error-code playbook** | Maps vendor error codes → `{category, severity, summary, suggested_fix, docs_link}` so the Integration Triage UI shows a one-line "Suggested fix" | QBO ✅ (15 codes) · Mercury ✅ (15 codes + ACH returns) · Jaz/Zoho — pending |
| **Retry + dead-letter queue** | Failed pushes get exponential backoff + DLQ after N attempts; admin endpoint exposes requeue. | QBO ✅ (`qbo_push_failures` + `/api/admin/qbo/dead_letters.php`) · Jaz ✅ (adapter outbox) · Mercury ✅ (Failed-PI state + `mpRequeueFailed`) · Zoho — pending |
| **Connection-liveness cron** | Periodically probes the vendor (token refresh / liveness GET) and flips the connection status before downstream calls hit a cliff. | QBO ✅ (`qbo_token_refresh.php`, 15m) · Mercury ✅ (`mercury_health_probe.php`, 30m) · Zoho — pending |
| **Two-way sync** | Pull the vendor's view of our entities into shadow tables; detect drift (paid-out-of-band, balance-changed, voided-in-vendor) and surface to the operator. | QBO ✅ (`qbo_inbound_*` + `qbo_sync_drift` + 30m cron) · Others — pending |
| **Drift badge on host UI** | The CoreFlux list page for the entity (BillsList / InvoicesList) shows the vendor-side state inline (e.g. "Paid in QBO" amber chip). | QBO ✅ (`<QboDriftBadge>`) · Others — pending |
| **Auto-reconcile drift** | Per-tenant opt-in flag closes `paid_out_of_band` drift automatically — inserts a matching CoreFlux payment + allocates via the canonical engine so the ledger matches vendor truth without manual triage. | QBO ✅ (`core/qbo/auto_reconcile.php`, `/api/admin/qbo/auto_reconcile.php`, gated by `qbo_connections.auto_reconcile_paid_out_of_band`) · Others — pending |
| **Inbound payments rail** | Tenant collects from their customers directly via the provider's merchant rail (cards / ACH); shadow table mirrors the upstream charge lifecycle and links it back to the originating AR invoice. | QBO ⏳ Phase 1 — client + shadow + operator endpoint live (`core/qbo/payments_client.php`, `qbo_payment_charges`, `/api/admin/qbo/payments_charge.php`); webhook + tokenizer UI pending · Others — n/a |

## The Rollout Order

When a new provider gets work or an existing one comes up for fixes, the priority is *always*:
1. Onboard onto the charter (every primitive 1–7, not just the immediate fix).
2. Apply relevant polish layers (playbook + retry/DLQ for write-side providers; liveness cron for all).
3. Then ship the user-requested feature.

This avoids the "drip-feed by enhancement" anti-pattern — primitives ship as a set.

## Operator surfaces

Every charter primitive and polish layer terminates in an operator-visible UI:

- **Charter score pill** → `IntegrationsHealthPanel` (`/admin/integrations`)
- **Integration triage inbox** → `IntegrationTriage` (`/admin/integrations/triage`) — unified queue of QBO DLQ + QBO drift + Mercury failed PIs
- **Drift badge on entity lists** → BillsList + InvoicesList
- **Per-source admin endpoints** (RBAC-gated, also reachable via curl):
  - `GET/POST /api/admin/qbo/dead_letters.php`
  - `GET/POST /api/admin/qbo/sync_drift.php`
  - `GET/POST /api/admin/mercury/failed_payments.php`
  - `GET     /api/admin/qbo/drift_badges.php` (read-only)
  - `GET     /api/admin/integration_triage.php` (read-only aggregator)

## Adding a new primitive

A new primitive joins the charter when (a) we hit the same class of bug twice across two providers, OR (b) a primitive's absence makes another primitive lose load-bearing weight. Charter changes get a row added to this table and a backfill issue against every existing provider.

Reviewer signing off on a new integration MUST verify all 7 cells are filled — the integrations-health endpoint is the canonical check.
