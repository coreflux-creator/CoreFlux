/**
 * JobDiva REST client (Node).
 *
 * Talks to the PHP internal bridge `/api/internal/jobdiva_proxy.php`
 * rather than JobDiva directly. The bridge owns:
 *   - per-tenant credential decryption,
 *   - session-token lifecycle (refresh on 401),
 *   - error normalisation,
 * so we don't duplicate any of it here.
 *
 * Why a bridge?
 *   • Credentials live in MariaDB encrypted with the PHP-side key. Re-
 *     implementing decryption in Node would duplicate trust.
 *   • Token refresh is already battle-tested in jobdivaCall().
 *   • One canonical place to add metrics / rate-limiting.
 */
import crypto from 'node:crypto';

const BRIDGE_URL = process.env.COREFLUX_API_BASE
  ? `${process.env.COREFLUX_API_BASE.replace(/\/$/, '')}/api/internal/jobdiva_proxy.php`
  : '';
const HMAC_SECRET = process.env.INTERNAL_HMAC_SECRET ?? '';

if (!BRIDGE_URL)   throw new Error('COREFLUX_API_BASE env var is required');
if (!HMAC_SECRET)  throw new Error('INTERNAL_HMAC_SECRET env var is required');

export interface JobDivaCallOpts {
  tenantId: number;
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path:   string;
  body?:  Record<string, unknown> | null;
  query?: Record<string, string | number | boolean> | null;
}

/**
 * Make a JobDiva REST call via the PHP bridge.
 *
 * Returns the raw `body` field from `jobdivaCall()`. Throws on 4xx/5xx
 * from JobDiva itself or the bridge.
 */
export async function jobdivaCall(opts: JobDivaCallOpts): Promise<any> {
  const payload = JSON.stringify({
    tenant_id: opts.tenantId,
    method:    opts.method,
    path:      opts.path,
    body:      opts.body  ?? null,
    query:     opts.query ?? null,
  });

  const ts  = Math.floor(Date.now() / 1000).toString();
  const sig = crypto.createHmac('sha256', HMAC_SECRET).update(`${ts}.${payload}`).digest('hex');

  const r = await fetch(BRIDGE_URL, {
    method: 'POST',
    headers: {
      'Content-Type':         'application/json',
      'X-Internal-Timestamp': ts,
      'X-Internal-Signature': sig,
    },
    body: payload,
  });
  const text = await r.text();
  let parsed: any;
  try { parsed = text ? JSON.parse(text) : {}; } catch { parsed = { raw: text }; }
  if (!r.ok || parsed?.ok === false) {
    const msg = parsed?.error ?? r.statusText ?? 'jobdiva bridge error';
    const err = new Error(`JobDiva ${opts.method} ${opts.path}: ${msg}`);
    (err as any).httpStatus = r.status;
    throw err;
  }
  return parsed.data;
}

/** Helper — extracts the first row out of JobDiva's `/searchX` envelope. */
export function firstRow(resp: any): Record<string, any> | null {
  const body = resp?.body ?? resp ?? null;
  if (!body) return null;
  // Search endpoints return { data: [...] } most often; sometimes { items: [...] } or
  // a bare array, or even a bare object on `searchStart` single-id lookups.
  const rows = body.data ?? body.items ?? (Array.isArray(body) ? body : null);
  if (Array.isArray(rows)) return rows.length > 0 && rows[0] && typeof rows[0] === 'object' ? rows[0] : null;
  if (typeof body === 'object') return body;
  return null;
}

// ---------------------------------------------------------------------
// Mappings bridge — translate external_id ↔ internal_id without DB access.
// ---------------------------------------------------------------------
const MAPPINGS_URL = process.env.COREFLUX_API_BASE
  ? `${process.env.COREFLUX_API_BASE.replace(/\/$/, '')}/api/internal/mappings_lookup.php`
  : '';

export type MappingLookupOp = 'find_external_by_internal' | 'find_internal_by_external';

interface MappingLookupArgs {
  op: MappingLookupOp;
  tenantId: number;
  sourceSystem: string;
  internalEntityType: string;
  internalId?: number;
  externalId?: string;
}

export async function mappingLookup(args: MappingLookupArgs): Promise<{ externalId?: string | null; internalId?: number | null }> {
  const payload = JSON.stringify({
    op: args.op,
    tenant_id: args.tenantId,
    source_system: args.sourceSystem,
    internal_entity_type: args.internalEntityType,
    internal_entity_id: args.internalId ?? null,
    external_id: args.externalId ?? null,
  });
  const ts = Math.floor(Date.now() / 1000).toString();
  const sig = crypto.createHmac('sha256', HMAC_SECRET).update(`${ts}.${payload}`).digest('hex');
  const r = await fetch(MAPPINGS_URL, {
    method: 'POST',
    headers: {
      'Content-Type':         'application/json',
      'X-Internal-Timestamp': ts,
      'X-Internal-Signature': sig,
    },
    body: payload,
  });
  const text = await r.text();
  let parsed: any;
  try { parsed = text ? JSON.parse(text) : {}; } catch { parsed = { raw: text }; }
  if (!r.ok || parsed?.ok === false) {
    throw new Error(`mappings_lookup ${args.op}: ${parsed?.error ?? r.statusText}`);
  }
  return {
    externalId: parsed.external_id ?? null,
    internalId: parsed.internal_id ?? null,
  };
}
