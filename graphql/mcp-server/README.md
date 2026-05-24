# CoreFlux MCP Server (GraphQL Federation)

A [Model Context Protocol](https://modelcontextprotocol.io/) server that
exposes the CoreFlux Apollo Federation graph to agentic AI clients.

## What it does

Lets an AI client (Claude Desktop, Cursor, an Apollo agent, or your own
LangGraph/CrewAI workflow) read CoreFlux + JobDiva data through a small
set of typed MCP tools:

| Tool                  | Purpose |
|-----------------------|---------|
| `coreflux_query`      | Execute any GraphQL query/mutation. The escape hatch. |
| `coreflux_introspect` | Return the supergraph SDL so the model can plan complex queries. |
| `coreflux_placement`  | Convenience read — one placement + jobDiva enrichment + rates + mappings, in one shot. |
| `coreflux_placements` | List with `status` and `personId` filters. |

The model decides which tool to call. We expose a deliberately small
surface because dozens of fine-grained tools (one per GraphQL field)
overflow context windows and confuse planners.

## Transports

Two transport modes — pick by env var.

### stdio (default — Claude Desktop, Cursor, local agents)

```bash
ROUTER_URL=http://localhost:4000/ \
COREFLUX_JWT=<dashboard JWT> \
node dist/index.js
```

Hook into Claude Desktop's `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "coreflux": {
      "command": "node",
      "args": ["/app/graphql/mcp-server/dist/index.js"],
      "env": {
        "ROUTER_URL":   "http://localhost:4000/",
        "COREFLUX_JWT": "eyJ..."
      }
    }
  }
}
```

### Streamable HTTP (remote agents, dashboard tools)

```bash
MCP_TRANSPORT=http MCP_HTTP_PORT=4100 \
ROUTER_URL=http://localhost:4000/ \
node dist/index.js
```

Reachable at `POST http://localhost:4100/mcp`. Each request creates (or
resumes) a session keyed by the `Mcp-Session-Id` header.

## Auth model

- The MCP server runs inside CoreFlux infrastructure. It never accepts
  unauthenticated traffic from the public internet — only from authorised
  agents on the same network.
- The server forwards either:
  - `COREFLUX_JWT` (env, for single-tenant service accounts), or
  - the JWT from the inbound HTTP request (for per-user MCP-over-HTTP)
- The Apollo Router then enforces RBAC just like for the dashboard.

## Build & run

```bash
cd /app/graphql/mcp-server
yarn install
yarn build
node dist/index.js
```

Smoke-tested via `/app/tests/graphql_federation_smoke.php` — the test
spawns the server in stdio mode, sends an `initialize` RPC, and asserts
it identifies as `coreflux-graphql`.
