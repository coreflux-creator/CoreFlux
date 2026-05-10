import React, { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

export default function InvoiceDetail() {
  const { id } = useParams();
  const { data, loading, error, reload } = useApi(`/modules/billing/api/invoices.php?id=${id}`);
  const [busy, setBusy] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [sendTo, setSendTo] = useState('');
  const [showSend, setShowSend] = useState(false);

  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error" data-testid="billing-invoice-detail-error">Error: {error.message}</p>;
  if (!data?.invoice) return <p>Not found.</p>;

  const inv = data.invoice;
  const lines = data.lines || [];
  const allocations = data.allocations || [];
  const token = data.token;

  const canEdit = inv.status === 'draft';
  const canApprove = inv.status === 'draft';
  const canSend = inv.status === 'approved';
  const canVoid = inv.status !== 'void';

  const run = async (label, fn) => {
    setBusy(label); setActionError(null);
    try { await fn(); reload(); } catch (e) { setActionError(e); }
    finally { setBusy(null); }
  };

  const approve = () => run('approve', () => api.post(`/modules/billing/api/invoices.php?action=approve&id=${id}`, {}));
  const send    = () => run('send',    async () => {
    const res = await api.post(`/modules/billing/api/invoices.php?action=send&id=${id}`, { to: sendTo });
    setShowSend(false);
    if (res.email_status !== 'sent') alert(`Token created but email status: ${res.email_status} (${res.email_error || 'no detail'})`);
    if (res.pdf_attached === false && res.pdf_error) alert(`PDF could not be generated: ${res.pdf_error}\nEmail sent without attachment.`);
  });
  const previewPdf = () => {
    // Open the inline PDF in a new tab. The endpoint streams application/pdf
    // so the browser's built-in viewer takes over — no JS download needed.
    window.open(`/modules/billing/api/invoices.php?action=pdf&id=${id}`, '_blank', 'noopener');
  };
  const downloadPdf = () => {
    // Force the "Save as…" flow.
    window.location.href = `/modules/billing/api/invoices.php?action=pdf&id=${id}&download=1`;
  };
  const voidIt  = () => {
    const reason = prompt('Void reason:');
    if (!reason) return;
    run('void', () => api.post(`/modules/billing/api/invoices.php?action=void&id=${id}`, { reason }));
  };

  return (
    <section data-testid="billing-invoice-detail">
      <Link to="/modules/billing/invoices" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← All invoices</Link>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginTop: 8, marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2 style={{ margin: 0 }} data-testid="billing-invoice-detail-number">{inv.invoice_number}</h2>
          <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 14 }}>{inv.client_name} · issued {inv.issue_date} · due {inv.due_date}</p>
          <span className={`badge badge--${inv.status}`}>{inv.status.replace('_',' ')}</span>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn btn--ghost" onClick={previewPdf} data-testid="billing-invoice-preview-pdf" title="Open PDF preview in a new tab">Preview PDF</button>
          <button className="btn btn--ghost" onClick={downloadPdf} data-testid="billing-invoice-download-pdf" title="Download PDF">Download</button>
          {canApprove && <button className="btn btn--primary" onClick={approve} disabled={busy==='approve'} data-testid="billing-invoice-approve">{busy==='approve' ? 'Approving…' : 'Approve'}</button>}
          {canSend && <button className="btn btn--primary" onClick={() => setShowSend(true)} data-testid="billing-invoice-send-open">Send</button>}
          {canVoid && <button className="btn btn--ghost" onClick={voidIt} disabled={busy==='void'} data-testid="billing-invoice-void">{busy==='void' ? 'Voiding…' : 'Void'}</button>}
        </div>
      </div>

      {actionError && <p className="error" data-testid="billing-invoice-action-error">Error: {actionError.message}</p>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12, marginBottom: 'var(--cf-space-4)' }}>
        <SummaryBox label="Subtotal"   value={`${Number(inv.subtotal).toFixed(2)} ${inv.currency}`} />
        <SummaryBox label="Tax"        value={`${Number(inv.tax_total).toFixed(2)}`} />
        <SummaryBox label="Total"      value={`${Number(inv.total).toFixed(2)} ${inv.currency}`} highlight />
        <SummaryBox label="Paid"       value={`${Number(inv.amount_paid).toFixed(2)}`} />
        <SummaryBox label="Due"        value={`${Number(inv.amount_due).toFixed(2)}`} highlight />
      </div>

      {token && (
        <div data-testid="billing-invoice-token-info" style={{ padding: 12, background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: 8, fontSize: 13, marginBottom: 16 }}>
          Public link issued {token.issued_at}. Viewed {token.view_count}× {token.last_viewed_at && `(last: ${token.last_viewed_at})`}.
          <a href={token.url} target="_blank" rel="noopener noreferrer" style={{ marginLeft: 8 }} data-testid="billing-invoice-token-link">Open</a>
        </div>
      )}

      <h3 style={{ margin: '24px 0 8px', fontSize: 14 }}>Line items</h3>
      <table className="data-table" data-testid="billing-invoice-detail-lines">
        <thead><tr><th>#</th><th>Description</th><th style={{textAlign:'right'}}>Qty</th><th>Unit</th><th style={{textAlign:'right'}}>Price</th><th style={{textAlign:'right'}}>Subtotal</th><th style={{textAlign:'right'}}>Tax</th><th style={{textAlign:'right'}}>Total</th></tr></thead>
        <tbody>
          {lines.map(l => (
            <tr key={l.id}>
              <td>{l.line_no}</td>
              <td>{l.description}</td>
              <td style={{textAlign:'right'}}>{Number(l.quantity).toFixed(2)}</td>
              <td>{l.unit}</td>
              <td style={{textAlign:'right'}}>{Number(l.unit_price).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(l.subtotal).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(l.tax_amount).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(l.total).toFixed(2)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {allocations.length > 0 && (
        <>
          <h3 style={{ margin: '24px 0 8px', fontSize: 14 }}>Payments allocated</h3>
          <table className="data-table" data-testid="billing-invoice-allocations">
            <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th style={{textAlign:'right'}}>Applied</th></tr></thead>
            <tbody>
              {allocations.map((a, i) => (
                <tr key={i}>
                  <td>{a.received_at}</td>
                  <td>{a.method}</td>
                  <td>{a.reference || '—'}</td>
                  <td style={{textAlign:'right'}}>{Number(a.amount_applied).toFixed(2)} {inv.currency}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      {showSend && (
        <div data-testid="billing-invoice-send-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && setShowSend(false)}>
          <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(420px, 100%)', padding: 24 }}>
            <h3 style={{ margin: '0 0 12px' }}>Send invoice</h3>
            <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>Sends an email with a public link to view the invoice. Email will be sent from your tenant's configured Reply-To.</p>
            <input
              type="email"
              className="input"
              placeholder="recipient@client.com"
              value={sendTo}
              onChange={(e) => setSendTo(e.target.value)}
              data-testid="billing-invoice-send-to"
              style={{ width: '100%', marginTop: 12 }}
            />
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
              <button className="btn btn--ghost" onClick={() => setShowSend(false)} data-testid="billing-invoice-send-cancel">Cancel</button>
              <button className="btn btn--primary" onClick={send} disabled={busy==='send' || !sendTo} data-testid="billing-invoice-send-confirm">
                {busy==='send' ? 'Sending…' : 'Send'}
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}

function SummaryBox({ label, value, highlight }) {
  return (
    <div style={{ padding: 12, background: 'var(--cf-surface-alt, #f9fafb)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--cf-text-muted, #6b7280)' }}>{label}</div>
      <div style={{ fontSize: highlight ? 18 : 16, fontWeight: highlight ? 700 : 500, marginTop: 4 }}>{value}</div>
    </div>
  );
}
