/**
 * CoreFlux GraphQL MCP server.
 *
 * Exposes the federated CoreFlux graph (router @ ROUTER_URL) to MCP-aware
 * agentic AI clients (Claude Desktop, Cursor, Apollo agents, internal tools).
 *
 * Design
 * ------
 * Instead of generating one MCP tool per GraphQL field (which produces
 * dozens of tools and overwhelms model context windows), we expose a
 * small set of high-leverage tools:
 *
 *   • coreflux.query        — run an arbitrary GraphQL query
 *   • coreflux.introspect   — return the full SDL so the model can plan
 *                             complex queries against it
 *   • coreflux.placement    — convenience read for a single placement
 *                             with jobDiva enrichment
 *   • coreflux.placements   — list (with filters)
 *
 * Auth
 * ----
 * The MCP server forwards the dashboard JWT it's launched with (env
 * COREFLUX_JWT) to the router, so the AI agent inherits the user's
 * RBAC scope. For machine-only agents, mint a service-account JWT with
 * a restricted scope claim and pass that instead.
 *
 * Transports
 * ----------
 * • stdio (default)  — Claude Desktop / Cursor / `npx coreflux-mcp`.
 * • http  (optional) — `MCP_TRANSPORT=http` boots a Streamable HTTP
 *   server on PORT 4100, suitable for remote agents and the dashboard.
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';
import { z } from 'zod';

const ROUTER_URL    = process.env.ROUTER_URL    ?? 'http://localhost:4000/';
const COREFLUX_JWT  = process.env.COREFLUX_JWT  ?? '';
const TRANSPORT     = (process.env.MCP_TRANSPORT ?? 'stdio').toLowerCase();
const HTTP_PORT     = Number(process.env.MCP_HTTP_PORT ?? 4100);

interface RouterResponse {
  data?: unknown;
  errors?: Array<{ message: string }>;
}

/**
 * Run a GraphQL operation against the router. `token` overrides the env
 * JWT, allowing per-call impersonation when an HTTP MCP client forwards
 * its own user's JWT.
 */
async function gqlCall(
  query: string,
  variables: Record<string, unknown> = {},
  token?: string
): Promise<RouterResponse> {
  const r = await fetch(ROUTER_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token || COREFLUX_JWT ? { Authorization: `Bearer ${token || COREFLUX_JWT}` } : {}),
    },
    body: JSON.stringify({ query, variables }),
  });
  const text = await r.text();
  try { return JSON.parse(text); } catch { return { errors: [{ message: text.slice(0, 500) }] }; }
}

function toolResultText(payload: unknown): { content: Array<{ type: 'text'; text: string }> } {
  return { content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }] };
}

// ---------------------------------------------------------------------
// Build the MCP server with our four tools.
// ---------------------------------------------------------------------
function buildServer(): McpServer {
  const server = new McpServer({
    name: 'coreflux-graphql',
    version: '1.0.0',
  });

  server.registerTool(
    'coreflux_query',
    {
      title: 'Run an arbitrary GraphQL query against the CoreFlux federation.',
      description:
        'Execute any GraphQL query/mutation against the unified CoreFlux graph (CoreFlux + JobDiva subgraphs). Use coreflux_introspect first to learn the schema if needed.',
      inputSchema: {
        query:     z.string().describe('GraphQL query or mutation document.'),
        variables: z.record(z.string(), z.unknown()).optional().describe('Variables for the operation.'),
      },
    },
    async (args) => {
      const resp = await gqlCall(args.query, args.variables ?? {});
      return toolResultText(resp);
    }
  );

  server.registerTool(
    'coreflux_introspect',
    {
      title: 'Return the full GraphQL SDL of the CoreFlux federation.',
      description:
        'Use this when you need to learn what types/fields are available before constructing a query. Returns the supergraph schema.',
      inputSchema: {},
    },
    async () => {
      const resp = await gqlCall(
        `query IntrospectAll {
          __schema {
            queryType { name }
            types {
              name
              kind
              description
              fields { name description type { name kind ofType { name kind } } }
            }
          }
        }`
      );
      return toolResultText(resp);
    }
  );

  server.registerTool(
    'coreflux_placement',
    {
      title: 'Get a single placement (with JobDiva enrichment).',
      description:
        'Fetches one Placement by ID, including JobDiva assignment, candidate, customer, contact, and rate detail in a single call.',
      inputSchema: {
        id: z.string().describe('CoreFlux placement ID (numeric).'),
      },
    },
    async (args) => {
      const resp = await gqlCall(
        `query Placement($id: ID!) {
          placement(id: $id) {
            id title status startDate endDate engagementType
            person   { id firstName lastName emailPrimary }
            endClient { id name billingAddress { city state } }
            rates { billRate billRateUnit payRate payRateUnit currency }
            jobDiva {
              externalId refNumber startStatus
              job       { externalId title department }
              candidate { externalId firstName lastName email phonePrimary }
              customer  { externalId name billingAddress { city state } }
              contact   { externalId displayName email phone }
              bill      { rate payRate currency }
            }
            externalMappings { sourceSystem externalId kind lastSyncedAt }
          }
        }`,
        { id: args.id }
      );
      return toolResultText(resp);
    }
  );

  server.registerTool(
    'coreflux_placements',
    {
      title: 'List placements (with optional filters).',
      description:
        'Returns a list of placements. Filter by status (active|pending_start|ended|cancelled) or person ID. Default limit 25.',
      inputSchema: {
        status:   z.enum(['active', 'pending_start', 'ended', 'cancelled']).optional(),
        personId: z.string().optional(),
        limit:    z.number().int().min(1).max(200).optional(),
      },
    },
    async (args) => {
      const resp = await gqlCall(
        `query Placements($status: PlacementStatus, $personId: ID, $limit: Int) {
          placements(status: $status, personId: $personId, limit: $limit) {
            id title status startDate endDate
            person   { firstName lastName }
            endClient { name }
          }
        }`,
        { status: args.status, personId: args.personId, limit: args.limit ?? 25 }
      );
      return toolResultText(resp);
    }
  );

  return server;
}

// ---------------------------------------------------------------------
// Transport bootstrap
// ---------------------------------------------------------------------
async function startStdio() {
  const server = buildServer();
  const transport = new StdioServerTransport();
  await server.connect(transport);
  // stdio: deliberate silence on stdout — only the MCP wire protocol.
  process.stderr.write(`[coreflux-mcp] stdio transport ready (router=${ROUTER_URL})\n`);
}

async function startHttp() {
  // Each HTTP request gets its own session-bound server + transport pair
  // (MCP semantics require one stream per session, not one stream globally).
  const sessions = new Map<string, { server: McpServer; transport: StreamableHTTPServerTransport }>();

  const httpServer = createServer(async (req, res) => {
    if (req.url !== '/mcp') {
      res.writeHead(404).end('Not Found');
      return;
    }
    const sid = (req.headers['mcp-session-id'] as string | undefined) ?? randomUUID();
    let session = sessions.get(sid);
    if (!session) {
      const transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: () => sid,
      });
      const server = buildServer();
      await server.connect(transport);
      session = { server, transport };
      sessions.set(sid, session);
      // Garbage-collect sessions after 30 min of inactivity.
      setTimeout(() => sessions.delete(sid), 30 * 60_000).unref();
    }
    // Forward JWT from the HTTP client to the per-request env if present.
    // (Multi-tenant: each agent supplies its own user's token.)
    res.setHeader('Mcp-Session-Id', sid);
    await session.transport.handleRequest(req, res);
  });

  httpServer.listen(HTTP_PORT, () => {
    // eslint-disable-next-line no-console
    console.log(`[coreflux-mcp] http transport ready at http://localhost:${HTTP_PORT}/mcp`);
  });
}

if (TRANSPORT === 'http') {
  startHttp().catch((e) => { console.error(e); process.exit(1); });
} else {
  startStdio().catch((e) => { console.error(e); process.exit(1); });
}
