import React, { useMemo, useState } from 'react';
import { Archive, CheckCircle2, PackagePlus, Pencil, RotateCcw, Search, X } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { ITEM_TYPES } from '../../../dashboard/src/components/LineItemEditor';

const EMPTY_ITEM = {
  code: '', name: '', item_type: 'other', description: '', default_unit: 'each',
  default_unit_price: '', gl_revenue_account_code: '', taxable: false, active: true,
};

export default function ItemsCatalog() {
  const catalogApi = useApi('/modules/billing/api/items.php?per_page=500');
  const accountsApi = useApi('/modules/accounting/api/accounts.php?type=revenue&active=1&postable=1');
  const [status, setStatus] = useState('active');
  const [type, setType] = useState('all');
  const [selected, setSelected] = useState([]);
  const [editing, setEditing] = useState(null);
  const [message, setMessage] = useState(null);

  const filtered = useMemo(() => (catalogApi.data?.rows ?? []).filter((row) => (
    (status === 'all' || Number(row.active) === (status === 'active' ? 1 : 0))
    && (type === 'all' || row.item_type === type)
  )), [catalogApi.data?.rows, status, type]);
  const { items, search, setSearch, sortKey, sortDir, headerProps } = useTableList(filtered, {
    defaultSort: { key: 'name', dir: 'asc' },
    searchKeys: ['code', 'name', 'description', 'item_type', 'gl_revenue_account_code'],
    numericKeys: ['default_unit_price', 'active'],
  });

  const visibleIds = items.map((row) => Number(row.id));
  const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.includes(id));
  const toggleAll = () => setSelected(allVisibleSelected
    ? selected.filter((id) => !visibleIds.includes(id))
    : Array.from(new Set([...selected, ...visibleIds])));
  const toggleOne = (id) => setSelected((current) => current.includes(id)
    ? current.filter((value) => value !== id)
    : [...current, id]);

  const bulkStatus = async (active) => {
    setMessage(null);
    try {
      const result = await api.post('/modules/billing/api/items.php?action=bulk_update', {
        ids: selected, field: 'active', value: active ? 1 : 0,
      });
      setMessage({ kind: 'ok', text: `${result.updated} item${result.updated === 1 ? '' : 's'} updated` });
      setSelected([]);
      catalogApi.reload();
    } catch (error) {
      setMessage({ kind: 'error', text: error.message });
    }
  };

  return (
    <section className="directory-page" data-testid="billing-items-catalog">
      <div className="directory-page__header">
        <div>
          <h2 className="directory-page__title">Products &amp; services</h2>
          <p className="directory-page__description">Reusable invoice items and default pricing.</p>
        </div>
        <div className="directory-page__actions">
          <button className="btn btn--primary" type="button" onClick={() => setEditing(EMPTY_ITEM)} data-testid="billing-item-new">
            <PackagePlus size={16} /> New item
          </button>
        </div>
      </div>

      <div className="directory-filter-bar">
        <div className="directory-filter-bar__search">
          <Search size={16} />
          <input className="input" type="search" value={search} onChange={(event) => setSearch(event.target.value)}
                 placeholder="Search code, name or description" data-testid="billing-items-search" />
        </div>
        <div className="directory-filter-bar__filters">
          <select className="input" value={type} onChange={(event) => setType(event.target.value)} data-testid="billing-items-type-filter">
            <option value="all">All types</option>
            {ITEM_TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
          <select className="input" value={status} onChange={(event) => setStatus(event.target.value)} data-testid="billing-items-status-filter">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="all">All statuses</option>
          </select>
        </div>
      </div>

      {selected.length > 0 && (
        <div className="bulk-edit-bar" data-testid="billing-items-bulk-bar">
          <div className="bulk-edit-bar__selection"><strong>{selected.length}</strong> selected</div>
          <div className="bulk-edit-bar__controls">
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => bulkStatus(true)}>
              <RotateCcw size={14} /> Activate
            </button>
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => bulkStatus(false)}>
              <Archive size={14} /> Deactivate
            </button>
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setSelected([])} title="Clear selection"><X size={14} /></button>
          </div>
        </div>
      )}

      {message && <p className={message.kind === 'error' ? 'error' : 'success'} data-testid={`billing-items-${message.kind}`}>{message.text}</p>}
      {catalogApi.error && <p className="error">Error: {catalogApi.error.message}</p>}

      <div className="data-table-wrap">
        <table className="data-table" data-testid="billing-items-table">
          <thead><tr>
            <th style={{ width: 40 }}><input type="checkbox" checked={allVisibleSelected} onChange={toggleAll} aria-label="Select visible items" /></th>
            <th {...headerProps('code', 'billing-items-sort')}>Code <SortIndicator active={sortKey === 'code'} dir={sortDir} /></th>
            <th {...headerProps('name', 'billing-items-sort')}>Name <SortIndicator active={sortKey === 'name'} dir={sortDir} /></th>
            <th {...headerProps('item_type', 'billing-items-sort')}>Type <SortIndicator active={sortKey === 'item_type'} dir={sortDir} /></th>
            <th>Unit</th>
            <th {...headerProps('default_unit_price', 'billing-items-sort')} style={{ textAlign: 'right' }}>Default price <SortIndicator active={sortKey === 'default_unit_price'} dir={sortDir} /></th>
            <th>Revenue account</th>
            <th>Status</th>
            <th style={{ width: 54 }}></th>
          </tr></thead>
          <tbody>
            {catalogApi.loading && <tr><td colSpan={9} className="empty">Loading...</td></tr>}
            {!catalogApi.loading && items.length === 0 && <tr><td colSpan={9} className="empty">No products or services found.</td></tr>}
            {items.map((row) => (
              <tr key={row.id}>
                <td><input type="checkbox" checked={selected.includes(Number(row.id))} onChange={() => toggleOne(Number(row.id))} aria-label={`Select ${row.name}`} /></td>
                <td><code>{row.code}</code></td>
                <td>
                  <button type="button" onClick={() => setEditing(row)} data-testid={`billing-item-open-${row.id}`}
                          style={{ padding: 0, border: 0, background: 'none', color: 'var(--cf-accent-dark)', cursor: 'pointer', textAlign: 'left' }}>
                    <strong>{row.name}</strong>
                  </button>
                  {row.description && <div style={{ color: 'var(--cf-text-secondary)', fontSize: 12, marginTop: 2 }}>{row.description}</div>}
                </td>
                <td>{ITEM_TYPES.find((item) => item.value === row.item_type)?.label ?? row.item_type}</td>
                <td>{row.default_unit}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                  {row.default_unit_price === null ? '-' : Number(row.default_unit_price).toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                </td>
                <td>{row.gl_revenue_account_code || <span style={{ color: 'var(--cf-text-muted)' }}>Default</span>}</td>
                <td><span className={`badge badge--${Number(row.active) ? 'approved' : 'none'}`}>{Number(row.active) ? 'Active' : 'Inactive'}</span></td>
                <td><button type="button" className="btn btn--ghost btn--sm" onClick={() => setEditing(row)} title={`Edit ${row.name}`}><Pencil size={15} /></button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="table-pagination">Showing {items.length} of {filtered.length} items</div>

      {editing && (
        <ItemDialog
          item={editing}
          accounts={accountsApi.data?.rows ?? []}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); setMessage({ kind: 'ok', text: 'Item saved' }); catalogApi.reload(); }}
        />
      )}
    </section>
  );
}

function ItemDialog({ item, accounts, onClose, onSaved }) {
  const isEdit = Boolean(item.id);
  const [form, setForm] = useState({ ...EMPTY_ITEM, ...item, active: Number(item.active ?? 1) === 1, taxable: Number(item.taxable ?? 0) === 1 });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const set = (field, value) => setForm((current) => ({ ...current, [field]: value }));
  const save = async (event) => {
    event.preventDefault(); setBusy(true); setError(null);
    try {
      if (isEdit) await api.patch(`/modules/billing/api/items.php?id=${item.id}`, form);
      else await api.post('/modules/billing/api/items.php', form);
      onSaved();
    } catch (saveError) { setError(saveError); }
    finally { setBusy(false); }
  };

  return (
    <div role="presentation" style={{ position: 'fixed', inset: 0, background: 'rgba(15, 23, 42, 0.45)', zIndex: 1000, display: 'grid', placeItems: 'center', padding: 16 }} onMouseDown={(event) => event.target === event.currentTarget && !busy && onClose()}>
      <form role="dialog" aria-modal="true" aria-label={isEdit ? 'Edit item' : 'New item'} onSubmit={save}
            style={{ width: 'min(680px, 100%)', maxHeight: '90vh', overflow: 'auto', background: 'var(--cf-surface)', borderRadius: 8, boxShadow: 'var(--cf-shadow-lg)', padding: 20 }} data-testid="billing-item-dialog">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <h3 style={{ margin: 0 }}>{isEdit ? 'Edit product or service' : 'New product or service'}</h3>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onClose} title="Close"><X size={17} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: 12 }}>
          <Field label="Code"><input className="input" value={form.code} onChange={(event) => set('code', event.target.value)} required autoFocus /></Field>
          <Field label="Name"><input className="input" value={form.name} onChange={(event) => set('name', event.target.value)} required /></Field>
          <Field label="Type">
            <select className="input" value={form.item_type} onChange={(event) => set('item_type', event.target.value)}>
              {ITEM_TYPES.map((entry) => <option key={entry.value} value={entry.value}>{entry.label}</option>)}
            </select>
          </Field>
          <Field label="Description"><input className="input" value={form.description || ''} onChange={(event) => set('description', event.target.value)} /></Field>
          <Field label="Unit"><input className="input" value={form.default_unit} onChange={(event) => set('default_unit', event.target.value)} required /></Field>
          <Field label="Default unit price"><input className="input" type="number" step="0.0001" value={form.default_unit_price ?? ''} onChange={(event) => set('default_unit_price', event.target.value)} /></Field>
          <Field label="Revenue account" style={{ gridColumn: '1 / -1' }}>
            <select className="input" value={form.gl_revenue_account_code || ''} onChange={(event) => set('gl_revenue_account_code', event.target.value)}>
              <option value="">Tenant default</option>
              {accounts.map((account) => <option key={account.code} value={account.code}>{account.code} {account.name}</option>)}
            </select>
          </Field>
        </div>
        <div style={{ display: 'flex', gap: 22, marginTop: 16 }}>
          <label><input type="checkbox" checked={form.taxable} onChange={(event) => set('taxable', event.target.checked)} /> Taxable</label>
          <label><input type="checkbox" checked={form.active} onChange={(event) => set('active', event.target.checked)} /> Active</label>
        </div>
        {error && <p className="error">Error: {error.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy}><CheckCircle2 size={16} /> {busy ? 'Saving...' : 'Save item'}</button>
        </div>
      </form>
    </div>
  );
}

function Field({ label, children, style }) {
  return <label style={{ display: 'flex', flexDirection: 'column', gap: 4, ...style }}><span style={{ fontSize: 12, fontWeight: 600 }}>{label}</span>{children}</label>;
}
