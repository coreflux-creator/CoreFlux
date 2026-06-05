import React, { useCallback, useEffect, useImperativeHandle, useState, forwardRef } from 'react';
import { ScrollText, RefreshCw, CheckCircle2, AlertTriangle } from 'lucide-react';

const ACTION_LABELS = {
  'layer.smoke_test.succeeded': 'Smoke test',
  'layer.smoke_test.failed': 'Smoke test failed',
  'layer.business.created': 'Business created',
  'layer.business.resolved_existing': 'Business resolved',
  'layer.business.create_failed': 'Business create failed',
  'layer.business_token.created': 'Business token issued',
  'layer.business_token.failed': 'Business token failed',
  'layer.status_viewed': 'Status viewed',
  'layer.tenant_enablement.changed': 'Tenant access changed',
  'layer.embedded_component.error': 'Embedded component error',
  'layer.transaction.categorized': 'Transaction categorized',
  'layer.transactions.fetched': 'Transactions fetched',
};

function timeAgo(iso) {
  const t = new Date((iso || '').replace(' ', 'T') + 'Z').getTime();
  if (Number.isNaN(t)) return iso;
  const s = Math.max(0, Math.floor((Date.now() - t) / 1000));
  if (s < 60) return `${s}s ago`;
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

/**
 * LayerAuditTimeline — surfaces the LayerFi integration audit trail
 * (integration_audit_log) for the current tenant. Expose a `reload()` via ref
 * so parent actions can refresh it.
 */
const LayerAuditTimeline = forwardRef(function LayerAuditTimeline({ client, limit = 20 }, ref) {
  const [entries, setEntries] = useState(null);
  const [error, setError] = useState(null);

  const load = useCallback(async () => {
    setError(null);
    try { const r = await client.auditLog(limit); setEntries(r.entries || []); }
    catch (e) { setError(e.message); setEntries([]); }
  }, [client, limit]);

  useEffect(() => { load(); }, [load]);
  useImperativeHandle(ref, () => ({ reload: load }), [load]);

  return (
    <div className="layer-audit" data-testid="layer-audit-timeline">
      <div className="layer-audit__head">
        <h2 className="layer-section-title"><ScrollText size={14} /> Integration audit trail</h2>
        <button className="layer-btn layer-btn--ghost layer-btn--sm" onClick={load} data-testid="layer-audit-refresh">
          <RefreshCw size={14} /> Refresh
        </button>
      </div>
      {error && <div className="layer-alert layer-alert--error">{error}</div>}
      {entries && entries.length === 0 && <p className="layer-hint">No LayerFi activity recorded for this tenant yet.</p>}
      <ul className="layer-audit__list">
        {(entries || []).map((e) => (
          <li key={e.id} className={`layer-audit__row is-${e.status}`} data-testid="layer-audit-row">
            <span className="layer-audit__icon">
              {e.status === 'error' ? <AlertTriangle size={15} /> : <CheckCircle2 size={15} />}
            </span>
            <span className="layer-audit__action">{ACTION_LABELS[e.action] || e.action}</span>
            {e.external_object_id && <code className="layer-audit__obj">{e.external_object_id}</code>}
            {e.error_message && <span className="layer-audit__err">{e.error_message}</span>}
            <span className="layer-audit__time">{timeAgo(e.created_at)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
});

export default LayerAuditTimeline;
