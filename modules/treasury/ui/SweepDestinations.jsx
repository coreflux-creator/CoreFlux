import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * <SweepDestinations /> — UI for the high-yield / external bank
 * account a Treasury Sweep Rule deposits excess cash into. Wraps the
 * same flow the `scripts/sweep_destination_setup.php` CLI provides
 * (create recipient → optional Mercury counterparty push → optional
 * rule wiring) so tenants don't need shell access.
 *
 * RBAC: gated server-side by accounting.bank.manage — surfaced via
 * 403 from the API which we render as the standard error banner.
 */
const blankForm = {
  name: '',
  routing_number: '',
  account_number: '',
  account_type: 'checking',
  account_id: '',
  push_to_mercury: true,
  wire_rule_id: '',
};

const formatLast4 = (l4) => (l4 ? `••${l4}` : '—');

export default function SweepDestinations() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [form, setForm] = useState(blankForm);
  const [saving, setSaving] = useState(false);
  const [flash, setFlash] = useState(null);
  const [showForm, setShowForm] = useState(false);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/admin/treasury/sweep_destinations.php');
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  };
  useEffect(() => { reload(); }, []);

  const handleCreate = async (e) => {
    e.preventDefault();
    setSaving(true); setError(null); setFlash(null);
    try {
      const payload = {
        name:           form.name.trim(),
        routing_number: form.routing_number.replace(/\D+/g, ''),
        account_number: form.account_number.replace(/\s+/g, ''),
        account_type:   form.account_type,
        account_id:     form.account_id.trim(),
        push_to_mercury: !!form.push_to_mercury,
      };
      if (form.wire_rule_id) payload.wire_rule_id = Number(form.wire_rule_id);
      const res = await api.post('/api/admin/treasury/sweep_destinations.php', payload);

      let msg = `Destination "${res.row?.name}" created.`;
      if (form.push_to_mercury) {
        const pushOK = res.push && res.push.ok !== false && !res.push.error;
        msg += pushOK
          ? ' Pushed to Mercury as counterparty.'
          : ` Mercury push deferred: ${res.push?.error || 'no active connection'}.`;
      }
      if (res.wired_rule_id) msg += ` Wired to rule #${res.wired_rule_id}.`;
      setFlash({ kind: 'success', msg });
      setForm(blankForm); setShowForm(false);
      await reload();
    } catch (e) {
      setError(e.message || 'Create failed');
    } finally { setSaving(false); }
  };

  const handleDelete = async (id, name) => {
    if (!window.confirm(`Revoke sweep destination "${name}"? Any rules wired to it will be unwired first.`)) return;
    setError(null); setFlash(null);
    try {
      await api.delete(`/api/admin/treasury/sweep_destinations.php?id=${id}`);
      setFlash({ kind: 'success', msg: `Destination "${name}" revoked.` });
      await reload();
    } catch (e) { setError(e.message || 'Delete failed'); }
  };

  const rows  = data?.rows  || [];
  const rules = data?.rules || [];
  const availableRules = rules.filter(r => !r.destination_recipient_id);

  return (
    <section data-testid="sweep-destinations" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0 }}>Sweep destinations</h2>
          <p style={{ color: '#64748b', fontSize: 13, marginTop: 4, maxWidth: 640 }}>
            External bank accounts the Treasury Sweep engine deposits excess cash into. Each
            destination wraps a Mercury counterparty record plus the underlying ACH details.
            A destination must be wired to a sweep rule before the worker will use it.
          </p>
        </div>
        <button
          type="button" className="btn btn--primary"
          onClick={() => { setShowForm(s => !s); setFlash(null); setError(null); }}
          data-testid="sweep-destinations-new-btn"
        >
          {showForm ? 'Cancel' : '+ New destination'}
        </button>
      </header>

      {flash && (
        <div
          data-testid="sweep-destinations-flash"
          style={{
            marginBottom: 12, padding: '8px 12px',
            background: flash.kind === 'success' ? '#dcfce7' : '#fee2e2',
            color: flash.kind === 'success' ? '#15803d' : '#991b1b',
            border: '1px solid ' + (flash.kind === 'success' ? '#86efac' : '#fca5a5'),
            borderRadius: 6, fontSize: 13,
          }}
        >{flash.msg}</div>
      )}
      {error && (
        <div data-testid="sweep-destinations-error" className="error" style={{ marginBottom: 12 }}>
          {error}
        </div>
      )}

      {showForm && (
        <form
          onSubmit={handleCreate}
          data-testid="sweep-destinations-form"
          style={{
            background: '#f8fafc', border: '1px solid #e2e8f0',
            borderRadius: 8, padding: 16, marginBottom: 16,
            display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12,
          }}
        >
          <label style={fieldLabel}>
            Destination name
            <input
              type="text" className="input" required maxLength={80}
              value={form.name}
              onChange={(e) => setForm(s => ({ ...s, name: e.target.value }))}
              data-testid="sweep-destinations-form-name"
            />
          </label>
          <label style={fieldLabel}>
            Mercury source account_id (optional reference)
            <input
              type="text" className="input"
              placeholder="acct_…"
              value={form.account_id}
              onChange={(e) => setForm(s => ({ ...s, account_id: e.target.value }))}
              data-testid="sweep-destinations-form-account-id"
            />
          </label>
          <label style={fieldLabel}>
            Routing number (9 digits)
            <input
              type="text" className="input" required pattern="\d{9}" maxLength={9}
              value={form.routing_number}
              onChange={(e) => setForm(s => ({ ...s, routing_number: e.target.value }))}
              data-testid="sweep-destinations-form-routing"
            />
          </label>
          <label style={fieldLabel}>
            Account number
            <input
              type="text" className="input" required minLength={4} maxLength={17}
              value={form.account_number}
              onChange={(e) => setForm(s => ({ ...s, account_number: e.target.value }))}
              data-testid="sweep-destinations-form-account"
            />
          </label>
          <label style={fieldLabel}>
            Account type
            <select
              className="input" value={form.account_type}
              onChange={(e) => setForm(s => ({ ...s, account_type: e.target.value }))}
              data-testid="sweep-destinations-form-type"
            >
              <option value="checking">checking</option>
              <option value="savings">savings</option>
            </select>
          </label>
          <label style={fieldLabel}>
            Wire to rule (optional)
            <select
              className="input" value={form.wire_rule_id}
              onChange={(e) => setForm(s => ({ ...s, wire_rule_id: e.target.value }))}
              data-testid="sweep-destinations-form-wire"
            >
              <option value="">— don't wire now —</option>
              {availableRules.map(r => (
                <option key={r.id} value={r.id}>
                  #{r.id} {r.name} (source {r.source_account_id || '—'})
                </option>
              ))}
            </select>
          </label>
          <label style={{ ...fieldLabel, gridColumn: '1 / -1', display: 'flex', alignItems: 'center', gap: 6 }}>
            <input
              type="checkbox"
              checked={form.push_to_mercury}
              onChange={(e) => setForm(s => ({ ...s, push_to_mercury: e.target.checked }))}
              data-testid="sweep-destinations-form-push"
            />
            <span>Push to Mercury as a counterparty now (recommended — required before live sweep can fire)</span>
          </label>
          <div style={{ gridColumn: '1 / -1', display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button type="button" className="btn" disabled={saving} onClick={() => setShowForm(false)}>Cancel</button>
            <button type="submit" className="btn btn--primary" disabled={saving} data-testid="sweep-destinations-form-submit">
              {saving ? 'Saving…' : 'Create destination'}
            </button>
          </div>
        </form>
      )}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p data-testid="sweep-destinations-empty" style={{ fontSize: 13, color: '#64748b' }}>
          No sweep destinations yet. Click "+ New destination" to add the high-yield account a sweep rule will deposit excess cash into.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="sweep-destinations-table" style={{ width: '100%', fontSize: 13 }}>
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Last 4</th>
              <th>Type</th>
              <th>Status</th>
              <th>Mercury counterparty</th>
              <th>Wired to</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} data-testid={`sweep-destinations-row-${r.id}`}>
                <td><code>D-{r.id}</code></td>
                <td>{r.name}</td>
                <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>{formatLast4(r.bank_last4)}</td>
                <td>{r.account_type || '—'}</td>
                <td>
                  <StatusPill value={r.status} />
                </td>
                <td>
                  {r.mercury_id ? (
                    <code data-testid={`sweep-destinations-counterparty-${r.id}`} style={{ fontSize: 11 }}>
                      {r.mercury_id}
                    </code>
                  ) : (
                    <span data-testid={`sweep-destinations-counterparty-${r.id}-missing`}
                          style={{ fontSize: 11, color: '#92400e' }}>
                      not pushed
                    </span>
                  )}
                </td>
                <td>
                  {(r.wired_rule_ids || []).length === 0 ? (
                    <span data-testid={`sweep-destinations-wiring-${r.id}-empty`}
                          style={{ fontSize: 11, color: '#92400e' }}>
                      not wired
                    </span>
                  ) : (
                    <span data-testid={`sweep-destinations-wiring-${r.id}`}
                          style={{ fontSize: 11 }}>
                      rules: {r.wired_rule_ids.join(', ')}
                    </span>
                  )}
                </td>
                <td style={{ fontSize: 11, color: '#64748b' }}>{r.created_at}</td>
                <td>
                  <button
                    type="button" className="btn btn--ghost"
                    onClick={() => handleDelete(r.id, r.name)}
                    data-testid={`sweep-destinations-delete-${r.id}`}
                  >
                    Revoke
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function StatusPill({ value }) {
  const tone =
    value === 'active'  ? { bg: '#d1fae5', fg: '#065f46' } :
    value === 'revoked' ? { bg: '#fee2e2', fg: '#991b1b' } :
                          { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
      background: tone.bg, color: tone.fg, padding: '2px 8px',
      borderRadius: 4, fontSize: 11, fontWeight: 500,
    }}>{value || '—'}</span>
  );
}

const fieldLabel = {
  display: 'flex', flexDirection: 'column', gap: 4,
  fontSize: 12, color: 'var(--cf-text-secondary, #475569)',
};
