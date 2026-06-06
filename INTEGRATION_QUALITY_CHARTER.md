# Integration Quality Charter

Every external integration that writes to a third-party system MUST implement the full primitive set below. This is the **non-negotiable baseline** — onboarding a new provider means filling out the entire row, not picking line items.

The charter exists because we kept hitting the same class of "we thought it worked, it actually didn't" bugs: wrong field names rejected by the vendor → fixed → silent draft-state mismatch → fixed → next thing. Patterns deserve patterns.

## The primitives

| # | Primitive | What it does | Why it's required |
|---|-----------|--------------|-------------------|
| 1 | **Schema vendored** | `spec/<provider>_(openapi|schema).json` checked into the repo | We never write payload code from memory or 2-month-old reading of the docs |
| 2 | **Contract smoke** | `tests/<provider>_payload_contract_smoke.php` walks every mapper, asserts every emitted field is in the schema and every required field is present | Catches wire-shape drift before it stalls the outbox |
| 3 | **Freshness smoke + refresh tool** | `tests/<provider>_spec_freshness_smoke.php` + `tools/refresh_<provider>_spec.sh` | Catches schema drift introduced by the vendor before it bites us |
| 4 | **Account-mapping fallback** | When destination_links has no row, resolver consults `accounting_account_mappings` (operator-set) before failing | Solves the "outbox stuck on first push because nothing's linked yet" case |
| 5 | **Post-push verification** | After every create, re-GET the resource and assert downstream status matches our expectation. Stamps `posted_unverified` on mismatch. | Catches silent state-mismatch (e.g. Jaz quietly filing JEs as drafts) — the wire was correct, the lifecycle wasn't |
| 6 | **Full vendor error-surface** | The vendor's raw response body is captured to `provider_result` so the operator sees what they sent | Without this, "Invalid request body" is unactionable |
| 7 | **Health-panel surfaces it** | Provider must appear in `IntegrationsHealthPanel` showing #1–6 coverage with a roll-up pill | One glance and the operator knows whether any vendor schema has drifted under us |

## Provider status

| Provider | #1 schema | #2 contract | #3 freshness | #4 mapping fallback | #5 post-push verify | #6 error surface | #7 health panel |
|----------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| **Jaz**       | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **QBO**       | ✅ | ✅ | ✅ | **✅ (this session)** | TBD (next session) | partial | ✅ |
| **Plaid**     | ❌ | ❌ | ❌ | n/a (read-only) | n/a | partial | ❌ |
| **ZohoBooks** | **✅ (this session)** | **✅ (this session)** | **✅ (this session)** | TBD (next session) | TBD (next session) | ❌ | **✅ (this session)** |
| **Mercury**   | **✅ (this session)** | **✅ (this session)** | **✅ (this session)** | n/a (no CoA) | TBD (next session) | ❌ | **✅ (this session)** |
| **LayerFi**   | n/a — SDK enforces shape | n/a | n/a | n/a | n/a | n/a | n/a |

## The Rollout Order

When a new provider gets work or an existing one comes up for fixes, the priority is *always*:
1. Onboard onto the charter (every primitive, not just the immediate fix).
2. Then ship the user-requested feature.

This avoids the "drip-feed by enhancement" anti-pattern — primitives ship as a set.

## Adding a new primitive

A new primitive joins the charter when (a) we hit the same class of bug twice across two providers, OR (b) a primitive's absence makes another primitive lose load-bearing weight. Charter changes get a row added to this table and a backfill issue against every existing provider.

Reviewer signing off on a new integration MUST verify all 7 cells are filled — the integrations-health endpoint is the canonical check.
