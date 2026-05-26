import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
import SweepRunsFeed from './SweepRunsFeed';

/**
 * Treasury Sweep Rules admin (P1 — cash-allocation workflow).
 *
 * Operators encode recipes like "keep $20k in operating, sweep excess
 * to high-yield every Friday" or "anything above $50k in the AP
 * funding account auto-routes to operating on Monday".
 *
 * Definition layer only — execution (creating the Mercury transfer
 * payment instruction when the source balance > target_min) is a
 * follow-up fork.
 */
const blankForm = {
  id: null,
  name: '',
  enabled: true,
  source_account_id: '',
  destination_account_id: '',
  target_min_balance_cents: '',
  sweep_above_cents: '',
  frequency: 'weekly_fri',
  require_approval_policy_id: '',
  sort_order: 100,
  notes: '',
};

const formatCents = (c) => (c == null || c === '' ? '—' : `$${(Number(c) / 100).toLocaleString()}`);

export default function SweepRulesAdmin() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [form, setForm] = useState(blankForm);
  const [saving, setSaving] = useState(false);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/admin/treasury/sweep_rules.php');
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  };
  useEffect(() => { reload(); }, []);

  const editRow = (r) => {
    setForm({
      ...blankForm,
      ...r,
      target_min_balance_cents: r.target_min_balance_cents ?? '',
      sweep_above_cents:        r.sweep_above_cents        ?? '',
      require_approval_policy_id: r.require_approval_policy_id ?? '',
      notes: r.notes ?? '',
    });
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      const payload = {
        ...form,
        target_min_balance_cents: form.target_min_balance_cents === '' ? null : Math.round(Number(form.target_min_balance_cents) * 100),
        sweep_above_cents:        form.sweep_above_cents        === '' ? null : Math.round(Number(form.sweep_above_cents)        * 100),
        require_approval_policy_id: form.require_approval_policy_id === '' ? null : Number(form.require_approval_policy_id),
      };
      await api.post('/api/admin/treasury/sweep_rules.php', payload);
      setForm(blankForm);
      await reload();
    } catch (e) { setError(e.message || 'Save failed'); }
    finally { setSaving(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this sweep rule?')) return;
    try {
      await api.delete(`/api/admin/treasury/sweep_rules.php?id=${id}`);
      await reload();
    } catch (e) { setError(e.message || 'Delete failed'); }
  };

  const rows  = data?.rows  || [];
  const freqs = data?.frequencies || [];

  return (
    <section data-testid="sweep-rules-admin" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: '1rem' }}>
        <h2 style={{ margin: 0 }}>Cash-allocation / sweep rules</h2>
        <p style={{ color: '#64748b', fontSize: 13, marginTop: 4 }}>
          Keep a minimum in the source account, sweep excess to the destination on the configured cadence. Rule execution requires the Mercury balance API + scheduled worker (next fork).
        </p>
        {data?.migration_pending && (
          <div data-testid="sweep-rules-migration-banner" style={{
            marginTop: '0.5rem', padding: '0.5rem 0.75rem',
            background: '#fef3c7', border: '1px solid #fde68a',
            color: '#92400e', borderRadius: 6, fontSize: 13,
          }}>
            Migration 073 hasn&apos;t run yet. Open <code>/admin/healthcheck</code> and click &quot;Run pending migrations&quot;.
          </div>
        )}
      </header>

      {error && <p className="error" data-testid="sweep-rules-error">{error}</p>}
      {loading && <p data-testid="sweep-rules-loading">Loading…</p>}

      {!loading && (
        <>
          <table className="data-table" data-testid="sweep-rules-table" style={{ width: '100%', marginBottom: '1.25rem' }}>
            <thead>
              <tr style={{ fontSize: 11, color: '#64748b', textAlign: 'left' }}>
                <th style={{ padding: '6px 8px' }}>Rule</th>
                <th style={{ padding: '6px 8px' }}>Source</th>
                <th style={{ padding: '6px 8px' }}>Destination</th>
                <th style={{ padding: '6px 8px' }}>Keep min</th>
                <th style={{ padding: '6px 8px' }}>Sweep above</th>
                <th style={{ padding: '6px 8px' }}>Frequency</th>
                <th style={{ padding: '6px 8px' }}>Last run</th>
                <th style={{ padding: '6px 8px' }}></th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={8} data-testid="sweep-rules-empty" style={{ padding: 12, color: '#64748b' }}>
                  No sweep rules configured.
                </td></tr>
              )}
              {rows.map(r => (
                <tr key={r.id} data-testid={`sweep-rule-row-${r.id}`}>
                  <td style={{ padding: '8px' }}>
                    <strong>{r.name}</strong> {r.enabled ? '' : <span style={{ color: '#94a3b8', fontSize: 11 }}>(disabled)</span>}
                  </td>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace', fontSize: 11 }}>{r.source_account_id}</td>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace', fontSize: 11 }}>{r.destination_account_id}</td>
                  <td style={{ padding: '8px' }}>{formatCents(r.target_min_balance_cents)}</td>
                  <td style={{ padding: '8px' }}>{formatCents(r.sweep_above_cents)}</td>
                  <td style={{ padding: '8px' }}>{r.frequency}</td>
                  <td style={{ padding: '8px', fontSize: 11, color: '#64748b' }}>
                    {r.last_run_at ? (
                      <>{r.last_run_at}<br /><span style={{ color: '#94a3b8' }}>{r.last_outcome || '—'}{r.last_run_amount_cents ? ` (${formatCents(r.last_run_amount_cents)})` : ''}</span></>
                    ) : '—'}
                  </td>
                  <td style={{ padding: '8px', display: 'flex', gap: 4 }}>
                    <button className="btn btn--ghost" style={{ fontSize: 12 }} onClick={() => editRow(r)}
                            data-testid={`sweep-rule-edit-${r.id}`}>Edit</button>
                    <button className="btn btn--ghost" style={{ fontSize: 12 }} onClick={() => handleDelete(r.id)}
                            data-testid={`sweep-rule-delete-${r.id}`}>Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <form onSubmit={handleSave} data-testid="sweep-rule-form" style={{
            border: '1px solid #e2e8f0', borderRadius: 8, padding: '1rem',
            display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem',
          }}>
            <Field label="Rule name" wide>
              <input className="input" required value={form.name}
                     onChange={(e) => setForm(f => ({ ...f, name: e.target.value }))}
                     data-testid="sweep-rule-name-input"
                     placeholder='e.g. "Friday sweep to high-yield"' />
            </Field>
            <Field label="Source Mercury account ID">
              <input className="input" required value={form.source_account_id}
                     onChange={(e) => setForm(f => ({ ...f, source_account_id: e.target.value }))}
                     data-testid="sweep-rule-source-input" />
            </Field>
            <Field label="Destination Mercury account ID">
              <input className="input" required value={form.destination_account_id}
                     onChange={(e) => setForm(f => ({ ...f, destination_account_id: e.target.value }))}
                     data-testid="sweep-rule-destination-input" />
            </Field>
            <Field label="Keep at least (USD)">
              <input className="input" type="number" step="0.01" min="0"
                     value={form.target_min_balance_cents === '' ? '' : (Number(form.target_min_balance_cents) / 100)}
                     onChange={(e) => setForm(f => ({ ...f, target_min_balance_cents: e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100) }))}
                     data-testid="sweep-rule-keep-input"
                     placeholder="(no floor)" />
            </Field>
            <Field label="Only sweep when source > (USD)">
              <input className="input" type="number" step="0.01" min="0"
                     value={form.sweep_above_cents === '' ? '' : (Number(form.sweep_above_cents) / 100)}
                     onChange={(e) => setForm(f => ({ ...f, sweep_above_cents: e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100) }))}
                     data-testid="sweep-rule-above-input"
                     placeholder="(always sweep)" />
            </Field>
            <Field label="Frequency">
              <select className="input" value={form.frequency}
                      onChange={(e) => setForm(f => ({ ...f, frequency: e.target.value }))}
                      data-testid="sweep-rule-frequency-select">
                {freqs.map(f => <option key={f} value={f}>{f}</option>)}
              </select>
            </Field>
            <Field label="Approval policy ID (optional)">
              <input className="input" type="number" min="1"
                     value={form.require_approval_policy_id}
                     onChange={(e) => setForm(f => ({ ...f, require_approval_policy_id: e.target.value }))}
                     data-testid="sweep-rule-policy-input"
                     placeholder="(no policy)" />
            </Field>
            <Field label="Sort order">
              <input className="input" type="number"
                     value={form.sort_order}
                     onChange={(e) => setForm(f => ({ ...f, sort_order: Number(e.target.value) || 100 }))}
                     data-testid="sweep-rule-sort-input" />
            </Field>
            <Field label="Notes" wide>
              <input className="input" value={form.notes}
                     onChange={(e) => setForm(f => ({ ...f, notes: e.target.value }))}
                     data-testid="sweep-rule-notes-input" />
            </Field>
            <div style={{ gridColumn: '1 / -1', display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
              <label style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 6 }}>
                <input type="checkbox" checked={!!form.enabled}
                       onChange={(e) => setForm(f => ({ ...f, enabled: e.target.checked }))}
                       data-testid="sweep-rule-enabled-input" />
                Enabled
              </label>
              <div style={{ display: 'flex', gap: 8 }}>
                {form.id && (
                  <button type="button" className="btn btn--ghost" onClick={() => setForm(blankForm)}
                          data-testid="sweep-rule-form-reset">Cancel edit</button>
                )}
                <button type="submit" className="btn btn--primary"
                        disabled={saving || !form.name}
                        data-testid="sweep-rule-save">
                  {saving ? 'Saving…' : (form.id ? 'Update rule' : '+ Add sweep rule')}
                </button>
              </div>
            </div>
          </form>
        </>
      )}
      <SweepRunsFeed />
    </section>
  );
}

function Field({ label, children, wide }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569', gridColumn: wide ? '1 / -1' : undefined }}>
      {label}
      {children}
    </label>
  );
}
