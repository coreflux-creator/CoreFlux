import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Issue a tokenized client-approval email for a set of pending_review entries.
 * All entries must share (placement_id, period_id) — enforced by caller.
 */
export default function TokenIssueModal({ entries, onClose, onIssued }) {
  const [ttlDays, setTtlDays] = useState(7);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  if (!entries?.length) return null;
  const first = entries[0];
  const total = entries.reduce((s, e) => s + parseFloat(e.hours || 0), 0);

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post('/api/v1/time/approval-tokens?action=issue', {
        placement_id: first.placement_id,
        period_id: first.period_id,
        entry_ids: entries.map(e => e.id),
        ttl_days: Number(ttlDays) || 7,
      });
      onIssued?.(res);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      data-testid="time-token-issue-modal"
      style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.55)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: 16 }}
      onClick={(e) => { if (e.target === e.currentTarget && !busy) onClose?.(); }}
    >
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(520px, 100%)', boxShadow: '0 24px 60px rgba(0,0,0,0.25)' }}>
        <header style={{ padding: 'var(--cf-space-4) var(--cf-space-5)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
          <h3 style={{ margin: 0 }} data-testid="time-token-issue-title">Request client approval</h3>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-muted, #6b7280)', fontSize: 13 }}>
            Send a one-time magic link to the client approver to approve or reject
            these entries without needing a CoreFlux login.
          </p>
        </header>
        <div style={{ padding: 'var(--cf-space-5)' }}>
          <dl style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', rowGap: 8, columnGap: 12, margin: 0, fontSize: 14 }}>
            <dt style={{ color: 'var(--cf-text-muted, #6b7280)' }}>Placement</dt>
            <dd style={{ margin: 0 }} data-testid="time-token-issue-placement">{first.placement_title || `#${first.placement_id}`}</dd>
            <dt style={{ color: 'var(--cf-text-muted, #6b7280)' }}>End client</dt>
            <dd style={{ margin: 0 }}>{first.end_client_name || '—'}</dd>
            <dt style={{ color: 'var(--cf-text-muted, #6b7280)' }}>Entries</dt>
            <dd style={{ margin: 0 }} data-testid="time-token-issue-count">{entries.length} entries, {total.toFixed(2)}h total</dd>
          </dl>

          <label style={{ display: 'block', marginTop: 'var(--cf-space-4)' }}>
            <span style={{ fontSize: 13, color: 'var(--cf-text-muted, #6b7280)' }}>Expires in (days)</span>
            <input
              className="input"
              type="number" min={1} max={30} value={ttlDays}
              onChange={(e) => setTtlDays(e.target.value)}
              style={{ width: 100, marginTop: 4 }}
              data-testid="time-token-issue-ttl"
            />
          </label>

          <p style={{ fontSize: 12, color: 'var(--cf-text-muted, #6b7280)', marginTop: 'var(--cf-space-3)' }}>
            The placement must have <code>tokenized_email_approval_enabled</code>
            turned on and a valid <code>client_approver_email</code> set under
            the Approval tab. Email delivery status will be shown after submit.
          </p>

          {error && <p className="error" data-testid="time-token-issue-error" style={{ marginTop: 12 }}>Error: {error.message}</p>}
        </div>
        <footer style={{ padding: 'var(--cf-space-4) var(--cf-space-5)', borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'flex-end', gap: 12, background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: '0 0 12px 12px' }}>
          <button className="btn btn--ghost" onClick={() => onClose?.()} disabled={busy} data-testid="time-token-issue-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy} data-testid="time-token-issue-submit">
            {busy ? 'Sending…' : 'Send approval email'}
          </button>
        </footer>
      </div>
    </div>
  );
}
