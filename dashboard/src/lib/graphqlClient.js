/**
 * CoreFlux GraphQL client — minimal, dependency-free.
 *
 * The dashboard already authenticates via PHP session cookie at
 * corefluxapp.com. The GraphQL endpoint at graphql.corefluxapp.com
 * verifies HS256 JWTs. This module bridges the two:
 *
 *   1. On first use, fetch a short-lived JWT from
 *      /api/auth/issue_dashboard_jwt.php (same-origin, cookie auth).
 *   2. Cache it in module scope until expiry.
 *   3. Expose `gql(query, variables)` that POSTs to the GraphQL
 *      endpoint with `Authorization: Bearer <jwt>`.
 *
 * Why not @apollo/client? For a single-feature pilot the SDK is heavy
 * (~80kb gzipped) and brings caching/devtools/links we don't need yet.
 * When we migrate the second feature we can revisit.
 */

const GRAPHQL_URL =
  (typeof import.meta !== 'undefined' &&
    import.meta.env &&
    import.meta.env.VITE_GRAPHQL_URL) ||
  'https://graphql.corefluxapp.com/';

const JWT_ENDPOINT = '/api/auth/issue_dashboard_jwt.php';

let cached = null; // { token: string, expiresAt: number(ms) }
let inflight = null;

async function fetchToken() {
  // Reuse a pending fetch if one is already in flight.
  if (inflight) return inflight;
  inflight = (async () => {
    let r;
    try {
      r = await fetch(JWT_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
    } catch (netErr) {
      // Network-level failure (DNS, offline, CORS preflight on JWT endpoint, …)
      const e = new Error(`Could not reach JWT mint endpoint at ${JWT_ENDPOINT}: ${netErr.message || netErr}`);
      e.code = 'AUTH_MINT_NETWORK';
      throw e;
    }
    if (!r.ok) {
      let body;
      try { body = await r.text(); } catch { body = ''; }
      let detail = '';
      try { detail = JSON.parse(body)?.error || ''; } catch { detail = body.slice(0, 160); }
      const e = new Error(
        `JWT mint endpoint returned HTTP ${r.status}` +
        (detail ? ` — ${detail}` : '') +
        (r.status === 401 ? ' (no active dashboard session — log out and log back in)' : '') +
        (r.status === 404 ? ` (issue_dashboard_jwt.php not deployed yet on ${JWT_ENDPOINT})` : '')
      );
      e.code = 'AUTH_MINT_HTTP';
      e.status = r.status;
      throw e;
    }
    const j = await r.json();
    if (!j?.jwt) {
      const e = new Error('JWT mint endpoint returned a 2xx response but no `jwt` field');
      e.code = 'AUTH_MINT_SHAPE';
      throw e;
    }
    cached = {
      token: j.jwt,
      // Refresh 60s before the server says it expires.
      expiresAt: (j.expires_at ? j.expires_at * 1000 : Date.now() + 8 * 3600 * 1000) - 60_000,
    };
    return cached.token;
  })().finally(() => {
    inflight = null;
  });
  return inflight;
}

export async function getToken() {
  if (cached && cached.expiresAt > Date.now()) return cached.token;
  return fetchToken();
}

export function clearToken() {
  cached = null;
}

/**
 * Execute a GraphQL query/mutation.
 *
 *   const { data, errors } = await gql(`query { placements { id title } }`);
 *
 * Throws a network error on non-2xx HTTP. Returns { data, errors } shape
 * — caller decides how to handle errors[]. We surface them rather than
 * swallowing because GraphQL can return partial data alongside errors.
 */
export async function gql(query, variables = undefined) {
  let token;
  try {
    token = await getToken();
  } catch (e) {
    // Surface auth-mint failures with a clean shape that callers can render
    // without having to know the codes.
    return {
      data: null,
      errors: [{
        message: e.message,
        extensions: { code: e.code || 'AUTH_MINT_FAILED', status: e.status },
      }],
    };
  }

  const startedAt = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
  let r;
  try {
    r = await fetch(GRAPHQL_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ query, variables }),
    });
  } catch (netErr) {
    // Network-level failure reaching the router (DNS, offline, CORS preflight,
    // mixed-content, TLS, firewall).
    return {
      data: null,
      errors: [{
        message: `Could not reach GraphQL endpoint at ${GRAPHQL_URL}: ${netErr.message || netErr} — open DevTools → Network for the underlying cause (typically CORS preflight rejection or DNS).`,
        extensions: { code: 'GQL_NETWORK', endpoint: GRAPHQL_URL },
      }],
    };
  }
  const elapsedMs = ((typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now()) - startedAt;

  if (r.status === 401) {
    // Token might have just expired between getToken() and the actual request.
    clearToken();
  }

  let json;
  try {
    json = await r.json();
  } catch {
    return {
      data: null,
      errors: [{
        message: `GraphQL endpoint returned non-JSON (HTTP ${r.status})`,
        extensions: { code: 'GQL_NON_JSON', status: r.status },
      }],
    };
  }

  // Attach transport metadata for the caller (perf analytics etc.).
  json.extensions = { ...(json.extensions || {}), transport: { elapsedMs, status: r.status } };
  return json;
}

/**
 * React-hook flavor — mirrors useApi() ergonomics from lib/api.js.
 *
 *   const { data, error, loading, reload } = useGql(QUERY, { variables });
 */
import { useState, useEffect, useCallback, useRef } from 'react';

export function useGql(query, { variables, enabled = true } = {}) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(Boolean(enabled));
  const [elapsedMs, setElapsedMs] = useState(null);
  // Stable JSON snapshot of variables so the effect re-runs when values change
  // but not when callers pass a fresh object with the same shape.
  const varsKey = variables ? JSON.stringify(variables) : '';
  const queryRef = useRef(query);
  queryRef.current = query;

  const load = useCallback(async () => {
    if (!enabled) return;
    setLoading(true);
    setError(null);
    try {
      const res = await gql(queryRef.current, variables);
      const ms = res?.extensions?.transport?.elapsedMs ?? null;
      setElapsedMs(ms);
      if (res.errors && res.errors.length) {
        const err = new Error(res.errors.map((e) => e.message).join('; '));
        err.graphqlErrors = res.errors;
        err.partialData = res.data;
        // First error's extensions usually carry the diagnostic code.
        err.code = res.errors[0]?.extensions?.code;
        setError(err);
        setData(res.data ?? null);
      } else {
        setData(res.data ?? null);
      }
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [varsKey, enabled]);

  useEffect(() => {
    load();
  }, [load]);

  return { data, error, loading, elapsedMs, reload: load };
}

/**
 * Diagnostic probe — checks BOTH the JWT mint endpoint and the GraphQL
 * endpoint independently. Use this when something fails and you need to
 * know which side is broken without opening DevTools.
 *
 *   const r = await runDiagnostics();
 *   // r = {
 *   //   jwtMint:   { ok: true,  status: 200, durationMs: 42 },
 *   //   graphql:   { ok: false, status: 0,   durationMs: 3014, error: 'fetch failed' },
 *   // }
 */
export async function runDiagnostics() {
  const out = { jwtMint: null, graphql: null };

  // 1. JWT mint endpoint (same-origin, cookie auth).
  {
    const t0 = Date.now();
    try {
      const r = await fetch(JWT_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      let bodySnippet = '';
      try { bodySnippet = (await r.text()).slice(0, 200); } catch { /* ignore */ }
      out.jwtMint = {
        ok: r.ok,
        status: r.status,
        durationMs: Date.now() - t0,
        body: bodySnippet,
        error: r.ok ? null : `HTTP ${r.status}`,
      };
    } catch (e) {
      out.jwtMint = { ok: false, status: 0, durationMs: Date.now() - t0, error: e.message || String(e) };
    }
  }

  // 2. GraphQL endpoint — unauthenticated introspection ping. If this
  //    succeeds the network/CORS/TLS path is healthy independent of auth.
  {
    const t0 = Date.now();
    try {
      const r = await fetch(GRAPHQL_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: '{ __schema { queryType { name } } }' }),
      });
      const json = await r.json().catch(() => null);
      out.graphql = {
        ok: r.ok && !!json?.data,
        status: r.status,
        durationMs: Date.now() - t0,
        introspectedType: json?.data?.__schema?.queryType?.name || null,
        error: r.ok ? null : `HTTP ${r.status}`,
      };
    } catch (e) {
      out.graphql = { ok: false, status: 0, durationMs: Date.now() - t0, error: e.message || String(e) };
    }
  }

  return out;
}

export const __GRAPHQL_URL__ = GRAPHQL_URL;
