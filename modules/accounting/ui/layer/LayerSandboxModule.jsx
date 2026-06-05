import React, { useMemo } from 'react';
import { createLayerClient } from './layerClient';
import LayerSandboxPage from './LayerSandboxPage';
import LayerIntegrationSettingsPage from './LayerIntegrationSettingsPage';

/**
 * LayerSandboxModule — CoreFlux dashboard wrapper.
 *
 * Mounts the shared LayerFi pages inside the live CoreFlux SPA. Auth here is
 * the platform session cookie (createLayerClient sends credentials:'include'),
 * and the tenant is resolved server-side by the PHP endpoints — so the same
 * components that run standalone also run, unchanged, inside the dashboard.
 */
const DASHBOARD_PATHS = {
  sandbox: '/modules/accounting/layer-sandbox',
  settings: '/modules/accounting/layer-integration',
};

function deriveTenant(session) {
  const name = session?.tenant || session?.tenant_name || 'Current tenant';
  let id = session?.tenant_id;
  if (!id && Array.isArray(session?.tenants)) {
    const match = session.tenants.find((t) => t.name === name);
    id = match?.id;
  }
  return { id, name };
}

function canManageIntegrations(session) {
  const roles = [session?.user?.role, session?.user?.global_role, session?.global_role].filter(Boolean);
  return roles.some((r) => ['admin', 'master_admin', 'tenant_admin'].includes(r));
}

export default function LayerSandboxModule({ session, view = 'sandbox' }) {
  const client = useMemo(() => createLayerClient({ baseUrl: '' }), []);
  const tenant = deriveTenant(session);

  if (view === 'settings') {
    return (
      <LayerIntegrationSettingsPage
        client={client}
        tenant={tenant}
        canManage={canManageIntegrations(session)}
        paths={DASHBOARD_PATHS}
      />
    );
  }
  return <LayerSandboxPage client={client} tenant={tenant} paths={DASHBOARD_PATHS} />;
}
