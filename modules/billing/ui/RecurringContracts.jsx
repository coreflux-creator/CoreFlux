import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const FREQUENCY_LABEL = { monthly: 'Monthly', quarterly: 'Quarterly', annual: 'Annual' };
const STATUS_COLOR = { active: '#16a34a', paused: '#a16207', ended: '#64748b' };

/**
 * Recurring invoice contracts.
 * Lists every contract, with next-3 generation dates inline, plus
 * create / edit / pause / resume / end / generate-now actions.
 */
export default function RecurringContracts() {
  const { data, loading, error, reload } = useApi('/api/v1/billing/recurring-contracts');
  const [editing, setEditing] = useState(null);   // contract row OR 'new'
  const [busyId, setBusyId] = useState(null);

  const rows = data?.rows || [];

  const act = async (id, action) => {
    setBusyId(id);
    try {
      await api.post(`/api/v1/billing/recurring-contracts?action=${action}&id=${id}`, {});
      reload();
    } catch (e) { alert(`Action failed: ${e.message}`); }
    finally { setBusyId(null); }
  };

  return (
    <section data-testid="billing-recurring-contracts">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <h3 style={{ margin: 0 }}>Recurring invoice contracts</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Flat-fee MRR contracts. The morning cron auto-generates drafts on each contract's <em>next due</em> date.
          </p>
        </div>
        <button className="btn btn--primary" onClick={() => setEditing('new')} data-testid="billing-contracts-new">+ New contract</button>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-contracts-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-contracts-table">
        <thead>
          <tr>
            <th>Contract</th><th>Client</th><th>Freq</th>
            <th style={{ textAlign: 'right' }}>Amount</th>
            <th>Next due</th><th>Next 3 dates</th>
            <th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && (
            <tr><td colSpan={8} style={{ textAlign: 'center', padding: 24, color: 'var(--cf-text-secondary)' }} data-testid="billing-contracts-empty">No recurring contracts yet. Click "New contract" to set one up.</td></tr>
          )}
          {rows.map(c => (
            <tr key={c.id} data-testid={`billing-contract-row-${c.id}`}>
              <td><strong>{c.contract_name}</strong>{c.description && <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{c.description}</div>}</td>
              <td>{c.client_name}</td>
              <td>{FREQUENCY_LABEL[c.frequency] || c.frequency}</td>
              <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>${Number(c.amount).toFixed(2)} {c.currency}</td>
              <td>{c.next_due_at || '—'}</td>
              <td>
                <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }} data-testid={`billing-contract-preview-${c.id}`}>
                  {(c.preview_next_3 || []).join(' · ') || '—'}
                </div>
              </td>
              <td>
                <span style={{
                  display: 'inline-block', padding: '2px 8px', borderRadius: 10,
                  background: (STATUS_COLOR[c.status] || '#64748b') + '22',
                  color: STATUS_COLOR[c.status] || '#64748b',
                  fontSize: 11, fontWeight: 600, textTransform: 'uppercase',
                }} data-testid={`billing-contract-status-${c.id}`}>
                  {c.status}
                </span>
              </td>
              <td>
                <div style={{ display: 'flex', gap: 4 }}>
                  {c.status === 'active' && (
                    <>
                      <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => act(c.id, 'generate_now')} disabled={busyId === c.id} data-testid={`billing-contract-generate-${c.id}`}>Generate now</button>
                      <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => act(c.id, 'pause')} disabled={busyId === c.id} data-testid={`billing-contract-pause-${c.id}`}>Pause</button>
                    </>
                  )}
                  {c.status === 'paused' && (
                    <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => act(c.id, 'resume')} disabled={busyId === c.id} data-testid={`billing-contract-resume-${c.id}`}>Resume</button>
                  )}
                  {c.status !== 'ended' && (
                    <button className="btn btn--ghost" style={{ fontSize: 11, color: '#dc2626' }} onClick={() => { if (confirm('End this contract permanently?')) act(c.id, 'end'); }} disabled={busyId === c.id} data-testid={`billing-contract-end-${c.id}`}>End</button>
                  )}
                  <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => setEditing(c)} data-testid={`billing-contract-edit-${c.id}`}>Edit</button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {editing && (
        <ContractModal
          contract={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </section>
  );
}

function ContractModal({ contract, onClose, onSaved }) {
  const isNew = !contract;
  const [form, setForm] = useState(() => ({
    client_name:      contract?.client_name      || '',
    contract_name:    contract?.contract_name    || '',
    description:      contract?.description      || '',
    frequency:        contract?.frequency        || 'monthly',
    day_of_period:    contract?.day_of_period    || 1,
    amount:           contract?.amount           || '',
    currency:         contract?.currency         || 'USD',
    start_date:       contract?.start_date       || new Date().toISOString().slice(0, 10),
    end_date:         contract?.end_date         || '',
    proration_policy: contract?.proration_policy || 'full',
    bill_to_email:    contract?.bill_to_email    || '',
    po_number:        contract?.po_number        || '',
    notes_internal:   contract?.notes_internal   || '',
  }));
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const submit = async () => {
    setBusy(true); setErr(null);
    try {
      const payload = { ...form, amount: Number(form.amount) || 0, day_of_period: Number(form.day_of_period) || 1 };
      if (!payload.end_date) delete payload.end_date;
      if (isNew) {
        await api.post('/api/v1/billing/recurring-contracts', payload);
      } else {
        // Only edit-allowed fields
        const { client_name, start_date, ...editable } = payload;
        await api.post(`/api/v1/billing/recurring-contracts?action=update&id=${contract.id}`, editable);
      }
      onSaved();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-contract-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(680px, 100%)', padding: 24 }}>
        <h3 style={{ margin: '0 0 16px' }}>{isNew ? 'New recurring contract' : `Edit: ${contract.contract_name}`}</h3>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Client name *">
            <input className="input" value={form.client_name} onChange={(e) => setForm({ ...form, client_name: e.target.value })} disabled={!isNew} data-testid="billing-contract-form-client" />
          </Field>
          <Field label="Contract name *">
            <input className="input" value={form.contract_name} onChange={(e) => setForm({ ...form, contract_name: e.target.value })} data-testid="billing-contract-form-name" />
          </Field>
          <Field label="Frequency">
            <select className="input" value={form.frequency} onChange={(e) => setForm({ ...form, frequency: e.target.value })} data-testid="billing-contract-form-frequency">
              <option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="annual">Annual</option>
            </select>
          </Field>
          <Field label="Day of period (1-31)">
            <input className="input" type="number" min="1" max="31" value={form.day_of_period} onChange={(e) => setForm({ ...form, day_of_period: e.target.value })} data-testid="billing-contract-form-dom" />
          </Field>
          <Field label="Amount *">
            <input className="input" type="number" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} data-testid="billing-contract-form-amount" />
          </Field>
          <Field label="Currency">
            <input className="input" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} maxLength={3} />
          </Field>
          <Field label="Start date *">
            <input className="input" type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} disabled={!isNew} data-testid="billing-contract-form-start" />
          </Field>
          <Field label="End date (optional)">
            <input className="input" type="date" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} />
          </Field>
          <Field label="Proration policy">
            <select className="input" value={form.proration_policy} onChange={(e) => setForm({ ...form, proration_policy: e.target.value })} data-testid="billing-contract-form-proration">
              <option value="full">Full (charge whole first period)</option>
              <option value="prorate">Prorate by days active</option>
              <option value="skip_first">Skip first period (start next cycle)</option>
            </select>
          </Field>
          <Field label="Bill-to email">
            <input className="input" type="email" value={form.bill_to_email} onChange={(e) => setForm({ ...form, bill_to_email: e.target.value })} placeholder="ar@client.com" />
          </Field>
          <Field label="PO number">
            <input className="input" value={form.po_number} onChange={(e) => setForm({ ...form, po_number: e.target.value })} />
          </Field>
        </div>
        <Field label="Internal notes">
          <textarea className="input" rows={2} value={form.notes_internal} onChange={(e) => setForm({ ...form, notes_internal: e.target.value })} />
        </Field>
        {err && <p className="error" style={{ marginTop: 12 }}>Error: {err.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !form.client_name || !form.contract_name || !form.amount} data-testid="billing-contract-form-save">{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{label}</span>
      {children}
    </label>
  );
}
