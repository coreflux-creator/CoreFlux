import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApi, getPinnedTenantId } from '../../../dashboard/src/lib/api';

export default function TimeEntryHistory() {
  const { entryId } = useParams();
  const path = `/modules/time/api/entries.php?action=entry_history&id=${entryId}`;
  const { data, loading, error } = useApi(path, [path]);
  if (loading) return <p>Loading time history…</p>;
  if (error) return <p role="alert">{error.message}</p>;
  const workspace = getPinnedTenantId();
  if (!data || String(data.entry_id) !== String(entryId) || (workspace && String(data.workspace_id) !== String(workspace))) return <p role="alert">Reload this entry in its original workspace.</p>;
  return <section className="people-directory" data-testid="time-entry-history">
    <h2>Time correction history</h2>
    <p>Original entries retain their recorded hours, source and approval. Draft replacements require separate approval.</p>
    <ol style={{ paddingLeft: 24 }}>
      {(data.entries || []).map((entry, index) => <li key={entry.id} data-testid={`time-history-entry-${entry.id}`} style={{ marginBottom: 20 }}>
        <strong>{index === 0 ? 'Original entry' : `Replacement ${index}`}</strong> · {String(entry.status).replaceAll('_', ' ')}
        <p>{entry.work_date} · {Number(entry.hours).toFixed(2)} hours · {String(entry.category).replaceAll('_', ' ')}</p>
        {entry.description && <p>{entry.description}</p>}
        <p>Source: {String(entry.source || 'manual_entry').replaceAll('_', ' ')}{entry.source_ref_id ? ` · source record ${entry.source_ref_id}` : ''}</p>
        <p>{entry.approved_at ? `Approved ${entry.approved_at} · ${String(entry.approved_via || 'Recorded approval').replaceAll('_', ' ')}` : 'Awaiting approval'}</p>
        {entry.correction_reason && <p><strong>Correction reason:</strong> {entry.correction_reason}</p>}
        {entry.timesheet_id && <Link to={`/modules/staffing/timesheets/${entry.timesheet_id}`}>Open this week</Link>}
      </li>)}
    </ol>
    <Link to="/modules/staffing/timesheets">Back to timesheets</Link>
  </section>;
}
