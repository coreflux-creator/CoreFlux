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
    const r = await fetch(JWT_ENDPOINT, {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    });
    if (!r.ok) {
      let err;
      try {
        const j = await r.json();
        err = new Error(j.error || `JWT mint failed (${r.status})`);
      } catch {
        err = new Error(`JWT mint failed (${r.status})`);
      }
      err.status = r.status;
      throw err;
    }
    const j = await r.json();
    if (!j?.jwt) throw new Error('JWT endpoint returned no token');
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
    // Surface auth-mint failures with a clean shape.
    return { data: null, errors: [{ message: e.message, extensions: { code: 'AUTH_MINT_FAILED' } }] };
  }

  const r = await fetch(GRAPHQL_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ query, variables }),
  });

  if (r.status === 401) {
    // Token might have just expired between getToken() and the actual request.
    clearToken();
  }

  const json = await r.json().catch(() => ({
    data: null,
    errors: [{ message: `Non-JSON response (HTTP ${r.status})` }],
  }));

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
      if (res.errors && res.errors.length) {
        const err = new Error(res.errors.map((e) => e.message).join('; '));
        err.graphqlErrors = res.errors;
        err.partialData = res.data;
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

  return { data, error, loading, reload: load };
}

export const __GRAPHQL_URL__ = GRAPHQL_URL;
