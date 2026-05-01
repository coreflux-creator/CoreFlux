import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Inline "Add new vendor" dialog used from BillCreate's vendor picker when
 * the typed name doesn't match any existing vendor. Two paths:
 *
 *   • Hourly labor — full onboarding required. We refuse to create the
 *     vendor here and direct the user to People > New person, since
 *     hourly_labor must be backed by a person + placement chain.
 *
 *   • Service provider (default) — minimal: name + optional payment
 *     details + optional remit-to email. Created in one POST and returned
 *     to the caller so the bill form can use it immediately.
 */
export default function VendorQuickCreate({ initialName, onCreated, onCancel }) {
  const [category, setCategory] = useState('service_provider');
  const [name, setName]         = useState(initialName || '');
  const [vendorType, setVendorType] = useState('w9_business');
  const [paymentMethod, setPaymentMethod] = useState('');
  const [remitEmail, setRemitEmail] = useState('');
  const [remitPhone, setRemitPhone] = useState('');
  const [acctLast4, setAcctLast4]   = useState('');
  const [taxIdLast4, setTaxIdLast4] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    if (category === 'hourly_labor') {
      // Hard-block the shortcut; redirect to full onboarding.
      onCancel();
      window.location.href = '/modules/people/clients/new'; // user will route to People manually
      return;
    }
    setBusy(true); setErr(null);
    try {
      const body = {
        vendor_name: name.trim(),
        vendor_type: vendorType,
        vendor_category: 'service_provider',
        payment_method: paymentMethod || null,
        remit_to_email: remitEmail || null,
        remit_to_phone: remitPhone || null,
        payment_account_last4: acctLast4 || null,
        tax_id_full: taxIdLast4 || null,  // last4 stored if no full id supplied
      };
      const res = await api.post('/modules/ap/api/vendors.php', body);
      onCreated({
        id: res.company_id || res.id,
        vendor_id: res.id,
        name: name.trim(),
        company_id: res.company_id || null,
        vendor_category: 'service_provider',
      });
    } catch (e2) { setErr(e2); }
    finally     { setBusy(false); }
  };

  return (
    <div
      data-testid="vendor-quick-create"
      onClick={onCancel}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50 }}
    >
      <form
        onClick={(e) => e.stopPropagation()}
        onSubmit={submit}
        style={{ background: '#fff', padding: 24, borderRadius: 8, width: 'min(560px, 95vw)' }}
      >
        <h3 style={{ margin: '0 0 4px' }}>Add vendor</h3>
        <p style={{ margin: '0 0 16px', fontSize: 13, color: '#666' }}>
          Pick a category. <strong>Hourly labor</strong> must go through full onboarding (person + placement). <strong>Service provider</strong> only needs name + optional payment details.
        </p>

        <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
          {[
            { value: 'service_provider', label: 'Service provider', desc: 'Microsoft, AWS, utilities, SaaS' },
            { value: 'hourly_labor',     label: 'Hourly labor',     desc: 'W-2, 1099, C2C — needs onboarding' },
          ].map((opt) => (
            <label
              key={opt.value}
              data-testid={`vendor-quick-create-cat-${opt.value}`}
              style={{ flex: 1, padding: 12, border: `2px solid ${category === opt.value ? '#2563eb' : '#e5e7eb'}`, borderRadius: 6, cursor: 'pointer', background: category === opt.value ? '#eff6ff' : '#fff' }}
            >
              <input
                type="radio"
                name="vendor-category"
                value={opt.value}
                checked={category === opt.value}
                onChange={() => setCategory(opt.value)}
                style={{ marginRight: 6 }}
              />
              <strong>{opt.label}</strong>
              <p style={{ margin: '4px 0 0', fontSize: 12, color: '#666' }}>{opt.desc}</p>
            </label>
          ))}
        </div>

        {category === 'hourly_labor' ? (
          <div data-testid="vendor-quick-create-hourly-notice" style={{ padding: 12, background: '#fffbea', borderRadius: 6, border: '1px solid #fde68a', marginBottom: 16 }}>
            Hourly-labor vendors must be onboarded as a Person with a Placement (and a Placement Chain row if there's a vendor portal in between us and the client). Click <strong>Continue to onboarding</strong> to start that flow — your bill draft will be lost and you can come back to enter it once the vendor is fully set up.
          </div>
        ) : (
          <>
            <Field label="Vendor name *">
              <input className="input" value={name} onChange={(e) => setName(e.target.value)} data-testid="vendor-quick-create-name" required autoFocus />
            </Field>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
              <Field label="Type">
                <select className="input" value={vendorType} onChange={(e) => setVendorType(e.target.value)} data-testid="vendor-quick-create-type">
                  <option value="w9_business">W-9 business</option>
                  <option value="utility">Utility</option>
                  <option value="other">Other</option>
                </select>
              </Field>
              <Field label="Default payment method">
                <select className="input" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} data-testid="vendor-quick-create-payment-method">
                  <option value="">— pick later —</option>
                  <option value="ach">ACH</option>
                  <option value="wire">Wire</option>
                  <option value="check">Check</option>
                  <option value="card">Credit card</option>
                  <option value="plaid">Plaid</option>
                  <option value="other">Other</option>
                </select>
              </Field>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 8 }}>
              <Field label="Remit-to email (where to send invoices/payment notices)">
                <input className="input" type="email" value={remitEmail} onChange={(e) => setRemitEmail(e.target.value)} data-testid="vendor-quick-create-remit-email" />
              </Field>
              <Field label="Remit-to phone">
                <input className="input" value={remitPhone} onChange={(e) => setRemitPhone(e.target.value)} data-testid="vendor-quick-create-remit-phone" />
              </Field>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
              <Field label="Bank account last 4 (optional)">
                <input className="input" maxLength={4} value={acctLast4} onChange={(e) => setAcctLast4(e.target.value)} data-testid="vendor-quick-create-acct-last4" />
              </Field>
              <Field label="Tax ID last 4 (optional)">
                <input className="input" maxLength={4} value={taxIdLast4} onChange={(e) => setTaxIdLast4(e.target.value)} data-testid="vendor-quick-create-tax-last4" />
              </Field>
            </div>
          </>
        )}

        {err && <p className="error" data-testid="vendor-quick-create-error">Error: {err.message}</p>}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button type="button" className="btn btn--ghost" onClick={onCancel} data-testid="vendor-quick-create-cancel">Cancel</button>
          {category === 'hourly_labor' ? (
            <button type="submit" className="btn btn--primary" data-testid="vendor-quick-create-onboard">Continue to onboarding →</button>
          ) : (
            <button type="submit" className="btn btn--primary" data-testid="vendor-quick-create-submit" disabled={busy || !name.trim()}>
              {busy ? 'Creating…' : 'Create vendor'}
            </button>
          )}
        </div>
      </form>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', marginBottom: 10 }}>
      <span style={{ fontSize: '0.85em', color: '#555', marginBottom: 4 }}>{label}</span>
      {children}
    </label>
  );
}
