/**
 * Centralised API client for CoreFlux mobile.
 *
 * - Reads base URL from app.json `extra.apiBaseUrl` so dev / staging /
 *   production builds point at the right Cloudways host.
 * - Attaches `Authorization: Bearer <jwt>` from secure store.
 * - On 401, transparently refreshes via /api/auth/mobile_refresh and retries.
 * - Pure fetch — no axios dep — keeps the bundle small.
 */
import Constants from 'expo-constants';
import * as Storage from './storage';

const extra = (Constants.expoConfig?.extra ?? {}) as { apiBaseUrl?: string };
export const API_BASE = extra.apiBaseUrl ?? 'https://app.coreflux.example.com';

let refreshing: Promise<string | null> | null = null;

async function refreshAccessToken(): Promise<string | null> {
  if (refreshing) return refreshing;
  refreshing = (async () => {
    const refresh = await Storage.getRefreshToken();
    if (!refresh) return null;
    try {
      const r = await fetch(`${API_BASE}/api/auth/mobile_refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refresh }),
      });
      if (!r.ok) return null;
      const j = await r.json();
      const access = j?.data?.access_token ?? j?.access_token;
      const newRefresh = j?.data?.refresh_token ?? j?.refresh_token;
      if (access)     await Storage.setAccessToken(access);
      if (newRefresh) await Storage.setRefreshToken(newRefresh);
      return access ?? null;
    } catch {
      return null;
    } finally {
      refreshing = null;
    }
  })();
  return refreshing;
}

export type ApiOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE' | 'PUT';
  body?: unknown;
  headers?: Record<string, string>;
  /** Skip auth attachment + refresh (e.g. /api/auth/mobile_login itself). */
  skipAuth?: boolean;
};

export async function api<T = unknown>(path: string, opts: ApiOptions = {}): Promise<T> {
  const url = path.startsWith('http') ? path : `${API_BASE}${path}`;
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(opts.headers ?? {}),
  };

  if (!opts.skipAuth) {
    const access = await Storage.getAccessToken();
    if (access) headers.Authorization = `Bearer ${access}`;
  }

  const init: RequestInit = {
    method: opts.method ?? 'GET',
    headers,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  };

  let r = await fetch(url, init);
  if (r.status === 401 && !opts.skipAuth) {
    const refreshed = await refreshAccessToken();
    if (refreshed) {
      headers.Authorization = `Bearer ${refreshed}`;
      r = await fetch(url, { ...init, headers });
    }
  }

  const text = await r.text();
  let payload: unknown = null;
  try { payload = text ? JSON.parse(text) : null; } catch { payload = text; }

  if (!r.ok) {
    const msg = (payload as { message?: string })?.message ?? `HTTP ${r.status}`;
    const err: Error & { status?: number; payload?: unknown } = new Error(msg);
    err.status = r.status;
    err.payload = payload;
    throw err;
  }

  // CoreFlux api_ok wraps responses as { ok: true, data: ... }; unwrap both shapes.
  const p = payload as { ok?: boolean; data?: T } | T;
  if (p && typeof p === 'object' && 'ok' in (p as object) && 'data' in (p as object)) {
    return (p as { data: T }).data;
  }
  return p as T;
}
