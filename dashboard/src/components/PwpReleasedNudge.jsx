import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../lib/api';

/**
 * PwpReleasedNudge — 2026-02 (P2.2 + P2.3)
 *
 * Surfaces bills the Pay-When-Paid 4-way-match gate JUST released for
 * payment in the past N days. Two mount points:
 *
 *   • AP Weekly Queue page — banner above the list so operators don't
 *     forget the newly-released bills now waiting for a payment run.
 *   • CFO Dashboard — compact tile with one-click "Suggest payment run"
 *     that opens the existing SuggestPaymentRunModal flow.
 *
 * Hides entirely when count === 0 so the surface stays quiet on a clean
 * cash cycle.
 */
export default function PwpReleasedNudge({
  variant = 'banner', // 'banner' | 'tile'
  days = 7,
  onSuggestRun = null,
}) {
  const { data, loading, error } = useApi(`/modules/ap/api/pwp_released.php?days=${days}`);
  const [running, setRunning] = useState(false);

  if (loading || error) return null;
  const count = data?.count || 0;
  if (count === 0) return null;

  const totalDue = Number(data?.total_due || 0);
  const fmt$ = (n) => '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const suggest = async () => {
    if (onSuggestRun) { onSuggestRun(data); return; }
    setRunning(true);
    try {
      const res = await api.post('/modules/ap/api/bills.php?action=suggest-payment-run', {
        days_ahead: days,
      });
      // Navigate user to the weekly queue where the suggestion now lives.
      window.location.href = '/modules/ap/weekly-queue';
    } catch (e) {
      alert('Failed to suggest payment run: ' + (e?.message || e));
    } finally {
      setRunning(false);
    }
  };

  if (variant === 'tile') {
    return (
      <div
        data-testid="pwp-released-tile"
        style={{
          background: 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)',
          border: '1px solid #6ee7b7',
          borderRadius: 8,
          padding: 14,
          marginBottom: 12,
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
          <div>
            <div style={{ fontSize: 11, fontWeight: 600, color: '#065f46', textTransform: 'uppercase', letterSpacing: 0.5 }}>
              Pay-When-Paid · just released
            </div>
            <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4, color: '#064e3b' }} data-testid="pwp-released-count">
              {count} bill{count === 1 ? '' : 's'} · {fmt$(totalDue)}
            </div>
            <div style={{ fontSize: 12, color: '#047857', marginTop: 2 }}>
              Released by AR cash collection in the last {days} day{days === 1 ? '' : 's'} — ready for vendor disbursement.
            </div>
          </div>
        </div>
        <div style={{ marginTop: 10, display: 'flex', gap: 8 }}>
          <button
            type="button"
            onClick={suggest}
            disabled={running}
            data-testid="pwp-released-suggest-run"
            style={{
              padding: '6px 12px', fontSize: 13, fontWeight: 600,
              background: '#059669', color: 'white', border: 'none',
              borderRadius: 4, cursor: running ? 'wait' : 'pointer',
            }}
          >
            {running ? 'Suggesting…' : 'Suggest payment run →'}
          </button>
          <Link
            to="/modules/ap/weekly-queue"
            data-testid="pwp-released-view-queue"
            style={{ padding: '6px 12px', fontSize: 13, color: '#065f46', textDecoration: 'none' }}
          >
            Open queue
          </Link>
        </div>
      </div>
    );
  }

  // Default: banner variant for the AP Weekly Queue page.
  return (
    <div
      data-testid="pwp-released-banner"
      style={{
        background: '#ecfdf5',
        border: '1px solid #a7f3d0',
        borderLeft: '4px solid #10b981',
        borderRadius: 6,
        padding: '10px 14px',
        marginBottom: 12,
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: 12,
        flexWrap: 'wrap',
      }}
    >
      <div style={{ fontSize: 13, color: '#065f46' }}>
        <strong>{count} vendor bill{count === 1 ? '' : 's'}</strong> just released by Pay-When-Paid in the last {days} day{days === 1 ? '' : 's'} ·{' '}
        <strong>{fmt$(totalDue)}</strong> ready to pay.
        <details style={{ display: 'inline-block', marginLeft: 8, fontSize: 12 }}>
          <summary style={{ cursor: 'pointer', color: '#047857' }}>What changed?</summary>
          <ul style={{ margin: '4px 0 0 16px', color: '#065f46' }} data-testid="pwp-released-bill-list">
            {(data.bills || []).slice(0, 5).map(b => (
              <li key={b.id} data-testid={`pwp-released-bill-${b.id}`}>
                <Link to={`/modules/ap/bills/${b.id}`}>{b.internal_ref}</Link>
                {' '}— {b.vendor_name} · {fmt$(Number(b.amount_due))}
                {b.linked_ar_invoice_id && (
                  <> · cleared by AR <Link to={`/modules/billing/invoices/${b.linked_ar_invoice_id}`}>#{b.linked_ar_invoice_id}</Link></>
                )}
              </li>
            ))}
            {data.bills.length > 5 && <li>…and {data.bills.length - 5} more</li>}
          </ul>
        </details>
      </div>
      <button
        type="button"
        onClick={suggest}
        disabled={running}
        data-testid="pwp-released-banner-suggest"
        style={{
          padding: '6px 12px', fontSize: 13, fontWeight: 600,
          background: '#10b981', color: 'white', border: 'none',
          borderRadius: 4, cursor: running ? 'wait' : 'pointer',
          whiteSpace: 'nowrap',
        }}
      >
        {running ? 'Suggesting…' : 'Suggest payment run →'}
      </button>
    </div>
  );
}
