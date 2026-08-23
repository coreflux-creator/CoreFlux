import React, { useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * QboPaymentsCollectModal — accept a card / e-check payment directly via
 * the QBO Payments merchant rail and apply it to the AR invoice.
 *
 * Backend: POST /api/admin/qbo/payments_charge.php
 *   Body: { invoice_id, amount, token, type, description? }
 *
 * Two tokenizer modes:
 *   1. **Direct Intuit tokenization** (default): POSTs card or bank values
 *      from this browser to Intuit's documented, unauthenticated Payments
 *      token endpoint, then exchanges the returned opaque token for a
 *      CoreFlux charge. The CoreFlux server never receives the raw PAN,
 *      routing number, or bank account number.
 *   2. **Paste-token fallback**: operator pastes a
 *      token previously generated via Intuit's developer tools. Useful
 *      for sandbox testing and support diagnostics.
 */
export default function QboPaymentsCollectModal({ invoice, environment = 'sandbox', onClose, onCollected }) {
  const paymentsEnvironment = environment === 'production' ? 'production' : 'sandbox';
  const tokenEndpoint = `${paymentsEnvironment === 'production' ? 'https://api.intuit.com' : 'https://sandbox.api.intuit.com'}/quickbooks/v4/payments/tokens`;
  const [mode, setMode] = useState('direct');

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

  // One Request-Id per immutable payment intent. Network/HTTP retries of
  // the same invoice+amount+type reuse it, allowing Intuit and CoreFlux to
  // return the original charge instead of double-charging the customer.
  const idempotencyRef = useRef({ intent: '', key: '' });

  if (!invoice) return null;

  const tokenizeDirect = async () => {
    const payload = type === 'card'
      ? {
          card: {
            number:   cardNumber.replace(/\s+/g, ''),
            expMonth: expMonth.trim().padStart(2, '0'),
            expYear:  expYear.trim(),
            cvc:      cvc.trim(),
            name:     holder.trim(),
            address:  postal ? { postalCode: postal.trim() } : undefined,
          },
        }
      : {
          bankAccount: {
            routingNumber: routing.trim(),
            accountNumber: account.trim(),
            name:          holder.trim(),
            bankName:      bankName.trim() || undefined,
            accountType:   'PERSONAL_CHECKING',
          },
        };

    const requestId = globalThis.crypto?.randomUUID?.()
      || `cf-token-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    const response = await fetch(tokenEndpoint, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'Request-Id': requestId,
      },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data?.value) {
      const firstError = Array.isArray(data?.errors) ? data.errors[0] : null;
      throw new Error(firstError?.message || `Intuit tokenization failed (HTTP ${response.status}).`);
    }
    return data.value;
  };

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
      if (mode === 'direct') {
        tok = await tokenizeDirect();
      } else if (!tok) {
        throw new Error('Paste the Intuit tokenizer token before submitting.');
      }

      const intent = `${invoice.id}:${amt.toFixed(2)}:${type}`;
      if (idempotencyRef.current.intent !== intent) {
        const nonce = globalThis.crypto?.randomUUID?.()
          || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        idempotencyRef.current = { intent, key: `cf-qbo-${nonce}`.slice(0, 64) };
      }

      const res = await api.post('/api/admin/qbo/payments_charge.php', {
        invoice_id: invoice.id,
        amount:     amt,
        token:      tok,
        type,
        idempotency_key: idempotencyRef.current.key,
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

        <div style={{ display: 'flex', gap: 8, marginBottom: 12, fontSize: 12 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer' }}>
            <input type="radio" name="qbo-mode" value="direct" checked={mode === 'direct'}
                   onChange={() => setMode('direct')} data-testid="qbo-payments-mode-direct" />
            Direct tokenization (Intuit)
          </label>
          <label style={{ display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer' }}>
            <input type="radio" name="qbo-mode" value="paste" checked={mode === 'paste'}
                   onChange={() => setMode('paste')} data-testid="qbo-payments-mode-paste" />
            Paste token (sandbox/support)
          </label>
        </div>
        <div data-testid="qbo-payments-token-environment"
             style={{ marginBottom: 10, fontSize: 11, color: '#6b7280' }}>
          Tokenizing against Intuit <strong>{paymentsEnvironment}</strong>.
        </div>

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

          {mode === 'direct' && type === 'card' && (
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
          {mode === 'direct' && type === 'echeck' && (
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
                    disabled={busy}
                    data-testid="qbo-payments-submit"
                    style={{ ...btnPrimary, opacity: busy ? 0.6 : 1 }}>
              {busy ? 'Charging…' : `Charge $${Number(amount || 0).toFixed(2)}`}
            </button>
          </footer>
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
