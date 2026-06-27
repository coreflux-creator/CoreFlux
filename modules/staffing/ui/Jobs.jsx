import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const EMPTY_JOB = {
  title: '',
  status: 'open',
  client_id: '',
  department: '',
  location_city: '',
  location_state: '',
  location_country: 'US',
  remote_policy: '',
  description: '',
};

export default function Jobs() {
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [drawer, setDrawer] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const path = `/modules/staffing/api/jobs.php?action=list&status=${encodeURIComponent(status)}&q=${encodeURIComponent(q)}`;
  const { data, loading, reload } = useApi(path, [path]);
  const rows = data?.rows ?? [];

  const openNew = () => {
    setDrawer({ mode: 'new', job: { ...EMPTY_JOB }, placements: [] });
    setError(null);
  };

  const openEdit = async (row) => {
    setError(null);
    const res = await api.get(`/modules/staffing/api/jobs.php?action=get&id=${row.id}`);
    setDrawer({ mode: 'edit', job: res.job, placements: res.placements ?? [] });
  };

  const save = async (evt) => {
    evt.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const body = { ...drawer.job };
      ['client_id', 'company_id'].forEach((key) => {
        if (body[key] === '' || body[key] === undefined) body[key] = null;
      });
      if (drawer.mode === 'new') {
        await api.post('/modules/staffing/api/jobs.php?action=create', body);
      } else {
        await api.post('/modules/staffing/api/jobs.php?action=update', body);
      }
      setDrawer(null);
      reload();
    } catch (e) {
      setError(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const closeJob = async () => {
    if (!drawer?.job?.id) return;
    if (!confirm(`Close job "${drawer.job.title}"? Existing placements stay linked for history.`)) return;
    setSaving(true);
    setError(null);
    try {
      await api.post('/modules/staffing/api/jobs.php?action=close', { id: drawer.job.id });
      setDrawer(null);
      reload();
    } catch (e) {
      setError(e.message || 'Close failed');
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="people-directory" data-testid="staffing-jobs">
      <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom:'var(--cf-space-3)', flexWrap:'wrap', gap:'var(--cf-space-3)' }}>
        <div>
          <h2>Jobs / Roles</h2>
          <div style={{ display:'flex', gap:'var(--cf-space-2)', flexWrap:'wrap', marginTop: 8 }}>
            <input
              value={q}
              onChange={e => setQ(e.target.value)}
              placeholder="Search title, department, external id"
              data-testid="staffing-jobs-search"
              style={{ padding:'6px 10px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 4, fontSize:'0.9em', minWidth: 260 }}
            />
            <select
              value={status}
              onChange={e => setStatus(e.target.value)}
              data-testid="staffing-jobs-status-filter"
              style={{ padding:'6px 10px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 4, fontSize:'0.9em' }}
            >
              <option value="">All statuses</option>
              <option value="open">Open</option>
              <option value="active">Active</option>
              <option value="on_hold">On hold</option>
              <option value="filled">Filled</option>
              <option value="closed">Closed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </div>
        <button className="btn btn--primary" onClick={openNew} data-testid="staffing-jobs-new">New Job</button>
      </header>

      {loading && <p className="empty">Loading jobs...</p>}
      {!loading && rows.length === 0 && <p className="empty" data-testid="staffing-jobs-empty">No jobs found.</p>}
      {rows.length > 0 && (
        <table className="data-table" data-testid="staffing-jobs-table">
          <thead>
            <tr>
              <th>Title</th><th>Client</th><th>Status</th><th>Location</th><th>Source</th><th>Placements</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(row => (
              <tr key={row.id} onClick={() => openEdit(row)} style={{ cursor: 'pointer' }} data-testid={`staffing-job-row-${row.id}`}>
                <td><strong>{row.title}</strong>{row.department ? <div style={{ fontSize:'0.75em', color:'var(--cf-text-muted)' }}>{row.department}</div> : null}</td>
                <td>{row.client_name || '-'}</td>
                <td><span className={`status-pill status-${row.status}`}>{row.status}</span></td>
                <td>{[row.location_city, row.location_state, row.location_country].filter(Boolean).join(', ') || '-'}</td>
                <td>{row.source_system === 'jobdiva' ? `JobDiva ${row.external_id || ''}` : row.source_system}</td>
                <td>{row.placement_count ?? 0}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {drawer && (
        <JobDrawer
          drawer={drawer}
          setDrawer={setDrawer}
          save={save}
          closeJob={closeJob}
          saving={saving}
          error={error}
        />
      )}
    </section>
  );
}

function JobDrawer({ drawer, setDrawer, save, closeJob, saving, error }) {
  const job = drawer.job;
  const set = (key, value) => setDrawer(d => ({ ...d, job: { ...d.job, [key]: value } }));

  return (
    <div
      style={{ position:'fixed', inset: 0, background:'rgba(0,0,0,0.4)', zIndex: 50, display:'flex', justifyContent:'flex-end' }}
      data-testid="staffing-job-drawer"
      onClick={(e) => { if (e.target === e.currentTarget) setDrawer(null); }}
    >
      <form
        onSubmit={save}
        style={{ width:'min(620px, 100%)', background:'var(--cf-surface, #fff)', height:'100%', overflowY:'auto', padding:'var(--cf-space-4)', boxShadow:'-8px 0 24px rgba(0,0,0,.18)' }}
      >
        <header style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:'var(--cf-space-3)' }}>
          <div>
            <h3 style={{ margin: 0 }}>{drawer.mode === 'new' ? 'New Job' : job.title}</h3>
            {job.source_system === 'jobdiva' && <p style={{ margin:'4px 0 0', color:'var(--cf-text-muted)' }}>JobDiva {job.external_id}</p>}
          </div>
          <button type="button" onClick={() => setDrawer(null)} style={{ background:'none', border:'none', fontSize:'1.5em', cursor:'pointer', color:'var(--cf-text-muted)' }} data-testid="staffing-job-drawer-close">x</button>
        </header>

        <Field label="Title"><input required value={job.title || ''} onChange={e => set('title', e.target.value)} data-testid="staffing-job-title" /></Field>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'var(--cf-space-2)' }}>
          <Field label="Status">
            <select value={job.status || 'open'} onChange={e => set('status', e.target.value)} data-testid="staffing-job-status">
              <option value="open">Open</option>
              <option value="active">Active</option>
              <option value="on_hold">On hold</option>
              <option value="filled">Filled</option>
              <option value="closed">Closed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </Field>
          <Field label="Department"><input value={job.department || ''} onChange={e => set('department', e.target.value)} data-testid="staffing-job-department" /></Field>
        </div>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 90px', gap:'var(--cf-space-2)' }}>
          <Field label="City"><input value={job.location_city || ''} onChange={e => set('location_city', e.target.value)} data-testid="staffing-job-city" /></Field>
          <Field label="State"><input value={job.location_state || ''} onChange={e => set('location_state', e.target.value)} data-testid="staffing-job-state" /></Field>
          <Field label="Country"><input value={job.location_country || ''} onChange={e => set('location_country', e.target.value)} data-testid="staffing-job-country" /></Field>
        </div>
        <Field label="Remote policy">
          <select value={job.remote_policy || ''} onChange={e => set('remote_policy', e.target.value)} data-testid="staffing-job-remote-policy">
            <option value="">-</option>
            <option value="onsite">Onsite</option>
            <option value="hybrid">Hybrid</option>
            <option value="remote">Remote</option>
          </select>
        </Field>
        <Field label="Description"><textarea rows="5" value={job.description || ''} onChange={e => set('description', e.target.value)} data-testid="staffing-job-description" /></Field>

        {drawer.mode === 'edit' && (
          <section style={{ marginTop:'var(--cf-space-3)', paddingTop:'var(--cf-space-3)', borderTop:'1px solid var(--cf-border, #e5e7eb)' }} data-testid="staffing-job-placements">
            <h4>Linked Placements</h4>
            {(drawer.placements || []).length === 0 ? <p className="empty">No placements linked.</p> : (
              <table className="data-table">
                <tbody>
                  {drawer.placements.map(p => (
                    <tr key={p.id}>
                      <td><Link to={`../placements/${p.id}`}>{p.title}</Link></td>
                      <td>{[p.first_name, p.last_name].filter(Boolean).join(' ') || p.email_primary || '-'}</td>
                      <td>{p.status}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>
        )}

        {error && <div className="error" style={{ color:'#dc2626', fontSize:'0.9em' }} data-testid="staffing-job-save-error">{error}</div>}
        <footer style={{ display:'flex', gap:'var(--cf-space-2)', justifyContent:'space-between', marginTop:'var(--cf-space-4)', paddingTop:'var(--cf-space-3)', borderTop:'1px solid var(--cf-border, #e5e7eb)' }}>
          {drawer.mode === 'edit' && job.status !== 'closed'
            ? <button type="button" onClick={closeJob} className="btn btn--ghost" disabled={saving} data-testid="staffing-job-close">Close job</button>
            : <span />}
          <div style={{ display:'flex', gap:'var(--cf-space-2)' }}>
            <button type="button" onClick={() => setDrawer(null)} className="btn" data-testid="staffing-job-cancel">Cancel</button>
            <button type="submit" className="btn btn--primary" disabled={saving} data-testid="staffing-job-save">{saving ? 'Saving...' : 'Save'}</button>
          </div>
        </footer>
      </form>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display:'block', marginBottom:'var(--cf-space-2)' }}>
      <div style={{ fontSize:'0.8em', fontWeight: 600, marginBottom: 4, color:'var(--cf-text-secondary)' }}>{label}</div>
      {React.cloneElement(children, { style: { width:'100%', padding:'8px 10px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 4, fontSize:'0.95em', ...(children.props.style || {}) } })}
    </label>
  );
}
