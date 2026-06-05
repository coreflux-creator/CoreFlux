import React, { useState } from 'react';
import { api } from '../lib/api';

/**
 * <TransactionRecommendationCard /> — drop-in component for the bank
 * reconciliation / classification UX (Slice B, Spec §11).
 *
 * Wraps a single AI-proposed classification for a bank-feed row. Lets
 * the operator Accept / Edit / Reject in one click.  Designed to be
 * embedded inline next to a transaction row — keeps the existing
 * BankReconcile / TransactionsToReview layout intact, just slots a
 * collapsible card under any row that carries a recommendation.
 *
 * Expected `recommendation` shape (from the classification graph or
 * accounting_ai_interpretations table):
 *   {
 *     payee_normalized?:    string,                         // optional, from resolveVendorAlias
 *     canonical_vendor?:    { id?, label?, source? },       // from resolveVendorAlias.alias
 *     proposed_account_id:  int,
 *     proposed_account:     { code, name, type },           // for display
 *     confidence:           number,                          // 0-1
 *     hour_type?:           string,                          // optional
 *     reasoning?:           string,                          // AI explanation
 *     ai_run_id?:           string,                          // links to /admin/ai-gateway/<id>
 *     workflow_run_id?:     string,
 *   }
 *
 * Callbacks:
 *   onAccept(recommendation)  — operator clicks Accept
 *   onEdit(recommendation)    — operator clicks Edit (opens parent's
 *                               existing edit modal / inline editor)
 *   onReject(recommendation)  — operator clicks Reject (parent should
 *                               escalate to an exception)
 */
export default function TransactionRecommendationCard({
  recommendation,
  transactionId,
  onAccept,
  onEdit,
  onReject,
  compact = false,
}) {
  const [busy, setBusy]     = useState(false);
  const [error, setError]   = useState(null);
  const [explained, setExplained] = useState(false);

  if (!recommendation) return null;

  const cv          = recommendation.canonical_vendor || {};
  const account     = recommendation.proposed_account || {};
  const confidence  = Number(recommendation.confidence || 0);
  const confidencePct = (confidence * 100).toFixed(0);
  const confidenceColor =
    confidence >= 0.85 ? '#16a34a' :
    confidence >= 0.6  ? '#d97706' :
                         '#dc2626';

  const handleAccept = async () => {
    if (!onAccept) return;
    setBusy(true); setError(null);
    try { await onAccept(recommendation); }
    catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };
  const handleReject = async () => {
    if (!onReject) return;
    setBusy(true); setError(null);
    try { await onReject(recommendation); }
    catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };
  // Pin the alias so re-classification won't silently overwrite it.
  const handlePinAlias = async () => {
    if (!recommendation.payee_normalized) return;
    setBusy(true); setError(null);
    try {
      // Use the tool endpoint so the call shows up in the AI audit trail.
      await api.post('/api/ai/tools.php?action=invoke', {
        tool: 'coreflux.record_vendor_alias',
        args: {
          payee:               recommendation.payee_normalized,
          canonical_vendor_id: cv.id || null,
          canonical_label:     !cv.id ? (cv.label || null) : null,
          confidence,
          pinned:              true,
        },
      });
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div
      data-testid={`txn-recommendation-${transactionId}`}
      style={{
        border: `1px solid ${confidenceColor}`,
        borderLeft: `4px solid ${confidenceColor}`,
        background: confidence >= 0.85 ? '#f0fdf4' :
                    confidence >= 0.6  ? '#fffbeb' :
                                         '#fef2f2',
        borderRadius: 6,
        padding: compact ? 8 : 12,
        marginTop: 6,
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
            <span data-testid={`txn-recommendation-${transactionId}-confidence`}
                  style={{
                    fontSize: 11, fontWeight: 700, color: confidenceColor,
                    background: '#fff', padding: '2px 8px', borderRadius: 999,
                    border: `1px solid ${confidenceColor}`,
                  }}>
              {confidencePct}% confidence
            </span>
            {cv && (cv.id || cv.label) && (
              <span data-testid={`txn-recommendation-${transactionId}-vendor`}
                    style={{ fontSize: 11, color: '#475569' }}>
                vendor: <strong>{cv.label || `#${cv.id}`}</strong>
                {cv.source === 'manual' && <em style={{ marginLeft: 4 }}>(pinned)</em>}
              </span>
            )}
          </div>
          <div data-testid={`txn-recommendation-${transactionId}-account`}
               style={{ fontSize: 13, fontWeight: 500 }}>
            → <code style={{ marginRight: 4 }}>{account.code}</code>
            {account.name}
            {account.type && <span style={{ marginLeft: 6, fontSize: 11, color: '#64748b' }}>({account.type})</span>}
          </div>
          {recommendation.reasoning && (
            <button type="button"
                    onClick={() => setExplained(v => !v)}
                    data-testid={`txn-recommendation-${transactionId}-explain-toggle`}
                    style={{
                      background: 'transparent', border: 'none', padding: 0, marginTop: 4,
                      color: '#0369a1', fontSize: 11, cursor: 'pointer',
                    }}>
              {explained ? 'Hide reasoning ↑' : 'Why this account? ↓'}
            </button>
          )}
          {explained && (
            <p data-testid={`txn-recommendation-${transactionId}-reasoning`}
               style={{ fontSize: 11, color: '#475569', margin: '4px 0 0', lineHeight: 1.4 }}>
              {recommendation.reasoning}
              {recommendation.ai_run_id && (
                <span style={{ display: 'block', marginTop: 4, fontFamily: 'var(--cf-mono, ui-monospace)' }}>
                  trace: <a href={`/admin/ai-gateway?run=${recommendation.ai_run_id}`}>
                    {String(recommendation.ai_run_id).slice(0, 8)}…
                  </a>
                </span>
              )}
            </p>
          )}
        </div>
        <div style={{ display: 'flex', gap: 4, flexShrink: 0 }}>
          <button type="button"
                  onClick={handleAccept}
                  disabled={busy || !onAccept}
                  data-testid={`txn-recommendation-${transactionId}-accept`}
                  className="btn btn--primary"
                  style={{ padding: '4px 10px', fontSize: 11 }}>
            Accept
          </button>
          {onEdit && (
            <button type="button"
                    onClick={() => onEdit(recommendation)}
                    disabled={busy}
                    data-testid={`txn-recommendation-${transactionId}-edit`}
                    className="btn btn--ghost"
                    style={{ padding: '4px 10px', fontSize: 11 }}>
              Edit
            </button>
          )}
          {onReject && (
            <button type="button"
                    onClick={handleReject}
                    disabled={busy}
                    data-testid={`txn-recommendation-${transactionId}-reject`}
                    className="btn btn--ghost"
                    style={{ padding: '4px 10px', fontSize: 11, color: '#dc2626' }}>
              Reject
            </button>
          )}
          {recommendation.payee_normalized && cv && (cv.id || cv.label) && (
            <button type="button"
                    onClick={handlePinAlias}
                    disabled={busy}
                    data-testid={`txn-recommendation-${transactionId}-pin-alias`}
                    title="Pin this vendor alias so AI re-classification can't silently overwrite it"
                    className="btn btn--ghost"
                    style={{ padding: '4px 8px', fontSize: 11 }}>
              📌
            </button>
          )}
        </div>
      </div>
      {error && (
        <div data-testid={`txn-recommendation-${transactionId}-error`}
             className="error" style={{ marginTop: 6, fontSize: 11 }}>
          {error}
        </div>
      )}
    </div>
  );
}
