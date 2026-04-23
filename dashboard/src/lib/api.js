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

  const load = useCallback(async () => {
    if (!enabled || !path) return;
    setLoading(true);
    setError(null);
    try {
      const result = await api.get(path);
      setData(result);
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [path, enabled]);

  useEffect(() => { load(); }, [load]);

  return { data, error, loading, reload: load };
}

export default api;
