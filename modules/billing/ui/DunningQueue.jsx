import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const STAGE_COLOR = { 1: '#0f172a', 2: '#a16207', 3: '#dc2626' };

/**
 * Dunning queue + policy editor.
 * Single page so AR ops can flip from "what needs nudging today" to
 * "what's our policy" without losing context.
 */
export default function DunningQueue() {
  const { data, loading, error, reload } = useApi('/api/v1/billing/dunning?action=queue');
  const [busyId, setBusyId] = useState(null);
  const [showPolicy, setShowPolicy] = useState(false);
  const [showAi, setShowAi] = useState(null);  // client_name when open

  const send = async (id) => {
    if (!confirm('Send the next-stage dunning email now?')) return;
    setBusyId(id);
    try {
      await api.post(`/api/v1/billing/dunning?action=send_now&id=${id}`, {});
      reload();
    } catch (e) { alert(`Send failed: ${e.message}`); }
    finally { setBusyId(null); }
  };
  const pause = async (id) => {
    const until = prompt('Pause dunning until (YYYY-MM-DD)?', new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10));
    if (!until) return;
    setBusyId(id);
    try {
      await api.post(`/api/v1/billing/dunning?action=pause&id=${id}`, { until });
      reload();
    } catch (e) { alert(`Pause failed: ${e.message}`); }
    finally { setBusyId(null); }
  };
  const resume = async (id) => {
    setBusyId(id);
    try {
      await api.post(`/api/v1/billing/dunning?action=resume&id=${id}`, {});
      reload();
    } catch (e) { alert(`Resume failed: ${e.message}`); }
    finally { setBusyId(null); }
  };

  const rows = data?.rows || [];
  const policy = data?.policy;

  return (
    <section data-testid="billing-dunning-queue">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 16 }}>
        <div>
          <h3 style={{ margin: 0 }}>Dunning queue</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Overdue invoices ready for the next reminder stage. The daily cron sends automatically; use Send Now to push manually.
          </p>
        </div>
        <button className="btn btn--ghost" onClick={() => setShowPolicy(true)} data-testid="billing-dunning-policy-open">Edit policy</button>
      </header>

      {policy && (
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16, fontSize: 12, color: 'var(--cf-text-secondary)' }} data-testid="billing-dunning-policy-summary">
          <span><strong>{policy.is_enabled ? 'Enabled' : 'Disabled'}</strong></span>
          <span>· {policy.schedule?.length || 0} stages</span>
          <span>· {policy.cadence_days}d cadence</span>
          <span>· max {policy.max_attempts} attempts</span>
          <span>· escalate after {policy.escalate_to_client_contact_after_attempts}</span>
          {policy.skip_weekends ? <span>· skips weekends</span> : null}
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-dunning-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-dunning-table">
        <thead>
          <tr><th>Invoice</th><th>Client</th><th style={{ textAlign: 'right' }}>Due amt</th><th>Days late</th><th>Stage</th><th>Recipient</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && (
            <tr><td colSpan={8} style={{ textAlign: 'center', padding: 24, color: 'var(--cf-text-secondary)' }} data-testid="billing-dunning-empty">No overdue invoices. 🎉</td></tr>
          )}
          {rows.map(r => {
            const nextStage = r.next_stage;
            const blocked   = r.block_reason;
            return (
              <tr key={r.invoice_id} data-testid={`billing-dunning-row-${r.invoice_id}`} style={{ background: r.days_overdue > 30 ? '#fef2f2' : undefined }}>
                <td><strong>{r.invoice_number}</strong></td>
                <td>
                  {r.client_name}
                  <button onClick={() => setShowAi(r.client_name)} title="AI suggestion" data-testid={`billing-dunning-ai-${r.invoice_id}`} style={{ background: 'none', border: 0, color: '#7c3aed', cursor: 'pointer', fontSize: 11, marginLeft: 4 }}>✨</button>
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>${Number(r.amount_due).toFixed(2)}</td>
                <td>{r.days_overdue}</td>
                <td>
                  <span style={{ display: 'inline-block', padding: '2px 8px', borderRadius: 10, background: '#e2e8f0', color: STAGE_COLOR[r.current_stage] || '#64748b', fontSize: 11, fontWeight: 600 }}>
                    {r.current_stage > 0 ? `Stage ${r.current_stage}` : 'Not yet sent'}
                  </span>
                  {nextStage && <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 2 }}>Next: stage {nextStage.stage_no} ({nextStage.template_key})</div>}
                </td>
                <td style={{ fontSize: 12 }}>
                  {r.recipients?.to || <em style={{ color: '#a16207' }}>no contact</em>}
                  {r.recipients?.cc?.length > 0 && (
                    <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>cc: {r.recipients.cc.join(', ')}</div>
                  )}
                </td>
                <td style={{ fontSize: 12 }}>
                  {r.paused_until ? <span style={{ color: '#a16207' }}>Paused until {r.paused_until}</span>
                    : blocked === 'do_not_contact' ? <span style={{ color: '#64748b' }}>Do-not-contact</span>
                    : blocked === 'no_contact' ? <span style={{ color: '#a16207' }}>No recipient</span>
                    : blocked === 'cadence' ? <span style={{ color: '#64748b' }}>Within cadence</span>
                    : nextStage ? <span style={{ color: '#16a34a' }}>Ready</span>
                    : <span style={{ color: '#64748b' }}>Up to date</span>}
                </td>
                <td>
                  <div style={{ display: 'flex', gap: 4 }}>
                    {nextStage && !blocked && (
                      <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => send(r.invoice_id)} disabled={busyId === r.invoice_id} data-testid={`billing-dunning-send-${r.invoice_id}`}>Send now</button>
                    )}
                    {!r.paused_until && (
                      <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => pause(r.invoice_id)} disabled={busyId === r.invoice_id} data-testid={`billing-dunning-pause-${r.invoice_id}`}>Pause</button>
                    )}
                    {r.paused_until && (
                      <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => resume(r.invoice_id)} disabled={busyId === r.invoice_id} data-testid={`billing-dunning-resume-${r.invoice_id}`}>Resume</button>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {showPolicy && policy && (
        <PolicyEditor policy={policy} onClose={() => setShowPolicy(false)} onSaved={() => { setShowPolicy(false); reload(); }} />
      )}
      {showAi && (
        <AiSuggestionModal client={showAi} onClose={() => setShowAi(null)} />
      )}
    </section>
  );
}

function PolicyEditor({ policy, onClose, onSaved }) {
  const [form, setForm] = useState(() => ({
    is_enabled:   policy.is_enabled,
    schedule:     policy.schedule || [],
    max_attempts: policy.max_attempts,
    cadence_days: policy.cadence_days,
    skip_weekends: policy.skip_weekends,
    escalate_to_client_contact_after_attempts: policy.escalate_to_client_contact_after_attempts,
    do_not_contact: (policy.do_not_contact || []).join(', '),
  }));
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      await api.post('/api/v1/billing/dunning?action=policy', {
        ...form,
        do_not_contact: form.do_not_contact.split(',').map(s => s.trim()).filter(Boolean),
      });
      onSaved();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  const updateStage = (i, key, val) => {
    const sched = [...form.schedule];
    sched[i] = { ...sched[i], [key]: val };
    setForm({ ...form, schedule: sched });
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} data-testid="billing-dunning-policy-modal" onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(640px, 100%)', padding: 24, maxHeight: '90vh', overflow: 'auto' }}>
        <h3 style={{ margin: '0 0 16px' }}>Dunning policy</h3>

        <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <input type="checkbox" checked={!!form.is_enabled} onChange={(e) => setForm({ ...form, is_enabled: e.target.checked ? 1 : 0 })} data-testid="billing-dunning-policy-enabled" />
          Enable dunning for this tenant
        </label>

        <h4 style={{ margin: '16px 0 8px', fontSize: 14 }}>Escalation stages</h4>
        {form.schedule.map((s, i) => (
          <div key={i} style={{ display: 'grid', gridTemplateColumns: '90px 1fr 90px', gap: 8, marginBottom: 6, alignItems: 'center' }} data-testid={`billing-dunning-policy-stage-${i}`}>
            <input className="input" type="number" value={s.days_overdue} onChange={(e) => updateStage(i, 'days_overdue', Number(e.target.value))} placeholder="Days" />
            <select className="input" value={s.template_key} onChange={(e) => updateStage(i, 'template_key', e.target.value)}>
              <option value="soft">Soft reminder</option>
              <option value="firm">Firm follow-up</option>
              <option value="final">Final notice</option>
            </select>
            <label style={{ fontSize: 11, display: 'flex', gap: 4, alignItems: 'center' }}>
              <input type="checkbox" checked={!!s.cc_client_contact} onChange={(e) => updateStage(i, 'cc_client_contact', e.target.checked)} /> CC escalation
            </label>
          </div>
        ))}

        <h4 style={{ margin: '16px 0 8px', fontSize: 14 }}>Cadence + limits</h4>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          <label style={{ fontSize: 12 }}>Days between sends
            <input className="input" type="number" value={form.cadence_days} onChange={(e) => setForm({ ...form, cadence_days: Number(e.target.value) })} data-testid="billing-dunning-policy-cadence" />
          </label>
          <label style={{ fontSize: 12 }}>Max attempts per invoice
            <input className="input" type="number" value={form.max_attempts} onChange={(e) => setForm({ ...form, max_attempts: Number(e.target.value) })} data-testid="billing-dunning-policy-max" />
          </label>
          <label style={{ fontSize: 12 }}>Add escalation CC after N attempts
            <input className="input" type="number" value={form.escalate_to_client_contact_after_attempts} onChange={(e) => setForm({ ...form, escalate_to_client_contact_after_attempts: Number(e.target.value) })} data-testid="billing-dunning-policy-escalate" />
          </label>
          <label style={{ fontSize: 12, alignSelf: 'end' }}>
            <input type="checkbox" checked={!!form.skip_weekends} onChange={(e) => setForm({ ...form, skip_weekends: e.target.checked ? 1 : 0 })} /> Skip weekends
          </label>
        </div>
        <label style={{ display: 'block', marginTop: 12, fontSize: 12 }}>Do-not-contact clients (comma-separated)
          <textarea className="input" rows={2} value={form.do_not_contact} onChange={(e) => setForm({ ...form, do_not_contact: e.target.value })} data-testid="billing-dunning-policy-dnc" />
        </label>

        {err && <p className="error" style={{ marginTop: 12 }}>Error: {err.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn btn--primary" onClick={save} disabled={busy} data-testid="billing-dunning-policy-save">{busy ? 'Saving…' : 'Save policy'}</button>
        </div>
      </div>
    </div>
  );
}

function AiSuggestionModal({ client, onClose }) {
  const { data, loading, error } = useApi(`/api/v1/billing/dunning?action=ai_suggest&client=${encodeURIComponent(client)}`);
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} data-testid="billing-dunning-ai-modal" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(480px, 100%)', padding: 24 }}>
        <h3 style={{ margin: '0 0 8px' }}>✨ Escalation suggestion — {client}</h3>
        {loading && <p>Analyzing payment history…</p>}
        {error && <p className="error">{error.message}</p>}
        {data && !data.suggestion && <p style={{ color: 'var(--cf-text-secondary)' }}>No actionable suggestion right now — this client's payment behavior is within normal range or we don't have enough history yet.</p>}
        {data?.suggestion && (
          <div data-testid="billing-dunning-ai-suggestion">
            <p style={{ background: '#ede9fe', padding: 12, borderRadius: 6, color: '#5b21b6', fontWeight: 600 }}>{data.suggestion.suggestion}</p>
            <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>{data.suggestion.rationale}</p>
          </div>
        )}
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose}>Close</button>
        </div>
      </div>
    </div>
  );
}
