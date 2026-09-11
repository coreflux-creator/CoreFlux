import React, { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';
import BulkEditBar from '../../../dashboard/src/components/BulkEditBar';
import { Building2, Download, Pencil, Plus, Search, Upload, Zap } from 'lucide-react';

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
  const [searchParams, setSearchParams] = useSearchParams();
  const [q, setQ] = useState('');
  const [statusFilter, setStatusFilter] = useState('active');
  const [sourceFilter, setSourceFilter] = useState('');
  const [sort, setSort] = useState({ key: 'name', dir: 'asc' });
  const path = `/modules/staffing/api/clients.php?action=list&status=${statusFilter}&source=${encodeURIComponent(sourceFilter)}&q=${encodeURIComponent(q)}&sort=${encodeURIComponent(sort.key)}&dir=${encodeURIComponent(sort.dir)}`;
  const { data, error, loading, reload } = useApi(path);
  const rows = data?.rows ?? [];
  const { items, sortKey, sortDir, headerProps } = useTableList(rows, {
    defaultSort: { key: 'name', dir: 'asc' },
    sort,
    onSortChange: setSort,
    dateKeys: ['created_at'],
    numericKeys: ['id', 'active_placements', 'payment_terms_days'],
  });

  const [drawer, setDrawer]   = useState(null); // { mode: 'new' | 'edit', client }
  const [savePending, setSP]  = useState(false);
  const [saveErr, setSE]      = useState(null);
  const [selected, setSelected] = useState(() => new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkResult, setBulkResult] = useState(null);

  const setClientParam = (id = null) => {
    const next = new URLSearchParams(searchParams);
    if (id) next.set('client_id', String(id)); else next.delete('client_id');
    setSearchParams(next, { replace: true });
  };
  const closeDrawer = () => { setDrawer(null); setClientParam(null); setSE(null); };
  const openNew  = () => { setClientParam(null); setDrawer({ mode: 'new', client: { ...EMPTY_CLIENT } }); setSE(null); };
  const openEdit = (row) => setClientParam(row.id);

  useEffect(() => {
    const id = Number(searchParams.get('client_id') || 0);
    if (id <= 0) return;
    let active = true;
    setSE(null);
    api.get(`/modules/staffing/api/clients.php?action=get&id=${id}`)
      .then(({ client }) => { if (active) setDrawer({ mode: 'edit', client }); })
      .catch(err => { if (active) setSE(err?.message || String(err)); });
    return () => { active = false; };
  }, [searchParams]);

  useEffect(() => {
    setSelected(new Set());
    setBulkResult(null);
  }, [q, statusFilter, sourceFilter]);

  const allShownSelected = items.length > 0 && items.every(row => selected.has(Number(row.id)));
  const toggleRow = (id) => {
    const numericId = Number(id);
    setSelected(previous => {
      const next = new Set(previous);
      if (next.has(numericId)) next.delete(numericId); else next.add(numericId);
      return next;
    });
  };
  const toggleAll = () => {
    setSelected(previous => {
      const next = new Set(previous);
      if (allShownSelected) items.forEach(row => next.delete(Number(row.id)));
      else items.forEach(row => next.add(Number(row.id)));
      return next;
    });
  };

  const bulkFields = useMemo(() => [
    {
      key: 'status', label: 'Status', type: 'select', placeholder: 'Choose status',
      options: ['active','prospect','on_hold','inactive','closed'].map(value => ({ value, label: value.replaceAll('_', ' ') })),
    },
    { key: 'payment_terms_days', label: 'Payment terms', type: 'number', min: 0, max: 365, step: 1, placeholder: 'Net days' },
    { key: 'industry', label: 'Industry', type: 'text', placeholder: 'Industry' },
    {
      key: 'msa_status', label: 'MSA status', type: 'select', placeholder: 'Choose MSA status',
      options: ['none','draft','executed','expired'].map(value => ({ value, label: value })),
    },
  ], []);

  const bulkUpdate = async (field, value, label) => {
    if (selected.size === 0) return;
    if (!confirm(`Change ${label.toLowerCase()} for ${selected.size} selected client${selected.size === 1 ? '' : 's'}?`)) return;
    setBulkBusy(true); setBulkResult(null);
    try {
      const result = await api.post('/modules/staffing/api/clients.php?action=bulk_update', {
        ids: Array.from(selected), field, value,
      });
      setBulkResult({ ...result, label, value });
      reload();
    } catch (err) {
      setBulkResult({ error: err?.message || String(err) });
    } finally {
      setBulkBusy(false);
    }
  };
  const exportSearch = () => {
    const params = new URLSearchParams();
    if (statusFilter) params.set('status', statusFilter);
    if (sourceFilter) params.set('source', sourceFilter);
    if (q.trim()) params.set('q', q.trim());
    return params.toString();
  };
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ template_id: String(tplId) });
    if (statusFilter) params.set('status', statusFilter);
    if (sourceFilter) params.set('source', sourceFilter);
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
      closeDrawer();
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
      closeDrawer();
      reload();
    } catch (err) { setSE(err.message); }
    finally { setSP(false); }
  };

  return (
    <section className="people-directory directory-page" data-testid="staffing-clients">
      <header className="directory-page__header">
        <div className="directory-page__title-lockup">
          <span className="directory-page__title-icon directory-page__title-icon--green" aria-hidden="true">
            <Building2 size={21} />
          </span>
          <div>
            <span className="directory-page__eyebrow">Customer directory</span>
            <h2>Clients</h2>
            <p className="directory-page__description">
              {loading ? 'Loading clients…' : `${rows.length} client${rows.length === 1 ? '' : 's'} in this view`}
            </p>
          </div>
        </div>
        <div className="directory-page__actions">
          <button className="btn btn--primary" onClick={openNew} data-testid="staffing-clients-new"><Plus size={16} aria-hidden="true" /> New client</button>
          <Link to="csv_import" className="btn" data-testid="staffing-clients-import-csv"><Upload size={16} aria-hidden="true" /> Import</Link>
          <a className="btn" href={`/api/v1/staffing/csv-export${exportSearch() ? `?${exportSearch()}` : ''}`} data-testid="staffing-clients-export-csv"><Download size={16} aria-hidden="true" /> Export</a>
          <ExportTemplatePicker
            dataset="staffing_clients"
            buildHref={buildTemplateExportHref}
            label="Templates"
            testid="staffing-clients-export-template"
          />
          <Link to="../clients-graphql" className="btn btn--ghost" data-testid="staffing-clients-switch-gql"><Zap size={16} aria-hidden="true" /> GraphQL</Link>
        </div>
      </header>

      <div className="directory-filter-bar">
        <div className="directory-filter-bar__search">
          <Search size={17} aria-hidden="true" />
          <input className="input" type="search" value={q} onChange={e => setQ(e.target.value)} placeholder="Search clients, contacts, IDs, sources…"
                 data-testid="staffing-clients-search" />
        </div>
        <div className="directory-filter-bar__filters">
          <select className="input" value={statusFilter} onChange={e => setStatusFilter(e.target.value)}
                  aria-label="Client status" data-testid="staffing-clients-status-filter">
            <option value="active">Active</option>
            <option value="prospect">Prospect</option>
            <option value="on_hold">On hold</option>
            <option value="inactive">Inactive</option>
            <option value="closed">Closed</option>
            <option value="">All statuses</option>
          </select>
          <select className="input" value={sourceFilter} onChange={e => setSourceFilter(e.target.value)}
                  aria-label="Client source" data-testid="staffing-clients-source-filter">
            <option value="">All sources</option>
            <option value="placement">Placement-linked</option>
            <option value="accounting">Accounting synced</option>
            <option value="jobdiva">JobDiva synced</option>
            <option value="manual">Manual</option>
          </select>
        </div>
      </div>

      <BulkEditBar
        count={selected.size}
        noun="client"
        fields={bulkFields}
        busy={bulkBusy}
        onApply={bulkUpdate}
        onClear={() => setSelected(new Set())}
        testid="staffing-clients-bulk"
      />

      {bulkResult && (
        <div
          data-testid="staffing-clients-bulk-result"
          style={{
            padding:'var(--cf-space-2) var(--cf-space-3)', marginBottom:'var(--cf-space-3)',
            background: bulkResult.error || bulkResult.failed ? '#fee2e2' : '#dcfce7',
            border:`1px solid ${bulkResult.error || bulkResult.failed ? '#fca5a5' : '#86efac'}`,
            borderRadius:6, fontSize:13,
          }}
        >
          {bulkResult.error
            ? <>Bulk update failed: {bulkResult.error}</>
            : <>Updated <strong>{bulkResult.updated}</strong>{bulkResult.skipped ? <>, skipped {bulkResult.skipped}</> : null}{bulkResult.failed ? <>, failed {bulkResult.failed}</> : null} · {bulkResult.label} set to <strong>{String(bulkResult.value).replaceAll('_', ' ')}</strong>.</>}
        </div>
      )}

      {loading && <p>Loading…</p>}
      {!loading && error && (
        <div className="error" data-testid="staffing-clients-error">
          Client directory could not be loaded: {error.message || 'Request failed'}
        </div>
      )}
      {!loading && !error && rows.length === 0 && <p className="empty" data-testid="staffing-clients-empty">No clients found.</p>}
      {rows.length > 0 && (
        <div className="data-table-wrap">
        <table className="data-table" data-testid="staffing-clients-table">
          <thead>
            <tr>
              <th style={{ width:32 }}>
                <input type="checkbox" checked={allShownSelected} onChange={toggleAll}
                       aria-label="Select all shown clients" data-testid="staffing-clients-select-all" />
              </th>
              <th {...headerProps('name', 'staffing-clients-sort')}>Name <SortIndicator active={sortKey === 'name'} dir={sortDir} /></th>
              <th {...headerProps('industry', 'staffing-clients-sort')}>Industry <SortIndicator active={sortKey === 'industry'} dir={sortDir} /></th>
              <th {...headerProps('active_placements', 'staffing-clients-sort')}>Active Placements <SortIndicator active={sortKey === 'active_placements'} dir={sortDir} /></th>
              <th {...headerProps('primary_contact_email', 'staffing-clients-sort')}>Contact <SortIndicator active={sortKey === 'primary_contact_email'} dir={sortDir} /></th>
              <th {...headerProps('source', 'staffing-clients-sort')}>Source <SortIndicator active={sortKey === 'source'} dir={sortDir} /></th>
              <th {...headerProps('payment_terms_days', 'staffing-clients-sort')}>Terms <SortIndicator active={sortKey === 'payment_terms_days'} dir={sortDir} /></th>
              <th {...headerProps('msa_status', 'staffing-clients-sort')}>MSA <SortIndicator active={sortKey === 'msa_status'} dir={sortDir} /></th>
              <th {...headerProps('status', 'staffing-clients-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
              <th style={{ width:44 }} aria-label="Edit" />
            </tr>
          </thead>
          <tbody>
            {items.map(r => (
              <tr key={r.id} onClick={() => openEdit(r)} style={{ cursor: 'pointer' }} data-testid={`staffing-client-row-${r.id}`}>
                <td onClick={event => event.stopPropagation()}>
                  <input type="checkbox" checked={selected.has(Number(r.id))} onChange={() => toggleRow(r.id)}
                         aria-label={`Select ${r.name}`} data-testid={`staffing-client-select-${r.id}`} />
                </td>
                <td>
                  <button type="button" onClick={() => openEdit(r)} data-testid={`staffing-client-open-${r.id}`}
                          style={{ border:0, padding:0, background:'none', color:'var(--cf-accent)', cursor:'pointer', font:'inherit', fontWeight:600, textAlign:'left' }}>
                    {r.name}
                  </button>
                  {r.legal_name ? <div style={{ fontSize:'0.75em', color:'var(--cf-text-muted)' }}>{r.legal_name}</div> : null}
                </td>
                <td>{r.industry || '—'}</td>
                <td style={{ textAlign:'center', fontWeight:600 }} onClick={event => event.stopPropagation()}>
                  {r.company_id && Number(r.active_placements) > 0 ? (
                    <Link to={`/modules/placements/list?status=active&end_client_company_id=${r.company_id}`}
                          data-testid={`staffing-client-placements-${r.id}`}>{r.active_placements}</Link>
                  ) : r.active_placements}
                </td>
                <td>{r.primary_contact_email ? <><div>{r.primary_contact_name}</div><div style={{ fontSize:'0.75em', color:'var(--cf-text-muted)' }}>{r.primary_contact_email}</div></> : '—'}</td>
                <td><span className="source-chip">{r.source_label || 'Manual'}</span></td>
                <td><span className="terms-chip">Net {r.payment_terms_days}</span></td>
                <td><span className={`badge badge--${r.msa_status || 'none'}`}>{r.msa_status || 'none'}</span></td>
                <td><span className={`badge badge--${r.status}`}>{String(r.status).replaceAll('_', ' ')}</span></td>
                <td onClick={event => event.stopPropagation()}>
                  <button type="button" className="btn btn--ghost" onClick={() => openEdit(r)}
                          title={`Edit ${r.name}`} aria-label={`Edit ${r.name}`} data-testid={`staffing-client-edit-${r.id}`}
                          style={{ width:32, height:32, padding:0, display:'inline-grid', placeItems:'center' }}>
                    <Pencil size={15} aria-hidden="true" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      )}

      {drawer && (
        <ClientDrawer drawer={drawer} setDrawer={setDrawer} save={save}
                      closeDrawer={closeDrawer} softDelete={softDelete} savePending={savePending} saveErr={saveErr} />
      )}
    </section>
  );
}

function ClientDrawer({ drawer, setDrawer, closeDrawer, save, softDelete, savePending, saveErr }) {
  const c = drawer.client;
  const set = (k, v) => setDrawer({ ...drawer, client: { ...drawer.client, [k]: v } });

  return (
    <div style={{ position:'fixed', inset: 0, background:'rgba(0,0,0,0.4)', zIndex: 50, display:'flex', justifyContent:'flex-end' }}
         data-testid="staffing-client-drawer"
         onClick={(e) => { if (e.target === e.currentTarget) closeDrawer(); }}>
      <form onSubmit={save}
            style={{ width: 560, maxWidth: '95vw', height: '100%', background:'#fff', padding: 24, overflowY:'auto', display:'flex', flexDirection:'column', gap:'var(--cf-space-3)' }}>
        <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start' }}>
          <div>
            <h3 style={{ margin: 0 }}>{drawer.mode === 'new' ? 'New Client' : c.name}</h3>
            {drawer.mode === 'edit' && <div style={{ fontSize:'0.8em', color:'var(--cf-text-muted)' }}>ID #{c.id}</div>}
          </div>
          <button type="button" onClick={closeDrawer} style={{ background:'none', border:'none', fontSize:'1.5em', cursor:'pointer', color:'var(--cf-text-muted)' }} data-testid="staffing-client-drawer-close">×</button>
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
          <input type="number" min="0" max="365" value={c.payment_terms_days ?? 30} onChange={e => set('payment_terms_days', parseInt(e.target.value, 10))} data-testid="staffing-client-terms" />
        </Field>
        <Field label="MSA status">
          <select value={c.msa_status || 'none'} onChange={e => set('msa_status', e.target.value)} data-testid="staffing-client-msa-status">
            <option value="none">None</option>
            <option value="draft">Draft</option>
            <option value="executed">Executed</option>
            <option value="expired">Expired</option>
          </select>
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
            <button type="button" onClick={closeDrawer} className="btn" data-testid="staffing-client-cancel">Cancel</button>
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
