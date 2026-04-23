import React, { useState } from 'react';
import { api } from '../lib/api';

/**
 * <AISuggestion /> — the ONLY way AI-generated text enters a CoreFlux screen.
 *
 * Renders a clearly-badged AI suggestion with a consistent review/edit/accept/reject
 * control set. No code path in any module should display LLM output without this
 * wrapper: it enforces the "human-review-gated" rule visually and operationally.
 *
 * Props:
 *   - envelope:       the response from `api.post('/modules/.../api/ai/*.php')`
 *                     i.e. { kind, content, model, interaction_id, ... }
 *   - featureKey:     module.feature identifier, used when committing a decision
 *   - subjectType?:   optional business object type (e.g. 'paystub')
 *   - subjectId?:     optional business object id
 *   - onAccepted?:    (finalContent: string, suggestionId: number) => void
 *   - onRejected?:    (suggestionId: number) => void
 *   - editable?:      boolean (default true)
 *   - commitUrl?:     defaults to /core/api/ai_suggestions.php (batch)
 *   - className?:     optional extra classes
 */
export default function AISuggestion({
  envelope,
  featureKey,
  subjectType = null,
  subjectId = null,
  onAccepted,
  onRejected,
  editable = true,
  commitUrl = '/core/api/ai_suggestions.php',
  className = '',
}) {
  const [content, setContent] = useState(envelope?.content ?? '');
  const [editing, setEditing] = useState(false);
  const [status, setStatus]   = useState('draft'); // draft | approving | approved | rejected | error
  const [error, setError]     = useState(null);
  const [suggestionId, setSuggestionId] = useState(null);

  if (!envelope || !envelope.content) return null;

  const disabled = status === 'approving' || status === 'approved' || status === 'rejected';

  const commit = async (action) => {
    setStatus(action === 'approve' ? 'approving' : 'rejecting');
    setError(null);
    try {
      const res = await api.post(commitUrl, {
        action,                                    // 'approve' | 'reject'
        interaction_id: envelope.interaction_id,
        feature_key: featureKey,
        subject_type: subjectType,
        subject_id: subjectId,
        draft_content: envelope.content,
        final_content: action === 'approve' ? content : null,
      });
      setSuggestionId(res?.id ?? null);
      setStatus(action === 'approve' ? 'approved' : 'rejected');
      if (action === 'approve' && onAccepted) onAccepted(content, res?.id ?? null);
      if (action === 'reject' && onRejected)  onRejected(res?.id ?? null);
    } catch (e) {
      setError(e.message || 'Failed to record review');
      setStatus('error');
    }
  };

  return (
    <section
      data-testid="ai-suggestion"
      className={`ai-suggestion ai-suggestion--${status} ${className}`}
    >
      <header className="ai-suggestion__header">
        <span className="ai-suggestion__badge" data-testid="ai-suggestion-badge">
          AI draft · human review required
        </span>
        <span className="ai-suggestion__meta">
          {envelope.model ? <code>{envelope.model}</code> : null}
          {envelope.kind ? <span> · {envelope.kind}</span> : null}
        </span>
      </header>

      {editing ? (
        <textarea
          data-testid="ai-suggestion-editor"
          className="ai-suggestion__editor"
          value={content}
          onChange={(e) => setContent(e.target.value)}
          rows={Math.max(4, content.split('\n').length + 1)}
          disabled={disabled}
        />
      ) : (
        <div className="ai-suggestion__content" data-testid="ai-suggestion-content">
          {content.split('\n').map((line, i) => (
            <p key={i}>{line || '\u00a0'}</p>
          ))}
        </div>
      )}

      {Array.isArray(envelope.citations) && envelope.citations.length > 0 && (
        <ul className="ai-suggestion__citations" data-testid="ai-suggestion-citations">
          {envelope.citations.map((c, i) => (
            <li key={i}><code>{c.source}</code>{c.excerpt ? ` — ${c.excerpt}` : ''}</li>
          ))}
        </ul>
      )}

      <footer className="ai-suggestion__actions">
        {editable && status === 'draft' && (
          <button
            type="button"
            data-testid="ai-suggestion-edit-btn"
            className="ai-suggestion__btn ai-suggestion__btn--ghost"
            onClick={() => setEditing((v) => !v)}
            disabled={disabled}
          >
            {editing ? 'Done editing' : 'Edit'}
          </button>
        )}
        {status === 'draft' && (
          <>
            <button
              type="button"
              data-testid="ai-suggestion-reject-btn"
              className="ai-suggestion__btn ai-suggestion__btn--reject"
              onClick={() => commit('reject')}
              disabled={disabled}
            >
              Reject
            </button>
            <button
              type="button"
              data-testid="ai-suggestion-accept-btn"
              className="ai-suggestion__btn ai-suggestion__btn--accept"
              onClick={() => commit('approve')}
              disabled={disabled}
            >
              Accept
            </button>
          </>
        )}
        {status === 'approving' && <span>Recording…</span>}
        {status === 'approved'  && <span className="ai-suggestion__ok">Accepted</span>}
        {status === 'rejected'  && <span className="ai-suggestion__ko">Rejected</span>}
        {error && <span className="ai-suggestion__err">Error: {error}</span>}
      </footer>

      <p className="ai-suggestion__disclaimer" data-testid="ai-suggestion-disclaimer">
        This is an AI-generated draft. No numbers, decisions, or actions from this
        text are consumed by CoreFlux until you accept above.
      </p>
    </section>
  );
}
