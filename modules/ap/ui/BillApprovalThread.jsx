import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Conversation thread that shows up on a bill's detail page — comments
 * left by approvers + AP are stitched together with state changes.
 */
export default function BillApprovalThread({ billId }) {
  const { data, loading, reload } = useApi(`/modules/ap/api/bill_approvals.php?comments_for_bill=${billId}`);
  const { data: chain } = useApi(`/modules/ap/api/bill_approvals.php?bill_id=${billId}`);
  const comments = data?.rows || [];
  const steps = chain?.rows || [];
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const submitComment = async () => {
    if (!body.trim()) return;
    setBusy(true); setErr(null);
    try {
      await api.post('/modules/ap/api/bill_approvals.php?action=comment', { bill_id: Number(billId), body });
      setBody('');
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="ap-bill-approval-thread" style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 8, background: '#fafafa' }}>
      <h4 style={{ margin: '0 0 8px', fontSize: 13 }}>Approval thread</h4>
      {steps.length > 0 && (
        <div data-testid="ap-bill-approval-steps" style={{ marginBottom: 8 }}>
          {steps.map((s) => (
            <div key={s.id} style={{ fontSize: 12, padding: '4px 0', borderBottom: '1px dashed #e5e7eb' }}>
              <span className={`badge badge--${s.state}`}>{s.state}</span>
              {' '}step {s.step_no} · {s.approver_name || s.approver_email || `user #${s.approver_user_id}`}
              {s.decision_at ? <span className="muted"> · {new Date(s.decision_at).toLocaleString()}</span> : null}
              {s.decision_note ? <em style={{ display: 'block', marginLeft: 12 }}>{s.decision_note}</em> : null}
            </div>
          ))}
        </div>
      )}
      {loading && <p>Loading…</p>}
      {!loading && comments.length === 0 && <p className="muted" style={{ fontSize: 12, margin: '4px 0' }}>No comments yet.</p>}
      {comments.map((c) => (
        <div key={c.id} data-testid={`ap-bill-comment-${c.id}`} style={{ padding: '6px 0', fontSize: 12, borderBottom: '1px solid #eee' }}>
          <strong>{c.user_name || c.user_email || `user #${c.user_id}`}</strong>{' '}
          <span className="muted">· {new Date(c.created_at).toLocaleString()}</span>
          <div>{c.body}</div>
        </div>
      ))}
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        <input
          className="input"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="Leave a comment for AP / approvers…"
          data-testid="ap-bill-comment-input"
          style={{ flex: 1 }}
        />
        <button type="button" className="btn btn--primary" onClick={submitComment} disabled={busy || !body.trim()} data-testid="ap-bill-comment-submit">
          {busy ? 'Posting…' : 'Comment'}
        </button>
      </div>
      {err && <p className="error" style={{ marginTop: 6 }}>{err}</p>}
    </div>
  );
}
