import React, { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';
import IntercompanySplitDialog from '../../../dashboard/src/components/IntercompanySplitDialog';
import ThreeWayMatchPanel from './ThreeWayMatchPanel';
import BillApprovalThread from './BillApprovalThread';

export default function BillDetail() {
  const { id } = useParams();
  const { data, loading, error, reload } = useApi(`/modules/ap/api/bills.php?id=${id}`);
  const [busy, setBusy] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [icSplitOpen, setIcSplitOpen] = useState(false);

  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error" data-testid="ap-bill-detail-error">Error: {error.message}</p>;
  if (!data?.bill) return <p>Not found.</p>;

  const bill = data.bill;
  const lines = data.lines || [];
  const allocations = data.allocations || [];

  const canApprove = ['pending_review','pending_approval'].includes(bill.status);
  const canDispute = ['pending_review','pending_approval','approved'].includes(bill.status);
  const canVoid    = bill.status !== 'void';
  const canPost    = ['approved','partially_paid','paid'].includes(bill.status);

  const run = async (label, fn) => {
    setBusy(label); setActionError(null);
    try { await fn(); reload(); } catch (e) { setActionError(e); }
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

  return (
    <section data-testid="ap-bill-detail">
      <Link to="/modules/ap/bills" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← All bills</Link>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginTop: 8, marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h2 style={{ margin: 0 }} data-testid="ap-bill-detail-ref">{bill.internal_ref}</h2>
          <p style={{ margin: '4px 0', color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            {bill.vendor_name} ({bill.vendor_type}) · billed {bill.bill_date} · due {bill.due_date}
            {bill.bill_number !== bill.internal_ref ? ` · vendor ref ${bill.bill_number}` : ''}
          </p>
          <span className={`badge badge--${bill.status}`}>{bill.status.replace('_',' ')}</span>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {canApprove && <button className="btn btn--primary" onClick={approve} disabled={busy==='approve'} data-testid="ap-bill-approve">{busy==='approve' ? 'Approving…' : 'Approve'}</button>}
          {canPost    && <button className="btn btn--ghost" onClick={postGl} disabled={busy==='post'} data-testid="ap-bill-post">{busy==='post' ? 'Posting…' : 'Post to GL'}</button>}
          {canPost    && !bill.journal_entry_id && <button className="btn btn--ghost" onClick={() => setIcSplitOpen(true)} data-testid="ap-bill-post-ic-split">⊕ Post with IC split</button>}
          {canDispute && <button className="btn btn--ghost" onClick={dispute} disabled={busy==='dispute'} data-testid="ap-bill-dispute">{busy==='dispute' ? 'Disputing…' : 'Dispute'}</button>}
          {canVoid    && <button className="btn btn--ghost" onClick={voidIt} disabled={busy==='void'} data-testid="ap-bill-void">{busy==='void' ? 'Voiding…' : 'Void'}</button>}
        </div>
      </div>

      {bill.status === 'disputed' && bill.dispute_reason && (
        <p className="error" data-testid="ap-bill-disputed-reason" style={{ marginBottom: 12 }}>Disputed: {bill.dispute_reason}</p>
      )}
      {actionError && <p className="error" data-testid="ap-bill-action-error">Error: {actionError.message}</p>}

      {icSplitOpen && (
        <IntercompanySplitDialog
          open={icSplitOpen}
          onClose={() => setIcSplitOpen(false)}
          onPosted={() => { setIcSplitOpen(false); reload(); }}
          amount={Number(bill.total)}
          sourceEntityId={1}
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
        <SummaryBox label="Due"       value={`${Number(bill.amount_due).toFixed(2)}`} highlight />
      </div>

      {bill.po_number && <ThreeWayMatchPanel billId={id} />}
      <BillApprovalThread billId={id} />

      <h3 style={{ fontSize: 14, margin: '0 0 8px' }}>Lines</h3>
      <table className="data-table" data-testid="ap-bill-lines" style={{ marginBottom: 'var(--cf-space-4)' }}>
        <thead><tr><th>#</th><th>Description</th><th style={{textAlign:'right'}}>Qty</th><th>Unit</th><th style={{textAlign:'right'}}>Unit $</th><th style={{textAlign:'right'}}>Subtotal</th><th style={{textAlign:'right'}}>Total</th><th>GL</th><th>1099?</th><th>Receipt</th></tr></thead>
        <tbody>
          {lines.map(l => (
            <tr key={l.id} data-testid={`ap-bill-line-${l.id}`}>
              <td>{l.line_no}</td>
              <td>{l.description}</td>
              <td style={{textAlign:'right'}}>{Number(l.quantity).toFixed(2)}</td>
              <td>{l.unit}</td>
              <td style={{textAlign:'right'}}>{Number(l.unit_price).toFixed(4)}</td>
              <td style={{textAlign:'right'}}>{Number(l.subtotal).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(l.total).toFixed(2)}</td>
              <td>{l.gl_expense_account_code || '—'}</td>
              <td>{Number(l.is_1099_eligible) ? 'Yes' : 'No'}</td>
              <td><LineReceiptCell line={l} /></td>
            </tr>
          ))}
        </tbody>
      </table>

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
                <td><span className={`badge badge--${a.payment_status}`}>{a.payment_status}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
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

