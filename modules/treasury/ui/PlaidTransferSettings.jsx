import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, RefreshCw, Settings2, Unplug, X } from 'lucide-react';
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
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);

  const onLinked = () => {
    setFlash({ kind: 'success', msg: 'Payment account connected. Approved vendor payments can now be sent online.' });
    reload();
  };

  const onDisconnect = async () => {
    setBusy(true);
    setFlash(null);
    try {
      await api.post('/api/plaid_transfer_link.php?action=disconnect', {});
      setFlash({ kind: 'success', msg: 'Payment account disconnected.' });
      setConfirmDisconnect(false);
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <div data-testid="plaid-transfer-settings-loading">Loading…</div>;
  if (error) {
    return (
      <div data-testid="plaid-transfer-settings-error" className="error operational-state" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        Couldn't load payout settings. {error.message || String(error)}
        <button type="button" className="btn btn--ghost btn--sm" onClick={reload} style={{ marginLeft: 'auto' }}>
          <RefreshCw size={14} aria-hidden="true" /> Retry
        </button>
      </div>
    );
  }

  const configured = !!data?.configured;
  const linked     = !!data?.linked;
  const rail       = data?.rail || null;

  return (
    <section data-testid="plaid-transfer-settings" style={{ maxWidth: 720 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Online vendor payments</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Connect the operating account used to send approved vendor payments. Most payments arrive within one business day.
          Without a connection, you can download a bank payment file instead.
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
          <strong>Online payments aren't available in this workspace yet.</strong>
          <p style={{ fontSize: 13, margin: '8px 0 0', color: 'var(--cf-text-secondary)' }}>
            You can continue downloading payment files, or ask a workspace administrator to finish the payment connection under Connections.
          </p>
          <Link className="btn btn--ghost btn--sm" to="/admin/integrations/plaid" style={{ marginTop: 12 }}>
            <Settings2 size={14} aria-hidden="true" /> Open Connections
          </Link>
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
              Account needed
            </span>
          </div>
          <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: '0 0 16px' }}>
            Sign in to your bank and choose the operating account that will fund approved vendor payments.
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
              <CheckCircle2 size={13} aria-hidden="true" /> Ready for online payments
            </span>
          </div>
          <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, fontSize: 13 }}>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Connected</dt>
            <dd style={{ margin: 0 }}>{rail.linked_at || '—'}</dd>
          </dl>
          <details style={{ marginTop: 12, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            <summary style={{ cursor: 'pointer' }}>Connection details</summary>
            <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: '8px 0 0', fontSize: 12 }}>
              <dt>Provider</dt>
              <dd style={{ margin: 0 }}>Plaid Transfer</dd>
              <dt>Connection ID</dt>
              <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="plaid-transfer-item-id">{rail.item_id || '—'}</dd>
              <dt>Account ID</dt>
              <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="plaid-transfer-account-id">{rail.account_id || '—'}</dd>
            </dl>
          </details>
          <div style={{ marginTop: 16 }}>
            {!confirmDisconnect ? (
              <button
                type="button"
                className="btn btn--ghost"
                disabled={busy}
                onClick={() => setConfirmDisconnect(true)}
                data-testid="plaid-transfer-disconnect-btn"
              >
                <Unplug size={15} aria-hidden="true" /> Disconnect payment account
              </button>
            ) : (
              <div className="operational-state" data-testid="plaid-transfer-disconnect-confirm" style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span style={{ fontSize: 13 }}>Disconnect this payment account? Future payments will use file export until another account is connected.</span>
                <button type="button" className="btn btn--danger btn--sm" onClick={onDisconnect} disabled={busy}>
                  <Unplug size={14} aria-hidden="true" /> {busy ? 'Disconnecting…' : 'Disconnect account'}
                </button>
                <button type="button" className="btn btn--ghost btn--sm" onClick={() => setConfirmDisconnect(false)} disabled={busy} title="Cancel">
                  <X size={14} aria-hidden="true" />
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
