import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Pencil, Plus, Save, X } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const ENDPOINT = '/modules/accounting/api/entities.php';
const ENTITY_TYPES = [
  ['llc', 'LLC'], ['corporation', 'Corporation'], ['partnership', 'Partnership'],
  ['sole_prop', 'Sole proprietor'], ['nonprofit', 'Nonprofit'], ['other', 'Other'],
];
const EMPTY = {
  code: '', legal_name: '', entity_type: '',
  first_fiscal_year: String(new Date().getFullYear()),
};

export default function LegalEntities() {
  const { data, loading, error, reload } = useApi(ENDPOINT);
  const [creating, setCreating] = useState(false);
  const [createForm, setCreateForm] = useState(EMPTY);
  const [editId, setEditId] = useState(null);
  const [editForm, setEditForm] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState('');
  const [notice, setNotice] = useState('');
  const rows = data?.rows || [];

  const create = async (event) => {
    event.preventDefault();
    setBusy(true); setActionError(''); setNotice('');
    try {
      await api.post(ENDPOINT, {
        ...createForm,
        country: 'US', base_currency: 'USD', accounting_basis: 'accrual',
        fiscal_year_start_month: 1,
      });
      setCreating(false);
      setCreateForm(EMPTY);
      setNotice('Legal entity created.');
      reload();
    } catch (err) {
      setActionError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const save = async (event) => {
    event.preventDefault();
    setBusy(true); setActionError(''); setNotice('');
    try {
      const result = await api.patch(`${ENDPOINT}?id=${editId}`, editForm);
      setEditId(null);
      setNotice(result?.unchanged ? 'No changes to save.' : 'Legal entity updated.');
      reload();
    } catch (err) {
      setActionError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="entity-settings" data-testid="accounting-legal-entities">
      <header className="entity-settings__header">
        <div>
          <h2>Legal entities</h2>
          <p>Company profiles used by the books and source documents.</p>
        </div>
        <button type="button" className="btn btn--primary" onClick={() => {
          setCreating(value => !value); setEditId(null); setActionError(''); setNotice('');
        }} aria-expanded={creating} data-testid="accounting-entity-new">
          <Plus size={16} aria-hidden="true" /> New entity
        </button>
      </header>

      {creating && (
        <form className="entity-settings__form" onSubmit={create} data-testid="accounting-entity-create-form">
          <label>Code
            <input required maxLength={40} value={createForm.code}
              onChange={event => setCreateForm(value => ({ ...value, code: event.target.value.toUpperCase() }))}
              data-testid="accounting-entity-code" />
          </label>
          <label>Legal name
            <input required maxLength={255} value={createForm.legal_name}
              onChange={event => setCreateForm(value => ({ ...value, legal_name: event.target.value }))}
              data-testid="accounting-entity-name" />
          </label>
          <label>Entity type
            <select required value={createForm.entity_type}
              onChange={event => setCreateForm(value => ({ ...value, entity_type: event.target.value }))}>
              <option value="">Select type</option>
              {ENTITY_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          <label>First fiscal year
            <input type="number" min="1900" max="2199" required value={createForm.first_fiscal_year}
              onChange={event => setCreateForm(value => ({ ...value, first_fiscal_year: event.target.value }))}
              data-testid="accounting-entity-first-year" />
          </label>
          <div className="entity-settings__fixed">
            <span>Country <strong>US</strong></span>
            <span>Base currency <strong>USD</strong></span>
            <span>Basis <strong>Accrual</strong></span>
            <span>Fiscal year <strong>January start</strong></span>
          </div>
          <div className="entity-settings__form-actions">
            <button className="btn btn--primary" type="submit" disabled={busy}>
              <Save size={15} aria-hidden="true" /> {busy ? 'Saving' : 'Create entity'}
            </button>
            <button className="btn btn--ghost" type="button" onClick={() => setCreating(false)} disabled={busy}>
              <X size={15} aria-hidden="true" /> Cancel
            </button>
          </div>
        </form>
      )}

      {loading && <p role="status">Loading legal entities...</p>}
      {error && <p className="error" role="alert">{error.message}</p>}
      {actionError && <p className="error" role="alert" data-testid="accounting-entity-error">{actionError}</p>}
      {notice && <p className="entity-settings__notice" role="status">{notice}</p>}
      {!loading && !error && rows.length === 0 && <p>No legal entities yet.</p>}

      {rows.length > 0 && (
        <div className="entity-settings__table-wrap">
          <table className="data-table entity-settings__table">
            <thead><tr><th>Code</th><th>Legal name</th><th>Type</th><th>Country</th><th>Currency</th><th>Basis</th><th>Fiscal start</th><th>Status</th><th> </th></tr></thead>
            <tbody>{rows.map(entity => (
              <tr key={entity.id} data-testid={`accounting-entity-row-${entity.id}`}>
                <td><strong>{entity.code}</strong></td>
                <td>{editId === entity.id
                  ? <input form="entity-settings-edit" required maxLength={255} aria-label="Legal name"
                    value={editForm.legal_name} onChange={event => setEditForm(value => ({ ...value, legal_name: event.target.value }))} />
                  : entity.legal_name}</td>
                <td>{editId === entity.id
                  ? <select form="entity-settings-edit" aria-label="Entity type" value={editForm.entity_type}
                    onChange={event => setEditForm(value => ({ ...value, entity_type: event.target.value }))}>
                    {ENTITY_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                  </select>
                  : (ENTITY_TYPES.find(([value]) => value === entity.entity_type)?.[1] || entity.entity_type)}</td>
                <td>{entity.country}</td><td>{entity.base_currency}</td>
                <td>{entity.accounting_basis}</td>
                <td>{Number(entity.fiscal_year_start_month) === 1 ? 'January' : `Month ${entity.fiscal_year_start_month}`}</td>
                <td>{Number(entity.active) === 1 ? 'Active' : 'Inactive'}</td>
                <td>{editId === entity.id
                  ? <div className="entity-settings__row-actions">
                    <button form="entity-settings-edit" type="submit" className="btn btn--primary" disabled={busy} title="Save legal entity"><Save size={15} aria-hidden="true" /></button>
                    <button type="button" className="btn btn--ghost" disabled={busy} title="Cancel edit" onClick={() => setEditId(null)}><X size={15} aria-hidden="true" /></button>
                  </div>
                  : <button type="button" className="btn btn--ghost" title={`Edit ${entity.legal_name}`}
                    onClick={() => { setEditId(entity.id); setEditForm({ legal_name: entity.legal_name, entity_type: entity.entity_type }); setCreating(false); setActionError(''); }}>
                    <Pencil size={15} aria-hidden="true" />
                  </button>}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
      <form id="entity-settings-edit" onSubmit={save} />
      <nav className="entity-settings__links" aria-label="Related accounting setup">
        <Link to="/modules/accounting/accounts">Chart of accounts</Link>
        <Link to="/modules/accounting/opening-balances">Opening balances</Link>
        <Link to="/modules/accounting/periods">Accounting periods</Link>
      </nav>
    </section>
  );
}
