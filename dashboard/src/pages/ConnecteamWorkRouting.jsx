import React, { useEffect, useState } from 'react';
import {
  BriefcaseBusiness, Building2, CalendarClock, Plus, RefreshCw, Route, Trash2,
} from 'lucide-react';
import { api } from '../lib/api';

const cellStyle = {
  padding: '10px 12px', borderBottom: '1px solid var(--cf-border)',
  verticalAlign: 'top', fontSize: 13,
};

const STATUS_META = {
  ready: { label: 'Ready', color: '#047857', background: '#d1fae5' },
  unlinked_person: { label: 'Link person', color: '#991b1b', background: '#fee2e2' },
  unmapped_work: { label: 'Add route', color: '#92400e', background: '#fef3c7' },
  ambiguous_route: { label: 'Conflicting routes', color: '#991b1b', background: '#fee2e2' },
  invalid_route: { label: 'Invalid route', color: '#991b1b', background: '#fee2e2' },
  unapproved: { label: 'Not approved', color: '#475569', background: '#e2e8f0' },
};

function todayInput() {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  return now.toISOString().slice(0, 10);
}

function daysAgoInput(days) {
  const date = new Date();
  date.setDate(date.getDate() - days);
  date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
  return date.toISOString().slice(0, 10);
}

function InlineNotice({ error, children }) {
  return (
    <div role={error ? 'alert' : 'status'} style={{
      marginTop: 12, padding: '9px 11px', borderRadius: 5,
      border: `1px solid ${error ? '#fecaca' : '#bbf7d0'}`,
      color: error ? '#991b1b' : '#166534', background: error ? '#fef2f2' : '#f0fdf4',
      fontSize: 13,
    }}>
      {children}
    </div>
  );
}

function RouteDestination({ route, placements }) {
  if (route.destination_type === 'overhead') {
    return <><strong>{route.overhead_name || route.overhead_code || 'Overhead'}</strong><div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>Overhead · {route.time_category}</div></>;
  }
  const placement = placements.find(item => Number(item.id) === Number(route.placement_id));
  return <><strong>{placement?.title || `Placement PL-${route.placement_id}`}</strong><div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>PL-{route.placement_id} · {route.time_category}</div></>;
}

export default function ConnecteamWorkRouting({ connected, revision = 0 }) {
  const [snapshot, setSnapshot] = useState(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState('');
  const [failure, setFailure] = useState('');
  const [routeDraft, setRouteDraft] = useState({
    source_job_id: '', source_user_id: '', destination_type: 'placement',
    placement_id: '', overhead_category_id: '', time_category: 'regular_billable',
    effective_from: todayInput(), effective_to: '',
  });
  const [overheadDraft, setOverheadDraft] = useState({
    code: '', name: '', department: '', time_category: 'regular_nonbillable',
  });
  const [range, setRange] = useState({ start_date: daysAgoInput(13), end_date: todayInput() });
  const [timePreview, setTimePreview] = useState(null);

  const load = async () => {
    if (!connected) return;
    setLoading(true); setFailure('');
    try {
      const result = await api.get('/api/connecteam/routing.php?action=routing');
      setSnapshot(result.routing);
    } catch (error) {
      setFailure(error.message || 'Could not load Connecteam work routing.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [connected, revision]); // eslint-disable-line react-hooks/exhaustive-deps

  const users = snapshot?.source?.users || [];
  const jobs = snapshot?.source?.jobs || [];
  const placements = snapshot?.placements || [];
  const overheads = snapshot?.overheads || [];
  const routes = snapshot?.routes || [];
  const selectedUser = users.find(user => String(user.id) === String(routeDraft.source_user_id));
  const eligiblePlacements = selectedUser?.person_id
    ? placements.filter(placement => Number(placement.person_id) === Number(selectedUser.person_id))
    : [];

  const changeDestination = (destination_type) => {
    setRouteDraft(draft => ({
      ...draft, destination_type,
      placement_id: '', overhead_category_id: '',
      time_category: destination_type === 'placement' ? 'regular_billable' : 'regular_nonbillable',
    }));
  };

  const saveRoute = async (event) => {
    event.preventDefault(); setFailure(''); setMessage(''); setBusy('route');
    try {
      await api.post('/api/connecteam/save_route.php?action=save_route', routeDraft);
      setMessage('Work route saved. The preview can now resolve matching approved time.');
      setRouteDraft(draft => ({ ...draft, placement_id: '', overhead_category_id: '' }));
      await load();
    } catch (error) {
      setFailure(error.message || 'Could not save the work route.');
    } finally { setBusy(''); }
  };

  const deleteRoute = async (route) => {
    if (!window.confirm(`Remove the route for ${route.source_user_name || 'all linked workers'} / ${route.source_job_name}?`)) return;
    setFailure(''); setMessage(''); setBusy(`route:${route.id}`);
    try {
      await api.post('/api/connecteam/delete_route.php?action=delete_route', { id: route.id });
      setMessage('Work route removed.');
      await load();
    } catch (error) { setFailure(error.message || 'Could not remove the work route.'); }
    finally { setBusy(''); }
  };

  const saveOverhead = async (event) => {
    event.preventDefault(); setFailure(''); setMessage(''); setBusy('overhead');
    try {
      await api.post('/api/connecteam/save_overhead.php?action=save_overhead', overheadDraft);
      setMessage('Overhead destination saved.');
      setOverheadDraft({ code: '', name: '', department: '', time_category: 'regular_nonbillable' });
      await load();
    } catch (error) { setFailure(error.message || 'Could not save the overhead destination.'); }
    finally { setBusy(''); }
  };

  const deleteOverhead = async (overhead) => {
    if (!window.confirm(`Remove the overhead destination “${overhead.name}”?`)) return;
    setFailure(''); setMessage(''); setBusy(`overhead:${overhead.id}`);
    try {
      await api.post('/api/connecteam/delete_overhead.php?action=delete_overhead', { id: overhead.id });
      setMessage('Overhead destination removed.');
      await load();
    } catch (error) { setFailure(error.message || 'Could not remove the overhead destination.'); }
    finally { setBusy(''); }
  };

  const previewTime = async (event) => {
    event.preventDefault(); setFailure(''); setMessage(''); setBusy('time');
    try {
      const result = await api.post('/api/connecteam/time_preview.php?action=time_preview', range);
      setTimePreview(result.preview);
      setMessage('Time routing preview complete. No CoreFlux records were created or changed.');
    } catch (error) { setFailure(error.message || 'Could not preview Connecteam time.'); }
    finally { setBusy(''); }
  };

  if (!connected) return null;

  return (
    <section data-testid="connecteam-work-routing" style={{ marginTop: 30, borderTop: '1px solid var(--cf-border)', paddingTop: 22 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Route size={19} /><h2 style={{ margin: 0, fontSize: 18 }}>Work assignment and time routing</h2></div>
          <p style={{ margin: '7px 0 0', color: 'var(--cf-text-secondary)', maxWidth: 1050 }}>
            Connecteam jobs are source work categories. Route each worker and category to a real CoreFlux placement or to overhead; CoreFlux never creates a placement from a Connecteam job.
          </p>
        </div>
        <button className="btn btn-secondary" type="button" onClick={load} disabled={loading || Boolean(busy)} title="Refresh routing data"><RefreshCw size={16} /> Refresh</button>
      </div>

      {failure ? <InlineNotice error>{failure}</InlineNotice> : null}
      {message ? <InlineNotice>{message}</InlineNotice> : null}
      {loading && !snapshot ? <div style={{ padding: '24px 0', color: 'var(--cf-text-secondary)' }}>Loading work categories and destinations…</div> : null}

      {snapshot ? <>
        <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap', marginTop: 18, padding: '12px 0', borderBottom: '1px solid var(--cf-border)' }}>
          <span><strong>{jobs.length}</strong> Connecteam work categories</span>
          <span><strong>{users.filter(user => user.person_id).length}</strong> linked workers</span>
          <span><strong>{routes.length}</strong> active routes</span>
          <span><strong>{overheads.length}</strong> overhead destinations</span>
        </div>

        <div style={{ marginTop: 22 }}>
          <h3 style={{ margin: 0, fontSize: 16 }}>Current routes</h3>
          <div style={{ overflowX: 'auto', marginTop: 10, border: '1px solid var(--cf-border)', borderRadius: 6 }}>
            <table style={{ width: '100%', minWidth: 920, borderCollapse: 'collapse' }} data-testid="connecteam-routes-table">
              <thead><tr style={{ background: 'var(--cf-surface-subtle, #f8fafc)' }}>{['CONNECTEAM WORKER', 'WORK CATEGORY', 'COREFLUX DESTINATION', 'EFFECTIVE', ''].map(label => <th key={label} style={{ ...cellStyle, textAlign: 'left', color: 'var(--cf-text-secondary)', fontSize: 12 }}>{label}</th>)}</tr></thead>
              <tbody>
                {routes.map(route => <tr key={route.id}>
                  <td style={cellStyle}>{route.source_user_name || 'All linked workers'}{route.person_id ? <div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>P-{route.person_id}</div> : null}</td>
                  <td style={cellStyle}><strong>{route.source_job_name || route.source_job_id}</strong>{route.source_sub_job_id ? <div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>Sub-job {route.source_sub_job_id}</div> : null}</td>
                  <td style={cellStyle}><RouteDestination route={route} placements={placements} /></td>
                  <td style={cellStyle}>{route.effective_from}{route.effective_to ? ` to ${route.effective_to}` : ' onward'}</td>
                  <td style={{ ...cellStyle, width: 60 }}><button className="btn btn-secondary" type="button" onClick={() => deleteRoute(route)} disabled={Boolean(busy)} title="Remove route"><Trash2 size={15} /></button></td>
                </tr>)}
                {!routes.length ? <tr><td colSpan={5} style={{ ...cellStyle, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>No work routes yet.</td></tr> : null}
              </tbody>
            </table>
          </div>
        </div>

        <form onSubmit={saveRoute} data-testid="connecteam-route-form" style={{ marginTop: 22 }}>
          <h3 style={{ margin: 0, fontSize: 16 }}>Add work route</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'minmax(220px, 1.3fr) minmax(220px, 1.3fr) minmax(170px, .8fr)', gap: 10, marginTop: 10 }}>
            <label style={labelStyle}><span>Connecteam work category</span><select aria-label="Connecteam work category" value={routeDraft.source_job_id} onChange={event => setRouteDraft({ ...routeDraft, source_job_id: event.target.value })} required><option value="">Choose category</option>{jobs.map(job => <option key={job.id} value={job.id}>{job.name}{job.code ? ` · ${job.code}` : ''}</option>)}</select></label>
            <label style={labelStyle}><span>Connecteam worker</span><select aria-label="Connecteam worker" value={routeDraft.source_user_id} onChange={event => setRouteDraft({ ...routeDraft, source_user_id: event.target.value, placement_id: '' })} required={routeDraft.destination_type === 'placement'}><option value="">{routeDraft.destination_type === 'overhead' ? 'All linked workers' : 'Choose linked worker'}</option>{users.map(user => <option key={user.id} value={user.id} disabled={!user.person_id}>{user.name}{user.person_id ? ` · P-${user.person_id}` : ' · not linked'}</option>)}</select></label>
            <fieldset style={{ border: 0, padding: 0, margin: 0 }}><legend style={{ fontSize: 12, fontWeight: 600, marginBottom: 6 }}>CoreFlux destination</legend><div style={{ display: 'flex', height: 38 }}><button type="button" className={routeDraft.destination_type === 'placement' ? 'btn btn-primary' : 'btn btn-secondary'} onClick={() => changeDestination('placement')} style={{ flex: 1, borderTopRightRadius: 0, borderBottomRightRadius: 0 }}><BriefcaseBusiness size={15} /> Placement</button><button type="button" className={routeDraft.destination_type === 'overhead' ? 'btn btn-primary' : 'btn btn-secondary'} onClick={() => changeDestination('overhead')} style={{ flex: 1, borderTopLeftRadius: 0, borderBottomLeftRadius: 0 }}><Building2 size={15} /> Overhead</button></div></fieldset>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'minmax(260px, 2fr) minmax(170px, 1fr) minmax(150px, .8fr) minmax(150px, .8fr) auto', gap: 10, marginTop: 10, alignItems: 'end' }}>
            {routeDraft.destination_type === 'placement' ? <label style={labelStyle}><span>CoreFlux placement</span><select data-testid="connecteam-placement-destination" value={routeDraft.placement_id} onChange={event => setRouteDraft({ ...routeDraft, placement_id: event.target.value })} required><option value="">{selectedUser?.person_id ? (eligiblePlacements.length ? 'Choose placement' : 'No eligible placements for this person') : 'Choose a linked worker first'}</option>{eligiblePlacements.map(placement => <option key={placement.id} value={placement.id}>PL-{placement.id} · {placement.title} · {placement.status}</option>)}</select></label> : <label style={labelStyle}><span>CoreFlux overhead destination</span><select data-testid="connecteam-overhead-destination" value={routeDraft.overhead_category_id} onChange={event => setRouteDraft({ ...routeDraft, overhead_category_id: event.target.value })} required><option value="">Choose overhead</option>{overheads.map(overhead => <option key={overhead.id} value={overhead.id}>{overhead.name} · {overhead.code}</option>)}</select></label>}
            <label style={labelStyle}><span>Time category</span><select aria-label="Time category" value={routeDraft.time_category} disabled={routeDraft.destination_type === 'overhead'} onChange={event => setRouteDraft({ ...routeDraft, time_category: event.target.value })}><option value="regular_billable">Regular billable</option><option value="regular_nonbillable">Regular nonbillable</option><option value="OT_billable">Overtime billable</option><option value="OT_nonbillable">Overtime nonbillable</option></select></label>
            <label style={labelStyle}><span>Effective from</span><input type="date" value={routeDraft.effective_from} onChange={event => setRouteDraft({ ...routeDraft, effective_from: event.target.value })} required /></label>
            <label style={labelStyle}><span>Effective through</span><input type="date" value={routeDraft.effective_to} onChange={event => setRouteDraft({ ...routeDraft, effective_to: event.target.value })} /></label>
            <button className="btn btn-primary" type="submit" disabled={Boolean(busy) || !routeDraft.source_job_id || (routeDraft.destination_type === 'placement' ? !routeDraft.placement_id : !routeDraft.overhead_category_id)}><Plus size={16} /> {busy === 'route' ? 'Saving…' : 'Add route'}</button>
          </div>
        </form>

        <div style={{ marginTop: 28, paddingTop: 20, borderTop: '1px solid var(--cf-border)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><Building2 size={18} /><h3 style={{ margin: 0, fontSize: 16 }}>Overhead destinations</h3></div>
          <p style={{ margin: '6px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>Define CoreFlux-owned destinations for internal, leave, and other non-placement time.</p>
          {overheads.length ? <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 10 }}>{overheads.map(overhead => <div key={overhead.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '7px 8px', border: '1px solid var(--cf-border)', borderRadius: 5 }}><strong>{overhead.name}</strong><span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>{overhead.code} · {overhead.time_category}</span><button type="button" className="btn btn-secondary" onClick={() => deleteOverhead(overhead)} disabled={Boolean(busy)} title="Remove overhead destination"><Trash2 size={14} /></button></div>)}</div> : null}
          <form onSubmit={saveOverhead} data-testid="connecteam-overhead-form" style={{ display: 'grid', gridTemplateColumns: '180px minmax(220px, 1fr) minmax(180px, 1fr) 210px auto', gap: 10, marginTop: 12, alignItems: 'end' }}>
            <label style={labelStyle}><span>Code</span><input value={overheadDraft.code} onChange={event => setOverheadDraft({ ...overheadDraft, code: event.target.value.toUpperCase() })} placeholder="INTERNAL" required /></label>
            <label style={labelStyle}><span>Name</span><input value={overheadDraft.name} onChange={event => setOverheadDraft({ ...overheadDraft, name: event.target.value })} placeholder="Internal operations" required /></label>
            <label style={labelStyle}><span>Department</span><input value={overheadDraft.department} onChange={event => setOverheadDraft({ ...overheadDraft, department: event.target.value })} placeholder="Optional" /></label>
            <label style={labelStyle}><span>Time category</span><select value={overheadDraft.time_category} onChange={event => setOverheadDraft({ ...overheadDraft, time_category: event.target.value })}><option value="regular_nonbillable">Regular nonbillable</option><option value="holiday">Holiday</option><option value="vacation">Vacation</option><option value="sick">Sick</option><option value="bereavement">Bereavement</option><option value="unpaid_leave">Unpaid leave</option><option value="custom">Custom</option></select></label>
            <button className="btn btn-secondary" type="submit" disabled={Boolean(busy)}><Plus size={16} /> {busy === 'overhead' ? 'Saving…' : 'Add overhead'}</button>
          </form>
        </div>

        <div style={{ marginTop: 28, paddingTop: 20, borderTop: '1px solid var(--cf-border)' }} data-testid="connecteam-time-preview">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><CalendarClock size={18} /><h3 style={{ margin: 0, fontSize: 16 }}>Approved time routing preview</h3></div>
          <p style={{ margin: '6px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>Checks approved Connecteam time against P-ID links and effective work routes. This preview does not create time, payroll, billing, accounting, or placement records.</p>
          <form onSubmit={previewTime} style={{ display: 'flex', alignItems: 'end', flexWrap: 'wrap', gap: 10, marginTop: 12 }}>
            <label style={{ ...labelStyle, width: 180 }}><span>From</span><input type="date" value={range.start_date} onChange={event => setRange({ ...range, start_date: event.target.value })} required /></label>
            <label style={{ ...labelStyle, width: 180 }}><span>Through</span><input type="date" value={range.end_date} onChange={event => setRange({ ...range, end_date: event.target.value })} required /></label>
            <button className="btn btn-primary" type="submit" disabled={Boolean(busy)}><CalendarClock size={16} /> {busy === 'time' ? 'Checking…' : 'Preview time routing'}</button>
          </form>
          {timePreview ? <>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 14 }}>{Object.entries(STATUS_META).map(([status, meta]) => <span key={status} style={{ padding: '5px 8px', borderRadius: 4, color: meta.color, background: meta.background, fontSize: 12, fontWeight: 600 }}>{meta.label}: {timePreview.counts?.[status] || 0}</span>)}</div>
            <div style={{ overflowX: 'auto', marginTop: 10, border: '1px solid var(--cf-border)', borderRadius: 6 }}><table style={{ width: '100%', minWidth: 980, borderCollapse: 'collapse' }}><thead><tr style={{ background: 'var(--cf-surface-subtle, #f8fafc)' }}>{['DATE', 'WORKER', 'CONNECTEAM CATEGORY', 'HOURS', 'SOURCE STATE', 'ROUTING RESULT'].map(label => <th key={label} style={{ ...cellStyle, textAlign: 'left', color: 'var(--cf-text-secondary)', fontSize: 12 }}>{label}</th>)}</tr></thead><tbody>{(timePreview.rows || []).map((row, index) => { const meta = STATUS_META[row.routing_status] || STATUS_META.invalid_route; return <tr key={`${row.source_activity_id}-${index}`}><td style={cellStyle}>{row.work_date}</td><td style={cellStyle}><strong>{row.source_user_name}</strong><div style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>{row.person_id ? `P-${row.person_id} · ${row.person_name || ''}` : 'No P-ID link'}</div></td><td style={cellStyle}>{row.source_job_name}</td><td style={cellStyle}>{Number(row.hours).toFixed(2)}</td><td style={cellStyle}>{row.source_approved ? 'Approved' : (row.source_submitted ? 'Submitted' : 'Open')}</td><td style={cellStyle}><span style={{ display: 'inline-block', padding: '3px 7px', borderRadius: 4, color: meta.color, background: meta.background, fontSize: 12, fontWeight: 600 }}>{meta.label}</span>{row.route ? <div style={{ marginTop: 4, fontSize: 12 }}>{row.route.destination_type === 'placement' ? `PL-${row.route.placement_id}` : row.route.overhead_name}</div> : null}</td></tr>;})}{!timePreview.rows?.length ? <tr><td colSpan={6} style={{ ...cellStyle, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>No Connecteam time records were returned for this period.</td></tr> : null}</tbody></table></div>
          </> : null}
        </div>
      </> : null}
    </section>
  );
}

const labelStyle = { display: 'grid', gap: 6, fontSize: 12, fontWeight: 600 };
