import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ArrowLeft, ArrowRight, Check, Clock3, KeyRound, RefreshCw, Save, Unplug } from 'lucide-react';
import { api, useApi } from '../lib/api';

const endpoint = '/api/quanta.php?action=';
const field = { padding: '8px 10px', border: '1px solid var(--cf-border)', borderRadius: 5, background: 'var(--cf-surface)', color: 'var(--cf-text-primary)', minHeight: 36 };
const cell = { padding: '9px 10px', borderBottom: '1px solid var(--cf-border)', verticalAlign: 'top', fontSize: 13 };
const head = { ...cell, color: 'var(--cf-text-secondary)', fontSize: 12, textAlign: 'left', background: 'var(--cf-surface-subtle, #f8fafc)' };
const section = { marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' };

function Notice({ error, children }) {
  return <div role={error ? 'alert' : 'status'} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '10px 12px', borderRadius: 5, background: error ? '#fef2f2' : '#ecfdf5', color: error ? '#991b1b' : '#065f46', marginTop: 14 }}>
    {error ? <AlertCircle size={16} /> : <Check size={16} />}{children}
  </div>;
}

function Status({ status }) {
  const tone = { ready: ['Ready', '#e0f2fe', '#075985'], update: ['Updated source', '#fef3c7', '#92400e'], imported: ['Imported', '#dcfce7', '#166534'], conflict: ['Needs mapping or review', '#fee2e2', '#991b1b'] }[status] || [status, '#f1f5f9', '#334155'];
  return <span style={{ display: 'inline-block', padding: '3px 7px', borderRadius: 4, background: tone[1], color: tone[2], fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' }}>{tone[0]}</span>;
}

function workerName(worker) {
  return worker?.full_name || worker?.name || worker?.email || worker?.id || 'Unknown worker';
}

function placementName(placement) {
  return `PL-${placement.id} · ${placement.person_name || 'Unnamed person'} · ${placement.title || 'Placement'}${placement.end_client_name ? ` · ${placement.end_client_name}` : ''}`;
}

function dimensionLabel(values) {
  if (!values) return 'No dimensions';
  try {
    const parsed = typeof values === 'string' ? JSON.parse(values) : values;
    const parts = Object.entries(parsed || {}).map(([key, value]) => `${key}: ${value}`);
    return parts.length ? parts.join(' · ') : 'No dimensions';
  } catch { return 'Dimension values unavailable'; }
}

function dimensionValues(values) {
  if (!values) return {};
  try { return typeof values === 'string' ? JSON.parse(values) : values; } catch { return {}; }
}

export default function QuantaSettings() {
  const status = useApi(`${endpoint}status`);
  const connected = !!status.data?.connected;
  const [catalog, setCatalog] = useState(null);
  const [key, setKey] = useState('');
  const [confirmWorkspace, setConfirmWorkspace] = useState(false);
  const [confirmSame, setConfirmSame] = useState(false);
  const [since, setSince] = useState(() => { const d = new Date(); d.setDate(d.getDate() - 30); return d.toISOString().slice(0, 10); });
  const [sourceStatus, setSourceStatus] = useState('approved');
  const [preview, setPreview] = useState(null);
  const [offset, setOffset] = useState(0);
  const [selected, setSelected] = useState([]);
  const [routeDrafts, setRouteDrafts] = useState({});
  const [routeEdit, setRouteEdit] = useState(null);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const run = async (label, work) => {
    setBusy(label); setError(''); setNotice('');
    try { await work(); } catch (e) { setError(e.message || 'Quanta request failed'); }
    finally { setBusy(''); }
  };
  const loadCatalog = async () => setCatalog(await api.get(`${endpoint}catalog`, { timeoutMs: 60000 }));
  useEffect(() => {
    if (!connected) { setCatalog(null); setPreview(null); return; }
    let active = true;
    api.get(`${endpoint}catalog`, { timeoutMs: 60000 })
      .then(data => { if (active) setCatalog(data); })
      .catch(e => { if (active) setError(e.message || 'Could not load Quanta workers'); });
    return () => { active = false; };
  }, [connected]);

  const workers = useMemo(() => new Map((catalog?.workers || []).map(w => [String(w.id), w])), [catalog]);
  const sites = useMemo(() => new Map((catalog?.worksites || []).map(s => [String(s.id), s])), [catalog]);
  const activeRoutes = catalog?.routes || [];
  const placements = catalog?.placements || [];
  const needsRoute = useMemo(() => {
    const pairs = new Map();
    for (const row of preview?.rows || []) {
      if (!row.worker_id || !row.work_date || row.placement_id) continue;
      if (!String(row.message || '').includes('Map this Quanta worker')) continue;
      const routeKey = `${row.worker_id}|${row.worksite_id || ''}|${row.dimension_key}`;
      const prior = pairs.get(routeKey);
      if (!prior || row.work_date < prior.work_date) pairs.set(routeKey, row);
    }
    return [...pairs.entries()].map(([routeKey, row]) => ({ routeKey, ...row }));
  }, [preview]);

  const suggestedPlacement = (row) => {
    const email = String(workers.get(String(row.worker_id))?.email || '').trim().toLowerCase();
    if (!email) return '';
    const matches = placements.filter(p => String(p.person_email || '').trim().toLowerCase() === email
      && (!p.start_date || p.start_date <= row.work_date) && (!p.end_date || p.end_date >= row.work_date));
    return matches.length === 1 ? String(matches[0].id) : '';
  };

  const showPreview = (page = 0) => run('preview', async () => {
    const data = await api.post(`${endpoint}preview`, { changed_since: since, source_status: sourceStatus, offset: page }, { timeoutMs: 120000 });
    setPreview(data); setOffset(page); setSelected([]);
  });

  const saveRoutes = () => run('routes', async () => {
    const routes = needsRoute.map(row => {
      const chosen = routeDrafts[row.routeKey] || suggestedPlacement(row);
      if (!chosen) return null;
      const placement = placements.find(p => String(p.id) === String(chosen));
      return { worker_id: row.worker_id, worksite_id: row.worksite_id || '', dimension_values: row.dimension_values,
        placement_id: Number(chosen),
        effective_from: placement?.start_date && placement.start_date < row.work_date ? placement.start_date : row.work_date };
    }).filter(Boolean);
    if (!routes.length) throw new Error('Choose at least one placement');
    const saved = await api.post(`${endpoint}save_routes`, { routes }, { timeoutMs: 120000 });
    setCatalog(prev => ({ ...prev, routes: saved.routes }));
    setRouteDrafts({});
    const data = await api.post(`${endpoint}preview`, { changed_since: since, source_status: sourceStatus, offset }, { timeoutMs: 120000 });
    setPreview(data); setSelected([]);
    setNotice(`${routes.length} placement route${routes.length === 1 ? '' : 's'} saved.`);
  });

  const importSelected = () => run('import', async () => {
    if (!selected.length) throw new Error('Select ready entries first');
    const result = await api.post(`${endpoint}import`, { changed_since: since, source_status: sourceStatus, entry_ids: selected }, { timeoutMs: 120000 });
    setNotice(`${result.inserted} time row${result.inserted === 1 ? '' : 's'} imported, ${result.updated} updated. ${result.timesheets.length} weekly timesheet${result.timesheets.length === 1 ? '' : 's'} ready for review.`);
    const data = await api.post(`${endpoint}preview`, { changed_since: since, source_status: sourceStatus, offset }, { timeoutMs: 120000 });
    setPreview(data); setSelected([]);
  });

  const saveEditedRoute = () => run('route-edit', async () => {
    const route = activeRoutes.find(item => Number(item.id) === Number(routeEdit?.id));
    if (!route) throw new Error('Route no longer exists');
    const result = await api.post(`${endpoint}save_routes`, { routes: [{
      id: route.id, worker_id: route.worker_id, worksite_id: route.worksite_id,
      dimension_values: dimensionValues(route.dimension_values_json),
      placement_id: Number(routeEdit.placement_id), effective_from: routeEdit.effective_from,
      effective_to: routeEdit.effective_to,
    }] }, { timeoutMs: 120000 });
    setCatalog(prev => ({ ...prev, routes: result.routes })); setRouteEdit(null); setPreview(null);
    setNotice('Placement route updated. Preview Quanta time again before importing.');
  });

  const selectable = (preview?.rows || []).filter(row => ['ready', 'update'].includes(row.status)).map(row => row.id);
  const allSelected = selectable.length > 0 && selectable.every(id => selected.includes(id));
  const selectAll = () => setSelected(allSelected ? [] : selectable);

  return <div data-testid="quanta-settings" style={{ maxWidth: 1500, color: 'var(--cf-text-primary)' }}>
    <Link to="/admin/integrations" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginBottom: 16 }}><ArrowLeft size={15} /> Integrations</Link>
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', gap: 16, flexWrap: 'wrap' }}>
      <div><h1 style={{ margin: '0 0 5px', fontSize: 26 }}>Quanta time</h1><div style={{ color: 'var(--cf-text-secondary)' }}>Workspace: <strong>{status.data?.workspace?.name || 'Loading'}</strong></div></div>
      <span style={{ padding: '4px 9px', borderRadius: 4, fontSize: 12, fontWeight: 700,
        background: connected ? '#dcfce7' : '#fef3c7', color: connected ? '#166534' : '#92400e' }}>
        {status.loading ? 'Checking' : connected ? 'Connected' : 'Not connected'}
      </span>
    </div>
    {status.error && <Notice error>{status.error.message}</Notice>}
    {error && <Notice error>{error}</Notice>}
    {notice && <Notice>{notice}</Notice>}

    <div style={section}>
      <h2 style={{ fontSize: 18, margin: '0 0 12px' }}>Connection</h2>
      {connected ? <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <span>Connected key ending {status.data?.api_key_last4 || '••••'}</span>
        <button className="btn btn-secondary" type="button" disabled={!!busy} onClick={() => run('probe', async () => { await api.post(`${endpoint}probe`); await status.reload(); setNotice('Quanta read access verified.'); })}><RefreshCw size={15} /> Check access</button>
        <button className="btn btn-secondary" type="button" disabled={!!busy} onClick={() => { if (window.confirm('Disconnect Quanta from this CoreFlux workspace? Existing imported time stays in CoreFlux.')) run('disconnect', async () => { await api.post(`${endpoint}disconnect`); await status.reload(); setNotice('Quanta disconnected.'); }); }}><Unplug size={15} /> Disconnect</button>
        {status.data?.last_probe_error && <Notice error>{status.data.last_probe_error}</Notice>}
      </div> : <div style={{ display: 'grid', gap: 12, maxWidth: 640 }}>
        <div>Use a Quanta key with <strong>timesheets:read, workers:read, and worksites:read</strong>. No data moves until you preview and choose entries to import.</div>
        <label style={{ display: 'grid', gap: 5 }}>Quanta API key<input type="password" autoComplete="off" value={key} onChange={e => setKey(e.target.value)} style={field} /></label>
        <label style={{ display: 'flex', gap: 8, alignItems: 'start' }}><input type="checkbox" checked={confirmWorkspace} onChange={e => setConfirmWorkspace(e.target.checked)} /> This Quanta account is for {status.data?.workspace?.name || 'this CoreFlux workspace'}.</label>
        {status.data?.status && <label style={{ display: 'flex', gap: 8, alignItems: 'start' }}><input type="checkbox" checked={confirmSame} onChange={e => setConfirmSame(e.target.checked)} /> This key belongs to the same Quanta workspace previously connected here.</label>}
        <div><button className="btn btn-primary" type="button" disabled={!key || !confirmWorkspace || (!!status.data?.status && !confirmSame) || !!busy} onClick={() => run('connect', async () => {
          await api.post(`${endpoint}connect`, { api_key: key, confirm_tenant_id: status.data.workspace.id, confirm_same_quanta_workspace: confirmSame }, { timeoutMs: 60000 });
          setKey(''); await status.reload(); setNotice('Quanta connected. Review placement routes before importing time.');
        })}><KeyRound size={15} /> Connect Quanta</button></div>
      </div>}
    </div>

    {connected && <>
      <div style={section}>
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
          <div><h2 style={{ fontSize: 18, margin: '0 0 4px' }}>Review source time</h2><span style={{ color: 'var(--cf-text-secondary)' }}>Quanta time stays pending review in CoreFlux after import.</span></div>
          <button className="btn btn-secondary" type="button" disabled={!!busy} onClick={() => run('catalog', async () => { await loadCatalog(); setNotice('Quanta workers and placements refreshed.'); })}><RefreshCw size={15} /> Refresh workers</button>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'end', marginTop: 14 }}>
          <label style={{ display: 'grid', gap: 4, fontSize: 13 }}>Changed since<input type="date" value={since} onChange={e => setSince(e.target.value)} style={field} /></label>
          <label style={{ display: 'grid', gap: 4, fontSize: 13 }}>Quanta status<select value={sourceStatus} onChange={e => setSourceStatus(e.target.value)} style={field}><option value="approved">Approved only</option><option value="submitted,approved">Submitted and approved</option></select></label>
          <button className="btn btn-primary" type="button" disabled={!!busy} onClick={() => showPreview(0)}><Clock3 size={15} /> Preview time</button>
        </div>
        {preview && <>
          <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap', margin: '18px 0 12px', fontSize: 14 }}>
            <span><strong>{preview.total}</strong> source entries</span><span><strong>{preview.counts.ready}</strong> ready</span><span><strong>{preview.counts.update}</strong> changed</span><span><strong>{preview.counts.imported}</strong> imported</span><span><strong>{preview.counts.conflict}</strong> need review</span>
          </div>
          {needsRoute.length > 0 && <div style={{ padding: '14px 0', borderTop: '1px solid var(--cf-border)' }}>
            <h3 style={{ fontSize: 15, margin: '0 0 4px' }}>Match workers to placements</h3>
            <div style={{ color: 'var(--cf-text-secondary)', fontSize: 13, marginBottom: 10 }}>Exact email matches are suggested, but nothing is linked until you save.</div>
            <div style={{ display: 'grid', gap: 8 }}>
              {needsRoute.map(row => <div key={row.routeKey} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: 10, alignItems: 'center' }}>
                <div><strong>{workerName(workers.get(String(row.worker_id)))}</strong><div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{sites.get(String(row.worksite_id))?.name || (row.worksite_id || 'No worksite')} · {row.work_date}</div><div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{dimensionLabel(row.dimension_values)}</div></div>
                <select aria-label={`Placement for ${workerName(workers.get(String(row.worker_id)))}`} value={routeDrafts[row.routeKey] ?? suggestedPlacement(row)} onChange={e => setRouteDrafts(prev => ({ ...prev, [row.routeKey]: e.target.value }))} style={{ ...field, width: '100%' }}>
                  <option value="">Choose a placement</option>{placements.filter(p => (!p.start_date || p.start_date <= row.work_date) && (!p.end_date || p.end_date >= row.work_date)).map(p => <option key={p.id} value={p.id}>{placementName(p)}</option>)}
                </select>
              </div>)}
            </div>
            <button className="btn btn-primary" type="button" disabled={!!busy} onClick={saveRoutes} style={{ marginTop: 10 }}><Save size={15} /> Save chosen routes</button>
          </div>}
          <div style={{ overflowX: 'auto', border: '1px solid var(--cf-border)', borderRadius: 5 }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 880 }}>
              <thead><tr><th style={head}><input type="checkbox" aria-label="Select all ready entries on this page" checked={allSelected} onChange={selectAll} /></th>{['Date', 'Worker', 'Worksite', 'Hours', 'Placement', 'Status', 'Detail'].map(h => <th key={h} style={head}>{h}</th>)}</tr></thead>
              <tbody>{preview.rows.map((row, index) => <tr key={`${row.id}-${index}`}>
                <td style={cell}><input type="checkbox" aria-label={`Select Quanta entry ${row.id}`} disabled={!['ready', 'update'].includes(row.status)} checked={selected.includes(row.id)} onChange={e => setSelected(prev => e.target.checked ? [...prev, row.id] : prev.filter(id => id !== row.id))} /></td>
                <td style={cell}>{row.work_date || '—'}</td><td style={cell}>{workerName(workers.get(String(row.worker_id)))}</td><td style={cell}>{sites.get(String(row.worksite_id))?.name || row.worksite_id || '—'}<div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>{dimensionLabel(row.dimension_values)}</div></td>
                <td style={cell}>{row.hours ?? '—'}</td><td style={cell}>{row.placement_id ? `PL-${row.placement_id} · ${row.person_name}` : '—'}</td>
                <td style={cell}><Status status={row.status} /></td><td style={{ ...cell, color: 'var(--cf-text-secondary)' }}>{row.message}</td>
              </tr>)}</tbody>
            </table>
          </div>
          {preview.total === 0 && <div style={{ padding: 16, color: 'var(--cf-text-secondary)' }}>No Quanta entries in this window.</div>}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginTop: 12 }}>
            <div style={{ display: 'flex', gap: 8 }}><button className="btn btn-secondary" type="button" disabled={!!busy || offset === 0} onClick={() => showPreview(Math.max(0, offset - 200))}><ArrowLeft size={15} /> Previous</button><button className="btn btn-secondary" type="button" disabled={!!busy || offset + 200 >= preview.total} onClick={() => showPreview(offset + 200)}>Next <ArrowRight size={15} /></button></div>
            <button className="btn btn-primary" type="button" disabled={!!busy || !selected.length} onClick={importSelected}><Check size={15} /> Import {selected.length} selected</button>
          </div>
        </>}
      </div>
      {activeRoutes.length > 0 && <div style={section}>
        <h2 style={{ fontSize: 18, margin: '0 0 10px' }}>Placement routes</h2>
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 650 }}><thead><tr>{['Worker', 'Worksite / dimensions', 'Placement', 'Effective', ''].map(h => <th key={h} style={head}>{h}</th>)}</tr></thead><tbody>{activeRoutes.map(route => <tr key={route.id}>
          <td style={cell}>{workerName(workers.get(String(route.worker_id)))}</td><td style={cell}>{sites.get(String(route.worksite_id))?.name || route.worksite_id || '—'}<div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>{dimensionLabel(route.dimension_values_json)}</div></td>
          <td style={cell}>{routeEdit?.id === route.id ? <select aria-label="Route placement" style={{ ...field, minWidth: 210 }} value={routeEdit.placement_id} onChange={e => setRouteEdit(prev => ({ ...prev, placement_id: e.target.value }))}>{placements.map(p => <option key={p.id} value={p.id}>{placementName(p)}</option>)}</select> : placementName({ id: route.placement_id, person_name: route.person_name, title: route.title, end_client_name: route.end_client_name })}</td>
          <td style={cell}>{routeEdit?.id === route.id ? <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}><input aria-label="Route start" type="date" style={field} value={routeEdit.effective_from} onChange={e => setRouteEdit(prev => ({ ...prev, effective_from: e.target.value }))} /><input aria-label="Route end" type="date" style={field} value={routeEdit.effective_to} onChange={e => setRouteEdit(prev => ({ ...prev, effective_to: e.target.value }))} /></div> : <>{route.effective_from}{route.effective_to ? ` to ${route.effective_to}` : ' onward'}</>}</td>
          <td style={cell}><div style={{ display: 'flex', gap: 5 }}>{routeEdit?.id === route.id ? <><button className="btn btn-primary" type="button" disabled={!!busy} onClick={saveEditedRoute}><Save size={14} /> Save</button><button className="btn btn-secondary" type="button" disabled={!!busy} onClick={() => setRouteEdit(null)}>Cancel</button></> : <><button className="btn btn-secondary" type="button" disabled={!!busy} onClick={() => setRouteEdit({ id: route.id, placement_id: String(route.placement_id), effective_from: route.effective_from, effective_to: route.effective_to || '' })}>Edit</button><button className="btn btn-secondary" type="button" disabled={!!busy} onClick={() => { if (window.confirm('Remove this route? Imported time will remain in CoreFlux.')) run('delete-route', async () => { const result = await api.post(`${endpoint}delete_route`, { id: route.id }); setCatalog(prev => ({ ...prev, routes: result.routes })); setPreview(null); }); }}>Remove</button></>}</div></td>
        </tr>)}</tbody></table></div>
      </div>}
    </>}
  </div>;
}
