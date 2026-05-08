import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Tax mappings — map each postable expense / revenue account onto a
 * line of a standard tax form (Schedule C, 1120-S, etc.). Powers
 * tax-time exports.
 *
 * Workflow:
 *   1. Pick a tax form from the dropdown
 *   2. For each unmapped account, set a line + (optional) label, click Save
 *   3. Click Edit on an existing row to update or remove
 */
export default function TaxMappings() {
  const [form, setForm] = useState('');
  const url = `/api/tax_mappings.php${form ? `?tax_form_code=${encodeURIComponent(form)}` : ''}`;
  const { data, error, loading, reload } = useApi(url);

  const [draft, setDraft] = useState({});       // accountId → { line, label, notes }
  const [busy, setBusy]   = useState({});
  const [errMsg, setErr]  = useState(null);

  const setField = (id, k, v) => setDraft(d => ({ ...d, [id]: { ...(d[id] || {}), [k]: v } }));

  const saveMapping = async (accountId) => {
    const d = draft[accountId] || {};
    if (!d.line || !d.line.trim()) { setErr('Line is required.'); return; }
    setBusy(b => ({ ...b, [accountId]: true })); setErr(null);
    try {
      await api.post('/api/tax_mappings.php', {
        account_id:     accountId,
        tax_form_code:  form,
        tax_form_line:  d.line.trim(),
        tax_form_label: (d.label || '').trim() || null,
        notes:          (d.notes || '').trim() || null,
      });
      setDraft(s => ({ ...s, [accountId]: undefined }));
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, [accountId]: false })); }
  };

  const removeMapping = async (id) => {
    setBusy(b => ({ ...b, [`del_${id}`]: true })); setErr(null);
    try {
      await api.delete(`/api/tax_mappings.php?id=${id}`);
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, [`del_${id}`]: false })); }
  };

  const forms     = data?.available_forms || [];
  const mappings  = data?.mappings || [];
  const unmapped  = data?.unmapped_accounts || [];

  return (
    <section data-testid="accounting-tax-mappings-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Tax mappings</h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Map each expense / revenue account to a line of a standard tax form. Used at tax time to export totals to Schedule C / 1120 / 1065.
          </p>
        </div>
        <button data-testid="accounting-tax-mappings-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>Refresh</button>
      </header>

      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <label style={lbl}>
          Tax form
          <select className="input"
                  data-testid="accounting-tax-mappings-form"
                  value={form}
                  onChange={e => setForm(e.target.value)}>
            <option value="">— pick a form —</option>
            {forms.map(f => <option key={f.code} value={f.code}>{f.label}</option>)}
          </select>
        </label>
        {data?.tax_form_code && (
          <span data-testid="accounting-tax-mappings-counts" style={{ fontSize: 12, color: '#64748b' }}>
            {data.mapped_count} mapped · {data.unmapped_count} unmapped
          </span>
        )}
      </div>

      {loading && <p data-testid="accounting-tax-mappings-loading">Loading…</p>}
      {error   && <p data-testid="accounting-tax-mappings-error" className="error">Error: {error.message}</p>}
      {errMsg  && <p data-testid="accounting-tax-mappings-action-error" className="error">{errMsg}</p>}

      {!form && (
        <div data-testid="accounting-tax-mappings-empty-state" style={emptyHero}>
          Pick a tax form to start mapping accounts.
        </div>
      )}

      {form && (
        <>
          <h3 style={{ margin: '20px 0 8px', fontSize: 14, color: '#0f172a' }}>Mapped accounts ({mappings.length})</h3>
          <table className="data-table" style={{ width: '100%' }} data-testid="accounting-tax-mappings-table-mapped">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Type</th><th>Form line</th><th>Label</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
              {mappings.length === 0 && (
                <tr><td colSpan={7} className="empty" data-testid="accounting-tax-mappings-mapped-empty">
                  No mappings yet for this form.
                </td></tr>
              )}
              {mappings.map(m => (
                <tr key={m.id} data-testid={`accounting-tax-mappings-mapped-row-${m.account_id}`}>
                  <td><code>{m.code}</code></td>
                  <td>{m.name}</td>
                  <td style={{ fontSize: 11, color: '#64748b' }}>{m.account_type}</td>
                  <td><code>{m.tax_form_line}</code></td>
                  <td style={{ fontSize: 12 }}>{m.tax_form_label || ''}</td>
                  <td style={{ fontSize: 12, color: '#64748b' }}>{m.notes || ''}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button data-testid={`accounting-tax-mappings-delete-${m.id}`}
                            className="btn btn--ghost"
                            onClick={() => removeMapping(m.id)}
                            disabled={busy[`del_${m.id}`]}
                            style={{ fontSize: 11, color: '#dc2626' }}>
                      Remove
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <h3 style={{ margin: '24px 0 8px', fontSize: 14, color: '#0f172a' }}>Unmapped accounts ({unmapped.length})</h3>
          <table className="data-table" style={{ width: '100%' }} data-testid="accounting-tax-mappings-table-unmapped">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Type</th><th>Line *</th><th>Label</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
              {unmapped.length === 0 && (
                <tr><td colSpan={7} className="empty" data-testid="accounting-tax-mappings-unmapped-empty">
                  All revenue + expense accounts are mapped. Nice.
                </td></tr>
              )}
              {unmapped.map(a => {
                const d = draft[a.id] || {};
                return (
                  <tr key={a.id} data-testid={`accounting-tax-mappings-unmapped-row-${a.id}`}>
                    <td><code>{a.code}</code></td>
                    <td>{a.name}</td>
                    <td style={{ fontSize: 11, color: '#64748b' }}>{a.account_type}</td>
                    <td>
                      <input className="input"
                             data-testid={`accounting-tax-mappings-line-${a.id}`}
                             value={d.line || ''}
                             onChange={e => setField(a.id, 'line', e.target.value)}
                             placeholder="e.g. 22"
                             style={{ width: 70 }} />
                    </td>
                    <td>
                      <input className="input"
                             data-testid={`accounting-tax-mappings-label-${a.id}`}
                             value={d.label || ''}
                             onChange={e => setField(a.id, 'label', e.target.value)}
                             placeholder="e.g. Supplies"
                             style={{ width: 150 }} />
                    </td>
                    <td>
                      <input className="input"
                             data-testid={`accounting-tax-mappings-notes-${a.id}`}
                             value={d.notes || ''}
                             onChange={e => setField(a.id, 'notes', e.target.value)}
                             style={{ width: 200, fontSize: 12 }} />
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <button data-testid={`accounting-tax-mappings-save-${a.id}`}
                              className="btn btn--primary"
                              disabled={busy[a.id] || !(d.line || '').trim()}
                              onClick={() => saveMapping(a.id)}
                              style={{ fontSize: 11 }}>
                        {busy[a.id] ? 'Saving…' : 'Save'}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };
const emptyHero = { padding: 36, textAlign: 'center', color: '#64748b', background: '#f8fafc', border: '1px dashed #e2e8f0', borderRadius: 10 };
