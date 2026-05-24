/**
 * CoreFlux GraphQL subgraph.
 *
 * Owns the canonical schema (Placement / Person / Company / etc.) and
 * proxies every resolver to the existing PHP REST API at COREFLUX_API_BASE.
 * No SQL is duplicated here — the PHP API is the system of record.
 *
 * Tenant context: the router forwards the dashboard's JWT in
 * Authorization: Bearer <token>. We decode it (HS256, same secret as PHP)
 * to extract { tenantId, userId, roles } and forward the SAME token to
 * the PHP REST API so existing RBAC / SoD middleware applies unchanged.
 */
import { ApolloServer } from '@apollo/server';
import { startStandaloneServer } from '@apollo/server/standalone';
import { buildSubgraphSchema } from '@apollo/subgraph';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import gql from 'graphql-tag';
import jwt from 'jsonwebtoken';

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------
const PORT = Number(process.env.PORT ?? 4001);
const JWT_SECRET = process.env.JWT_SECRET ?? '';
const COREFLUX_API_BASE = process.env.COREFLUX_API_BASE ?? '';
if (!JWT_SECRET) throw new Error('JWT_SECRET env var is required');
if (!COREFLUX_API_BASE) throw new Error('COREFLUX_API_BASE env var is required');

// ---------------------------------------------------------------------
// Context — decoded JWT claims + a pre-bound PHP-API fetcher.
// ---------------------------------------------------------------------
interface TenantContext {
  tenantId: number | null;
  userId:   number | null;
  roles:    string[];
  rawToken: string | null;
  /** Convenience helper — proxies to the PHP REST API with the user's JWT. */
  api: (path: string, init?: RequestInit) => Promise<any>;
}

function decodeContext(req: { headers: Record<string, string | string[] | undefined> }): TenantContext {
  const auth = (req.headers['authorization'] || req.headers['Authorization']) as string | undefined;
  const token = auth?.startsWith('Bearer ') ? auth.slice(7) : null;
  let tenantId: number | null = null;
  let userId: number | null = null;
  let roles: string[] = [];

  if (token) {
    try {
      const claims = jwt.verify(token, JWT_SECRET) as Record<string, unknown>;
      tenantId = Number(claims.tenant_id ?? claims.tid ?? 0) || null;
      userId   = Number(claims.user_id   ?? claims.uid ?? claims.sub ?? 0) || null;
      roles    = Array.isArray(claims.roles) ? (claims.roles as string[]) : [];
    } catch {
      // Soft-fail — anonymous queries get null context, resolvers gate themselves.
    }
  }

  const api = async (path: string, init: RequestInit = {}): Promise<any> => {
    const headers = new Headers(init.headers);
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (!headers.has('Content-Type') && init.body) headers.set('Content-Type', 'application/json');
    const url = path.startsWith('http') ? path : `${COREFLUX_API_BASE}${path}`;
    const r = await fetch(url, { ...init, headers });
    const text = await r.text();
    let body: any = null;
    try { body = text ? JSON.parse(text) : null; } catch { body = { raw: text }; }
    if (!r.ok) {
      const msg = body?.error ?? body?.raw ?? r.statusText;
      throw new Error(`CoreFlux API ${r.status}: ${msg}`);
    }
    return body;
  };

  return { tenantId, userId, roles, rawToken: token, api };
}

// ---------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------
const __filename = fileURLToPath(import.meta.url);
const __dirname  = dirname(__filename);
// In `tsx watch` __dirname is src/, after `tsc` it's dist/. Either way
// schema.graphql sits one level up from the JS.
const typeDefs = gql(readFileSync(resolve(__dirname, '../schema.graphql'), 'utf8'));

// ---------------------------------------------------------------------
// Helpers — shape PHP API payloads into GraphQL types.
// ---------------------------------------------------------------------
function shapePlacement(row: any): any {
  if (!row) return null;
  return {
    id: String(row.id),
    title: row.title ?? null,
    status: row.status ?? null,
    startDate: row.start_date ?? null,
    endDate: row.end_date ?? null,
    actualEndDate: row.actual_end_date ?? null,
    dueDate: row.due_date ?? null,
    engagementType: row.engagement_type ?? null,
    remotePolicy: row.remote_policy ?? null,
    worksiteState: row.worksite_state ?? null,
    worksiteCountry: row.worksite_country ?? null,
    notes: row.notes ?? null,
    endClientName: row.end_client_name ?? null,
    clientApproverName: row.client_approver_name ?? null,
    clientApproverEmail: row.client_approver_email ?? null,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
    // FK-only stubs — federation populates the rest via @key reference.
    person: row.person_id != null ? { id: String(row.person_id) } : null,
    endClient: row.end_client_company_id != null ? { id: String(row.end_client_company_id) } : null,
    _personId: row.person_id ?? null,
    _endClientId: row.end_client_company_id ?? null,
    _raw: row,
  };
}

function shapePerson(row: any): any {
  if (!row) return null;
  return {
    id: String(row.id),
    firstName: row.first_name ?? null,
    middleName: row.middle_name ?? null,
    lastName: row.last_name ?? null,
    preferredName: row.preferred_name ?? null,
    emailPrimary: row.email_primary ?? null,
    emailSecondary: row.email_secondary ?? null,
    phonePrimary: row.phone_primary ?? null,
    phoneSecondary: row.phone_secondary ?? null,
    classification: row.classification ?? null,
    employmentType: row.employment_type ?? null,
    status: row.status ?? null,
    hireDate: row.hire_date ?? null,
    terminationDate: row.termination_date ?? null,
    workAuthStatus: row.work_auth_status ?? null,
    workAuthExpiry: row.work_auth_expiry ?? null,
    requiresSponsorship: row.requires_sponsorship ?? null,
    homeAddress: {
      line1: row.home_address_line1 ?? null,
      line2: row.home_address_line2 ?? null,
      city: row.home_city ?? null,
      state: row.home_state ?? null,
      postalCode: row.home_postal_code ?? null,
      country: row.home_country ?? null,
    },
    linkedinUrl: row.linkedin_url ?? null,
    source: row.source ?? null,
    recruiterNotes: row.recruiter_notes ?? null,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

function shapeCompany(row: any): any {
  if (!row) return null;
  return {
    id: String(row.id),
    name: row.name,
    industry: row.industry ?? null,
    website: row.website ?? null,
    phone: row.phone ?? null,
    description: row.description ?? null,
    billingEmail: row.billing_email ?? null,
    billingTerms: row.billing_terms ?? null,
    taxIdLast4: row.tax_id_last4 ?? null,
    billingAddress: {
      line1: row.address_line1 ?? null,
      line2: row.address_line2 ?? null,
      city: row.city ?? null,
      state: row.state ?? null,
      postalCode: row.postal_code ?? null,
      country: row.country ?? null,
    },
  };
}

// ---------------------------------------------------------------------
// Resolvers
// ---------------------------------------------------------------------
const resolvers = {
  Query: {
    async placement(_: unknown, { id }: { id: string }, ctx: TenantContext) {
      const row = await ctx.api(`/api/placements/placements?id=${encodeURIComponent(id)}`);
      return shapePlacement(row?.placement ?? row);
    },
    async placements(_: unknown, args: { personId?: string; status?: string; limit?: number }, ctx: TenantContext) {
      const q = new URLSearchParams();
      if (args.personId) q.set('person_id', args.personId);
      if (args.status)   q.set('status', args.status);
      if (args.limit)    q.set('per_page', String(args.limit));
      const body = await ctx.api(`/api/placements/placements?${q.toString()}`);
      return (body?.rows ?? body?.placements ?? body ?? []).map(shapePlacement);
    },
    async person(_: unknown, { id }: { id: string }, ctx: TenantContext) {
      const row = await ctx.api(`/api/people/people?id=${encodeURIComponent(id)}`);
      return shapePerson(row?.person ?? row);
    },
    async people(_: unknown, { limit = 50 }: { limit?: number }, ctx: TenantContext) {
      const body = await ctx.api(`/api/people/people?per_page=${limit}`);
      return (body?.rows ?? body?.people ?? body ?? []).map(shapePerson);
    },
    async company(_: unknown, { id }: { id: string }, ctx: TenantContext) {
      const row = await ctx.api(`/api/people/companies?id=${encodeURIComponent(id)}`);
      return shapeCompany(row?.company ?? row);
    },
    async companies(_: unknown, { limit = 50 }: { limit?: number }, ctx: TenantContext) {
      const body = await ctx.api(`/api/people/companies?per_page=${limit}`);
      return (body?.rows ?? body?.companies ?? body ?? []).map(shapeCompany);
    },
  },

  // Federation reference resolvers — let other subgraphs reference our
  // canonical types by @key.
  Placement: {
    __resolveReference: async (ref: { id: string }, ctx: TenantContext) => {
      const row = await ctx.api(`/api/placements/placements?id=${encodeURIComponent(ref.id)}`);
      return shapePlacement(row?.placement ?? row);
    },
    // Field resolvers that fetch the full Person/Company. Federation
    // won't fire `_entities` against ourselves when the parent already
    // returned a Person object (even one with only `{id}`), so we have
    // to load these inline.
    async person(parent: any, _: unknown, ctx: TenantContext) {
      if (parent?._personId == null) return null;
      const row = await ctx.api(`/api/people/people?id=${parent._personId}`);
      return shapePerson(row?.person ?? row);
    },
    async endClient(parent: any, _: unknown, ctx: TenantContext) {
      if (parent?._endClientId == null) return null;
      const row = await ctx.api(`/api/people/companies?id=${parent._endClientId}`);
      return shapeCompany(row?.company ?? row);
    },
    async externalMappings(parent: any, _: unknown, ctx: TenantContext) {
      const body = await ctx.api(
        `/api/integrations/mappings.php?action=list_for_internal&entity_type=placement&internal_id=${parent.id}`
      );
      return (body?.rows ?? body?.mappings ?? []).map((r: any) => ({
        sourceSystem: r.source_system,
        kind: r.internal_entity_type ?? r.kind ?? 'placement',
        externalId: r.external_id,
        direction: r.direction ?? null,
        payloadSnapshot: r.payload_snapshot ?? null,
        lastSyncedAt: r.last_synced_at ?? r.updated_at ?? r.created_at ?? null,
      }));
    },
    async rates(parent: any, _: unknown, ctx: TenantContext) {
      const body = await ctx.api(`/api/placements/placements?id=${encodeURIComponent(parent.id)}`);
      const cur = body?.current_rate ?? null;
      if (!cur) return null;
      return {
        billRate: cur.bill_rate,
        billRateUnit: cur.bill_rate_unit,
        payRate: cur.pay_rate,
        payRateUnit: cur.pay_rate_unit,
        currency: cur.currency,
        otMultiplier: cur.ot_multiplier,
        dtMultiplier: cur.dt_multiplier,
        effectiveFrom: cur.effective_from,
        effectiveTo: cur.effective_to,
      };
    },
  },
  Person: {
    __resolveReference: async (ref: { id: string }, ctx: TenantContext) => {
      const row = await ctx.api(`/api/people/people?id=${encodeURIComponent(ref.id)}`);
      return shapePerson(row?.person ?? row);
    },
    async placements(parent: any, _: unknown, ctx: TenantContext) {
      const body = await ctx.api(`/api/placements/placements?person_id=${parent.id}`);
      return (body?.rows ?? body?.placements ?? []).map(shapePlacement);
    },
  },
  Company: {
    __resolveReference: async (ref: { id: string }, ctx: TenantContext) => {
      const row = await ctx.api(`/api/people/companies?id=${encodeURIComponent(ref.id)}`);
      return shapeCompany(row?.company ?? row);
    },
    async placements(parent: any, _: unknown, ctx: TenantContext) {
      const body = await ctx.api(`/api/placements/placements?end_client=${parent.id}`);
      return (body?.rows ?? body?.placements ?? []).map(shapePlacement);
    },
  },
};

// ---------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------
const server = new ApolloServer<TenantContext>({
  schema: buildSubgraphSchema({ typeDefs, resolvers: resolvers as any }),
  introspection: true,
});

const { url } = await startStandaloneServer(server, {
  listen: { port: PORT },
  context: async ({ req }) => decodeContext(req as any),
});

// eslint-disable-next-line no-console
console.log(`[subgraph-coreflux] ready at ${url}`);
