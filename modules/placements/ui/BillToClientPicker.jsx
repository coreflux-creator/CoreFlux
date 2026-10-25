import React from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

export default function BillToClientPicker({ value, currentName, onChange, testId }) {
  const { data, error, loading } = useApi('/modules/staffing/api/clients.php?action=list&status=active&limit=500&sort=name&dir=asc');
  const rows = data?.rows || [];
  const missingCurrent = value && !rows.some(row => String(row.id) === String(value));
  return <label style={{ display: 'flex', flexDirection: 'column' }}>
    <span style={{ color: 'var(--cf-text-secondary)', fontSize: '0.85em' }}>Bill-to client</span>
    <select className="input" value={value || ''} onChange={event => onChange(event.target.value)} disabled={loading || !!error} data-testid={testId}>
      <option value="">— Not selected —</option>
      {missingCurrent && <option value={value}>{currentName || `Client ${value}`} (current)</option>}
      {rows.map(row => <option key={row.id} value={row.id}>{row.record_number ? `${row.record_number} · ` : ''}{row.name}</option>)}
    </select>
    {error && <span role="alert">Clients could not be loaded. Your current selection is unchanged.</span>}
  </label>;
}
