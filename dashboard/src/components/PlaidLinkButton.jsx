import React, { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';

/**
 * <PlaidLinkButton /> — minimal drop-in to launch Plaid Link from any
 * onboarding flow (Vendor edit, Employee bank, Accounting bank account).
 *
 * Loads Plaid Link's stable JS from cdn.plaid.com on first mount, requests
 * a link_token from /api/plaid_link_token, opens the Link modal on click,
 * and on success POSTs the public_token to /api/plaid_exchange. The CLI
 * caller decides what to do next (typically: re-fetch the parent record).
 *
 * Props:
 *   purpose:                'bank_feed' | 'vendor_banking' | 'employee_banking' | 'tenant_funding'
 *   vendorId?:              number  (purpose=vendor_banking)
 *   employeeId?:            number  (purpose=employee_banking)
 *   accountingBankAccountId?: number (purpose=bank_feed)
 *   products?:              string[]  default ['auth','transactions']
 *   onLinked:               (result: { plaid_item_pk, item_id, accounts }) => void
 *   onError?:               (err: Error) => void
 *   label?:                 string  default 'Connect bank'
 *   testIdSuffix?:          string  appended to data-testid
 *   deferUntilClick?:       boolean  request Plaid only after the user clicks
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

export default function PlaidLinkButton({
  purpose,
  vendorId,
  employeeId,
  accountingBankAccountId,
  products,
  onLinked,
  onError,
  label = 'Connect bank',
  testIdSuffix = '',
  deferUntilClick = false,
}) {
  const [status, setStatus] = useState('idle'); // idle | loading | ready | linking | exchanging | done | error
  const [error, setError] = useState(null);
  const [linkToken, setLinkToken] = useState(null);
  const [retry, setRetry] = useState(0);
  const openAfterLoad = useRef(false);

  // Pre-fetch by default; manual bank screens can wait for an explicit click.
  // products: explicit prop wins; else server picks per-purpose defaults
  // (vendor/employee/funding → ['auth']; bank_feed → ['transactions','auth']).
  useEffect(() => {
    if (deferUntilClick && retry === 0) return undefined;
    let cancelled = false;
    setStatus('loading');
    const reqBody = { purpose };
    if (products) reqBody.products = products;
    Promise.all([
      api.post('/api/plaid_link_token.php', reqBody),
      loadPlaidLink(),
    ])
      .then(([tokenResp]) => {
        if (cancelled) return;
        setLinkToken(tokenResp.link_token);
        setStatus('ready');
        if (openAfterLoad.current) {
          openAfterLoad.current = false;
          openPlaid(tokenResp.link_token);
        }
      })
      .catch(err => {
        if (cancelled) return;
        setError(err.message || String(err));
        setStatus('error');
        onError && onError(err);
      });
    return () => { cancelled = true; };
  }, [purpose, JSON.stringify(products), retry, deferUntilClick]);  // eslint-disable-line react-hooks/exhaustive-deps

  function openPlaid(token) {
    if (!window.Plaid || !token) return;
    setStatus('linking');
    const handler = window.Plaid.create({
      token,
      onSuccess: async (publicToken, metadata) => {
        setStatus('exchanging');
        try {
          const result = await api.post('/api/plaid_exchange.php', {
            public_token: publicToken,
            purpose,
            vendor_id:                  vendorId,
            employee_id:                employeeId,
            accounting_bank_account_id: accountingBankAccountId,
            institution: metadata.institution,
            selected_account_ids:       (metadata.accounts || []).map((a) => a.id).filter(Boolean),
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
  }

  function handleClick() {
    if (status === 'idle' || status === 'error') {
      setError(null);
      openAfterLoad.current = deferUntilClick;
      setRetry(value => value + 1);
      return;
    }
    openPlaid(linkToken);
  }

  const disabled = !['ready','done','error'].includes(status) && !(deferUntilClick && status === 'idle');
  const tid = `plaid-link-btn${testIdSuffix ? '-' + testIdSuffix : ''}`;

  return (
    <div className="plaid-link" data-testid={`plaid-link-${purpose}`} style={{ minWidth: 0, maxWidth: 220 }}>
      <button
        type="button"
        onClick={handleClick}
        disabled={disabled}
        title={error || undefined}
        data-testid={tid}
        className="btn btn-secondary"
      >
        {status === 'idle'        && label}
        {status === 'loading'     && 'Loading…'}
        {status === 'ready'       && label}
        {status === 'linking'     && 'Choose your bank…'}
        {status === 'exchanging'  && 'Saving…'}
        {status === 'done'        && '✓ Connected'}
        {status === 'error'       && 'Retry'}
      </button>
      {error && <div className="plaid-link__error" data-testid="plaid-link-error" title={error} style={{ fontSize: 11, overflowWrap: 'anywhere' }}>
        {error.includes('not configured') ? 'Bank connection not configured' : error}
      </div>}
    </div>
  );
}
