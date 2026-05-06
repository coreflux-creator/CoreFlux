import React, { useState } from 'react';
import { api, useApi } from '../lib/api';
import { Inbox, Clock } from 'lucide-react';

/**
 * WorkflowInbox — generic approval inbox surfacing every pending
 * `workflow_instances` row routed to the current user (Sprint 4
 * WorkflowEngine + Sprint 6 mobile cousin).
 *
 *   GET  /api/workflow.php?path=inbox
 *   POST /api/workflow.php?action=act&id=N    body: { action, comment, via }
 *
 * Surfaces subject_type, label, payload body / amount / risk, SLA, and
 * one-click Approve / Reject / Comment buttons. Ships at /inbox so any
 * role with at least one assigned step can land here from the avatar
 * menu and clear their queue without hopping into per-module screens.
 */
export default function WorkflowInbox() {
  const { data, error, loading, reload } = useApi('/api/workflow.php?path=inbox');
  const items = data?.instances ?? [];
  const [busy, setBusy] = useState(null);
  const [actErr, setActErr] = useState(null);
  const [commenting, setCommenting] = useState(null);
  const [commentText, setCommentText] = useState('');

  const act = async (id, action, comment) => {
    setBusy(`${id}:${action}`); setActErr(null);
    try {
      await api.post(`/api/workflow.php?action=act&id=${id}`, { action, comment, via: 'app' });
      setCommenting(null); setCommentText('');
      await reload();
    } catch (e) {
      setActErr(e);
    } finally {
      setBusy(null);
    }
  };

  return (
    <section data-testid="workflow-inbox" style={{ padding: 'var(--cf-space-6)' }}>
      <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 24 }}>
        <Inbox size={24} color="#2563eb" />
        <div>
          <h1 style={{ margin: 0, fontSize: 24, fontWeight: 700 }}>Approval Inbox</h1>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 13 }}>
            Every pending workflow step routed to you across modules — approve, reject, or comment in one place.
          </p>
        </div>
      </header>

      {loading && <p data-testid="workflow-inbox-loading">Loading…</p>}
      {error   && <p className="error" data-testid="workflow-inbox-error">Error: {error.message}</p>}
      {actErr  && <p className="error" data-testid="workflow-inbox-action-error">Action failed: {actErr.message}</p>}

      {!loading && !error && items.length === 0 && (
        <div data-testid="workflow-inbox-empty"
             style={{ padding: 48, textAlign: 'center', background: '#f8fafc', border: '1px dashed #cbd5e1', borderRadius: 12 }}>
          <p style={{ color: '#64748b', margin: 0 }}>No pending approvals.</p>
        </div>
      )}

      <div style={{ display: 'grid', gap: 12 }}>
        {items.map(i => {
          const payload = i.payload || {};
          const overdue = i.sla_due_at && new Date(i.sla_due_at) < new Date();
          return (
            <article key={i.id}
                     data-testid={`workflow-inbox-row-${i.id}`}
                     style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginBottom: 8 }}>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.5 }}>
                    {i.subject_type} · #{i.subject_id} · step {i.current_step}
                  </div>
                  <div style={{ fontSize: 16, fontWeight: 600, color: '#0f172a', marginTop: 4 }}>{i.label}</div>
                </div>
                {i.sla_due_at && (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, color: overdue ? '#dc2626' : '#64748b' }}
                       data-testid={`workflow-inbox-sla-${i.id}`}>
                    <Clock size={14} />
                    {overdue ? 'Overdue' : 'Due'} {i.sla_due_at}
                  </div>
                )}
              </div>

              {payload.body && (
                <p style={{ margin: '8px 0', color: '#334155', fontSize: 14 }}>{payload.body}</p>
              )}

              <div style={{ display: 'flex', gap: 16, fontSize: 13, color: '#475569', marginTop: 8 }}>
                {payload.amount_label && <span><strong>Amount:</strong> {payload.amount_label}</span>}
                {payload.risk         && <span style={{ color: payload.risk === 'high' ? '#dc2626' : '#475569' }}>
                                          <strong>Risk:</strong> {payload.risk}
                                        </span>}
                {payload.policy_id    && <span><strong>Policy:</strong> #{payload.policy_id}</span>}
              </div>

              <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                <button className="btn btn--primary"
                        data-testid={`workflow-inbox-approve-${i.id}`}
                        disabled={busy?.startsWith(`${i.id}:`)}
                        onClick={() => act(i.id, 'approve')}>
                  {busy === `${i.id}:approve` ? 'Approving…' : 'Approve'}
                </button>
                <button className="btn btn--ghost"
                        style={{ color: '#dc2626', borderColor: '#fecaca' }}
                        data-testid={`workflow-inbox-reject-${i.id}`}
                        disabled={busy?.startsWith(`${i.id}:`)}
                        onClick={() => act(i.id, 'reject')}>
                  {busy === `${i.id}:reject` ? 'Rejecting…' : 'Reject'}
                </button>
                <button className="btn btn--ghost"
                        data-testid={`workflow-inbox-comment-${i.id}`}
                        disabled={busy?.startsWith(`${i.id}:`)}
                        onClick={() => { setCommenting(i.id === commenting ? null : i.id); setCommentText(''); }}>
                  Comment
                </button>
                {payload.deep_link && (
                  <a href={payload.deep_link}
                     data-testid={`workflow-inbox-open-${i.id}`}
                     className="btn btn--ghost"
                     style={{ marginLeft: 'auto', textDecoration: 'none' }}>
                    Open in module →
                  </a>
                )}
              </div>

              {commenting === i.id && (
                <div style={{ marginTop: 12 }}>
                  <textarea
                    className="input"
                    data-testid={`workflow-inbox-comment-input-${i.id}`}
                    placeholder="Add a note (recorded in workflow_step_actions)…"
                    value={commentText}
                    onChange={e => setCommentText(e.target.value)}
                    rows={3}
                    style={{ width: '100%' }}
                  />
                  <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                    <button className="btn btn--primary"
                            data-testid={`workflow-inbox-comment-submit-${i.id}`}
                            disabled={!commentText.trim() || busy?.startsWith(`${i.id}:`)}
                            onClick={() => act(i.id, 'comment', commentText.trim())}>
                      Post comment
                    </button>
                    <button className="btn btn--ghost" onClick={() => { setCommenting(null); setCommentText(''); }}>Cancel</button>
                  </div>
                </div>
              )}
            </article>
          );
        })}
      </div>
    </section>
  );
}
