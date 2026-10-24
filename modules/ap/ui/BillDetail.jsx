import React, { useEffect, useRef, useState } from 'react';
import { useParams, Link, useSearchParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';
import IntercompanySplitDialog from '../../../dashboard/src/components/IntercompanySplitDialog';
import EvidenceAttachments from '../../../dashboard/src/components/EvidenceAttachments';
import ThreeWayMatchPanel from './ThreeWayMatchPanel';
import BillApprovalThread from './BillApprovalThread';
import { directBillAccountChoices, generatedBillAccountChoices } from './directBillAccounts';
import { formatDocumentMeasure } from '../../../dashboard/src/lib/documentTotals';
import { Pencil, RotateCcw, X } from 'lucide-react';

const statusLabel = (value) => String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());

export default function BillDetail({ session }) {
  const { id } = useParams();
  const [params, setParams] = useSearchParams();
  const requestedEntityId = params.get('entity_id');
  const attachmentWarning = params.has('attach_error');
  const returnParams = new URLSearchParams();
  if (requestedEntityId) returnParams.set('entity_id', requestedEntityId);
  if (params.get('status')) returnParams.set('status', params.get('status'));
  const { data, loading, error, reload } = useApi(`/modules/ap/api/bills.php?id=${id}`);
  const accountsApi = useApi('/modules/accounting/api/accounts.php?active=1&include_required_dimensions=1');
  const [busy, setBusy] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [threadRevision, setThreadRevision] = useState(0);
  const [icSplitOpen, setIcSplitOpen] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState(null);
  const [codingOpen, setCodingOpen] = useState(false);
  const [codingByLine, setCodingByLine] = useState({});
  const [correctionOpen, setCorrectionOpen] = useState(false);
  const [correctionReason, setCorrectionReason] = useState('');
  const [sourceLine, setSourceLine] = useState(null);

  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error" data-testid="ap-bill-detail-error">Error: {error.message}</p>;
  if (!data?.bill) return <p>Not found.</p>;

  const bill = data.bill;
  const lines = data.lines || [];
  const standaloneAccounting = session?.product_mode === 'coreaccounting';
  const allocations = data.allocations || [];
  const generatedCoding = bill.coding_mode === 'generated';
  const expenseAccounts = generatedCoding
    ? generatedBillAccountChoices(accountsApi.data?.rows ?? [])
    : directBillAccountChoices(accountsApi.data?.rows ?? []);
  const eligibleCodes = new Set(expenseAccounts.filter(account => !account.disabled).map(account => account.code));
  const codingInvalid = lines.some(line => {
    const selected = codingByLine[line.id] ?? '';
    return (generatedCoding && selected === '') || (selected !== '' && !eligibleCodes.has(selected));
  });
  const uncodedGenerated = generatedCoding && lines.some(line => !line.gl_expense_account_code);

  const createdByCurrentUser = Number(session?.user?.id) > 0
    && Number(bill.created_by_user_id) === Number(session.user.id);
  const canApprove = Boolean(data.approval?.can_approve);
  const hasAccountingActivity = Boolean(bill.journal_entry_id)
    || Number(bill.amount_paid) > 0.005 || allocations.length > 0;
  const canDispute = ['pending_review','pending_approval','approved'].includes(bill.status)
    && !hasAccountingActivity;
  const canVoid    = bill.status !== 'void' && !hasAccountingActivity
    && !['partially_paid', 'paid'].includes(bill.status);
  const canPost    = ['approved','partially_paid','paid'].includes(bill.status) && !bill.journal_entry_id;
  const canEdit    = ['inbox','pending_review','pending_approval'].includes(bill.status)
    && !hasAccountingActivity;
  const canCorrect = Boolean(bill.correction_available && bill.correction_permitted);
  const canEditCoding = Boolean(bill.coding_permitted);

  const run = async (label, fn) => {
    setBusy(label); setActionError(null);
    try { await fn(); reload(); setThreadRevision(value => value + 1); } catch (e) { setActionError(e); }
    finally { setBusy(null); }
  };

  const approve = () => run('approve', () => api.post(`/modules/ap/api/bills.php?action=approve&id=${id}`, {}));
  const voidIt  = () => {
    const reason = prompt('Void reason:');
    if (!reason) return;
    run('void', () => api.post(`/modules/ap/api/bills.php?action=void&id=${id}`, { reason }));
  };
  const dispute = () => {
    const reason = prompt('Dispute reason:');
    if (!reason) return;
    run('dispute', () => api.post(`/modules/ap/api/bills.php?action=dispute&id=${id}`, { reason }));
  };
  const postGl  = () => run('post', () => api.post(`/modules/ap/api/bills.php?action=post&id=${id}`, {}));
  const correctPosted = (event) => {
    event.preventDefault();
    if (!correctionReason.trim()) return;
    run('correct', async () => {
      await api.post(`/modules/ap/api/bills.php?action=correct_posted&id=${id}`, { reason: correctionReason.trim() });
      setCorrectionOpen(false);
      setCorrectionReason('');
    });
  };
  const startEdit = () => {
    setEditForm({
      bill_number: bill.bill_number || '',
      bill_date: bill.bill_date || '',
      due_date: bill.due_date || '',
      po_number: bill.po_number || '',
      notes_internal: bill.notes_internal || '',
    });
    setActionError(null);
    setEditing(true);
  };
  const saveDetails = (event) => {
    event.preventDefault();
    run('edit', async () => {
      await api.patch(`/modules/ap/api/bills.php?id=${id}`, editForm);
      setEditing(false);
    });
  };
  const startCoding = () => {
    setCodingByLine(Object.fromEntries(lines.map(line => [line.id, line.gl_expense_account_code || ''])));
    setActionError(null);
    setCodingOpen(true);
  };
  const saveCoding = (event) => {
    event.preventDefault();
    if (accountsApi.loading || accountsApi.error || codingInvalid) return;
    run('coding', async () => {
      await api.patch(`/modules/ap/api/bills.php?action=coding&id=${id}`, {
        lines: lines.map(line => ({
          line_id: Number(line.id),
          expected_gl_expense_account_code: line.gl_expense_account_code || null,
          gl_expense_account_code: codingByLine[line.id] || null,
        })),
      });
      setCodingOpen(false);
    });
  };

  return (
    <section data-testid="ap-bill-detail">
      <Link to={`/modules/ap/bills${returnParams.size ? `?${returnParams}` : ''}`} style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Bills</Link>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginTop: 8, marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h2 style={{ margin: 0 }} data-testid="ap-bill-detail-ref">{bill.internal_ref}</h2>
          <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            {bill.vendor_name} ({bill.vendor_type}) · billed {bill.bill_date} · due {bill.due_date}
            {bill.bill_number !== bill.internal_ref ? ` · vendor ref ${bill.bill_number}` : ''}
          </p>
          <span className={`badge badge--${bill.status}`}>{statusLabel(bill.status)}</span>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {canEdit && <button className="btn btn--ghost" onClick={startEdit} disabled={busy === 'edit'} data-testid="ap-bill-edit"><Pencil size={15} aria-hidden="true" /> Edit details</button>}
          {canEditCoding && !codingOpen && <button className="btn btn--ghost" type="button" onClick={startCoding} data-testid="ap-bill-edit-coding"><Pencil size={15} aria-hidden="true" /> Edit coding</button>}
          {canApprove && <button className="btn btn--primary" onClick={approve} disabled={busy==='approve'} data-testid="ap-bill-approve">{busy==='approve' ? 'Approving…' : 'Approve'}</button>}
          {canPost    && <button className="btn btn--ghost" onClick={postGl} disabled={busy==='post' || codingOpen || uncodedGenerated} data-testid="ap-bill-post">{busy==='post' ? 'Posting…' : 'Post to GL'}</button>}
          {canPost    && <button className="btn btn--ghost" onClick={() => setIcSplitOpen(true)} disabled={codingOpen || uncodedGenerated} data-testid="ap-bill-post-ic-split">⊕ Post with IC split</button>}
          {canCorrect && <button className="btn btn--ghost" type="button" onClick={() => setCorrectionOpen(true)} disabled={busy === 'correct'} aria-expanded={correctionOpen} data-testid="ap-bill-correct-posted"><RotateCcw size={15} aria-hidden="true" /> Correct posted bill</button>}
          {canDispute && <button className="btn btn--ghost" onClick={dispute} disabled={busy==='dispute'} data-testid="ap-bill-dispute">{busy==='dispute' ? 'Disputing…' : 'Dispute'}</button>}
          {canVoid    && <button className="btn btn--ghost" onClick={voidIt} disabled={busy==='void'} data-testid="ap-bill-void">{busy==='void' ? 'Voiding…' : 'Void'}</button>}
        </div>
      </div>

      {attachmentWarning && (
        <div className="error" role="alert" data-testid="ap-bill-attachment-warning" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ flex: 1 }}>Bill saved, but its PDF was not attached. <a href="#ap-bill-evidence-section">Upload it under supporting documents.</a></span>
          <button type="button" className="btn btn--ghost" aria-label="Dismiss attachment warning" title="Dismiss attachment warning" onClick={() => {
            const next = new URLSearchParams(params);
            next.delete('attach_error');
            setParams(next, { replace: true });
          }}><X size={15} aria-hidden="true" /></button>
        </div>
      )}
      {createdByCurrentUser && ['pending_review','pending_approval'].includes(bill.status) && (
        <p className="operational-state" data-testid="ap-bill-two-eye-note">Another authorized user must approve this bill.</p>
      )}
      {!createdByCurrentUser && !canApprove && data.approval?.reason && (
        <p className="operational-state" data-testid="ap-bill-approval-note">{data.approval.reason}</p>
      )}
      {hasAccountingActivity && bill.status !== 'void' && (
        <p className="operational-state" data-testid="ap-bill-correction-note">
          {canCorrect ? 'This unpaid manual bill can be corrected with a linked journal reversal.' : (bill.correction_unavailable_reason || 'Posted bills need a linked accounting correction; paid bills also need a payment correction. They cannot be voided here.')}
        </p>
      )}
      {bill.journal_entry_id && (
        <p className="operational-state" data-testid="ap-bill-journal-links">
          <Link to={`/modules/accounting/journal-entries/${bill.journal_entry_id}`}>Original journal</Link>
          {bill.reversal_journal_entry_id && <> · <Link to={`/modules/accounting/journal-entries/${bill.reversal_journal_entry_id}`}>Reversal journal</Link></>}
          {bill.status === 'void' && bill.void_reason && <> · Correction: {bill.void_reason}</>}
        </p>
      )}
      {canCorrect && correctionOpen && (
        <form onSubmit={correctPosted} className="operational-state" data-testid="ap-bill-correction-form" style={{ marginBottom: 'var(--cf-space-4)' }}>
          <p style={{ margin: '0 0 8px' }}>
            This will void the bill and post a linked reversal for its original journal. The bill and both journals remain in the audit trail.
          </p>
          <label htmlFor="ap-bill-correction-reason">Correction reason</label>
          <textarea
            id="ap-bill-correction-reason"
            className="input"
            required
            value={correctionReason}
            onChange={event => setCorrectionReason(event.target.value)}
            style={{ display: 'block', width: '100%', maxWidth: 600, marginTop: 6 }}
          />
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button className="btn btn--primary" type="submit" disabled={busy === 'correct' || !correctionReason.trim()}>
              {busy === 'correct' ? 'Correcting…' : 'Void bill and reverse journal'}
            </button>
            <button className="btn btn--ghost" type="button" onClick={() => { setCorrectionOpen(false); setCorrectionReason(''); }} disabled={busy === 'correct'}>Cancel</button>
          </div>
        </form>
      )}
      {editing && editForm && (
        <form onSubmit={saveDetails} data-testid="ap-bill-edit-form" style={{ marginBottom: 'var(--cf-space-4)' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
            <label>Vendor bill #<input className="input" value={editForm.bill_number} onChange={e => setEditForm({ ...editForm, bill_number: e.target.value })} /></label>
            <label>Bill date<input className="input" type="date" required value={editForm.bill_date} onChange={e => setEditForm({ ...editForm, bill_date: e.target.value })} /></label>
            <label>Due date<input className="input" type="date" required value={editForm.due_date} onChange={e => setEditForm({ ...editForm, due_date: e.target.value })} /></label>
            <label>PO #<input className="input" value={editForm.po_number} onChange={e => setEditForm({ ...editForm, po_number: e.target.value })} /></label>
          </div>
          <label>Internal notes<textarea className="input" value={editForm.notes_internal} onChange={e => setEditForm({ ...editForm, notes_internal: e.target.value })} /></label>
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <button className="btn btn--primary" type="submit" disabled={busy === 'edit'}>{busy === 'edit' ? 'Saving…' : 'Save changes'}</button>
            <button className="btn btn--ghost" type="button" onClick={() => setEditing(false)} disabled={busy === 'edit'}>Cancel</button>
          </div>
        </form>
      )}

      {bill.status === 'disputed' && bill.dispute_reason && (
        <p className="error" data-testid="ap-bill-disputed-reason" style={{ marginBottom: 12 }}>Disputed: {bill.dispute_reason}</p>
      )}
      {(bill.pwp_status === 'awaiting_ar' || bill.pwp_status === 'triggered' || (bill.payment_terms || '').startsWith('PWP')) && (
        <div
          data-testid="ap-bill-pwp-banner"
          style={{
            marginBottom: 12, padding: 10,
            background: bill.pwp_status === 'triggered' ? '#ecfdf5' : '#fef3c7',
            border: '1px solid ' + (bill.pwp_status === 'triggered' ? '#a7f3d0' : '#fde68a'),
            borderRadius: 6, fontSize: 13,
          }}
        >
          <strong>Pay-When-Paid · {bill.payment_terms || 'PWP'}</strong> —{' '}
          {bill.pwp_status === 'triggered' ? (
            <span data-testid="ap-bill-pwp-released">
              ✓ Released {bill.pwp_released_at ? new Date(bill.pwp_released_at).toLocaleString() : ''}
              {bill.linked_ar_invoice_id && (
                <> · AR <Link to={`/modules/billing/invoices/${bill.linked_ar_invoice_id}`} data-testid="ap-bill-pwp-ar-link">#{bill.linked_ar_invoice_id}</Link> paid in full</>
              )}
              {['inbox', 'pending_review', 'pending_approval'].includes(bill.status) && (
                <> · Bill approval still required</>
              )}
            </span>
          ) : bill.pwp_status === 'awaiting_ar' ? (
            <span data-testid="ap-bill-pwp-awaiting">
              ⚠ Vendor disbursement blocked until AR{' '}
              {bill.linked_ar_invoice_id && (
                <Link to={`/modules/billing/invoices/${bill.linked_ar_invoice_id}`} data-testid="ap-bill-pwp-ar-link">#{bill.linked_ar_invoice_id}</Link>
              )} is paid by the client (4-way match gate).
            </span>
          ) : (
            <span>{bill.pwp_status}</span>
          )}
        </div>
      )}
      {actionError && <p className="error" data-testid="ap-bill-action-error">Error: {actionError.message}</p>}
      {uncodedGenerated && <p className="operational-state" data-testid="ap-bill-coding-required">
        This generated bill needs a direct-cost account on every line before it can post.
        {canEditCoding ? ' Use Edit coding to finish it.' : ' Ask an authorized AP user to finish its coding.'}
      </p>}

      {icSplitOpen && (
        <IntercompanySplitDialog
          open={icSplitOpen}
          onClose={() => setIcSplitOpen(false)}
          onPosted={() => { setIcSplitOpen(false); reload(); }}
          amount={Number(bill.total)}
          sourceEntityId={Number(bill.entity_id)}
          sourceOffsetAccountCode="2000"
          sourceOffsetSide="credit"
          defaultMemo={`AP Bill ${bill.internal_ref} / ${bill.vendor_name}`}
          postUrl={`/modules/ap/api/bills.php?action=post_with_ic_split&id=${id}`}
        />
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12, marginBottom: 'var(--cf-space-4)' }}>
        <SummaryBox label="Subtotal"  value={`${Number(bill.subtotal).toFixed(2)} ${bill.currency}`} />
        <SummaryBox label="Tax"       value={`${Number(bill.tax_total).toFixed(2)}`} />
        <SummaryBox label="Total"     value={`${Number(bill.total).toFixed(2)} ${bill.currency}`} highlight />
        <SummaryBox label="Paid"      value={`${Number(bill.amount_paid).toFixed(2)}`} />
        {Number(bill.amount_credited) !== 0 && <SummaryBox label="Credits applied" value={Number(bill.amount_credited || 0).toFixed(2)} />}
        <SummaryBox label="Due"       value={`${Number(bill.amount_due).toFixed(2)}`} highlight />
      </div>

      {/* P0 — Inline liquidity projection. Only shown when the bill still has
          a balance due so the panel is meaningful. */}
      {bill.status !== 'void' && Number(bill.amount_due) > 0 && (
        <LiquidityImpactPanel billId={id} amountDue={Number(bill.amount_due)} />
      )}

      {bill.po_number && <ThreeWayMatchPanel billId={id} />}
      <BillApprovalThread key={`${id}:${threadRevision}`} billId={id} />

      <h3 style={{ fontSize: 14, margin: '0 0 8px' }}>Lines</h3>
      {codingOpen && <form id="ap-bill-coding-form" onSubmit={saveCoding} data-testid="ap-bill-coding-form" style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginBottom: 10 }}>
        <span style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>Change expense accounts only. Approved amounts stay the same.</span>
        <button className="btn btn--primary" type="submit" disabled={busy === 'coding' || accountsApi.loading || Boolean(accountsApi.error) || codingInvalid} data-testid="ap-bill-save-coding">{busy === 'coding' ? 'Saving…' : 'Save coding'}</button>
        <button className="btn btn--ghost" type="button" onClick={() => setCodingOpen(false)} disabled={busy === 'coding'}>Cancel</button>
        {accountsApi.error && <span className="error" role="alert">Accounts unavailable: {accountsApi.error.message}</span>}
        {codingInvalid && <span className="error" role="alert">{generatedCoding ? 'Choose an active direct-cost account for every line.' : 'Choose an account available for a direct bill.'}</span>}
      </form>}
      <table className="data-table" data-testid="ap-bill-lines" style={{ marginBottom: 'var(--cf-space-4)' }}>
        <thead><tr><th>#</th><th>Description</th><th style={{textAlign:'right'}}>Qty</th><th>Unit</th><th style={{textAlign:'right'}}>Unit $</th><th style={{textAlign:'right'}}>Subtotal</th><th style={{textAlign:'right'}}>Total</th><th>GL</th><th>1099?</th><th>Receipt</th></tr></thead>
        <tbody>
          {lines.map(l => (
            <tr key={l.id} data-testid={`ap-bill-line-${l.id}`}>
              <td>{l.line_no}</td>
              <td>
                {l.description}
                {(l.source_type === 'time_entry' || l.source_type === 'time') && l.source_ref_id && l.source_work_date && (
                  standaloneAccounting ? (
                    <button
                      type="button"
                      onClick={() => setSourceLine(l)}
                      data-testid={`ap-bill-line-time-entry-${l.id}`}
                      style={{ display: 'block', marginTop: 4, padding: 0, border: 0, background: 'none', color: 'var(--cf-primary)', fontSize: 12, textDecoration: 'underline', cursor: 'pointer' }}
                    >
                      ↳ time entry #{l.source_ref_id}
                    </button>
                  ) : l.source_timesheet_id ? (
                    <Link
                      to={`/modules/staffing/timesheets/${l.source_timesheet_id}/lifecycle?entry_id=${l.source_ref_id}`}
                      data-testid={`ap-bill-line-time-entry-${l.id}`}
                      style={{ fontSize: 11, marginLeft: 4, color: '#6b7280' }}
                      title={`Trace back to time entry #${l.source_ref_id} (${l.source_work_date})`}
                    >
                      ↳ time entry #{l.source_ref_id}
                    </Link>
                  ) : null
                )}
              </td>
              <td style={{textAlign:'right'}}>{formatDocumentMeasure(l.quantity)}</td>
              <td>{l.unit}</td>
              <td style={{textAlign:'right'}}>{formatDocumentMeasure(l.unit_price)}</td>
              <td style={{textAlign:'right'}}>{Number(l.subtotal).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(l.total).toFixed(2)}</td>
              <td>{codingOpen ? <select
                className="input"
                aria-label={`Expense account for line ${l.line_no}`}
                value={codingByLine[l.id] ?? ''}
                onChange={event => setCodingByLine(current => ({ ...current, [l.id]: event.target.value }))}
                data-testid={`ap-bill-line-${l.id}-coding`}
                style={{ minWidth: 185 }}
              >
                <option value="">{generatedCoding ? 'Choose direct-cost account' : 'Default expense (6990)'}</option>
                {l.gl_expense_account_code && !expenseAccounts.some(account => account.code === l.gl_expense_account_code) &&
                  <option value={l.gl_expense_account_code} disabled>{l.gl_expense_account_code} (unavailable)</option>}
                {expenseAccounts.map(account => <option key={account.code} value={account.code} disabled={account.disabled}>
                  {account.code} {account.name}{account.unavailable_reason ? ` (${account.unavailable_reason})` : ''}
                </option>)}
              </select> : (l.gl_expense_account_code || (generatedCoding ? 'Not coded' : '6990 default'))}</td>
              <td>{Number(l.is_1099_eligible) ? 'Yes' : 'No'}</td>
              <td><LineReceiptCell line={l} /></td>
            </tr>
          ))}
        </tbody>
      </table>
      {sourceLine && <TimeEntrySourceDialog line={sourceLine} onClose={() => setSourceLine(null)} />}

      <h3 style={{ fontSize: 14, margin: '0 0 8px' }}>Payments</h3>
      {allocations.length === 0 ? (
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }} data-testid="ap-bill-no-payments">No payments allocated yet.</p>
      ) : (
        <table className="data-table" data-testid="ap-bill-allocations">
          <thead><tr><th>Payment</th><th>Date</th><th>Method</th><th>Ref</th><th style={{textAlign:'right'}}>Applied</th><th>Status</th></tr></thead>
          <tbody>
            {allocations.map((a, i) => (
              <tr key={i}>
                <td>#{a.payment_id}</td>
                <td>{a.pay_date}</td>
                <td>{a.method}</td>
                <td>{a.reference || '—'}</td>
                <td style={{textAlign:'right'}}>{Number(a.amount_applied).toFixed(2)}</td>
                <td><span className={`badge badge--${a.payment_status}`}>{statusLabel(a.payment_status)}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <div id="ap-bill-evidence-section" style={{ marginTop: 16 }}>
        <EvidenceAttachments
          subjectType="ap_bill"
          subjectId={bill.id}
          label="Vendor invoice & supporting docs"
          documentType="vendor_invoice"
          testidPrefix="ap-bill-evidence"
        />
      </div>
    </section>
  );
}

function SummaryBox({ label, value, highlight }) {
  return (
    <div style={{
      padding: 12, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8,
      background: highlight ? 'var(--cf-surface-alt, #f9fafb)' : 'transparent',
    }}>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.5 }}>{label}</div>
      <div style={{ fontSize: 16, fontWeight: 600, marginTop: 4 }}>{value}</div>
    </div>
  );
}

/**
 * P0 — Inline liquidity projection panel.
 *
 * Reads /api/ap_bill_liquidity_impact.php with ?bill_id and a date picker
 * defaulting to today. Surfaces the projected lowest-balance shift and any
 * runway loss so the operator can decide whether to pay now or stretch.
 */
function LiquidityImpactPanel({ billId, amountDue }) {
  const today = new Date().toISOString().slice(0, 10);
  const [payDate, setPayDate] = useState(today);
  const { data, loading, error } = useApi(
    `/api/ap_bill_liquidity_impact.php?bill_id=${billId}&pay_date=${payDate}`
  );

  const fmt = (n) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(n));

  return (
    <div data-testid="ap-bill-liquidity-impact"
         style={{
           padding: 14,
           border: '1px solid #c4b5fd',
           borderRadius: 10,
           background: 'linear-gradient(135deg,#f5f3ff,#ecfeff)',
           marginBottom: 'var(--cf-space-4)',
         }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
        <strong style={{ fontSize: 13, color: '#5b21b6' }}>
          Liquidity impact if paid {payDate === today ? 'today' : `on ${payDate}`}
        </strong>
        <label style={{ fontSize: 12, color: '#475569', display: 'flex', gap: 6, alignItems: 'center' }}>
          Pay date
          <input
            type="date"
            value={payDate}
            min={today}
            onChange={(e) => setPayDate(e.target.value || today)}
            data-testid="ap-bill-liquidity-impact-date"
            className="input"
            style={{ fontSize: 12, padding: '2px 6px' }}
          />
        </label>
      </div>

      {loading && <p data-testid="ap-bill-liquidity-impact-loading" style={{ fontSize: 12, color: '#475569', margin: '8px 0 0' }}>Projecting…</p>}
      {error && <p data-testid="ap-bill-liquidity-impact-error" className="error" style={{ fontSize: 12, margin: '8px 0 0' }}>{error.message}</p>}

      {data?.note && (
        <p data-testid="ap-bill-liquidity-impact-note" style={{ fontSize: 12, color: '#475569', margin: '8px 0 0' }}>{data.note}</p>
      )}

      {data?.baseline && data?.simulated && data?.delta && (
        <>
          <p style={{ fontSize: 12, color: '#1e293b', margin: '8px 0 6px' }}>
            Paying <strong>{fmt(data.bill_amount || amountDue)}</strong> on this date shifts your projected lowest balance:
          </p>
          <div data-testid="ap-bill-liquidity-impact-shift"
               style={{ fontSize: 13, fontFamily: 'system-ui', color: '#1e293b' }}>
            <strong>{fmt(data.baseline.lowest_balance)}</strong>
            <span style={{ margin: '0 6px', color: '#64748b' }}>→</span>
            <strong style={{ color: data.delta.lowest_balance_shift < 0 ? '#b91c1c' : '#065f46' }}>
              {fmt(data.simulated.lowest_balance)}
            </strong>
            <span style={{ marginLeft: 8, fontSize: 11, color: '#64748b' }}>
              ({data.delta.lowest_balance_shift >= 0 ? '+' : ''}{fmt(data.delta.lowest_balance_shift)})
            </span>
          </div>

          {data.delta.runway_days_lost > 0 && (
            <p data-testid="ap-bill-liquidity-impact-runway"
               style={{ fontSize: 12, color: '#b91c1c', margin: '6px 0 0' }}>
              ⚠ Runway shortens by <strong>{data.delta.runway_days_lost} day{data.delta.runway_days_lost === 1 ? '' : 's'}</strong>
              {data.simulated.runway_days_to_zero !== null
                ? ` — balance projected to cross zero in ${data.simulated.runway_days_to_zero} day${data.simulated.runway_days_to_zero === 1 ? '' : 's'}.`
                : '.'}
            </p>
          )}
          {data.delta.crosses_zero === false && data.delta.runway_days_lost === 0 && (
            <p data-testid="ap-bill-liquidity-impact-safe"
               style={{ fontSize: 12, color: '#065f46', margin: '6px 0 0' }}>
              ✓ Balance stays positive across the {data.days_horizon}-day horizon.
            </p>
          )}
        </>
      )}
    </div>
  );
}


function TimeEntrySourceDialog({ line, onClose }) {
  const closeRef = useRef(null);
  useEffect(() => {
    const previousFocus = document.activeElement;
    closeRef.current?.focus();
    return () => previousFocus?.focus?.();
  }, []);

  const fields = [
    ['Work date', line.source_work_date],
    ['Hours', Number(line.source_hours).toFixed(2)],
    ['Category', statusLabel(line.source_category)],
    ['Entry status', statusLabel(line.source_status)],
    ['Entry note', line.source_entry_description],
    ['Timesheet', line.source_timesheet_id ? `#${line.source_timesheet_id}` : null],
    ['Timesheet artifact', line.source_timesheet_artifact_id],
    ['Week', line.source_period_start ? `${line.source_period_start} to ${line.source_period_end}` : null],
    ['Timesheet status', line.source_timesheet_status ? statusLabel(line.source_timesheet_status) : null],
  ].filter(([, value]) => value != null && value !== '');

  return (
    <div
      style={{ position: 'fixed', inset: 0, zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16, background: 'rgba(10, 37, 64, 0.42)' }}
      onClick={onClose}
      onKeyDown={event => {
        if (event.key === 'Escape') onClose();
        if (event.key === 'Tab') { event.preventDefault(); closeRef.current?.focus(); }
      }}
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="ap-time-source-title"
        data-testid="ap-bill-time-source-dialog"
        onClick={event => event.stopPropagation()}
        style={{ width: 'min(560px, 100%)', maxHeight: 'calc(100vh - 32px)', overflow: 'auto', padding: 20, borderRadius: 6, background: 'white', boxShadow: '0 12px 32px rgba(10, 37, 64, 0.18)' }}
      >
        <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 16 }}>
          <h3 id="ap-time-source-title" style={{ margin: 0, fontSize: 18 }}>Time entry #{line.source_ref_id}</h3>
          <button ref={closeRef} type="button" className="btn btn--ghost" aria-label="Close time entry details" title="Close" onClick={onClose}>
            <X size={16} aria-hidden="true" />
          </button>
        </header>
        <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: '14px 20px', margin: 0 }}>
          {fields.map(([label, value]) => (
            <div key={label} style={{ minWidth: 0 }}>
              <dt style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginBottom: 3 }}>{label}</dt>
              <dd style={{ margin: 0, fontSize: 13, overflowWrap: 'anywhere' }}>{value}</dd>
            </div>
          ))}
        </dl>
      </section>
    </div>
  );
}

function LineReceiptCell({ line }) {
  const [state, setState] = useState({ status: 'idle', filename: null, draft: null, error: null });

  const onPick = async (file) => {
    if (!file) return;
    setState({ status: 'uploading', filename: file.name, draft: null, error: null });
    try {
      const uploaded = await uploadFileViaPresignedPost(
        `/modules/ap/api/bills.php?action=upload_url&line_id=${line.id}&file_name=${encodeURIComponent(file.name)}`,
        file
      );
      // Persist the attachment first.
      await api.post(`/modules/ap/api/bills.php?action=attach_line&line_id=${line.id}`, uploaded);
      // Then offer extraction (auto-run, suggestion only).
      setState((s) => ({ ...s, status: 'extracting' }));
      try {
        const ex = await api.post(`/modules/ap/api/bills.php?action=extract_receipt&line_id=${line.id}`, { storage_key: uploaded.storage_key });
        setState({ status: 'extracted', filename: file.name, draft: ex.draft, error: null });
      } catch (extractErr) {
        // Attachment was saved; extraction is bonus. Surface as soft warning.
        setState({ status: 'attached', filename: file.name, draft: null, error: extractErr });
      }
    } catch (e) {
      setState({ status: 'error', filename: file.name, draft: null, error: e });
    }
  };

  return (
    <div data-testid={`ap-bill-line-${line.id}-receipt`} style={{ minWidth: 160 }}>
      {state.status === 'idle' && (
        <label className="btn btn--ghost" style={{ cursor: 'pointer', fontSize: 12 }} data-testid={`ap-bill-line-${line.id}-attach-label`}>
          📎 Attach
          <input
            type="file"
            accept="application/pdf,image/*"
            onChange={(e) => onPick(e.target.files?.[0] || null)}
            data-testid={`ap-bill-line-${line.id}-attach-input`}
            style={{ display: 'none' }}
          />
        </label>
      )}
      {state.status === 'uploading'  && <span style={{ fontSize: 12, color: '#6b7280' }}>Uploading…</span>}
      {state.status === 'extracting' && <span style={{ fontSize: 12, color: '#6b7280' }}>✨ Extracting…</span>}
      {state.status === 'attached'   && <span style={{ fontSize: 12, color: '#065f46' }} title={state.filename}>📎 Attached</span>}
      {state.status === 'extracted'  && (
        <span style={{ fontSize: 12, color: '#065f46' }} title={JSON.stringify(state.draft)} data-testid={`ap-bill-line-${line.id}-receipt-extracted`}>
          ✨ {state.draft?.merchant || 'Extracted'} · {state.draft?.total != null ? `$${Number(state.draft.total).toFixed(2)}` : '—'}
        </span>
      )}
      {state.status === 'error'      && <span style={{ fontSize: 12, color: '#991b1b' }} data-testid={`ap-bill-line-${line.id}-receipt-error`}>{state.error?.message || 'Failed'}</span>}
    </div>
  );
}

