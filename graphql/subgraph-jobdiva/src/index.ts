/**
 * JobDiva GraphQL subgraph (Apollo Federation v2).
 *
 * Owns: JobDivaAssignment, JobDivaJob, JobDivaCandidate, JobDivaCustomer,
 * JobDivaContact, JobDivaBilling, JobDivaAddress, JobDivaRecruiter.
 * Extends: Placement (CoreFlux-owned) with `jobDiva: JobDivaAssignment`.
 *
 * Pluck logic and endpoint shapes mirror the existing PHP
 * `jobdivaSyncEnrichRelatedEntities()` so AI agents see the same fields
 * the dashboard does.
 */
import { ApolloServer } from '@apollo/server';
import { startStandaloneServer } from '@apollo/server/standalone';
import { buildSubgraphSchema } from '@apollo/subgraph';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import gql from 'graphql-tag';
import jwt from 'jsonwebtoken';

import { firstRow, jobdivaCall, mappingLookup } from './client.js';
import { buildJobDivaLoaders, type JobDivaLoaders } from './loaders.js';

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------
const PORT       = Number(process.env.PORT ?? 4002);
const JWT_SECRET = process.env.JWT_SECRET ?? '';
if (!JWT_SECRET) throw new Error('JWT_SECRET env var is required');

// ---------------------------------------------------------------------
// Context
// ---------------------------------------------------------------------
interface JDContext {
  tenantId: number | null;
  userId:   number | null;
  role:     string | null;
  scopes:   string[];          // role-derived scopes for @requiresScopes
  loaders:  JobDivaLoaders;
}

/**
 * Map a single PHP `role` string into the scopes JobDiva fields gate on.
 * Keeps a single source of truth in one place — when RBAC v2 lands the
 * resolver tree won't need to change, only this function.
 */
function scopesForRole(role: string | null): string[] {
  if (!role) return [];
  if (role === 'master_admin' || role === 'global_admin') {
    return ['bill_rate.read', 'candidate.contact.read', 'customer.contact.read'];
  }
  if (role === 'cfo' || role === 'controller' || role === 'finance') {
    return ['bill_rate.read', 'customer.contact.read'];
  }
  if (role === 'recruiter' || role === 'recruiter_lead') {
    return ['candidate.contact.read', 'customer.contact.read'];
  }
  return [];
}

function decodeContext(req: { headers: Record<string, string | string[] | undefined> }): Omit<JDContext, 'loaders'> {
  const auth = (req.headers['authorization'] || req.headers['Authorization']) as string | undefined;
  const token = auth?.startsWith('Bearer ') ? auth.slice(7) : null;
  let tenantId: number | null = null;
  let userId:   number | null = null;
  let role:     string | null = null;
  if (token) {
    try {
      const claims = jwt.verify(token, JWT_SECRET) as Record<string, unknown>;
      tenantId = Number(claims.tenant_id ?? claims.tid ?? 0) || null;
      userId   = Number(claims.user_id   ?? claims.uid ?? claims.sub ?? 0) || null;
      role     = typeof claims.role === 'string' ? (claims.role as string) : null;
    } catch { /* soft-fail */ }
  }
  // Smoke/dev escape hatch — when DEFAULT_TENANT_ID is set the subgraph
  // resolves with that tenant if no JWT was supplied. Production never
  // sets this env var; the router rejects unauth queries upstream.
  if (tenantId == null && process.env.DEFAULT_TENANT_ID) {
    tenantId = Number(process.env.DEFAULT_TENANT_ID) || null;
  }
  return { tenantId, userId, role, scopes: scopesForRole(role) };
}

// ---------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------
const __filename = fileURLToPath(import.meta.url);
const __dirname  = dirname(__filename);
const typeDefs   = gql(readFileSync(resolve(__dirname, '../schema.graphql'), 'utf8'));

// ---------------------------------------------------------------------
// Field pluck helpers — same chains as the PHP enricher.
// ---------------------------------------------------------------------
function pluck(item: Record<string, any> | null | undefined, keys: string[]): string {
  if (!item || typeof item !== 'object') return '';
  for (const k of keys) {
    if (k in item && item[k] != null) {
      const v = String(item[k]).trim();
      if (v !== '') return v;
    }
  }
  // Also try a case-insensitive scan once — JobDiva inconsistently cases keys.
  const lowerKeys = keys.map((k) => k.toLowerCase());
  for (const realKey of Object.keys(item)) {
    if (lowerKeys.includes(realKey.toLowerCase())) {
      const v = item[realKey];
      if (v != null && String(v).trim() !== '') return String(v).trim();
    }
  }
  return '';
}

function pluckId(item: Record<string, any> | null | undefined, keys: string[]): number | null {
  const s = pluck(item, keys);
  if (s === '') return null;
  const n = Number(s);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function asDate(raw: any): string | null {
  if (!raw) return null;
  if (typeof raw === 'number') {
    // JobDiva sometimes returns epoch-ms
    const d = new Date(raw);
    return isNaN(d.getTime()) ? null : d.toISOString().slice(0, 10);
  }
  const s = String(raw).trim();
  if (!s) return null;
  // Best-effort YYYY-MM-DD extraction. JobDiva mixes "2025-04-01", "04/01/2025", "2025-04-01T00:00:00".
  const m = s.match(/(\d{4})-(\d{2})-(\d{2})/);
  if (m) return `${m[1]}-${m[2]}-${m[3]}`;
  const m2 = s.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
  if (m2) return `${m2[3]}-${m2[1]}-${m2[2]}`;
  return null;
}

function shapeAddress(jd: Record<string, any> | null): any {
  if (!jd) return null;
  return {
    line1:      jd.address1 ?? jd.address_line1 ?? jd.address ?? null,
    line2:      jd.address2 ?? jd.address_line2 ?? null,
    city:       jd.city ?? null,
    state:      jd.state ?? jd.stateProvince ?? null,
    postalCode: jd.zipCode ?? jd.zip ?? jd.postalCode ?? jd.postal_code ?? null,
    country:    jd.country ?? null,
  };
}

// ---------------------------------------------------------------------
// Shape — JobDiva row → GraphQL shape
// ---------------------------------------------------------------------
function shapeJob(jd: Record<string, any> | null): any {
  if (!jd) return null;
  const id = pluckId(jd, ['id', 'jobId', 'job_id', 'jobID', 'job id']);
  return {
    externalId:   id != null ? String(id) : '',
    title:        pluck(jd, ['title', 'jobTitle', 'job_title', 'job title', 'positionTitle', 'position_title', 'roleName', 'name', 'jobName']) || null,
    refNumber:    pluck(jd, ['refNumber', 'jobRefNumber', 'refNo', 'reference', 'job ref number']) || null,
    description:  pluck(jd, ['description', 'jobDescription', 'descr', 'summary']) || null,
    department:   pluck(jd, ['department', 'departmentName', 'dept']) || null,
    status:       pluck(jd, ['status', 'jobStatus']) || null,
    hiringManager: pluck(jd, ['hiringManager', 'hiring_manager']) || null,
    postedDate:   asDate(jd.postedDate ?? jd.posted_date ?? jd.dateposted ?? null),
  };
}

function shapeCandidate(jd: Record<string, any> | null): any {
  if (!jd) return null;
  const id = pluckId(jd, ['id', 'candidateId', 'candidate_id', 'candidateID', 'employeeId']);
  return {
    externalId:    id != null ? String(id) : '',
    firstName:     pluck(jd, ['firstName', 'first_name', 'first name', 'fName']) || null,
    middleName:    pluck(jd, ['middleName', 'middle_name', 'middle name']) || null,
    lastName:      pluck(jd, ['lastName', 'last_name', 'last name', 'lName']) || null,
    displayName:   pluck(jd, ['displayName', 'fullName', 'name']) || null,
    email:         pluck(jd, ['email', 'emailAddress', 'email_address', 'primaryEmail']) || null,
    phonePrimary:  pluck(jd, ['phone', 'phoneNumber', 'homePhone', 'phone1', 'primaryPhone']) || null,
    phoneSecondary: pluck(jd, ['phone2', 'mobilePhone', 'workPhone', 'cellPhone']) || null,
    addressRaw:    pluck(jd, ['address', 'addressRaw', 'fullAddress']) || null,
    homeAddress:   shapeAddress(jd),
    workAuthStatus: pluck(jd, ['workAuthStatus', 'workAuth', 'workauth_status', 'visaStatus']) || null,
    workAuthExpiry: asDate(jd.workAuthExpiry ?? jd.workauth_expiry ?? jd.visaExpiry ?? null),
    redacted:      Boolean(jd.redacted ?? jd.is_redacted ?? false),
    hireDate:      asDate(jd.hireDate ?? jd.hire_date ?? null),
  };
}

function shapeCustomer(jd: Record<string, any> | null): any {
  if (!jd) return null;
  const id = pluckId(jd, ['id', 'customerId', 'customer_id', 'customerID', 'clientId']);
  return {
    externalId:    id != null ? String(id) : '',
    name:          pluck(jd, ['name', 'customerName', 'customer_name', 'displayName', 'companyName']) || '',
    industry:      pluck(jd, ['industry']) || null,
    billingAddress: shapeAddress(jd),
    billingTerms:  pluck(jd, ['billingTerms', 'billing_terms', 'paymentTerms']) || null,
    accountManager: pluck(jd, ['accountManager', 'account_manager', 'amName']) || null,
  };
}

function shapeContact(jd: Record<string, any> | null): any {
  if (!jd) return null;
  const id = pluckId(jd, ['id', 'contactId', 'contact_id', 'contactID']);
  return {
    externalId:  id != null ? String(id) : '',
    firstName:   pluck(jd, ['firstName', 'first_name', 'first name']) || null,
    lastName:    pluck(jd, ['lastName', 'last_name', 'last name']) || null,
    displayName: pluck(jd, ['displayName', 'name', 'fullName']) || null,
    title:       pluck(jd, ['title', 'jobTitle', 'position']) || null,
    email:       pluck(jd, ['email', 'emailAddress', 'primaryEmail']) || null,
    phone:       pluck(jd, ['phone', 'phoneNumber', 'workPhone']) || null,
  };
}

function shapeBilling(jd: Record<string, any> | null): any {
  if (!jd) return null;
  // JobDiva mixes the rate string forms "USD/Hour", "USD/Hr", or just "75.00".
  const rateRaw   = jd.rate ?? jd.billRate ?? jd.bill_rate ?? null;
  const payRaw    = jd.payRate ?? jd.pay_rate ?? null;
  const rateUnit  = jd.rateUnit ?? jd.bill_rate_unit ?? jd.billRateUnit ?? null;
  const payUnit   = jd.payRateUnit ?? jd.pay_rate_unit ?? null;
  const currency  = jd.currency ?? jd.currencyCode ?? jd.curr ?? null;
  return {
    rate:         rateRaw == null ? null : Number(rateRaw),
    rateUnit:     rateUnit ?? null,
    payRate:      payRaw  == null ? null : Number(payRaw),
    payRateUnit:  payUnit ?? null,
    currency:     currency ?? null,
    otMultiplier: jd.otMultiplier ?? jd.ot_multiplier ?? null,
    dtMultiplier: jd.dtMultiplier ?? jd.dt_multiplier ?? null,
  };
}

/** Convert one JobDiva Assignment row into the GraphQL type. */
function shapeAssignment(jd: Record<string, any> | null): any {
  if (!jd) return null;
  const id = pluckId(jd, ['id', 'startId', 'start_id', 'placementId']);
  return {
    externalId:     id != null ? String(id) : '',
    refNumber:      pluck(jd, ['refNumber', 'jobRefNumber', 'job ref number']) || null,
    status:         pluck(jd, ['status']) || null,
    startStatus:    pluck(jd, ['startStatus', 'start_status']) || null,
    startDate:      asDate(jd.startDate ?? jd.start_date ?? null),
    endDate:        asDate(jd.endDate   ?? jd.end_date   ?? null),
    submittalDate:  asDate(jd.submittalDate ?? jd.submittal_date ?? null),
    interviewDate:  asDate(jd.interviewDate ?? jd.interview_date ?? null),
    positionType:   pluck(jd, ['positionType', 'position_type']) || null,
    internalNotes:  pluck(jd, ['internalNotes', 'internal_notes', 'notes']) || null,
    // Foreign-key stubs — actual fetches happen in field resolvers so
    // queries that don't ask for `job { ... }` skip the round-trip.
    _jobId:         pluckId(jd, ['job id', 'jobId', 'job_id', 'jobID']),
    _candidateId:   pluckId(jd, ['candidate id', 'candidateId', 'candidate_id']),
    _customerId:    pluckId(jd, ['customer id', 'customerId', 'customer_id', 'clientId']),
    _contactId:     pluckId(jd, ['job contact id', 'jobContactId', 'contactId']),
    _raw:           jd,
    recruiter: {
      externalId:   pluck(jd, ['recruitedById', 'recruiterId', 'recruited by id']) || null,
      displayName:  pluck(jd, ['recruitedBy', 'recruiter', 'recruited by']) || null,
    },
    rawPayload:     jd,
  };
}

// ---------------------------------------------------------------------
// Resolvers
// ---------------------------------------------------------------------
const resolvers = {
  Query: {
    async jobDivaAssignment(_: unknown, { externalId }: { externalId: string }, ctx: JDContext) {
      if (!ctx.tenantId) return null;
      const row = await ctx.loaders.start.load(externalId);
      return shapeAssignment(row);
    },
    async jobDivaJob(_: unknown, { externalId }: { externalId: string }, ctx: JDContext) {
      if (!ctx.tenantId) return null;
      const row = await ctx.loaders.job.load(externalId);
      return shapeJob(row);
    },
    async jobDivaCandidate(_: unknown, { externalId }: { externalId: string }, ctx: JDContext) {
      if (!ctx.tenantId) return null;
      const row = await ctx.loaders.candidate.load(externalId);
      return shapeCandidate(row);
    },
    async jobDivaCustomer(_: unknown, { externalId }: { externalId: string }, ctx: JDContext) {
      if (!ctx.tenantId) return null;
      const row = await ctx.loaders.customer.load(externalId);
      return shapeCustomer(row);
    },
    async jobDivaContact(_: unknown, { externalId }: { externalId: string }, ctx: JDContext) {
      if (!ctx.tenantId) return null;
      const row = await ctx.loaders.contact.load(externalId);
      return shapeContact(row);
    },
  },

  // Federation reference resolvers — let CoreFlux's `Placement.jobDiva`
  // resolve to the right Assignment without needing the full row.
  JobDivaAssignment: {
    __resolveReference: async (ref: { externalId: string }, ctx: JDContext) => {
      if (!ctx.tenantId) return null;
      const row = await ctx.loaders.start.load(ref.externalId);
      return shapeAssignment(row);
    },
    // Nested entities — fetched lazily via DataLoader, soft-fail to null.
    async job(parent: any, _: unknown, ctx: JDContext) {
      if (parent._jobId == null) return null;
      const row = await ctx.loaders.job.load(parent._jobId);
      return shapeJob(row);
    },
    async candidate(parent: any, _: unknown, ctx: JDContext) {
      if (parent._candidateId == null) return null;
      const row = await ctx.loaders.candidate.load(parent._candidateId);
      return shapeCandidate(row);
    },
    async customer(parent: any, _: unknown, ctx: JDContext) {
      if (parent._customerId == null) return null;
      const row = await ctx.loaders.customer.load(parent._customerId);
      return shapeCustomer(row);
    },
    async contact(parent: any, _: unknown, ctx: JDContext) {
      if (parent._contactId == null) return null;
      const row = await ctx.loaders.contact.load(parent._contactId);
      return shapeContact(row);
    },
    // Bill / pay rate — falls back to /searchStart detail when missing.
    async bill(parent: any, _: unknown, ctx: JDContext) {
      const raw = parent._raw ?? {};
      // If the BI payload already has rate fields, use them; otherwise fetch detail.
      const hasRate = ['rate', 'billRate', 'bill_rate', 'payRate', 'pay_rate']
        .some((k) => raw[k] != null && String(raw[k]).trim() !== '');
      if (hasRate) return shapeBilling(raw);
      // Detail call via DataLoader — same /searchStart but with the start ID.
      const detail = await ctx.loaders.start.load(String(parent.externalId));
      return shapeBilling(detail);
    },
  },

  JobDivaJob:       { __resolveReference: async (r: { externalId: string }, c: JDContext) => shapeJob(await c.loaders.job.load(r.externalId)) },
  JobDivaCandidate: { __resolveReference: async (r: { externalId: string }, c: JDContext) => shapeCandidate(await c.loaders.candidate.load(r.externalId)) },
  JobDivaCustomer:  { __resolveReference: async (r: { externalId: string }, c: JDContext) => shapeCustomer(await c.loaders.customer.load(r.externalId)) },
  JobDivaContact:   { __resolveReference: async (r: { externalId: string }, c: JDContext) => shapeContact(await c.loaders.contact.load(r.externalId)) },

  // CoreFlux Placement extension — the whole reason this subgraph exists.
  Placement: {
    async jobDiva(parent: { id: string }, _: unknown, ctx: JDContext) {
      if (!ctx.tenantId) return null;
      // 1. Find the JobDiva Start ID this placement was sourced from.
      const lookup = await mappingLookup({
        op: 'find_external_by_internal',
        tenantId: ctx.tenantId,
        sourceSystem: 'jobdiva',
        internalEntityType: 'placement',
        internalId: Number(parent.id),
      }).catch(() => ({ externalId: null }));
      if (!lookup.externalId) return null;
      // 2. Fetch the Start row through the loader (deduped + cached per request).
      const row = await ctx.loaders.start.load(lookup.externalId);
      return shapeAssignment(row);
    },
  },
};

// ---------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------
const server = new ApolloServer<JDContext>({
  schema: buildSubgraphSchema({ typeDefs, resolvers: resolvers as any }),
  introspection: true,
});

const { url } = await startStandaloneServer(server, {
  listen: { port: PORT },
  context: async ({ req }) => {
    const base = decodeContext(req as any);
    return {
      ...base,
      loaders: buildJobDivaLoaders({ tenantId: base.tenantId ?? 0 }),
    };
  },
});

// eslint-disable-next-line no-console
console.log(`[subgraph-jobdiva] ready at ${url}`);
