import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

export default function PlacementTimeHistory({ pid }) {
  const pin = getPinnedTenantId();
  return <TimeHistory key={`${pid}:${pin || 'session'}`} pid={Number(pid)} pin={pin} />;
}

function TimeHistory({ pid, pin }) {
  const [page, setPage] = useState(null), [error, setError] = useState(''), [busy, setBusy] = useState(false);
  const mounted = useRef(false), generation = useRef(0);
  const load = async (before = 0) => {
    const expected = ++generation.current;
    setBusy(true); setError('');
    try {
      const result = await api.get(`/modules/placements/api/time_history.php?placement_id=${pid}&before_id=${before}`, pin ? { headers: { 'X-CoreFlux-Tenant-Id': pin } } : undefined);
      if (!mounted.current || generation.current !== expected || getPinnedTenantId() !== pin) return;
      if (Number(result.placement_id) !== pid || (pin && String(result.workspace_id) !== pin) || !Array.isArray(result.entries)) throw new Error('Time history returned a different placement or workspace.');
      setPage(previous => ({ ...result, entries: before ? [...(previous?.entries || []), ...result.entries] : result.entries }));
    } catch (e) { if (mounted.current && generation.current === expected) { setPage(null); setError(e.message || 'Unable to load time history.'); } }
    finally { if (mounted.current && generation.current === expected) setBusy(false); }
  };
  useEffect(() => {
    const alive = mounted;
    alive.current = true; load();
    return () => { alive.current = false; };
    // A keyed instance owns this placement and workspace.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pid, pin]);
  return <section aria-label="Original recorded time" data-testid="placement-time-history">
    <h3>Recorded time</h3>
    <p>Original entries and corrections remain under this placement number. Hours below are shown per entry; superseded entries are retained as history.</p>
    {error && <p role="alert">{error} <button type="button" onClick={() => load()} disabled={busy}>Retry</button></p>}
    {busy && <p>Loading time history…</p>}
    {page && <>
      <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Work date</th><th>Category</th><th>Hours</th><th>Status</th><th>Core approval</th><th>Description</th></tr></thead>
        <tbody>{page.entries.map(entry => <tr key={entry.id}><td>{entry.work_date}</td><td>{String(entry.category || entry.hour_type || '').replaceAll('_', ' ')}</td><td>{Number(entry.hours).toFixed(2)}</td><td>{String(entry.status).replaceAll('_', ' ')}</td><td>{entry.approved_at || 'Not recorded'}</td><td>{entry.description || '—'}</td></tr>)}</tbody></table></div>
      {!page.entries.length && <p>No recorded time is stored for this placement in the selected workspace.</p>}
      {page.next_before_id && <button type="button" disabled={busy} onClick={() => load(page.next_before_id)}>Load older entries</button>}
    </>}
  </section>;
}
