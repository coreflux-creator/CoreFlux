import React from 'react';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/**
 * Vendor self-service portal landing page.
 *
 * Auth: handled by the cf_vp_sid HttpOnly cookie set when the vendor
 * redeems the magic link at /api/ap/vendor_portal.php?action=redeem.
 * No platform-user session required.
 */
export default function VendorPortal() {
  const [data, setData]       = React.useState(null);
  const [loading, setLoading] = React.useState(true);
  const [err, setErr]         = React.useState(null);
  const [tab, setTab]         = React.useState('overview');
  const [reloadKey, setReloadKey] = React.useState(0);

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
  }, [reloadKey]);

  const reload = () => setReloadKey((k) => k + 1);

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

  const { vendor, bills = [], payments = [], documents = [], session_expires_at } = data || {};
  const outstanding = bills.filter((b) => !['paid','void','cancelled'].includes((b.status || '').toLowerCase()))
                          .reduce((s, b) => s + Number(b.amount_total || 0), 0);

  return (
    <section data-testid="vendor-portal" style={{ maxWidth: 1100, margin: '20px auto', padding: 20 }}>
      <header style={{ marginBottom: 24 }}>
        <h1 style={{ margin: 0, fontSize: 26 }}>{vendor?.vendor_name || 'Vendor portal'}</h1>
        <p className="muted" style={{ margin: '4px 0 0', fontSize: 13 }}>
          Your bills, payments, and documents. Session expires {new Date(session_expires_at).toLocaleDateString()}.
        </p>
      </header>

      <nav style={{ display: 'flex', gap: 16, borderBottom: '1px solid #e5e7eb', marginBottom: 16 }}>
        {[['overview','Overview'],['documents','Documents'],['banking','Banking']].map(([v, l]) => (
          <button
            key={v}
            type="button"
            onClick={() => setTab(v)}
            data-testid={`vendor-portal-tab-${v}`}
            style={{
              padding: '8px 12px', background: 'transparent', border: 'none',
              borderBottom: tab === v ? '2px solid #111' : '2px solid transparent',
              marginBottom: -1, fontWeight: tab === v ? 600 : 400, cursor: 'pointer',
            }}
          >{l}</button>
        ))}
      </nav>

      {tab === 'overview' && (
        <>
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
                  <th>Bill #</th><th>Date</th><th>Due</th>
                  <th style={{ textAlign: 'right' }}>Amount</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                {bills.map((b) => (
                  <tr key={b.id} data-testid={`vendor-portal-bill-${b.id}`}>
                    <td><code>{b.bill_number || '—'}</code></td>
                    <td>{b.bill_date}</td>
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
                  <th style={{ textAlign: 'right' }}>Amount</th><th>Bill</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((p) => (
                  <tr key={p.id} data-testid={`vendor-portal-payment-${p.id}`}>
                    <td>{p.payment_date}</td>
                    <td>{p.method || '—'}</td>
                    <td style={{ textAlign: 'right' }}>{fmtMoney(p.amount)}</td>
                    <td>{p.bill_number ? <code>{p.bill_number}</code> : <span className="muted">—</span>}</td>
                    <td><span className="badge">{p.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      )}

      {tab === 'documents' && <DocumentsTab documents={documents} reload={reload} />}
      {tab === 'banking'   && <BankingTab vendor={vendor} reload={reload} />}
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

function DocumentsTab({ documents, reload }) {
  const [docType, setDocType] = React.useState('w9');
  const [file, setFile]       = React.useState(null);
  const [busy, setBusy]       = React.useState(false);
  const [err, setErr]         = React.useState(null);
  const [success, setSuccess] = React.useState(false);

  const upload = async () => {
    if (!file) return;
    setBusy(true); setErr(null); setSuccess(false);
    try {
      // Step 1: get presigned POST
      const ur = await fetch('/api/ap/vendor_portal.php?action=upload_url', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ document_type: docType, file_name: file.name }),
      });
      const uj = await ur.json();
      if (!ur.ok) throw new Error(uj.error || `HTTP ${ur.status}`);

      // Step 2: POST file to presigned URL
      const presigned = uj.presigned;
      const fd = new FormData();
      Object.entries(presigned.fields || {}).forEach(([k, v]) => fd.append(k, v));
      fd.append('file', file);
      const upr = await fetch(presigned.form_action, { method: 'POST', body: fd });
      if (!upr.ok && upr.status !== 204) throw new Error('Upload failed');

      // Step 3: register document
      const rr = await fetch('/api/ap/vendor_portal.php?action=upload_document', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ storage_key: uj.storage_key, document_type: docType, file_name: file.name }),
      });
      const rj = await rr.json();
      if (!rr.ok) throw new Error(rj.error || `HTTP ${rr.status}`);
      setSuccess(true); setFile(null);
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="vendor-portal-documents">
      <h2 style={{ fontSize: 16, marginBottom: 8 }}>Upload a document</h2>
      <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 16 }}>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Type</label>
          <select className="input" value={docType} onChange={(e) => setDocType(e.target.value)} data-testid="vendor-portal-doc-type">
            <option value="w9">W-9</option>
            <option value="coi">Certificate of insurance</option>
            <option value="banking_form">Banking form</option>
            <option value="contract">Contract</option>
            <option value="other">Other</option>
          </select>
        </div>
        <input type="file" onChange={(e) => setFile(e.target.files?.[0] || null)} data-testid="vendor-portal-doc-file" />
        <button type="button" className="btn btn--primary" onClick={upload} disabled={busy || !file} data-testid="vendor-portal-doc-upload">
          {busy ? 'Uploading…' : 'Upload'}
        </button>
      </div>
      {err && <p className="error" data-testid="vendor-portal-doc-error">{err}</p>}
      {success && <p className="muted" data-testid="vendor-portal-doc-success">Uploaded — pending AP review.</p>}

      <h3 style={{ fontSize: 14, marginBottom: 8 }}>Your uploaded documents</h3>
      {documents.length === 0 ? (
        <p className="muted" data-testid="vendor-portal-doc-empty">No documents uploaded yet.</p>
      ) : (
        <table className="data-table" data-testid="vendor-portal-doc-table">
          <thead><tr><th>Type</th><th>File</th><th>Status</th><th>Uploaded</th></tr></thead>
          <tbody>
            {documents.map((d) => (
              <tr key={d.id} data-testid={`vendor-portal-doc-row-${d.id}`}>
                <td>{d.document_type}</td>
                <td>{d.file_name}</td>
                <td><span className="badge">{d.status}</span></td>
                <td>{d.uploaded_at}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function BankingTab({ vendor, reload }) {
  const [remitEmail, setRemitEmail] = React.useState(vendor?.remit_to_email || '');
  const [remitPhone, setRemitPhone] = React.useState(vendor?.remit_to_phone || '');
  const [payMethod, setPayMethod]   = React.useState(vendor?.payment_method || '');
  const [acctType, setAcctType]     = React.useState('');
  const [acctFull, setAcctFull]     = React.useState('');
  const [routFull, setRoutFull]     = React.useState('');
  const [busy, setBusy]             = React.useState(false);
  const [err, setErr]               = React.useState(null);
  const [success, setSuccess]       = React.useState(false);

  const save = async () => {
    setBusy(true); setErr(null); setSuccess(false);
    try {
      const r = await fetch('/api/ap/vendor_portal.php?action=update_banking', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          remit_to_email: remitEmail || null,
          remit_to_phone: remitPhone || null,
          payment_method: payMethod || null,
          payment_account_type: acctType || null,
          payment_account_full: acctFull || null,
          payment_routing_full: routFull || null,
        }),
      });
      const j = await r.json();
      if (!r.ok) throw new Error(j.error || `HTTP ${r.status}`);
      setSuccess(true); setAcctFull(''); setRoutFull('');
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="vendor-portal-banking">
      <h2 style={{ fontSize: 16, marginBottom: 8 }}>Remittance + banking</h2>
      <p className="muted" style={{ fontSize: 12, marginBottom: 16 }}>
        Update where you'd like remittance advices sent and how you'd like to be paid.
        Account numbers are encrypted at rest; only the last 4 digits are shown to AP.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Remittance email</label>
          <input className="input" type="email" value={remitEmail} onChange={(e) => setRemitEmail(e.target.value)} data-testid="vendor-portal-banking-email" style={{ width: '100%' }} />
        </div>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Phone</label>
          <input className="input" value={remitPhone} onChange={(e) => setRemitPhone(e.target.value)} data-testid="vendor-portal-banking-phone" style={{ width: '100%' }} />
        </div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Preferred method</label>
          <select className="input" value={payMethod} onChange={(e) => setPayMethod(e.target.value)} data-testid="vendor-portal-banking-method" style={{ width: '100%' }}>
            <option value="">—</option>
            <option value="ach">ACH</option><option value="wire">Wire</option>
            <option value="check">Check</option><option value="card">Card</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Account type</label>
          <select className="input" value={acctType} onChange={(e) => setAcctType(e.target.value)} data-testid="vendor-portal-banking-acct-type" style={{ width: '100%' }}>
            <option value="">—</option>
            <option value="checking">Checking</option>
            <option value="savings">Savings</option>
          </select>
        </div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Routing # (replace existing)</label>
          <input className="input" value={routFull} onChange={(e) => setRoutFull(e.target.value)} data-testid="vendor-portal-banking-routing" style={{ width: '100%' }} placeholder="9 digits" />
        </div>
        <div>
          <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Account # (replace existing)</label>
          <input className="input" value={acctFull} onChange={(e) => setAcctFull(e.target.value)} data-testid="vendor-portal-banking-account" style={{ width: '100%' }} placeholder={vendor?.payment_account_last4 ? `currently ••${vendor.payment_account_last4}` : ''} />
        </div>
      </div>
      {err && <p className="error" data-testid="vendor-portal-banking-error">{err}</p>}
      {success && <p className="muted" data-testid="vendor-portal-banking-success">Saved. AP has been notified.</p>}
      <button type="button" className="btn btn--primary" onClick={save} disabled={busy} data-testid="vendor-portal-banking-save">
        {busy ? 'Saving…' : 'Save banking info'}
      </button>
    </div>
  );
}
