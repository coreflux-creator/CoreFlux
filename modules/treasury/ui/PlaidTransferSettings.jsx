import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';
import PlaidTransferLinkButton from '../../../dashboard/src/components/PlaidTransferLinkButton';

/**
 * Plaid Transfer Settings — tenant self-service panel for the AP pay-out
 * funding source. Mounted at /modules/treasury/payout-rails.
 *
 * Three render branches keyed off /api/plaid_transfer_link.php?action=status:
 *   - configured=false                 → muted "Not configured" notice
 *   - configured=true,  linked=false   → <PlaidTransferLinkButton />
 *   - configured=true,  linked=true    → linked summary + Disconnect CTA
 */
export default function PlaidTransferSettings() {
  const { data, loading, error, reload } = useApi('/api/plaid_transfer_link.php?action=status');
  const [busy, setBusy]   = useState(false);
  const [flash, setFlash] = useState(null);

  const onLinked = () => {
    setFlash({ kind: 'success', msg: 'Funding source connected. Plaid Transfer is now available on the AP Payments tab.' });
    reload();
  };

  const onDisconnect = async () => {
    if (!window.confirm('Disconnect the Plaid Transfer funding source? Outbound ACH/RTP payouts will fall back to NACHA until you reconnect.')) return;
    setBusy(true);
    setFlash(null);
    try {
      await api.post('/api/plaid_transfer_link.php?action=disconnect', {});
      setFlash({ kind: 'success', msg: 'Funding source disconnected.' });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <div data-testid="plaid-transfer-settings-loading">Loading…</div>;
  if (error)   return <div data-testid="plaid-transfer-settings-error" className="error">{error.message || String(error)}</div>;

  const configured = !!data?.configured;
  const linked     = !!data?.linked;
  const rail       = data?.rail || null;

  return (
    <section data-testid="plaid-transfer-settings" style={{ maxWidth: 720 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>AP Pay-out Rail — Plaid Transfer</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Authorise CoreFlux to originate ACH / RTP payouts from your operating account via Plaid Transfer.
          Settles 0–1 business days. Without this, AP payments fall back to manual NACHA file export.
        </p>
      </header>

      {flash && (
        <div
          data-testid={`plaid-transfer-flash-${flash.kind}`}
          style={{
            padding: '10px 14px',
            borderRadius: 6,
            marginBottom: 16,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color: flash.kind === 'success' ? 'var(--cf-green, #047857)' : 'var(--cf-red, #b91c1c)',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

      {!configured && (
        <div
          data-testid="plaid-transfer-not-configured"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, background: '#fafafa' }}
        >
          <strong>Plaid not configured on this pod.</strong>
          <p style={{ fontSize: 13, margin: '8px 0 0', color: 'var(--cf-text-secondary)' }}>
            An administrator must set <code>PLAID_CLIENT_ID</code> + <code>PLAID_SECRET_SANDBOX</code> (or
            <code>PLAID_SECRET_PRODUCTION</code>) in the pod environment and ensure the Plaid Transfer
            Application has been approved. Until then, AP payments use manual NACHA.
          </p>
        </div>
      )}

      {configured && !linked && (
        <div
          data-testid="plaid-transfer-not-linked"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
        >
          <div style={{ marginBottom: 12 }}>
            <span className="badge" style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
              Not linked
            </span>
          </div>
          <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '0 0 16px' }}>
            Click below to open Plaid Link, sign into your bank, and choose the operating account that
            will fund AP pay-outs.
          </p>
          <PlaidTransferLinkButton
            onLinked={onLinked}
            onError={(err) => setFlash({ kind: 'error', msg: err.message || String(err) })}
            testIdSuffix="settings"
          />
        </div>
      )}

      {configured && linked && rail && (
        <div
          data-testid="plaid-transfer-linked"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
        >
          <div style={{ marginBottom: 12 }}>
            <span className="badge badge--success" style={{ padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
              ✓ Linked
            </span>
          </div>
          <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, fontSize: 13 }}>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Plaid item</dt>
            <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="plaid-transfer-item-id">{rail.item_id || '—'}</dd>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Funding account</dt>
            <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="plaid-transfer-account-id">{rail.account_id || '—'}</dd>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Linked at</dt>
            <dd style={{ margin: 0 }}>{rail.linked_at || '—'}</dd>
          </dl>
          <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
            <button
              type="button"
              className="btn"
              disabled={busy}
              onClick={onDisconnect}
              data-testid="plaid-transfer-disconnect-btn"
            >
              {busy ? 'Disconnecting…' : 'Disconnect'}
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
