import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

/**
 * Staffing → Clients — list, create, edit.
 *
 * Phase 2 v1: table view with inline create dialog. Click a row to open
 * a side drawer with full fields + stats (active placements, MTD revenue).
 */
const EMPTY_CLIENT = {
  name: '', legal_name: '', industry: '', status: 'active',
  primary_contact_name: '', primary_contact_email: '', primary_contact_phone: '',
  billing_city: '', billing_state: '', billing_country: 'US',
  payment_terms_days: 30, notes: '', msa_status: 'none',
};

export default function Clients() {
  const [q, setQ] = useState('');
  const [statusFilter, setStatusFilter] = useState('active');
  const path = `/modules/staffing/api/clients.php?action=list&status=${statusFilter}&q=${encodeURIComponent(q)}`;
  const { data, loading, reload } = useApi(path, [path]);
  const rows = data?.rows ?? [];

  const [drawer, setDrawer]   = useState(null); // { mode: 'new' | 'edit', client }
  const [savePending, setSP]  = useState(false);
  const [saveErr, setSE]      = useState(null);

  const openNew  = () => { setDrawer({ mode: 'new',  client: { ...EMPTY_CLIENT } }); setSE(null); };
  const openEdit = async (row) => {
    const { client } = await api.get(`/modules/staffing/api/clients.php?action=get&id=${row.id}`);
    setDrawer({ mode: 'edit', client });
    setSE(null);
  };
  const exportSearch = () => {
    const params = new URLSearchParams();
    if (statusFilter) params.set('status', statusFilter);
    if (q.trim()) params.set('q', q.trim());
    return params.toString();
  };
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ template_id: String(tplId) });
    if (statusFilter) params.set('status', statusFilter);
    if (q.trim()) params.set('q', q.trim());
    return `/api/v1/staffing/csv-export?${params.toString()}`;
  };

  const save = async (e) => {
    e.preventDefault();
    setSP(true); setSE(null);
    try {
      if (drawer.mode === 'new') {
        await api.post('/modules/staffing/api/clients.php?action=create', drawer.client);
      } else {
        await api.post('/modules/staffing/api/clients.php?action=update', drawer.client);
      }
      setDrawer(null);
      reload();
    } catch (err) {
      setSE(err.message || String(err));
    } finally { setSP(false); }
  };

  const softDelete = async () => {
    if (!confirm(`Close client "${drawer.client.name}"? Existing placements stay linked for history.`)) return;
    setSP(true); setSE(null);
    try {
      await api.post('/modules/staffing/api/clients.php?action=delete', { id: drawer.client.id });
      setDrawer(null);
      reload();
    } catch (err) { setSE(err.message); }
    finally { setSP(false); }
  };

  return (
    <section className="people-directory" data-testid="staffing-clients">
      <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom:'var(--cf-space-3)', flexWrap:'wrap', gap:'var(--cf-space-3)' }}>
        <div>
          <h2>Clients</h2>
          <p style={{ color:'var(--cf-text-secondary)' }}>End-clients you place workers with. Linked from Placements.</p>
        </div>
        <div style={{ display:'flex', gap:'var(--cf-space-2)', flexWrap:'wrap', alignItems:'center' }}>
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search…"
                 data-testid="staffing-clients-search"
                 style={{ padding:'6px 10px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 4, fontSize:'0.9em' }} />
          <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)}
                  data-testid="staffing-clients-status-filter"
                  style={{ padding:'6px 10px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 4, fontSize:'0.9em' }}>
            <option value="active">Active</option>
            <option value="prospect">Prospect</option>
            <option value="on_hold">On hold</option>
            <option value="inactive">Inactive</option>
            <option value="closed">Closed</option>
            <option value="">All</option>
          </select>
          <button className="btn btn--primary" onClick={openNew} data-testid="staffing-clients-new">+ New Client</button>
          <Link to="csv_import" className="btn" data-testid="staffing-clients-import-csv">Import CSV</Link>
          <a className="btn" href={`/api/v1/staffing/csv-export${exportSearch() ? `?${exportSearch()}` : ''}`} data-testid="staffing-clients-export-csv">Export CSV</a>
          <ExportTemplatePicker
            dataset="staffing_clients"
            buildHref={buildTemplateExportHref}
            label="Export via template"
            testid="staffing-clients-export-template"
          />
          <Link to="../clients-graphql" className="btn btn--ghost" data-testid="staffing-clients-switch-gql">⚡ GraphQL pilot</Link>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && <p className="empty" data-testid="staffing-clients-empty">No clients found.</p>}
      {rows.length > 0 && (
        <table className="data-table" data-testid="staffing-clients-table">
          <thead>
            <tr>
              <th>Name</th><th>Industry</th><th>Active Placements</th><th>Contact</th><th>Terms</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} onClick={() => openEdit(r)} style={{ cursor: 'pointer' }} data-testid={`staffing-client-row-${r.id}`}>
                <td><strong>{r.name}</strong>{r.legal_name ? <div style={{ fontSize:'0.75em', color:'var(--cf-text-muted)' }}>{r.legal_name}</div> : null}</td>
                <td>{r.industry || '—'}</td>
                <td style={{ textAlign:'center', fontWeight: 600 }}>{r.active_placements}</td>
                <td>{r.primary_contact_email ? <><div>{r.primary_contact_name}</div><div style={{ fontSize:'0.75em', color:'var(--cf-text-muted)' }}>{r.primary_contact_email}</div></> : '—'}</td>
                <td>Net {r.payment_terms_days}</td>
                <td><code>{r.status}</code></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {drawer && (
        <ClientDrawer drawer={drawer} setDrawer={setDrawer} save={save}
                      softDelete={softDelete} savePending={savePending} saveErr={saveErr} />
      )}
    </section>
  );
}

function ClientDrawer({ drawer, setDrawer, save, softDelete, savePending, saveErr }) {
  const c = drawer.client;
  const set = (k, v) => setDrawer({ ...drawer, client: { ...drawer.client, [k]: v } });

  return (
    <div style={{ position:'fixed', inset: 0, background:'rgba(0,0,0,0.4)', zIndex: 50, display:'flex', justifyContent:'flex-end' }}
         data-testid="staffing-client-drawer"
         onClick={(e) => { if (e.target === e.currentTarget) setDrawer(null); }}>
      <form onSubmit={save}
            style={{ width: 560, maxWidth: '95vw', height: '100%', background:'#fff', padding: 24, overflowY:'auto', display:'flex', flexDirection:'column', gap:'var(--cf-space-3)' }}>
        <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start' }}>
          <div>
            <h3 style={{ margin: 0 }}>{drawer.mode === 'new' ? 'New Client' : c.name}</h3>
            {drawer.mode === 'edit' && <div style={{ fontSize:'0.8em', color:'var(--cf-text-muted)' }}>ID #{c.id}</div>}
          </div>
          <button type="button" onClick={() => setDrawer(null)} style={{ background:'none', border:'none', fontSize:'1.5em', cursor:'pointer', color:'var(--cf-text-muted)' }} data-testid="staffing-client-drawer-close">×</button>
        </header>

        <Field label="Display name *">
          <input required value={c.name || ''} onChange={e => set('name', e.target.value)} data-testid="staffing-client-name" />
        </Field>
        <Field label="Legal name">
          <input value={c.legal_name || ''} onChange={e => set('legal_name', e.target.value)} data-testid="staffing-client-legal-name" />
        </Field>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'var(--cf-space-2)' }}>
          <Field label="Industry">
            <input value={c.industry || ''} onChange={e => set('industry', e.target.value)} data-testid="staffing-client-industry" />
          </Field>
          <Field label="Status">
            <select value={c.status} onChange={e => set('status', e.target.value)} data-testid="staffing-client-status">
              <option value="active">Active</option>
              <option value="prospect">Prospect</option>
              <option value="on_hold">On hold</option>
              <option value="inactive">Inactive</option>
              <option value="closed">Closed</option>
            </select>
          </Field>
        </div>

        <h4 style={{ margin: '8px 0 -4px', color:'var(--cf-text-secondary)', fontSize:'0.85em', textTransform:'uppercase' }}>Primary contact</h4>
        <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'var(--cf-space-2)' }}>
          <Field label="Name">
            <input value={c.primary_contact_name || ''} onChange={e => set('primary_contact_name', e.target.value)} data-testid="staffing-client-contact-name" />
          </Field>
          <Field label="Phone">
            <input value={c.primary_contact_phone || ''} onChange={e => set('primary_contact_phone', e.target.value)} data-testid="staffing-client-contact-phone" />
          </Field>
        </div>
        <Field label="Email">
          <input type="email" value={c.primary_contact_email || ''} onChange={e => set('primary_contact_email', e.target.value)} data-testid="staffing-client-contact-email" />
        </Field>

        <h4 style={{ margin: '8px 0 -4px', color:'var(--cf-text-secondary)', fontSize:'0.85em', textTransform:'uppercase' }}>Billing</h4>
        <div style={{ display:'grid', gridTemplateColumns:'2fr 1fr 1fr', gap:'var(--cf-space-2)' }}>
          <Field label="City">
            <input value={c.billing_city || ''} onChange={e => set('billing_city', e.target.value)} data-testid="staffing-client-billing-city" />
          </Field>
          <Field label="State">
            <input value={c.billing_state || ''} onChange={e => set('billing_state', e.target.value)} data-testid="staffing-client-billing-state" />
          </Field>
          <Field label="Country">
            <input value={c.billing_country || ''} onChange={e => set('billing_country', e.target.value)} data-testid="staffing-client-billing-country" />
          </Field>
        </div>
        <Field label="Payment terms (Net days)">
          <input type="number" min="0" max="180" value={c.payment_terms_days ?? 30} onChange={e => set('payment_terms_days', parseInt(e.target.value, 10))} data-testid="staffing-client-terms" />
        </Field>

        <Field label="Notes">
          <textarea rows="3" value={c.notes || ''} onChange={e => set('notes', e.target.value)} data-testid="staffing-client-notes" />
        </Field>

        {saveErr && <div className="error" style={{ color:'#dc2626', fontSize:'0.9em' }} data-testid="staffing-client-save-error">{saveErr}</div>}

        <footer style={{ display:'flex', gap:'var(--cf-space-2)', justifyContent:'space-between', marginTop:'auto', paddingTop:'var(--cf-space-3)', borderTop:'1px solid var(--cf-border, #e5e7eb)' }}>
          {drawer.mode === 'edit' ? (
            <button type="button" onClick={softDelete} disabled={savePending}
                    style={{ background:'none', border:'1px solid #dc2626', color:'#dc2626', padding:'6px 14px', borderRadius:4, cursor:'pointer' }}
                    data-testid="staffing-client-close">Close client</button>
          ) : <span />}
          <div style={{ display:'flex', gap:'var(--cf-space-2)' }}>
            <button type="button" onClick={() => setDrawer(null)} className="btn" data-testid="staffing-client-cancel">Cancel</button>
            <button type="submit" className="btn btn--primary" disabled={savePending} data-testid="staffing-client-save">
              {savePending ? 'Saving…' : drawer.mode === 'new' ? 'Create' : 'Save'}
            </button>
          </div>
        </footer>
      </form>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display:'block' }}>
      <div style={{ fontSize:'0.8em', fontWeight: 600, marginBottom: 4, color:'var(--cf-text-secondary)' }}>{label}</div>
      {React.cloneElement(children, {
        style: { width:'100%', padding:'6px 8px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 4, fontSize:'0.95em', ...(children.props.style || {}) }
      })}
    </label>
  );
}
