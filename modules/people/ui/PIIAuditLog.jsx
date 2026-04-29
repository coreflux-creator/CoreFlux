import React, { useMemo, useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

const EVENT_TYPES = ['', 'pii.viewed', 'banking.viewed', 'banking.updated', 'tax.viewed', 'tax.updated', 'ssn.revealed', 'document.downloaded'];

export default function PIIAuditLog() {
  const [eventType, setEventType] = useState('');
  const [since, setSince] = useState('');
  const [page, setPage]   = useState(1);

  const path = useMemo(() => {
    const params = new URLSearchParams();
    if (eventType) params.set('event_type', eventType);
    if (since)     params.set('since', since);
    params.set('page', String(page));
    return `/modules/people/api/audit_pii.php?${params.toString()}`;
  }, [eventType, since, page]);

  const { data, loading, error } = useApi(path);
  const rows  = data?.rows  ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.per_page ?? 50;
  const lastPage = Math.max(1, Math.ceil(total / perPage));

  return (
    <section data-testid="pii-audit-log">
      <h2>PII Access Log</h2>
      <p style={{ color: '#666' }}>SOC2 self-serve. Visible to tenant_admin per HARD_RULES decision log.</p>

      <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem' }}>
        <select className="input" value={eventType} onChange={e => { setEventType(e.target.value); setPage(1); }} data-testid="pii-audit-event-filter">
          {EVENT_TYPES.map(e => <option key={e} value={e}>{e === '' ? 'All events' : e}</option>)}
        </select>
        <input className="input" type="date" value={since} onChange={e => { setSince(e.target.value); setPage(1); }} data-testid="pii-audit-since" />
        <span data-testid="pii-audit-total" style={{ alignSelf: 'center', color: '#666' }}>{total} entries</span>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="pii-audit-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="pii-audit-table" style={{ width: '100%' }}>
        <thead><tr><th>When</th><th>Actor</th><th>Target</th><th>Event</th><th>Fields</th><th>IP</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="pii-audit-empty">No entries.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`pii-audit-row-${r.id}`}>
              <td>{(r.created_at || '').replace('T', ' ').slice(0, 19)}</td>
              <td>#{r.actor_user_id}</td>
              <td>{r.target_person_id ? `#${r.target_person_id}` : '—'}</td>
              <td><code>{r.event_type}</code></td>
              <td><code style={{ fontSize: '0.85em' }}>{r.fields_json || '—'}</code></td>
              <td>{r.ip_address || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: '0.75rem', display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
        <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="btn" data-testid="pii-audit-prev-page">Prev</button>
        <span data-testid="pii-audit-page-indicator">Page {page} of {lastPage}</span>
        <button disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} className="btn" data-testid="pii-audit-next-page">Next</button>
      </div>
    </section>
  );
}
