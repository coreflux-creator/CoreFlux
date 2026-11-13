import React from 'react';
import { Link } from 'react-router-dom';
import SyncHistoryDrawer from '../../../dashboard/src/components/SyncHistoryDrawer';
import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel';

export function PlacementHistoryLinks({ identity }) {
  if (!identity || identity.family.length < 2) return null;
  return <aside aria-label="Related placement numbers" style={{ padding: 12, background: '#f1f5f9', borderRadius: 8, marginBottom: 16 }}>
    <strong>Placement history</strong>{' · '}
    {identity.family.map((p, index) => <React.Fragment key={p.id}>{index > 0 && ' · '}
      <Link to={`/modules/placements/${p.id}/history`}>{p.placement_number}{p.id === identity.current.id ? ' (current)' : ''}</Link>
    </React.Fragment>)}
  </aside>;
}

export default function PlacementHistory({ identity }) {
  const p = identity.source;
  return <section data-testid="placement-original-history" aria-label={`History for ${p.placement_number}`}>
    <h2>{p.placement_number} · {p.title}</h2>
    {identity.merged ? <p role="status">This placement was consolidated into{' '}
      <Link to={`/modules/placements/${identity.current.id}/overview`}>{identity.current.placement_number} · {identity.current.title}</Link>.
      {' '}Open the current placement to make changes.</p> : <p>These records belong to {p.placement_number}.</p>}
    <p>{p.start_date || 'Start not recorded'} → {p.actual_end_date || p.end_date || 'Open-ended'} · {p.engagement_type.toUpperCase()} · {p.status.replaceAll('_', ' ')}</p>
    <p>Earlier rates, time, documents and financial records retain their original placement number.</p>
    <PlacementHistoryLinks identity={identity} />
    <h3>Original rate history</h3>
    {identity.can_view_financial_history ? <div style={{ overflowX: 'auto' }}>
      <table className="data-table" style={{ width: '100%' }}><thead><tr><th>Effective from</th><th>Through</th><th>Bill rate</th><th>Pay rate</th><th>Status</th></tr></thead>
        <tbody>{identity.source_rates.map(rate => <tr key={rate.id}><td>{rate.effective_from}</td><td>{rate.effective_to || 'Open-ended'}</td>
          <td>{rate.currency} {rate.bill_rate} / {rate.bill_rate_unit}</td><td>{rate.currency} {rate.pay_rate} / {rate.pay_rate_unit}</td>
          <td>{rate.approved_at ? 'Approved' : 'Draft'}</td></tr>)}</tbody></table>
      {!identity.source_rates.length && <p>No rate records are stored under this placement number.</p>}
    </div> : <p>Financial history requires placement financial access.</p>}
    {identity.can_view_source_history && <details style={{ marginTop: 16 }}><summary>Original source links and changes</summary>
      <LinkedExternalSystemsPanel entityType="placement" internalId={p.id} readOnly />
      <SyncHistoryDrawer entityType="placement" internalId={p.id} readOnly />
    </details>}
  </section>;
}
