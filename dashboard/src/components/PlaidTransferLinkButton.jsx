import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <PlaidTransferLinkButton /> — drives the Plaid Link flow specifically for
 * AP pay-outs (Plaid Transfer rail). Distinct from <PlaidLinkButton /> because
 * the funding-source link uses /api/plaid_transfer_link.php (which writes to
 * tenant_payment_rails and is gated by accounting.bank.manage) instead of the
 * generic bank-feed /api/plaid_link_token + /api/plaid_exchange endpoints.
 *
 * Flow:
 *   1. POST /api/plaid_transfer_link.php (no action)  → link_token
 *   2. open Plaid Link with the token
 *   3. POST /api/plaid_transfer_link.php?action=exchange
 *      body: { public_token, account_id } → server stores access_token + funding account
 *
 * Props:
 *   onLinked:       (result: { item_id, account_id, status }) => void
 *   onError?:       (err: Error) => void
 *   label?:         string   default 'Connect funding source'
 *   testIdSuffix?:  string   appended to data-testid
 */

const PLAID_LINK_SRC = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';

let plaidLoadPromise = null;
function loadPlaidLink() {
  if (typeof window.Plaid !== 'undefined') return Promise.resolve(window.Plaid);
  if (plaidLoadPromise) return plaidLoadPromise;
  plaidLoadPromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = PLAID_LINK_SRC;
    s.async = true;
    s.onload  = () => resolve(window.Plaid);
    s.onerror = () => reject(new Error('Plaid Link script failed to load'));
    document.head.appendChild(s);
  });
  return plaidLoadPromise;
}

export default function PlaidTransferLinkButton({
  onLinked,
  onError,
  label = 'Connect funding source',
  testIdSuffix = '',
}) {
  const [status, setStatus] = useState('idle'); // idle | loading | ready | linking | exchanging | done | error
  const [error, setError] = useState(null);
  const [linkToken, setLinkToken] = useState(null);

  // Pre-fetch link_token + Plaid SDK so the first click is instant.
  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    Promise.all([
      api.post('/api/plaid_transfer_link.php', {}),
      loadPlaidLink(),
    ])
      .then(([tokenResp]) => {
        if (cancelled) return;
        setLinkToken(tokenResp.link_token);
        setStatus('ready');
      })
      .catch(err => {
        if (cancelled) return;
        setError(err.message || String(err));
        setStatus('error');
        onError && onError(err);
      });
    return () => { cancelled = true; };
  }, []);  // eslint-disable-line react-hooks/exhaustive-deps

  const handleClick = useCallback(() => {
    if (!window.Plaid || !linkToken) return;
    setStatus('linking');
    const handler = window.Plaid.create({
      token: linkToken,
      onSuccess: async (publicToken, metadata) => {
        setStatus('exchanging');
        try {
          // Plaid Link returns metadata.accounts[0].id as the chosen funding
          // account when the Link UI prompts for a single selection.
          const accountId = (metadata && metadata.accounts && metadata.accounts[0] && metadata.accounts[0].id) || '';
          const result = await api.post('/api/plaid_transfer_link.php?action=exchange', {
            public_token: publicToken,
            account_id:   accountId,
          });
          setStatus('done');
          onLinked && onLinked(result);
        } catch (err) {
          setError(err.message || String(err));
          setStatus('error');
          onError && onError(err);
        }
      },
      onExit: (err, _meta) => {
        if (err) {
          setError(err.error_message || err.display_message || 'Plaid Link cancelled');
          setStatus('error');
          onError && onError(new Error(err.error_message || 'cancelled'));
        } else {
          setStatus('ready');
        }
      },
    });
    handler.open();
  }, [linkToken, onLinked, onError]);

  const disabled = !['ready', 'done'].includes(status);
  const tid = `plaid-transfer-link-btn${testIdSuffix ? '-' + testIdSuffix : ''}`;

  return (
    <div className="plaid-transfer-link" data-testid="plaid-transfer-link">
      <button
        type="button"
        onClick={handleClick}
        disabled={disabled}
        data-testid={tid}
        className="btn btn--primary"
      >
        {status === 'loading'    && 'Loading…'}
        {status === 'ready'      && label}
        {status === 'linking'    && 'Choose your bank…'}
        {status === 'exchanging' && 'Saving…'}
        {status === 'done'       && '✓ Connected'}
        {status === 'error'      && 'Retry'}
      </button>
      {error && <div className="plaid-transfer-link__error" data-testid="plaid-transfer-link-error" style={{ marginTop: 6, color: 'var(--cf-red, #b91c1c)', fontSize: 12 }}>{error}</div>}
    </div>
  );
}
