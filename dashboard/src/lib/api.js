/**
 * CoreFlux Shared API Client
 *
 * Thin fetch wrapper used by every React module. Handles:
 *   - Same-origin credentials (PHP session cookie)
 *   - JSON request/response
 *   - Consistent error shape: throws { status, message, data }
 *   - Relative base URL (works on spa.php served from Cloudways)
 *
 * Usage:
 *     import { api } from '../../lib/api';
 *
 *     const employees = await api.get('/modules/payroll/api/employees.php');
 *     const created   = await api.post('/modules/payroll/api/employees.php', { name, email });
 */

const BASE = ''; // same-origin; override via VITE_API_BASE if ever needed
const ENV_BASE =
  (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.VITE_API_BASE) || '';

function joinUrl(path) {
  const base = ENV_BASE || BASE;
  if (/^https?:\/\//i.test(path)) return path;
  if (!base) return path;
  return base.replace(/\/$/, '') + (path.startsWith('/') ? path : '/' + path);
}

async function request(method, path, body, options = {}) {
  const url = joinUrl(path);
  const init = {
    method,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    ...options.fetch,
  };

  let res;
  try {
    res = await fetch(url, init);
  } catch (networkErr) {
    const err = new Error('Network error');
    err.status = 0;
    err.cause = networkErr;
    throw err;
  }

  const text = await res.text();
  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = { raw: text };
    }
  }

  if (!res.ok) {
    const err = new Error((data && data.error) || res.statusText || 'Request failed');
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

export const api = {
  get:    (path, options)       => request('GET',    path, undefined, options),
  post:   (path, body, options) => request('POST',   path, body ?? {}, options),
  put:    (path, body, options) => request('PUT',    path, body ?? {}, options),
  patch:  (path, body, options) => request('PATCH',  path, body ?? {}, options),
  delete: (path, options)       => request('DELETE', path, undefined, options),
};

/**
 * Hook-friendly helper: returns { data, error, loading, reload }.
 * Kept dependency-free so any module can use it without pulling swr/react-query.
 */
import { useState, useEffect, useCallback } from 'react';

export function useApi(path, { enabled = true } = {}) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(Boolean(enabled));
  const [elapsedMs, setElapsedMs] = useState(null);

  const load = useCallback(async () => {
    if (!enabled || !path) return;
    setLoading(true);
    setError(null);
    const t0 = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    try {
      const result = await api.get(path);
      setData(result);
    } catch (e) {
      setError(e);
    } finally {
      const t1 = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
      setElapsedMs(t1 - t0);
      setLoading(false);
    }
  }, [path, enabled]);

  useEffect(() => { load(); }, [load]);

  // `mutate` lets callers patch the cached data in place without
  // triggering a refetch — used for optimistic updates after a POST
  // that returns the canonical row(s). Accepts either a value or an
  // updater function `(prev) => next`, matching React's setState shape.
  const mutate = useCallback((updater) => {
    setData(prev => (typeof updater === 'function' ? updater(prev) : updater));
  }, []);

  return { data, error, loading, elapsedMs, reload: load, mutate };
}

// ---------------------------------------------------------------------------
// LP-001 — SWR-style cache for snappy list navigation.
// ---------------------------------------------------------------------------
// In-memory, module-scoped, dependency-free. The contract:
//   1. Cache hits return the stale value immediately so the list paints
//      on the next paint after navigating back (no spinner flash).
//   2. A background revalidation always fires; the component re-renders
//      once fresh data lands.
//   3. Concurrent calls with the same key share one in-flight Promise
//      (dedup) — useful when two components mount the same key in the
//      same tick.
//   4. TTL is a hint, not a hard expiry. After TTL the entry is still
//      returned (stale-while-revalidate); explicit `bustApiCache(key)`
//      drops the entry — used by mutations.
// Opt-in: only `useApiCached()` consults the cache. `useApi` is unchanged.

const __apiCache    = new Map(); // key → { data, ts }
const __apiInflight = new Map(); // key → Promise<data>

export function bustApiCache(keyOrPredicate) {
  // No argument → clear the entire cache.
  if (keyOrPredicate === undefined || keyOrPredicate === null) {
    __apiCache.clear();
    return;
  }
  // Function → predicate-based bust. Lets callers express
  // "bust every entry whose key starts with X".
  if (typeof keyOrPredicate === 'function') {
    for (const k of Array.from(__apiCache.keys())) {
      if (keyOrPredicate(k)) __apiCache.delete(k);
    }
    return;
  }
  // String → single exact-match key delete (the original signature).
  __apiCache.delete(keyOrPredicate);
}

/**
 * Convenience helper: bust every entry whose cache key starts with the
 * given prefix. Used by mutation flows that change a list, so neighbour
 * filtered views (different status filters, paging, etc.) all refresh
 * on next mount instead of paint-then-flicker.
 */
export function bustApiCachePrefix(prefix) {
  if (typeof prefix !== 'string' || prefix === '') return;
  bustApiCache((k) => typeof k === 'string' && k.startsWith(prefix));
}

export function peekApiCache(key) {
  return __apiCache.get(key) ?? null;
}

/**
 * Background prefetch — kicks `_fetchDeduped` for `path` so the result
 * is sitting in the cache when the user finally clicks. Returns a
 * promise that resolves on success and *swallows* errors so a hover
 * over a row that the user has no permission to open can't blow up.
 *
 * Typical usage on row links:
 *   <Link onMouseEnter={() => prefetchApi(detailPath, `bills-detail:${id}`)} … />
 */
export function prefetchApi(path, cacheKey) {
  if (!path) return Promise.resolve();
  const key = cacheKey ?? path;
  // Don't refetch fresh entries — covers the case where the user
  // hovers, clicks, lands, then comes back and hovers again.
  const hit = __apiCache.get(key);
  if (hit) return Promise.resolve(hit.data);
  return _fetchDeduped(path, key).catch(() => undefined);
}

function _fetchDeduped(path, key) {
  if (__apiInflight.has(key)) return __apiInflight.get(key);
  const p = api.get(path).then(
    (data) => {
      __apiCache.set(key, { data, ts: Date.now() });
      __apiInflight.delete(key);
      return data;
    },
    (err) => {
      __apiInflight.delete(key);
      throw err;
    }
  );
  __apiInflight.set(key, p);
  return p;
}

/**
 * SWR-style cached fetch.
 * @param path     Endpoint URL. Doubles as the cache key unless `cacheKey`
 *                 is given (use a custom key when the same data lives at
 *                 different URLs, e.g. paginated tail-shares).
 * @param options.enabled       Pause requests when false. Default true.
 * @param options.cacheKey      Override key. Default = path.
 * @param options.ttlMs         Freshness window. Default 30000.
 * @param options.revalidateOnMount  When true and cache hit is fresh,
 *                 still kick a background fetch. Default true.
 */
export function useApiCached(path, options = {}) {
  const {
    enabled = true,
    cacheKey,
    ttlMs = 30000,
    revalidateOnMount = true,
  } = options;
  const key = cacheKey ?? path;

  const cached = (enabled && key) ? __apiCache.get(key) : null;
  const [data, setData] = useState(cached ? cached.data : null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(Boolean(enabled) && !cached);
  const [elapsedMs, setElapsedMs] = useState(null);

  const load = useCallback(async () => {
    if (!enabled || !path) return;
    setError(null);
    const fresh = __apiCache.get(key);
    const isFresh = fresh && (Date.now() - fresh.ts) < ttlMs;
    if (!fresh) setLoading(true);
    const t0 = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    try {
      // Fresh hit + revalidateOnMount=false → skip the network call.
      if (isFresh && !revalidateOnMount) {
        setData(fresh.data);
        return;
      }
      const result = await _fetchDeduped(path, key);
      setData(result);
    } catch (e) {
      setError(e);
    } finally {
      const t1 = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
      setElapsedMs(t1 - t0);
      setLoading(false);
    }
  }, [path, key, enabled, ttlMs, revalidateOnMount]);

  useEffect(() => { load(); }, [load]);

  const mutate = useCallback((updater) => {
    setData(prev => {
      const next = (typeof updater === 'function' ? updater(prev) : updater);
      // Keep the cache aligned with optimistic updates so a sibling
      // component reading the same key sees the same value.
      if (key) __apiCache.set(key, { data: next, ts: Date.now() });
      return next;
    });
  }, [key]);

  const reload = useCallback(() => {
    if (key) __apiCache.delete(key);
    return load();
  }, [key, load]);

  return { data, error, loading, elapsedMs, reload, mutate };
}

export default api;
