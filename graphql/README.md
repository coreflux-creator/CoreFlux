# CoreFlux GraphQL Federation

Apollo-Federation-v2 layer over CoreFlux + JobDiva (and, later, QBO, Zoho, Mercury, Plaid).

See `/app/memory/GRAPHQL_FEDERATION_PLAN.md` for the architectural rationale.
This README is just how to run it locally and on Cloudways.

## Topology

```
dashboard / MCP server
        │
        ▼
   Apollo Router  ─── :4000 (public)
        │
        ├──────────────── subgraph-coreflux  :4001 (internal)
        │                  │   └─► PHP REST API (/api/*) over PDO
        │
        └──────────────── subgraph-jobdiva   :4002 (internal)
                            │   └─► /api/internal/jobdiva_creds.php  (cred lookup)
                            └─► JobDiva REST (apivanilla.jobdiva.com)
```

All three processes run on the same Cloudways box as PHP-FPM. No new deploy host.

## Folder layout

```
graphql/
├── README.md                this file
├── docker-compose.yml       boots all three services for local dev
├── .env.example             secrets template
├── router/
│   ├── Dockerfile
│   ├── router.yaml          Apollo Router config (auth, CORS, tracing)
│   └── supergraph.yaml      lists subgraph endpoints for composition
├── subgraph-coreflux/
│   ├── package.json
│   ├── tsconfig.json
│   ├── schema.graphql       canonical types: Placement, Person, Company
│   └── src/index.ts         Apollo Server + resolvers proxying PHP REST
└── subgraph-jobdiva/
    ├── package.json
    ├── tsconfig.json
    ├── schema.graphql       JobDivaAssignment, JobDivaJob, JobDivaCandidate, …
    └── src/                 resolvers + JobDiva client + DataLoaders
        ├── index.ts
        ├── client.ts
        └── loaders.ts
```

## Local dev

```bash
cp .env.example .env       # fill in JWT_SECRET, COREFLUX_API_BASE, etc.
docker-compose up          # boots router + 2 subgraphs
# router playground at http://localhost:4000
```

## Try it

```graphql
query {
  placement(id: "17") {
    title
    startDate
    person { firstName lastName }
    endClient { name }
    jobDiva {
      externalId
      job { title department }
      candidate { firstName lastName email address }
      customer { name billingAddress { city state } }
    }
  }
}
```

## Production (Cloudways)

Three systemd units alongside PHP-FPM. See `/app/graphql/docker-compose.yml` for the
canonical process definition — wrap each container's `command:` in a systemd unit
file and point `WorkingDirectory` at the relevant subgraph dir.

## What this layer is NOT

- It is NOT a database. Subgraphs other than `coreflux` store nothing.
- It is NOT a sync engine. Existing cron sync writers in `/app/cron/` keep running
  (Phase 3 converts them into GraphQL clients).
- It does NOT touch any file under `/app/api/`, `/app/core/`, `/app/modules/`,
  `/app/dashboard/`, or `/app/cron/`. New surface area only.

## Versioning the supergraph

Run `npm run compose` in `/app/graphql/router/` to regenerate `supergraph.graphql`
from the current subgraph schemas. Apollo Router hot-reloads.
