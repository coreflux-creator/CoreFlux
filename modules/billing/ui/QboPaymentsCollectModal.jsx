import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * QboPaymentsCollectModal — accept a card / e-check payment directly via
 * the QBO Payments merchant rail and apply it to the AR invoice.
 *
 * Backend: POST /api/admin/qbo/payments_charge.php
 *   Body: { invoice_id, amount, token, type, description? }
 *
 * Two tokenizer modes:
 *   1. **Intuit hosted tokenizer SDK** (default when the publishable key
 *      is configured): loads `https://js.intuit.com/v1/intuit-js`, calls
 *      `intuit.ipp.payments.tokenize({ card: {...} })`, exchanges the
 *      response token for a CoreFlux charge in a single submit. Raw PAN
 *      stays in Intuit-controlled DOM nodes; the page only ever holds
 *      the opaque token CoreFlux relays.
 *   2. **Paste-token fallback** (when window.__INTUIT_PAYMENTS_KEY isn't
 *      set, OR the operator opts in for dev/test): operator pastes a
 *      token previously generated via Intuit's developer tools. Useful
 *      for sandbox testing before domain registration completes.
 *
 * The publishable Intuit Payments key is exposed at boot via
 * window.__INTUIT_PAYMENTS_KEY (set by index.php from env when the
 * tenant has registered their domain with Intuit). If unset, the modal
 * gracefully degrades to paste mode and surfaces a helper hint.
 */
export default function QboPaymentsCollectModal({ invoice, onClose, onCollected }) {
  // Tokenizer mode — default to live when the publishable key is
  // configured, otherwise force paste fallback.
  const publishableKey = typeof window !== 'undefined' ? window.__INTUIT_PAYMENTS_KEY : '';
  const liveAvailable  = !!publishableKey && publishableKey.length > 0;
  const [mode, setMode] = useState(liveAvailable ? 'live' : 'paste');

  // Form state.
  const [type, setType]         = useState('card');
  const [amount, setAmount]     = useState(invoice?.amount_due ? Number(invoice.amount_due).toFixed(2) : '');
  const [token, setToken]       = useState('');
  const [desc, setDesc]         = useState(invoice ? `Invoice ${invoice.invoice_number}` : '');
  const [busy, setBusy]         = useState(false);
  const [result, setResult]     = useState(null);
  const [error, setError]       = useState(null);

  // Live tokenizer card inputs.
  const [cardNumber, setCardNumber] = useState('');
  const [expMonth,   setExpMonth]   = useState('');
  const [expYear,    setExpYear]    = useState('');
  const [cvc,        setCvc]        = useState('');
  const [holder,     setHolder]     = useState('');
  const [postal,     setPostal]     = useState('');

  // E-check inputs.
  const [routing,    setRouting]    = useState('');
  const [account,    setAccount]    = useState('');
  const [bankName,   setBankName]   = useState('');

  const sdkLoaded = useRef(false);
  const [sdkReady, setSdkReady] = useState(false);
  const [sdkError, setSdkError] = useState(null);

  useEffect(() => {
    if (mode !== 'live' || sdkLoaded.current || !liveAvailable) return;
    sdkLoaded.current = true;

    // Load Intuit Payments JS SDK once per page session.
    const SCRIPT_URL = 'https://js.intuit.com/v1/intuit-js';
    const existing = document.querySelector(`script[src="${SCRIPT_URL}"]`);
    const onReady = () => {
      try {
        if (window.intuit?.ipp?.payments?.init) {
          window.intuit.ipp.payments.init(publishableKey, {
            environment: window.__INTUIT_PAYMENTS_ENV || 'sandbox',
          });
        }
        setSdkReady(true);
      } catch (e) {
        setSdkError(e.message || 'Intuit SDK init failed.');
      }
    };
    if (existing) { onReady(); return; }
    const s = document.createElement('script');
    s.src = SCRIPT_URL;
    s.async = true;
    s.onload = onReady;
    s.onerror = () => setSdkError(
      'Could not load Intuit Payments SDK. Switch to paste-token mode or check that the domain is registered with Intuit.'
    );
    document.head.appendChild(s);
  }, [mode, liveAvailable, publishableKey]);

  if (!invoice) return null;

  const tokenizeLive = () => new Promise((resolve, reject) => {
    if (!window.intuit?.ipp?.payments?.tokenize) {
      reject(new Error('Intuit Payments SDK not loaded.'));
      return;
    }
    const payload = type === 'card'
      ? {
          card: {
            number:   cardNumber.replace(/\s+/g, ''),
            expMonth: parseInt(expMonth, 10),
            expYear:  parseInt(expYear,  10),
            cvc:      cvc.trim(),
            name:     holder.trim(),
            address:  postal ? { postalCode: postal.trim() } : undefined,
          },
        }
      : {
          bankAccount: {
            routingNumber: routing.trim(),
            accountNumber: account.trim(),
            name:          bankName.trim() || holder.trim() || 'Bank account',
            accountType:   'CHECKING',
          },
        };

    window.intuit.ipp.payments.tokenize(payload, (resp) => {
      if (resp?.value) resolve(resp.value);
      else reject(new Error(resp?.errors?.[0]?.message || 'Tokenization failed.'));
    });
  });

  const handleSubmit = async (e) => {
    e?.preventDefault?.();
    setError(null);
    setResult(null);

    const amt = parseFloat(amount);
    if (!amt || amt <= 0) {
      setError('Amount must be greater than zero.');
      return;
    }

    setBusy(true);
    try {
      let tok = token.trim();
      if (mode === 'live') {
        if (!sdkReady) throw new Error('Intuit SDK is still loading. Try again in a moment.');
        tok = await tokenizeLive();
      } else if (!tok) {
        throw new Error('Paste the Intuit tokenizer token before submitting.');
      }

      const res = await api.post('/api/admin/qbo/payments_charge.php', {
        invoice_id: invoice.id,
        amount:     amt,
        token:      tok,
        type,
        description: desc.trim() || undefined,
      });
      setResult(res);
      if (res?.charge?.status === 'CAPTURED' && typeof onCollected === 'function') {
        onCollected(res);
      }
    } catch (err) {
      setError(err.message || 'Charge failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      data-testid="qbo-payments-modal-backdrop"
      onClick={onClose}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.5)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50,
      }}
    >
      <div
        data-testid="qbo-payments-modal"
        onClick={(e) => e.stopPropagation()}
        style={{
          background: '#fff', borderRadius: 8, padding: 24, width: 480, maxWidth: '90vw',
          boxShadow: '0 24px 56px rgba(15,23,42,0.18)', maxHeight: '90vh', overflowY: 'auto',
        }}
      >
        <header style={{ marginBottom: 12 }}>
          <h3 style={{ margin: 0, fontSize: 18 }}>Accept payment via QuickBooks</h3>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: '#6b7280' }}>
            Invoice <code data-testid="qbo-payments-modal-invoice">{invoice.invoice_number}</code> · open balance{' '}
            <strong>${Number(invoice.amount_due).toFixed(2)} {invoice.currency || 'USD'}</strong>
          </p>
        </header>

        {liveAvailable && (
          <div style={{ display: 'flex', gap: 8, marginBottom: 12, fontSize: 12 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer' }}>
              <input type="radio" name="qbo-mode" value="live" checked={mode === 'live'}
                     onChange={() => setMode('live')} data-testid="qbo-payments-mode-live" />
              Live tokenizer (Intuit SDK)
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer' }}>
              <input type="radio" name="qbo-mode" value="paste" checked={mode === 'paste'}
                     onChange={() => setMode('paste')} data-testid="qbo-payments-mode-paste" />
              Paste token (sandbox/dev)
            </label>
          </div>
        )}
        {!liveAvailable && (
          <div data-testid="qbo-payments-live-unavailable"
               style={{ padding: '6px 10px', borderRadius: 4, marginBottom: 8,
                        background: '#fef3c7', color: '#92400e', fontSize: 11 }}>
            Live tokenizer unavailable — <code>window.__INTUIT_PAYMENTS_KEY</code> is not set. Set the env
            <code> INTUIT_PAYMENTS_PUBLISHABLE_KEY</code> after registering this pod&apos;s domain with Intuit
            to enable card collection here. Paste-token mode remains available for sandbox.
          </div>
        )}

        <form onSubmit={handleSubmit}>
          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <button type="button" onClick={() => setType('card')} data-testid="qbo-payments-type-card" style={typeBtn(type === 'card')}>Card</button>
            <button type="button" onClick={() => setType('echeck')} data-testid="qbo-payments-type-echeck" style={typeBtn(type === 'echeck')}>ACH e-check</button>
          </div>

          <Field label="Amount (USD)">
            <input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)}
                   data-testid="qbo-payments-amount" required max={Number(invoice.amount_due)} style={inputStyle} />
          </Field>

          <Field label="Description (appears on the customer receipt)">
            <input type="text" value={desc} onChange={(e) => setDesc(e.target.value)}
                   data-testid="qbo-payments-description" style={inputStyle} maxLength={120} />
          </Field>

          {mode === 'live' && type === 'card' && (
            <div data-testid="qbo-payments-live-card-fields">
              <Field label="Cardholder name">
                <input value={holder} onChange={(e) => setHolder(e.target.value)} data-testid="qbo-payments-card-holder" required style={inputStyle} />
              </Field>
              <Field label="Card number">
                <input value={cardNumber} onChange={(e) => setCardNumber(e.target.value)} data-testid="qbo-payments-card-number"
                       required inputMode="numeric" autoComplete="cc-number" placeholder="4242 4242 4242 4242"
                       style={{ ...inputStyle, fontFamily: 'ui-monospace, monospace' }} />
              </Field>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
                <Field label="Exp month"><input value={expMonth} onChange={(e) => setExpMonth(e.target.value)} data-testid="qbo-payments-exp-month" placeholder="MM" required inputMode="numeric" maxLength={2} style={inputStyle} /></Field>
                <Field label="Exp year"><input value={expYear} onChange={(e) => setExpYear(e.target.value)} data-testid="qbo-payments-exp-year" placeholder="YYYY" required inputMode="numeric" maxLength={4} style={inputStyle} /></Field>
                <Field label="CVC"><input value={cvc} onChange={(e) => setCvc(e.target.value)} data-testid="qbo-payments-cvc" required inputMode="numeric" maxLength={4} style={inputStyle} /></Field>
              </div>
              <Field label="Billing ZIP / postal code (optional)">
                <input value={postal} onChange={(e) => setPostal(e.target.value)} data-testid="qbo-payments-postal" style={inputStyle} />
              </Field>
            </div>
          )}
          {mode === 'live' && type === 'echeck' && (
            <div data-testid="qbo-payments-live-echeck-fields">
              <Field label="Account holder name">
                <input value={holder} onChange={(e) => setHolder(e.target.value)} data-testid="qbo-payments-echeck-holder" required style={inputStyle} />
              </Field>
              <Field label="Bank name (shown on the customer receipt)">
                <input value={bankName} onChange={(e) => setBankName(e.target.value)} data-testid="qbo-payments-echeck-bank" style={inputStyle} />
              </Field>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                <Field label="Routing number">
                  <input value={routing} onChange={(e) => setRouting(e.target.value)} data-testid="qbo-payments-routing" required inputMode="numeric" maxLength={9} style={{ ...inputStyle, fontFamily: 'ui-monospace, monospace' }} />
                </Field>
                <Field label="Account number">
                  <input value={account} onChange={(e) => setAccount(e.target.value)} data-testid="qbo-payments-account" required inputMode="numeric" style={{ ...inputStyle, fontFamily: 'ui-monospace, monospace' }} />
                </Field>
              </div>
            </div>
          )}

          {mode === 'paste' && (
            <Field
              label={`Intuit tokenizer ${type === 'card' ? 'card' : 'bank'} token`}
              help={
                type === 'card'
                  ? 'Obtain via Intuit\'s hosted card form. Token format like "ey..." — never paste a raw PAN.'
                  : 'Obtain via Intuit\'s hosted bank form. Token format like "ey..." — never paste raw account numbers.'
              }
            >
              <textarea value={token} onChange={(e) => setToken(e.target.value)} data-testid="qbo-payments-token"
                        required rows={3}
                        style={{ ...inputStyle, fontFamily: 'ui-monospace, monospace', fontSize: 12 }}
                        placeholder="ey..." />
            </Field>
          )}

          {sdkError && <div data-testid="qbo-payments-sdk-error" style={errorStyle}>{sdkError}</div>}
          {error && <div data-testid="qbo-payments-error" style={errorStyle}>{error}</div>}
          {result?.charge && (
            <div data-testid="qbo-payments-result" style={resultStyle(result.charge.status)}>
              <div><strong>Status:</strong> {result.charge.status}</div>
              <div><strong>Charge ID:</strong> {result.charge.id}</div>
              {result.payment_id && <div><strong>CoreFlux payment:</strong> #{result.payment_id} — allocated to invoice.</div>}
              {result.allocation_error && <div style={{ color: '#92400e' }}><strong>Allocation:</strong> {result.allocation_error}</div>}
            </div>
          )}

          <footer style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
            <button type="button" onClick={onClose} data-testid="qbo-payments-cancel" style={btnGhost}>Close</button>
            <button type="submit"
                    disabled={busy || (mode === 'live' && !sdkReady)}
                    data-testid="qbo-payments-submit"
                    style={{ ...btnPrimary, opacity: (busy || (mode === 'live' && !sdkReady)) ? 0.6 : 1 }}>
              {busy ? 'Charging…' : `Charge $${Number(amount || 0).toFixed(2)}`}
            </button>
          </footer>
          {mode === 'live' && !sdkReady && !sdkError && (
            <p data-testid="qbo-payments-sdk-loading" style={{ fontSize: 11, color: '#6b7280', marginTop: 6 }}>
              Loading Intuit Payments SDK…
            </p>
          )}
        </form>
      </div>
    </div>
  );
}

function Field({ label, help, children }) {
  return (
    <label style={{ display: 'block', marginBottom: 10 }}>
      <span style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>{label}</span>
      {children}
      {help && <span style={{ display: 'block', fontSize: 11, color: '#6b7280', marginTop: 4 }}>{help}</span>}
    </label>
  );
}

const inputStyle = {
  width: '100%', padding: '8px 10px', borderRadius: 6,
  border: '1px solid #d1d5db', fontSize: 14, boxSizing: 'border-box',
};
const btnPrimary = {
  padding: '8px 16px', borderRadius: 6, border: 'none',
  background: '#0f172a', color: '#fff', cursor: 'pointer', fontWeight: 600, fontSize: 13,
};
const btnGhost = {
  padding: '8px 16px', borderRadius: 6,
  border: '1px solid #d1d5db', background: '#fff', color: '#374151', cursor: 'pointer', fontSize: 13,
};
const typeBtn = (active) => ({
  flex: 1, padding: '8px 0', borderRadius: 6, fontSize: 13, cursor: 'pointer',
  background: active ? '#0f172a' : '#fff',
  color: active ? '#fff' : '#374151',
  border: '1px solid', borderColor: active ? '#0f172a' : '#d1d5db', fontWeight: 600,
});
const errorStyle = {
  padding: '8px 10px', borderRadius: 6, background: '#fee2e2', color: '#991b1b', fontSize: 13, marginBottom: 8,
};
const resultStyle = (status) => ({
  padding: '8px 10px', borderRadius: 6, fontSize: 13, marginBottom: 8,
  background: status === 'CAPTURED' ? '#d1fae5' : '#fef3c7',
  color:      status === 'CAPTURED' ? '#065f46' : '#92400e',
});
