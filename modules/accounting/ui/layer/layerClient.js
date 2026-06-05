/**
 * createLayerClient — tiny fetch wrapper for the CoreFlux LayerFi endpoints.
 *
 * Portable between:
 *   • the standalone sandbox (JWT bearer via getAuthHeaders)
 *   • the live CoreFlux dashboard (session cookie via credentials:'include')
 *
 * Business tokens are returned to React state ONLY — never written to
 * localStorage (per the integration security spec).
 */
export function createLayerClient({ baseUrl = '', getAuthHeaders } = {}) {
  async function call(path, { method = 'GET', body } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (getAuthHeaders) Object.assign(headers, getAuthHeaders() || {});
    const res = await fetch(baseUrl + path, {
      method,
      headers,
      credentials: 'include',
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    let data;
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: text }; }
    if (!res.ok) {
      const err = new Error(data?.error || `HTTP ${res.status}`);
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  return {
    status: () => call('/api/accounting/layer-status'),
    smokeTest: () => call('/api/accounting/layer-smoke-test'),
    setupTenant: (payload) => call('/api/accounting/layer-setup-tenant', { method: 'POST', body: payload || {} }),
    businessToken: () => call('/api/accounting/layer-business-token', { method: 'POST', body: {} }),
    setTenantEnabled: (enabled) =>
      call('/api/accounting/layer-tenant-enablement', { method: 'POST', body: { enabled } }),
    auditLog: (limit = 25) => call(`/api/accounting/layer-audit-log?limit=${limit}`),
    reportClientError: (payload) =>
      call('/api/accounting/layer-client-error', { method: 'POST', body: payload }).catch(() => {}),
  };
}
