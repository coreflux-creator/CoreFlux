import React, { useEffect, useState } from 'react';
import { useParams, Link, useSearchParams } from 'react-router-dom';
import { ArrowRight, BookOpenCheck, Check, Landmark, Send, UserRoundCog, X } from 'lucide-react';
import { api, useApi, bustApiCachePrefix } from '../../../dashboard/src/lib/api';
import EvidenceAttachments from '../../../dashboard/src/components/EvidenceAttachments';
import { addEntityScope } from '../../../dashboard/src/lib/useAccountingEntityScope';

const statusLabel = (value) => String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());

export default function InvoiceDetail() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const requestedScope = searchParams.get('entity_id');
  const requestedEntityId = requestedScope && /^[1-9][0-9]*$/.test(requestedScope)
    ? Number(requestedScope) : null;
  const listPath = addEntityScope('/modules/billing/invoices', requestedEntityId, requestedScope === 'all');
  const editPath = addEntityScope(`/modules/billing/invoices/${id}/edit`, requestedEntityId, requestedScope === 'all');
  const { data, loading, error, reload } = useApi(`/api/v1/billing/invoices?id=${id}`);
  const [busy, setBusy] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [sendTo, setSendTo] = useState('');
  const [showSend, setShowSend] = useState(false);
  const [showReassign, setShowReassign] = useState(false);
  const [showOtherReceipts, setShowOtherReceipts] = useState(false);
  const [selectedReviewers, setSelectedReviewers] = useState([]);
  const approval = useApi(
    data?.invoice?.status === 'draft' ? `/modules/billing/api/approval_assignment.php?invoice_id=${id}` : null,
    { enabled: data?.invoice?.status === 'draft' }
  );
  const canFindReceipts = ['approved', 'sent', 'partially_paid'].includes(data?.invoice?.status)
    && data?.invoice?.journal_status === 'posted'
    && Number(data?.invoice?.amount_due) > 0;
  const receipts = useApi(
    canFindReceipts ? `/modules/accounting/api/bank_statements.php?action=receipt_candidates&invoice_id=${id}` : null,
    { enabled: canFindReceipts }
  );

  useEffect(() => {
    const saved = data?.default_recipient?.email || '';
    if (saved) setSendTo((current) => current || saved);
  }, [data?.default_recipient?.email]);

  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error" data-testid="billing-invoice-detail-error">Error: {error.message}</p>;
  if (!data?.invoice) return <p>Not found.</p>;

  const inv = data.invoice;
  const lines = data.lines || [];
  const allocations = data.allocations || [];
  const token = data.token;
  const receiptRows = receipts.data?.rows || [];
  const likelyReceipts = receiptRows.filter(line => line.reference_match
    || Math.abs(Number(line.amount) - Number(inv.amount_due)) < 0.005);
  const otherReceipts = receiptRows.filter(line => !likelyReceipts.includes(line));
  const visibleReceipts = showOtherReceipts ? [...likelyReceipts, ...otherReceipts] : likelyReceipts;

  const approvalState = Number(approval.data?.invoice_id) === Number(id) ? approval.data : null;
  const canEdit = inv.status === 'draft' && approvalState && !approvalState.pending && lines.every((line) => line.source_type === 'manual');
  const canApprove = inv.status === 'draft' && approvalState?.viewer_can_approve;
  const canRequest = inv.status === 'draft' && approvalState?.viewer_can_request;
  const canSend = !inv.opening_cutover_id && inv.status === 'approved' && inv.journal_status === 'posted';
  const canPost = ['approved', 'sent', 'partially_paid', 'paid'].includes(inv.status) && !inv.journal_entry_id;
  const canVoid = inv.status === 'draft' && approvalState && !approvalState.pending
    && !inv.journal_entry_id
    && Number(inv.amount_paid || 0) === 0
    && allocations.length === 0;

  const run = async (label, fn) => {
    setBusy(label); setActionError(null);
    try {
      await fn();
      bustApiCachePrefix('billing-invoices-list:');
    } catch (e) { setActionError(e); }
    finally { await reload(); await approval.reload(); setBusy(null); }
  };

  const requestApproval = () => run('request', () => api.post(`/api/v1/billing/invoices?action=request_approval&id=${id}`, {}));
  const approve = () => run('approve', () => api.post(`/api/v1/billing/invoices?action=approve&id=${id}`, {}));
  const reject = () => {
    const reason = prompt('Reason for rejecting this invoice:');
    if (!reason?.trim()) return;
    run('reject', () => api.post(`/api/workflow.php?action=act&id=${approvalState.workflow_instance_id}`,
      { action: 'reject', comment: reason.trim() }));
  };
  const openReassign = () => {
    const eligible = new Set((approvalState?.eligible_reviewers || []).map((person) => Number(person.id)));
    setSelectedReviewers((approvalState?.assigned_reviewer_user_ids || []).filter((value) => eligible.has(Number(value))));
    setShowReassign(true);
  };
  const reassign = () => run('reassign', async () => {
    await api.post('/modules/billing/api/approval_assignment.php', {
      invoice_id: Number(id), reviewer_user_ids: selectedReviewers,
    });
    setShowReassign(false);
  });
  const post = () => run('post', () => api.post(`/api/v1/billing/invoices?action=post&id=${id}`, {}));
  const send    = () => run('send',    async () => {
    const res = await api.post(`/api/v1/billing/invoices?action=send&id=${id}`, { to: sendTo.trim() });
    setShowSend(false);
    if (res.email_status !== 'sent') alert(`Token created but email status: ${res.email_status} (${res.email_error || 'no detail'})`);
    if (res.pdf_attached === false && res.pdf_error) alert(`PDF could not be generated: ${res.pdf_error}\nEmail sent without attachment.`);
  });
  const applyReceipt = (line) => {
    const amount = Number(line.amount);
    const due = Number(inv.amount_due);
    if (!confirm(`Apply the ${inv.currency} ${amount.toFixed(2)} deposit from ${line.bank_account_name} to invoice ${inv.invoice_number} for ${inv.client_name}?\n\nBank description: ${line.description || 'None provided'}${line.reference_match ? '' : '\nThe bank description does not identify this invoice or client.'}`)) return;
    run(`receipt-${line.id}`, async () => {
      if (Math.abs(amount - due) < 0.005) {
        await api.post(`/modules/accounting/api/bank_statements.php?action=match_invoice&line_id=${line.id}`, { invoice_id: Number(id) });
      } else {
        await api.post(`/modules/accounting/api/bank_statements.php?action=split_match_invoices&line_id=${line.id}`, {
          allocations: [{ invoice_id: Number(id), amount }],
          account_splits: [],
        });
      }
      await receipts.reload();
    });
  };
  const previewPdf = () => {
    // Open the inline PDF in a new tab. The endpoint streams application/pdf
    // so the browser's built-in viewer takes over — no JS download needed.
    window.open(`/api/v1/billing/invoices?action=pdf&id=${id}`, '_blank', 'noopener');
  };
  const downloadPdf = () => {
    // Force the "Save as…" flow.
    window.location.href = `/api/v1/billing/invoices?action=pdf&id=${id}&download=1`;
  };
  const voidIt  = () => {
    const reason = prompt('Void reason:');
    if (!reason) return;
    run('void', () => api.post(`/api/v1/billing/invoices?action=void&id=${id}`, { reason }));
  };

  return (
    <section data-testid="billing-invoice-detail">
      <Link to={listPath} style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Invoices</Link>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12, marginTop: 8, marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2 style={{ margin: 0 }} data-testid="billing-invoice-detail-number">{inv.invoice_number}</h2>
          <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 14 }}>{inv.client_name} · issued {inv.issue_date} · due {inv.due_date}</p>
          <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 13 }} data-testid="billing-invoice-detail-entity">
            Issued by {inv.entity_name ? `${inv.entity_name} (${inv.entity_code})` : 'an unassigned legal entity'}
          </p>
          <span className={`badge badge--${inv.status}`}>{statusLabel(inv.status)}</span>
          {inv.opening_cutover_id && <p style={{ margin: '8px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Opening receivable. This is the unpaid balance brought forward; the original invoice and payment history were not imported.
          </p>}
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {canEdit && <Link className="btn btn--ghost" to={editPath} data-testid="billing-invoice-edit">Edit draft</Link>}
          {!inv.opening_cutover_id && <button className="btn btn--ghost" onClick={previewPdf} data-testid="billing-invoice-preview-pdf" title="Open PDF preview in a new tab">Preview PDF</button>}
          {!inv.opening_cutover_id && <button className="btn btn--ghost" onClick={downloadPdf} data-testid="billing-invoice-download-pdf" title="Download PDF">Download</button>}
          {canRequest && <button className="btn btn--primary" onClick={requestApproval} disabled={Boolean(busy)} data-testid="billing-invoice-request-approval"><Send size={15} aria-hidden="true" /> {busy==='request' ? 'Requesting…' : 'Request approval'}</button>}
          {canApprove && <button className="btn btn--primary" onClick={approve} disabled={Boolean(busy)} data-testid="billing-invoice-approve"><Check size={15} aria-hidden="true" /> {busy==='approve' ? 'Approving…' : 'Approve'}</button>}
          {canApprove && approvalState?.pending && <button className="btn btn--ghost" onClick={reject} disabled={Boolean(busy)} data-testid="billing-invoice-reject"><X size={15} aria-hidden="true" /> {busy==='reject' ? 'Rejecting…' : 'Reject'}</button>}
          {canSend && <button className="btn btn--primary" onClick={() => setShowSend(true)} data-testid="billing-invoice-send-open">Send</button>}
          {canPost && <button className="btn btn--ghost" onClick={post} disabled={busy==='post'} data-testid="billing-invoice-post">{busy==='post' ? 'Posting…' : 'Post to ledger'}</button>}
          {canVoid && <button className="btn btn--ghost" onClick={voidIt} disabled={busy==='void'} data-testid="billing-invoice-void">{busy==='void' ? 'Voiding…' : 'Void'}</button>}
        </div>
      </div>

      {actionError && <p className="error" data-testid="billing-invoice-action-error">Error: {actionError.message}</p>}
      {inv.status === 'draft' && approval.error && (
        <p className="error" role="alert" data-testid="billing-invoice-approval-error">Approval status could not load: {approval.error.message} <button className="btn btn--ghost" onClick={approval.reload}>Retry</button></p>
      )}
      {inv.status === 'draft' && approvalState?.pending && (
        <div data-testid="billing-invoice-approval-pending" style={{ borderTop: '1px solid var(--cf-border)', borderBottom: '1px solid var(--cf-border)', padding: '12px 0', marginBottom: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ fontSize: 13 }}>
            <strong style={{ color: 'var(--cf-primary, #0877f9)' }}>Awaiting approval</strong>
            <span style={{ color: 'var(--cf-text-secondary)', marginLeft: 8 }}>
              {approvalState.assigned_reviewers?.map((person) => person.name).join(', ') || 'No reviewer assigned'}
            </span>
            {!approvalState.approval_available && <div className="error" style={{ marginTop: 4 }}>Assigned reviewers no longer have access. Reassign this approval.</div>}
          </div>
          {approvalState.viewer_can_reassign && <button className="btn btn--ghost" onClick={openReassign} disabled={Boolean(busy)} data-testid="billing-invoice-reassign"><UserRoundCog size={15} aria-hidden="true" /> Reassign</button>}
        </div>
      )}
      {inv.status === 'draft' && approvalState?.approval_required && !approvalState?.pending && !approvalState?.approval_available && (
        <p className="error" role="alert" data-testid="billing-invoice-no-reviewer">This invoice needs an independent reviewer before approval can be requested.</p>
      )}
      {inv.status === 'draft' && approvalState?.prior_review_status && !approvalState?.pending && (
        <p className="error" role="alert" data-testid="billing-invoice-prior-review">
          {approvalState.prior_review_status === 'rejected'
            ? 'The previous review was rejected. Review the draft and request approval again.'
            : `The previous approval ${approvalState.prior_review_status.replaceAll('_', ' ')}. This draft cannot request another review.`}
          {approvalState.prior_review_note && <span style={{ display: 'block', marginTop: 4 }}>Reviewer note: {approvalState.prior_review_note}</span>}
        </p>
      )}
      {inv.journal_entry_id && inv.journal_status !== 'posted' && (
        <p className="error" data-testid="billing-invoice-ledger-error">This invoice points to a journal entry that is not posted. Review the ledger link before sending or applying a receipt.</p>
      )}

      {inv.journal_status === 'posted' && (
        <p style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, margin: '0 0 16px' }}>
          <BookOpenCheck size={16} aria-hidden="true" />
          Posted to the ledger
          <Link to={`/modules/accounting/journal-entries/${inv.journal_entry_id}`} data-testid="billing-invoice-journal-link">
            View journal entry <ArrowRight size={13} aria-hidden="true" />
          </Link>
        </p>
      )}

      {!canVoid && inv.status !== 'void' && (
        <p className="muted" style={{ fontSize: 12, margin: '0 0 16px' }} data-testid="billing-invoice-void-note">
          This invoice cannot be voided from here. Its approval, ledger, and payment activity must be corrected together.
        </p>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12, marginBottom: 'var(--cf-space-4)' }}>
        <SummaryBox label="Subtotal"   value={`${Number(inv.subtotal).toFixed(2)} ${inv.currency}`} />
        <SummaryBox label="Tax"        value={`${Number(inv.tax_total).toFixed(2)}`} />
        <SummaryBox label="Total"      value={`${Number(inv.total).toFixed(2)} ${inv.currency}`} highlight />
        <SummaryBox label="Paid"       value={`${Number(inv.amount_paid).toFixed(2)}`} />
        <SummaryBox label="Due"        value={inv.status === 'void' ? '—' : Number(inv.amount_due).toFixed(2)} highlight={inv.status !== 'void'} />
      </div>

      {token && (
        <div data-testid="billing-invoice-token-info" style={{ padding: 12, background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: 8, fontSize: 13, marginBottom: 16 }}>
          Public link issued {token.issued_at}. Viewed {token.view_count}× {token.last_viewed_at && `(last: ${token.last_viewed_at})`}.
          <a href={token.url} target="_blank" rel="noopener noreferrer" style={{ marginLeft: 8 }} data-testid="billing-invoice-token-link">Open</a>
        </div>
      )}

      <h3 style={{ margin: '24px 0 8px', fontSize: 14 }}>Line items</h3>
      <div style={{ maxWidth: '100%', overflowX: 'auto' }}>
      <table className="data-table" style={{ minWidth: 680 }} data-testid="billing-invoice-detail-lines">
        <thead><tr><th>#</th><th>Product / service</th><th>Description</th><th style={{textAlign:'right'}}>Qty</th><th>Unit</th><th style={{textAlign:'right'}}>Price</th><th style={{textAlign:'right'}}>Subtotal</th><th style={{textAlign:'right'}}>Tax</th><th style={{textAlign:'right'}}>Total</th></tr></thead>
        <tbody>
          {lines.map(l => (
            <tr key={l.id}>
              <td>{l.line_no}</td>
              <td>{l.catalog_item_name ? <><strong>{l.catalog_item_name}</strong><div style={{ fontSize: 11, color: 'var(--cf-text-muted)' }}>{l.catalog_item_code}</div></> : <span style={{ color: 'var(--cf-text-muted)' }}>Custom</span>}</td>
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
      </div>

      {allocations.length > 0 && (
        <>
          <h3 style={{ margin: '24px 0 8px', fontSize: 14 }}>Payments allocated</h3>
          <div style={{ maxWidth: '100%', overflowX: 'auto' }}>
          <table className="data-table" style={{ minWidth: 520 }} data-testid="billing-invoice-allocations">
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
          </div>
        </>
      )}

      {canFindReceipts && (
        <section style={{ marginTop: 24 }} data-testid="billing-invoice-receipts">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <h3 style={{ margin: 0, fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Landmark size={16} aria-hidden="true" /> Incoming bank receipts
            </h3>
            <Link to={addEntityScope('/modules/accounting/transactions-to-review', inv.entity_id)} className="btn btn--ghost" data-testid="billing-invoice-open-bank-feed">Open bank feed <ArrowRight size={14} aria-hidden="true" /></Link>
          </div>
          <p className="muted" style={{ fontSize: 12, margin: '6px 0 12px' }}>Review the deposit before applying it. A larger deposit can be split across invoices in the bank feed.</p>
          {receipts.loading && <p className="muted">Looking for deposits…</p>}
          {receipts.error && <p className="error">Could not load bank receipts: {receipts.error.message}</p>}
          {!receipts.loading && !receipts.error && likelyReceipts.length === 0 && (
            <p className="muted">No likely deposit matches were found. Check the bank feed for other incoming payments.</p>
          )}
          {otherReceipts.length > 0 && <button className="btn btn--ghost" type="button" onClick={() => setShowOtherReceipts(value => !value)} data-testid="billing-invoice-other-receipts-toggle">
            {showOtherReceipts ? 'Hide other deposits' : `Show ${otherReceipts.length} other recent deposit${otherReceipts.length === 1 ? '' : 's'}`}
          </button>}
          {visibleReceipts.length > 0 && (
            <div className="invoice-receipt-list" role="table" data-testid="billing-invoice-receipt-candidates">
              <div className="invoice-receipt-list__header" role="row">
                <span role="columnheader">Date</span><span role="columnheader">Bank account</span>
                <span role="columnheader">Description</span><span role="columnheader">Amount</span><span role="columnheader">Action</span>
              </div>
              {visibleReceipts.map(line => (
                <div className="invoice-receipt-list__row" role="row" key={line.id}>
                  <span className="invoice-receipt-list__date" role="cell">{line.posted_date}</span>
                  <span className="invoice-receipt-list__bank" role="cell">{line.bank_account_name}</span>
                  <span className="invoice-receipt-list__description" role="cell">{line.description}{line.reference_match
                    ? <span className="badge" style={{ marginLeft: 6 }}>Reference match</span>
                    : Math.abs(Number(line.amount) - Number(inv.amount_due)) < 0.005
                      ? <span className="badge" style={{ marginLeft: 6 }}>Amount only</span>
                      : <span className="badge" style={{ marginLeft: 6 }}>No match</span>}</span>
                  <span className="invoice-receipt-list__amount" role="cell">{Number(line.amount).toFixed(2)} {inv.currency}</span>
                  <span className="invoice-receipt-list__action" role="cell">
                    {likelyReceipts.includes(line) && line.can_apply_directly ? (
                      <button className="btn btn--primary" type="button" disabled={Boolean(busy)} onClick={() => applyReceipt(line)} data-testid={`billing-invoice-apply-receipt-${line.id}`}>
                        {busy === `receipt-${line.id}` ? 'Applying…' : Number(line.amount) < Number(inv.amount_due) ? 'Apply partial receipt' : 'Apply receipt'}
                      </button>
                    ) : (
                      <Link className="btn btn--ghost" to={addEntityScope(`/modules/accounting/bank-rec/${line.bank_account_id}?line_id=${line.id}`, inv.entity_id)}>{likelyReceipts.includes(line) ? 'Split in reconciliation' : 'Review bank line'}</Link>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </section>
      )}

      {showSend && (
        <div data-testid="billing-invoice-send-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && setShowSend(false)}>
          <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(420px, 100%)', padding: 24 }}>
            <h3 style={{ margin: '0 0 12px' }}>Send invoice</h3>
            <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
              Sends the PDF and public link from this invoice's legal entity.
              {data.default_recipient?.source ? ` Recipient loaded from ${data.default_recipient.source}.` : ' Save a default under Client contacts to avoid entering it again.'}
            </p>
            {!data.delivery_sender?.ready && <p className="error" role="alert">
              {data.delivery_sender?.reason || 'This invoice has no valid legal entity for delivery.'}{' '}
              <Link to={`/modules/billing/clients?entity_id=${inv.entity_id}`} onClick={() => setShowSend(false)}>Set up billing sender</Link>
            </p>}
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
              <button className="btn btn--primary" onClick={send} disabled={busy==='send' || !sendTo || !data.delivery_sender?.ready} data-testid="billing-invoice-send-confirm">
                {busy==='send' ? 'Sending…' : 'Send'}
              </button>
            </div>
          </div>
        </div>
      )}

      {showReassign && approvalState?.viewer_can_reassign && (
        <div role="dialog" aria-modal="true" aria-label="Reassign invoice approval" data-testid="billing-invoice-reassign-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(event) => event.target === event.currentTarget && setShowReassign(false)}>
          <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 8, width: 'min(440px, 100%)', maxHeight: '80vh', overflowY: 'auto', padding: 24 }}>
            <h3 style={{ margin: '0 0 8px' }}>Reassign approval</h3>
            <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--cf-text-secondary)' }}>Choose the people who may review this invoice. The change is recorded in its audit history.</p>
            <div style={{ display: 'grid', gap: 2 }}>
              {(approvalState.eligible_reviewers || []).map((person) => {
                const blocked = (approvalState.blocked_reviewer_user_ids || []).includes(Number(person.id));
                return <label key={person.id} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '9px 0', borderBottom: '1px solid var(--cf-border)', opacity: blocked ? 0.5 : 1 }}>
                  <input type="checkbox" checked={selectedReviewers.includes(Number(person.id))} disabled={blocked} onChange={() => setSelectedReviewers((current) => current.includes(Number(person.id)) ? current.filter((value) => value !== Number(person.id)) : [...current, Number(person.id)])} />
                  <span><strong>{person.name}</strong><small style={{ display: 'block', color: 'var(--cf-text-secondary)' }}>{person.email}{blocked ? ' · invoice preparer or requester' : ''}</small></span>
                </label>;
              })}
            </div>
            {actionError && <p className="error" role="alert">{actionError.message}</p>}
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
              <button className="btn btn--ghost" onClick={() => setShowReassign(false)} disabled={Boolean(busy)}>Cancel</button>
              <button className="btn btn--primary" onClick={reassign} disabled={Boolean(busy) || selectedReviewers.length === 0} data-testid="billing-invoice-reassign-save">{busy === 'reassign' ? 'Saving…' : 'Save reviewers'}</button>
            </div>
          </div>
        </div>
      )}

      <div style={{ marginTop: 16 }}>
        <EvidenceAttachments
          subjectType="billing_invoice"
          subjectId={inv.id}
          label="Supporting docs (timesheet bundles, customer PO, contracts)"
          documentType="supporting_doc"
          testidPrefix="billing-invoice-evidence"
        />
      </div>
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
