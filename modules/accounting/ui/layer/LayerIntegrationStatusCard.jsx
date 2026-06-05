import React from 'react';
import { ShieldCheck, ShieldAlert, Building2, Globe2, Hash } from 'lucide-react';

function Badge({ ok, yes = 'Yes', no = 'No' }) {
  return (
    <span className={`layer-badge ${ok ? 'layer-badge--ok' : 'layer-badge--muted'}`}>
      {ok ? <ShieldCheck size={13} /> : <ShieldAlert size={13} />} {ok ? yes : no}
    </span>
  );
}

/**
 * LayerIntegrationStatusCard — renders the result of /layer-status.
 */
export default function LayerIntegrationStatusCard({ status }) {
  if (!status) return null;
  return (
    <div className="layer-status-card" data-testid="layer-status-card">
      <div className="layer-status-grid">
        <div className="layer-status-row">
          <span className="layer-status-label"><Globe2 size={14} /> Feature enabled</span>
          <Badge ok={!!status.enabled} />
        </div>
        <div className="layer-status-row">
          <span className="layer-status-label"><Building2 size={14} /> Tenant configured</span>
          <Badge ok={!!status.configured} data-testid="layer-status-configured" />
        </div>
        <div className="layer-status-row">
          <span className="layer-status-label"><Hash size={14} /> Environment</span>
          <span className="layer-status-value layer-mono">{status.environment || '—'}</span>
        </div>
        <div className="layer-status-row">
          <span className="layer-status-label">Mode</span>
          <span className={`layer-badge ${status.stub ? 'layer-badge--warn' : 'layer-badge--ok'}`}>
            {status.stub ? 'sandbox stub' : 'live LayerFi'}
          </span>
        </div>
        {status.legalName && (
          <div className="layer-status-row">
            <span className="layer-status-label">Legal name</span>
            <span className="layer-status-value">{status.legalName}</span>
          </div>
        )}
        {status.layerExternalId && (
          <div className="layer-status-row">
            <span className="layer-status-label">External id</span>
            <span className="layer-status-value layer-mono" data-testid="layer-status-external-id">{status.layerExternalId}</span>
          </div>
        )}
        {status.layerBusinessId && (
          <div className="layer-status-row">
            <span className="layer-status-label">Layer business id</span>
            <span className="layer-status-value layer-mono" data-testid="layer-status-business-id">{status.layerBusinessId}</span>
          </div>
        )}
      </div>
    </div>
  );
}
