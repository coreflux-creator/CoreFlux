# CoreFlux GraphQL Federation — Architecture Plan

**Status**: PROPOSED — awaiting sign-off before Phase 1 spike.
**Author**: agent session, 2026-02
**Supersedes**: the iterative `tenant_integration_field_map` approach for new integration sources.

---

## Why we're doing this

The field-mapping approach (admin UI → registry rows → resolver chain → injected `_jd_*` keys → defensive transforms) does not scale. Each new integration requires:

- A new sync writer + an allow-list expansion + tenant-by-tenant remapping
- N+1 detail fetches with bespoke caching per integration
- Per-field permission patches sprinkled across the API surface
- AI agents that have to learn each tenant's mapping config to read data

A typed federated GraphQL schema collapses all of that into a single contract per source. AI agents talk to one endpoint; integrations contribute typed extensions; cross-source queries become a single GraphQL operation; field-level auth is a directive in the schema.

---

## Runtime topology

```
                ┌─────────────────────────────────────┐
                │   Apollo Router  (Rust, one process)│
                │   • multi-tenant context             │
                │   • schema composition               │
                │   • auth pass-through                │
                │   • per-field tracing                │
                └────────────────┬────────────────────┘
                                 │
   ┌────────────┬────────────┬───┴────────┬────────────┐
   ▼            ▼            ▼            ▼            ▼
┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
│CoreFlux│ │JobDiva │ │  QBO   │ │  Zoho  │ │Mercury │   ← Apollo Server (Node)
│subgraph│ │subgraph│ │subgraph│ │subgraph│ │subgraph│
└───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘
    │          │          │          │          │
    ▼          ▼          ▼          ▼          ▼
 PHP REST   JobDiva     QBO        Zoho      Mercury
 (/api/*) → REST API → REST API  → REST API → REST API
 over PDO
```

- The **router** is the only thing the dashboard, MCP server, and scheduled jobs talk to. Public endpoint.
- Each **subgraph** is a small Node service exposing a typed projection of one source. Internal — only the router calls them.
- **CoreFlux subgraph** is special: its resolvers call the existing PHP REST API (or hit Postgres/MariaDB directly via a thin pool). It owns the canonical types.
- Every other subgraph wraps its integration's REST API and **stores nothing**. State of record stays where it is.

### Process & deploy footprint
- Router: 1 process (Rust binary, ~10MB RAM).
- Subgraphs: 1 process per integration (Node, ~80MB RAM each).
- All run in containers next to the PHP-FPM pool. New deploy targets but no migration of existing code.

---

## What we throw away / keep / refactor

| Today | Action |
|---|---|
| `tenant_integration_field_map` registry + UI editor | **Throw away** (eventually). Replaced by typed schema. Keep running during migration for safety. |
| `tenantIntegrationFieldMapPluckInternal` resolver chain | **Refactor**. Logic moves into JobDiva subgraph resolvers verbatim. |
| `jobdivaSyncEnrichRelatedEntities` (just shipped) | **Refactor into resolvers**. The N+1 elimination, soft-fail-per-endpoint, dedup logic, all stays — just runs inside `@key`-aware GraphQL resolvers with DataLoader. |
| `jobdivaSyncPlacements` cron + `jobdivaSyncUpsertPlacement` writer | **Keep**. Becomes a scheduled GraphQL client that pulls from the federated graph and writes through `CoreFluxMutation.upsertPlacement`. Same data flow, typed in the middle. |
| `external_entity_mappings` table | **Keep**. Maps directly to federation `@key` IDs. |
| `tenant_integration_keys` (per-tenant API creds) | **Keep**. Passed into each subgraph via Apollo `context`. |
| PHP REST API (`/api/*`) | **Keep**. The dashboard keeps using it for mutations and admin UI. CoreFlux subgraph resolvers also call it for reads. |
| RBAC / auth / session middleware | **Keep**. JWT verification happens at the router; tenant + user claims flow into subgraph context. |
| All accounting / AP / billing / RBAC PHP code | **Untouched.** |
| Smoke test suite (259 currently) | **Keep and grow**. Add subgraph-level integration tests in TypeScript using `graphql-tester`. |

---

## Subgraph plan — what each one owns

### CoreFlux subgraph (the "core")
Owns the canonical types. Every other subgraph `extend`s these via `@key`.

```graphql
type Placement @key(fields: "id") {
  id: ID!
  title: String
  startDate: Date
  endDate: Date
  status: PlacementStatus
  person: Person
  endClient: Company
  billRate: Money
  payRate: Money
  notes: String
  externalMappings: [ExternalMapping!]!
}

type Person @key(fields: "id") {
  id: ID!
  firstName: String
  lastName: String
  email: String
  homeAddress: Address
}

type Company @key(fields: "id") {
  id: ID!
  name: String
  industry: String
  billingAddress: Address
}

type Money {
  amount: Decimal!
  currency: String!
  unit: RateUnit
}
```

Resolvers: thin layer over your existing PHP API (`GET /api/placements/:id`) OR direct DB reads via a small connection pool. Whichever is faster to ship — probably the PHP API first to avoid duplicating SQL.

### JobDiva subgraph (the first external)
Wraps the JobDiva REST API. Stores nothing. Per-tenant creds from `tenant_integration_keys`.

```graphql
extend type Placement @key(fields: "id") {
  id: ID! @external
  jobDiva: JobDivaAssignment
}

type JobDivaAssignment {
  externalId: ID!           # JobDiva Start ID
  rawPayload: JSON
  job: JobDivaJob           # → /searchJob, DataLoader-batched
  candidate: JobDivaCandidate
  customer: JobDivaCustomer
  contact: JobDivaContact
  bill: BillingDetail       # → /searchStart with rates, opt-in
}

type JobDivaJob {
  id: ID!
  title: String
  department: String
  description: String
}

# ... etc.
```

Resolvers reuse the auth + caching + soft-fail logic from `jobdivaSyncEnrichRelatedEntities` — line-for-line translation.

### QBO / Zoho / Mercury / Plaid (later)
Same shape: `extend type Company`, `extend type Invoice`, etc. Each one ships independently.

---

## Tenant routing — multi-tenant, single router

- Dashboard → Router with `Authorization: Bearer <JWT>`.
- Router verifies the JWT (same secret as PHP) and extracts `tenant_id` + `user_id` + `roles`.
- These get passed into every subgraph as Apollo `context`.
- Each subgraph uses `context.tenantId` to read `tenant_integration_keys` for the right creds.
- A field-level directive `@requiresScope("cfo")` enforces RBAC at schema level — no more endpoint-by-endpoint checks.

**No per-tenant router instance.** One router process, tenant context per request. Standard pattern.

---

## Auth — what changes, what doesn't

- **PHP session / JWT issuance**: unchanged. The dashboard still logs in via `/api/auth/login` and gets a JWT.
- **Subgraph auth**: the router verifies the JWT once and forwards trusted claims to each subgraph via a signed header. Subgraphs don't re-verify.
- **RBAC**: legacy `rbac_legacy_require()` keeps gating the PHP REST endpoints. The CoreFlux subgraph honours it transitively (since it calls those endpoints). For NEW fields exposed only via GraphQL, use `@requiresScope`.
- **MCP server**: gets its own service account JWT with restricted scopes. AI agents query through MCP → router → subgraphs.

---

## Phase 1 — Spike (~2 days)

**Goal**: prove one end-to-end query works.

1. Add `graphql/` to the repo. Three top-level dirs:
   - `graphql/router/` — Apollo Router config (`router.yaml`), Dockerfile.
   - `graphql/subgraph-coreflux/` — Node, TypeScript, Apollo Server. Exposes `Placement`, `Person`, `Company`. Resolvers proxy to existing PHP REST.
   - `graphql/subgraph-jobdiva/` — Node, TypeScript, Apollo Server. Exposes `extend type Placement` + `JobDivaAssignment`. Resolvers call JobDiva REST directly.
2. Get this query working from the GraphQL playground:
   ```graphql
   query {
     placement(id: "17") {
       title
       startDate
       jobDiva {
         job { title department }
         candidate { firstName lastName address }
         customer { name }
       }
     }
   }
   ```
3. Validate JWT pass-through end-to-end (login on dashboard → query through router → tenant context lands in each subgraph).
4. Smoke test in Node: hit the federated graph, assert response shape.

**Exit criteria**: that query returns real data for Andrew Lee's placement (#17), pulled live from both CoreFlux DB AND JobDiva API.

**Out of scope**: writes, MCP, more than one tenant, retiring any existing code.

---

## Phase 2 — MCP integration (~1 day)

Drop `mcp-apollo` (or `graphql-mcp`) pointed at the router. Service-account JWT with read scopes. Hook the existing OpenAI/Claude integration in the PHP backend to it.

**Exit criteria**: AI prompt "What's Andrew Lee's pay rate?" returns the answer without any per-source glue code.

---

## Phase 3 — Migrate sync writers (~2-3 days per source)

Convert each `jobdivaSync*` cron into a GraphQL client that:
- Queries the federated graph for source data (with `jobDiva.*` fields).
- Writes through `CoreFluxMutation.upsertPlacement(...)` to the PHP API.
- Logs to `entity_sync_history` (existing table).

The mapping logic disappears because both sides are now typed.

---

## Phase 4 — Retire the field-map registry (~1 day)

- Mark `tenant_integration_field_map` table read-only.
- Hide the FieldMapEditor UI.
- After 2 weeks of no regressions, drop the table.

---

## Open questions before Phase 1

1. **Apollo OSS vs Apollo GraphOS**: GraphOS-managed federation is the fastest start but $$$. Self-hosted Apollo Router (Rust) is free and runs in a container. Recommend self-hosted for now.
2. **Subgraph languages**: standardising on TypeScript + Apollo Server. Open to deviating if a subgraph has a strong reason (e.g. an integration with an SDK only in another language).
3. **Container orchestration**: where do the new Node services run? Same Cloudways pool as PHP-FPM? A new Docker host? Kubernetes? **This is the biggest infra decision.**
4. **Caching layer**: Apollo Router has a built-in response cache + per-resolver caching via `@cacheControl`. Plus DataLoader inside each subgraph. Do we want Redis between subgraph and upstream API for the slow ones? (Probably yes for JobDiva, given rate limits.)

---

## What I need from you to start Phase 1

1. **Infra answer for open question 3**: where do Node services run?
2. **Approval to add `graphql/` directory to the repo** (it does not touch any PHP files).
3. **First subgraph scope**: confirm "Placement + Person + Company in CoreFlux, JobDivaAssignment in JobDiva" is the right starting slice (not too ambitious, not too trivial).

---

## What this doc deliberately does NOT do

- It does not retire the field-map work just done. Keep it. The expanded allow-list, the `date_normalise` defensive fallback, the customer-id auto-resolver, the `_jd_*` enrichment — all those continue to deliver value while Phase 1-3 land. They retire at Phase 4, not now.
- It does not rewrite any existing PHP code. The PHP REST API stays. The cron sync jobs stay (just become GraphQL clients later).
- It does not introduce a new database, ORM, or schema-migration framework. All data stays in MariaDB.
- It does not commit to Apollo GraphOS. Self-hosted is the recommended default.
