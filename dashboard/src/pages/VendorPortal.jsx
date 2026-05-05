import React from 'react';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/**
 * Vendor self-service portal landing page.
 *
 * Auth: handled by the cf_vp_sid HttpOnly cookie set when the vendor
 * redeems the magic link at /api/ap/vendor_portal.php?action=redeem.
 * No platform-user session required. If the cookie is missing or expired,
 * the /api/ap/vendor_portal.php?action=me endpoint returns 401 and we
 * render the "request a fresh link" message.
 */
export default function VendorPortal() {
  const [data, setData]       = React.useState(null);
  const [loading, setLoading] = React.useState(true);
  const [err, setErr]         = React.useState(null);

  React.useEffect(() => {
    let cancelled = false;
    fetch('/api/ap/vendor_portal.php?action=me', { credentials: 'include' })
      .then(async (r) => {
        const j = await r.json().catch(() => ({}));
        if (cancelled) return;
        if (!r.ok) { setErr(j.error || `HTTP ${r.status}`); }
        else      { setData(j); }
      })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  if (loading) return <div style={{ padding: 40, textAlign: 'center' }}>Loading vendor portal…</div>;

  if (err) {
    return (
      <div data-testid="vendor-portal-no-session" style={{
        maxWidth: 540, margin: '60px auto', padding: 24,
        background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8,
      }}>
        <h2 style={{ margin: '0 0 8px' }}>Vendor portal</h2>
        <p>Your access link has expired or is invalid.</p>
        <p className="muted" style={{ fontSize: 13 }}>
          Please ask the AP team to send a new invitation to your registered email.
        </p>
      </div>
    );
  }

  const { vendor, bills = [], payments = [], session_expires_at } = data || {};
  const outstanding = bills.filter((b) => !['paid','void','cancelled'].includes((b.status || '').toLowerCase()))
                          .reduce((s, b) => s + Number(b.amount_total || 0), 0);

  return (
    <section
      data-testid="vendor-portal"
      style={{ maxWidth: 1100, margin: '20px auto', padding: 20 }}
    >
      <header style={{ marginBottom: 24 }}>
        <h1 style={{ margin: 0, fontSize: 26 }}>{vendor?.vendor_name || 'Vendor portal'}</h1>
        <p className="muted" style={{ margin: '4px 0 0', fontSize: 13 }}>
          Your bills and payments at a glance. Session expires {new Date(session_expires_at).toLocaleDateString()}.
        </p>
      </header>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 24 }}>
        <Stat label="Open bills"       value={bills.filter(b => b.status === 'pending_approval' || b.status === 'approved' || b.status === 'inbox').length} />
        <Stat label="Outstanding"      value={fmtMoney(outstanding)} />
        <Stat label="Payments to date" value={payments.length} />
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 8 }}>Bills</h2>
      {bills.length === 0 ? (
        <p className="muted" data-testid="vendor-portal-bills-empty">No bills on file yet.</p>
      ) : (
        <table className="data-table" data-testid="vendor-portal-bills-table">
          <thead>
            <tr>
              <th>Invoice #</th><th>Date</th><th>Due</th>
              <th style={{ textAlign: 'right' }}>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {bills.map((b) => (
              <tr key={b.id} data-testid={`vendor-portal-bill-${b.id}`}>
                <td><code>{b.invoice_number || '—'}</code></td>
                <td>{b.invoice_date}</td>
                <td>{b.due_date || '—'}</td>
                <td style={{ textAlign: 'right' }}>{fmtMoney(b.amount_total)}</td>
                <td><span className="badge">{b.status}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <h2 style={{ fontSize: 16, margin: '24px 0 8px' }}>Payments</h2>
      {payments.length === 0 ? (
        <p className="muted" data-testid="vendor-portal-payments-empty">No payments yet.</p>
      ) : (
        <table className="data-table" data-testid="vendor-portal-payments-table">
          <thead>
            <tr>
              <th>Date</th><th>Method</th>
              <th style={{ textAlign: 'right' }}>Amount</th>
              <th>Bill</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            {payments.map((p) => (
              <tr key={p.id} data-testid={`vendor-portal-payment-${p.id}`}>
                <td>{p.payment_date}</td>
                <td>{p.method || '—'}</td>
                <td style={{ textAlign: 'right' }}>{fmtMoney(p.amount)}</td>
                <td>{p.invoice_number ? <code>{p.invoice_number}</code> : <span className="muted">—</span>}</td>
                <td><span className="badge">{p.status}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function Stat({ label, value }) {
  return (
    <div style={{ padding: 12, background: '#f8fafc', border: '1px solid #e5e7eb', borderRadius: 8 }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase' }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 600 }}>{value}</div>
    </div>
  );
}
