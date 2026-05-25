import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * Approval Policies admin (P1 — SoD threshold engine).
 *
 * Tenants encode rules that gate Mercury payment approvals:
 *   - amount band → required role
 *   - N-of-M co-approver chain (e.g. >$10k needs 2 distinct approvers)
 *   - cool-off window between approval and worker auto-advance
 *   - per-vendor / per-source-account scoping
 *
 * Resolution: most-specific match wins (recipient > account > broad),
 * ties broken by sort_order. See /app/core/approval_policy.php.
 *
 * RBAC: treasury.payment.approve (server-enforced).
 */
const blankForm = {
  id: null,
  name: '',
  integration: 'mercury',
  enabled: true,
  min_amount_cents: '',
  max_amount_cents: '',
  required_approver_role: '',
  min_approvers: 1,
  cool_off_minutes: 0,
  applies_to_recipient_id: '',
  applies_to_account_id: '',
  sort_order: 100,
  notes: '',
};

const formatCents = (c) => (c == null || c === '' ? '—' : `$${(Number(c) / 100).toLocaleString()}`);

export default function ApprovalPoliciesAdmin() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [form, setForm] = useState(blankForm);
  const [saving, setSaving] = useState(false);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/admin/treasury/approval_policies.php?integration=mercury');
      setData(r);
    } catch (e) {
      setError(e.message || 'Failed to load');
    } finally { setLoading(false); }
  };
  useEffect(() => { reload(); }, []);

  const editRow = (r) => {
    setForm({
      ...blankForm,
      ...r,
      min_amount_cents: r.min_amount_cents ?? '',
      max_amount_cents: r.max_amount_cents ?? '',
      applies_to_recipient_id: r.applies_to_recipient_id ?? '',
      applies_to_account_id:   r.applies_to_account_id ?? '',
      required_approver_role:  r.required_approver_role ?? '',
      notes: r.notes ?? '',
    });
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true); setError(null);
    try {
      // Convert dollar entries to cents — operator types $10,000 in
      // a friendlier ../$0.01 fixture they re-derive each time.
      const payload = {
        ...form,
        min_amount_cents: form.min_amount_cents === '' ? null : Math.round(Number(form.min_amount_cents) * 100),
        max_amount_cents: form.max_amount_cents === '' ? null : Math.round(Number(form.max_amount_cents) * 100),
        applies_to_recipient_id: form.applies_to_recipient_id === '' ? null : Number(form.applies_to_recipient_id),
        applies_to_account_id:   form.applies_to_account_id   === '' ? null : form.applies_to_account_id,
        required_approver_role:  form.required_approver_role  === '' ? null : form.required_approver_role,
      };
      await api.post('/api/admin/treasury/approval_policies.php', payload);
      setForm(blankForm);
      await reload();
    } catch (e) {
      setError(e.message || 'Save failed');
    } finally { setSaving(false); }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this policy? Payments approved AFTER this point will fall back to the default single-approver SoD rule.')) return;
    try {
      await api.delete(`/api/admin/treasury/approval_policies.php?id=${id}`);
      await reload();
    } catch (e) { setError(e.message || 'Delete failed'); }
  };

  const rows = data?.rows || [];
  const roles = data?.roles || [];

  return (
    <section data-testid="approval-policies-admin" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: '1rem' }}>
        <h2 style={{ margin: 0 }}>Approval policies</h2>
        <p style={{ color: '#64748b', fontSize: 13, marginTop: 4 }}>
          Rules that gate Mercury payment approvals. Most-specific match wins; rules without an amount band apply to every payment.
        </p>
        {data?.migration_pending && (
          <div data-testid="approval-policies-migration-banner" style={{
            marginTop: '0.5rem', padding: '0.5rem 0.75rem',
            background: '#fef3c7', border: '1px solid #fde68a',
            color: '#92400e', borderRadius: 6, fontSize: 13,
          }}>
            Migration 072 hasn&apos;t run yet. Open <code>/admin/healthcheck</code> and click &quot;Run pending migrations&quot;.
          </div>
        )}
      </header>

      {error && <p className="error" data-testid="approval-policies-error">{error}</p>}
      {loading && <p data-testid="approval-policies-loading">Loading…</p>}

      {!loading && (
        <>
          <table className="data-table" data-testid="approval-policies-table" style={{ width: '100%', marginBottom: '1.25rem' }}>
            <thead>
              <tr style={{ fontSize: 11, color: '#64748b', textAlign: 'left' }}>
                <th style={{ padding: '6px 8px' }}>Name</th>
                <th style={{ padding: '6px 8px' }}>Amount band</th>
                <th style={{ padding: '6px 8px' }}>Required role</th>
                <th style={{ padding: '6px 8px' }}>Approvers</th>
                <th style={{ padding: '6px 8px' }}>Cool-off</th>
                <th style={{ padding: '6px 8px' }}>Enabled</th>
                <th style={{ padding: '6px 8px' }}></th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={7} data-testid="approval-policies-empty" style={{ padding: 12, color: '#64748b' }}>
                  No policies configured — Mercury approvals use the default single-approver SoD rule (creator ≠ approver + <code>treasury.payment.approve</code> permission).
                </td></tr>
              )}
              {rows.map(r => (
                <tr key={r.id} data-testid={`approval-policy-row-${r.id}`}>
                  <td style={{ padding: '8px' }}><strong>{r.name}</strong></td>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace' }}>
                    {formatCents(r.min_amount_cents)} – {formatCents(r.max_amount_cents)}
                  </td>
                  <td style={{ padding: '8px' }}>{r.required_approver_role || '—'}</td>
                  <td style={{ padding: '8px' }}>{r.min_approvers}-of-N distinct</td>
                  <td style={{ padding: '8px' }}>{r.cool_off_minutes ? `${r.cool_off_minutes} min` : '—'}</td>
                  <td style={{ padding: '8px' }}>{r.enabled ? '✓' : '✗'}</td>
                  <td style={{ padding: '8px', display: 'flex', gap: 4 }}>
                    <button
                      className="btn btn--ghost"
                      style={{ fontSize: 12 }}
                      onClick={() => editRow(r)}
                      data-testid={`approval-policy-edit-${r.id}`}
                    >Edit</button>
                    <button
                      className="btn btn--ghost"
                      style={{ fontSize: 12 }}
                      onClick={() => handleDelete(r.id)}
                      data-testid={`approval-policy-delete-${r.id}`}
                    >Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <form onSubmit={handleSave} data-testid="approval-policy-form" style={{
            border: '1px solid #e2e8f0', borderRadius: 8, padding: '1rem',
            display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem',
          }}>
            <Field label="Name" testid="approval-policy-name">
              <input className="input" value={form.name} required
                     onChange={(e) => setForm(f => ({ ...f, name: e.target.value }))}
                     data-testid="approval-policy-name-input"
                     placeholder="e.g. CFO approval over $10k" />
            </Field>
            <Field label="Min amount (USD)" testid="approval-policy-min">
              <input className="input" type="number" step="0.01" min="0"
                     value={form.min_amount_cents === '' ? '' : (Number(form.min_amount_cents) / 100)}
                     onChange={(e) => setForm(f => ({ ...f, min_amount_cents: e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100) }))}
                     data-testid="approval-policy-min-input"
                     placeholder="(no minimum)" />
            </Field>
            <Field label="Max amount (USD)" testid="approval-policy-max">
              <input className="input" type="number" step="0.01" min="0"
                     value={form.max_amount_cents === '' ? '' : (Number(form.max_amount_cents) / 100)}
                     onChange={(e) => setForm(f => ({ ...f, max_amount_cents: e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100) }))}
                     data-testid="approval-policy-max-input"
                     placeholder="(no maximum)" />
            </Field>
            <Field label="Required approver role" testid="approval-policy-role">
              <select className="input"
                      value={form.required_approver_role || ''}
                      onChange={(e) => setForm(f => ({ ...f, required_approver_role: e.target.value }))}
                      data-testid="approval-policy-role-select">
                <option value="">— any approver —</option>
                {roles.map(r => <option key={r} value={r}>{r}</option>)}
              </select>
            </Field>
            <Field label="Distinct approvers required" testid="approval-policy-min-approvers">
              <input className="input" type="number" min="1" max="5"
                     value={form.min_approvers}
                     onChange={(e) => setForm(f => ({ ...f, min_approvers: Math.max(1, Math.min(5, Number(e.target.value) || 1)) }))}
                     data-testid="approval-policy-min-approvers-input" />
            </Field>
            <Field label="Cool-off (minutes)" testid="approval-policy-coolof">
              <input className="input" type="number" min="0" max="2880"
                     value={form.cool_off_minutes}
                     onChange={(e) => setForm(f => ({ ...f, cool_off_minutes: Math.max(0, Number(e.target.value) || 0) }))}
                     data-testid="approval-policy-coolof-input" />
            </Field>
            <Field label="Recipient ID (optional)" testid="approval-policy-recipient">
              <input className="input" type="number" min="1"
                     value={form.applies_to_recipient_id}
                     onChange={(e) => setForm(f => ({ ...f, applies_to_recipient_id: e.target.value }))}
                     data-testid="approval-policy-recipient-input"
                     placeholder="(any vendor)" />
            </Field>
            <Field label="Source account ID (optional)" testid="approval-policy-account">
              <input className="input"
                     value={form.applies_to_account_id}
                     onChange={(e) => setForm(f => ({ ...f, applies_to_account_id: e.target.value }))}
                     data-testid="approval-policy-account-input"
                     placeholder="(any account)" />
            </Field>
            <Field label="Sort order" testid="approval-policy-sort">
              <input className="input" type="number"
                     value={form.sort_order}
                     onChange={(e) => setForm(f => ({ ...f, sort_order: Number(e.target.value) || 100 }))}
                     data-testid="approval-policy-sort-input" />
            </Field>
            <Field label="Notes" testid="approval-policy-notes" wide>
              <input className="input"
                     value={form.notes}
                     onChange={(e) => setForm(f => ({ ...f, notes: e.target.value }))}
                     data-testid="approval-policy-notes-input" />
            </Field>
            <div style={{ gridColumn: '1 / -1', display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
              <label style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 6 }}>
                <input type="checkbox" checked={!!form.enabled}
                       onChange={(e) => setForm(f => ({ ...f, enabled: e.target.checked }))}
                       data-testid="approval-policy-enabled-input" />
                Enabled
              </label>
              <div style={{ display: 'flex', gap: 8 }}>
                {form.id && (
                  <button type="button" className="btn btn--ghost"
                          onClick={() => setForm(blankForm)}
                          data-testid="approval-policy-form-reset">Cancel edit</button>
                )}
                <button type="submit" className="btn btn--primary"
                        disabled={saving || !form.name}
                        data-testid="approval-policy-save">
                  {saving ? 'Saving…' : (form.id ? 'Update policy' : '+ Add policy')}
                </button>
              </div>
            </div>
          </form>
        </>
      )}
    </section>
  );
}

function Field({ label, children, wide, testid }) {
  return (
    <label data-testid={`${testid}-field`} style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569', gridColumn: wide ? '1 / -1' : undefined }}>
      {label}
      {children}
    </label>
  );
}
