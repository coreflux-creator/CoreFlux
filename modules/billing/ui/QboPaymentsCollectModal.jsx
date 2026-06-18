import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * QboPaymentsCollectModal — accept a card / e-check payment directly via
 * the QBO Payments merchant rail and apply it to the AR invoice.
 *
 * Backend: POST /api/admin/qbo/payments_charge.php
 *   Body: { invoice_id, amount, token, type, description? }
 *
 * Tokenizer modes:
 *   - **paste**: Operator obtains a token off-band (via Intuit's hosted
 *     payment form) and pastes it. Works today, no domain registration.
 *   - **iframe**: TODO — embed Intuit's hosted tokenizer iframe directly.
 *     Requires the tenant's pod domain to be registered with Intuit
 *     before card data can be tokenized. Until then the iframe mode is
 *     stubbed out behind a "coming soon" notice.
 */
export default function QboPaymentsCollectModal({ invoice, onClose, onCollected }) {
  const [type, setType]         = useState('card');
  const [amount, setAmount]     = useState(invoice?.amount_due ? Number(invoice.amount_due).toFixed(2) : '');
  const [token, setToken]       = useState('');
  const [desc, setDesc]         = useState(invoice ? `Invoice ${invoice.invoice_number}` : '');
  const [busy, setBusy]         = useState(false);
  const [result, setResult]     = useState(null);
  const [error, setError]       = useState(null);

  if (!invoice) return null;

  const handleSubmit = async (e) => {
    e?.preventDefault?.();
    setError(null);
    setResult(null);

    const amt = parseFloat(amount);
    if (!amt || amt <= 0) {
      setError('Amount must be greater than zero.');
      return;
    }
    if (!token.trim()) {
      setError('Paste the Intuit tokenizer token before submitting.');
      return;
    }

    setBusy(true);
    try {
      const res = await api.post('/api/admin/qbo/payments_charge.php', {
        invoice_id: invoice.id,
        amount:     amt,
        token:      token.trim(),
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

        <form onSubmit={handleSubmit}>
          {/* Type picker */}
          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <button
              type="button"
              onClick={() => setType('card')}
              data-testid="qbo-payments-type-card"
              style={typeBtn(type === 'card')}
            >Card</button>
            <button
              type="button"
              onClick={() => setType('echeck')}
              data-testid="qbo-payments-type-echeck"
              style={typeBtn(type === 'echeck')}
            >ACH e-check</button>
          </div>

          <Field label="Amount (USD)">
            <input
              type="number"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              data-testid="qbo-payments-amount"
              required
              max={Number(invoice.amount_due)}
              style={inputStyle}
            />
          </Field>

          <Field label="Description (appears on the customer receipt)">
            <input
              type="text"
              value={desc}
              onChange={(e) => setDesc(e.target.value)}
              data-testid="qbo-payments-description"
              style={inputStyle}
              maxLength={120}
            />
          </Field>

          <Field
            label={`Intuit tokenizer ${type === 'card' ? 'card' : 'bank'} token`}
            help={
              type === 'card'
                ? 'Obtain via the Intuit hosted card form (https://developer.intuit.com/app/developer/qbpayments/docs/api/resources/all-entities/tokens). Token format like "ey..." — never paste a raw PAN.'
                : 'Obtain via the Intuit hosted bank form. Token format like "ey..." — never paste raw account numbers.'
            }
          >
            <textarea
              value={token}
              onChange={(e) => setToken(e.target.value)}
              data-testid="qbo-payments-token"
              required
              rows={3}
              style={{ ...inputStyle, fontFamily: 'ui-monospace, monospace', fontSize: 12 }}
              placeholder="ey..."
            />
          </Field>

          {error && (
            <div data-testid="qbo-payments-error" style={errorStyle}>{error}</div>
          )}
          {result?.charge && (
            <div data-testid="qbo-payments-result" style={resultStyle(result.charge.status)}>
              <div><strong>Status:</strong> {result.charge.status}</div>
              <div><strong>Charge ID:</strong> {result.charge.id}</div>
              {result.payment_id && <div><strong>CoreFlux payment:</strong> #{result.payment_id} — allocated to invoice.</div>}
              {result.allocation_error && <div style={{ color: '#92400e' }}><strong>Allocation:</strong> {result.allocation_error}</div>}
            </div>
          )}

          <footer style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
            <button
              type="button"
              onClick={onClose}
              data-testid="qbo-payments-cancel"
              style={btnGhost}
            >Close</button>
            <button
              type="submit"
              disabled={busy}
              data-testid="qbo-payments-submit"
              style={{ ...btnPrimary, opacity: busy ? 0.6 : 1 }}
            >{busy ? 'Charging…' : `Charge $${Number(amount || 0).toFixed(2)}`}</button>
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
