import React from 'react';
import { useApi } from '../lib/api';
import { CheckCircle2, Clock } from 'lucide-react';

/**
 * InboxProgressBadge
 *
 * Sits at the top of /workflow showing:
 *   - "X pending — finish in ~Y min"
 *   - inline progress bar of (cleared today / cleared + pending)
 *
 * Hides itself entirely when there's nothing pending AND nothing cleared
 * (no momentum to show). When `cleared > 0` and `pending === 0` it
 * congratulates ("Inbox zero today ✓"). Polls every 30s so the bar
 * advances live as the user clears items.
 *
 * Source of truth: GET /api/workflow/inbox_summary.php
 */
export default function InboxProgressBadge({ refreshKey }) {
  const { data, loading } = useApi('/api/workflow/inbox_summary.php?_=' + (refreshKey || 0));

  if (loading || !data) return null;

  const pending  = data.pending_total ?? 0;
  const cleared  = data.cleared_today ?? 0;
  const eta      = data.eta_minutes ?? 0;
  const progress = data.progress_pct ?? 0;

  // Nothing happening — hide entirely.
  if (pending === 0 && cleared === 0) return null;

  // Inbox-zero celebration.
  if (pending === 0 && cleared > 0) {
    return (
      <div data-testid="inbox-progress-badge" data-state="zero" style={{
        background: '#f0fdf4', border: '1px solid #bbf7d0', color: '#166534',
        padding: '12px 16px', borderRadius: 10, marginBottom: 16,
        display: 'flex', alignItems: 'center', gap: 10, fontSize: 13,
      }}>
        <CheckCircle2 size={18} />
        <strong>Inbox zero today.</strong>
        <span style={{ color: '#15803d' }}>You cleared {cleared} approval{cleared === 1 ? '' : 's'}.</span>
      </div>
    );
  }

  const breakdown = [];
  if (data.ap_pending > 0)       breakdown.push(`${data.ap_pending} AP bill${data.ap_pending === 1 ? '' : 's'}`);
  if (data.workflow_pending > 0) breakdown.push(`${data.workflow_pending} workflow task${data.workflow_pending === 1 ? '' : 's'}`);

  return (
    <div data-testid="inbox-progress-badge" data-state="pending" style={{
      background: 'linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%)',
      border: '1px solid #bae6fd',
      borderRadius: 10, padding: '14px 18px', marginBottom: 16,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, justifyContent: 'space-between', marginBottom: 8, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Clock size={18} color="#0284c7" />
          <span style={{ fontSize: 14, color: '#075985' }}>
            <strong data-testid="inbox-progress-pending">{pending}</strong> pending
            {breakdown.length > 0 && (
              <span style={{ color: '#0c4a6e', fontWeight: 400, fontSize: 12 }}> &middot; {breakdown.join(' · ')}</span>
            )}
          </span>
        </div>
        <span style={{ fontSize: 12, color: '#0c4a6e', fontWeight: 500 }} data-testid="inbox-progress-eta">
          finish in ~{eta} min
        </span>
      </div>

      <div style={{
        height: 6, background: '#bae6fd', borderRadius: 3, overflow: 'hidden',
      }} data-testid="inbox-progress-bar-container">
        <div data-testid="inbox-progress-bar" style={{
          width: `${progress}%`, height: '100%', background: '#0284c7',
          transition: 'width 320ms ease-out',
        }} />
      </div>

      {cleared > 0 && (
        <div style={{ fontSize: 11, color: '#0c4a6e', marginTop: 6, textAlign: 'right' }}
             data-testid="inbox-progress-cleared">
          Cleared {cleared} today &middot; {progress}% done
        </div>
      )}
    </div>
  );
}
