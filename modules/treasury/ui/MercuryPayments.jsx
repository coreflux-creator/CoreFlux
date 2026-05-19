import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

/**
 * <MercuryPayments /> — Slice 3 payment engine dashboard.
 *
 * Visualizes the 9-state machine + drives the workflow:
 *   Draft → PendingApproval → Approved → Funding → Submitted → Settled
 *                                                              → Failed / Returned / Reconciled
 * Approval enforces Segregation of Duties at the API level (creator ≠ approver).
 *
 * "Run worker" button manually advances a single row one step forward
 * (cron does this automatically every 5 min).
 */

const STATE_COLORS = {
  Draft:           { bg: '#f3f4f6', fg: '#374151' },
  PendingApproval: { bg: '#fef3c7', fg: '#92400e' },
  Approved:        { bg: '#dbeafe', fg: '#1e40af' },
  Funding:         { bg: '#e9d5ff', fg: '#6b21a8' },
  Submitted:       { bg: '#cffafe', fg: '#155e75' },
  Settled:         { bg: '#d1fae5', fg: '#065f46' },
  Reconciled:      { bg: '#ecfdf5', fg: '#047857' },
  Failed:          { bg: '#fee2e2', fg: '#991b1b' },
  Returned:        { bg: '#fee2e2', fg: '#991b1b' },
  Cancelled:       { bg: '#e5e7eb', fg: '#374151' },
};

const fmtAmount = (cents, cur = 'USD') =>
  cents != null ? `${cur === 'USD' ? '$' : ''}${(cents / 100).toFixed(2)}` : '—';

export default function MercuryPayments() {
  const list = useApi('/api/mercury_payments.php');
  const recipients = useApi('/api/mercury_recipients.php?kind=vendor');
  const reconStats = useApi('/api/mercury_reconciliation.php?action=stats');
  const [showCreate, setShowCreate] = useState(false);
  const [flash, setFlash] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [showDetail, setShowDetail] = useState(null);  // payment id
  const [reconBusy, setReconBusy] = useState(false);

  const rows = list.data?.rows ?? [];
  const stats = reconStats.data?.stats || null;

  const runReconciliation = async () => {
    setReconBusy(true);
    setFlash(null);
    try {
      const res = await api.post('/api/mercury_reconciliation.php?action=run', {});
      setFlash({
        kind: 'success',
        msg: `Reconciliation done: scanned=${res.scanned} matched=${res.matched} discrepancies=${res.discrepancies} missing=${res.missing}`,
      });
      list.reload();
      reconStats.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    } finally {
      setReconBusy(false);
    }
  };

  const act = async (id, action, body = {}) => {
    setBusyId(id);
    setFlash(null);
    try {
      const res = await api.post(`/api/mercury_payments.php?action=${action}&id=${id}`, body);
      setFlash({ kind: 'success', msg: `Action ${action} → ${res.state}` });
      list.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div data-testid="mercury-payments" style={{ padding: '24px' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>Mercury Payments</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Gated workflow: Draft → Approve (SoD) → Funding (debit external) → Verify clearance → Submit ACH to vendor → Settled.
            All Mercury calls run asynchronously via the worker — UI never blocks.
          </p>
        </div>
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => setShowCreate(true)}
          data-testid="mercury-payment-create-btn"
        >
          + New payment
        </button>
      </header>

      {flash && (
        <div
          data-testid={`mercury-payments-flash-${flash.kind}`}
          style={{
            padding: '10px 14px', borderRadius: 6, marginBottom: 16,
            background: flash.kind === 'success' ? '#ecfdf5' : '#fef2f2',
            color:      flash.kind === 'success' ? '#047857' : '#b91c1c',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

      {/* Reconciliation tile — Slice 4 */}
      {stats && (
        <div
          data-testid="mercury-reconciliation-tile"
          style={{
            display: 'grid', gridTemplateColumns: 'repeat(4, 1fr) auto', gap: 12,
            padding: 12, marginBottom: 16, borderRadius: 8,
            border: '1px solid var(--cf-border, #e5e7eb)', background: '#f9fafb',
            alignItems: 'center', fontSize: 13,
          }}
        >
          <ReconKpi label="Settled, awaiting reconciliation"
                    value={stats.settled_unreconciled}
                    testid="recon-kpi-pending"
                    accent={stats.settled_unreconciled > 0 ? '#92400e' : '#374151'} />
          <ReconKpi label="Reconciled (total)"
                    value={stats.reconciled_total}
                    testid="recon-kpi-reconciled"
                    accent="#047857" />
          <ReconKpi label="Open discrepancies"
                    value={stats.discrepancies_open}
                    testid="recon-kpi-discrepancies"
                    accent={stats.discrepancies_open > 0 ? '#991b1b' : '#374151'} />
          <ReconKpi label="Mercury txn missing"
                    value={stats.missing_mercury_txn}
                    testid="recon-kpi-missing"
                    accent={stats.missing_mercury_txn > 0 ? '#92400e' : '#374151'} />
          <button
            type="button"
            className="btn btn--primary"
            onClick={runReconciliation}
            disabled={reconBusy}
            data-testid="mercury-reconciliation-run-btn"
          >
            {reconBusy ? 'Running…' : 'Reconcile now'}
          </button>
          {stats.oldest_unreconciled && (
            <div data-testid="mercury-reconciliation-lag" style={{ gridColumn: '1 / -1', fontSize: 11, color: '#64748b' }}>
              Oldest unreconciled Settled payment: {stats.oldest_unreconciled}
            </div>
          )}
        </div>
      )}

      {list.loading && <p>Loading…</p>}
      {list.error   && <p className="error">Error: {list.error.message}</p>}
      {!list.loading && rows.length === 0 && (
        <p data-testid="mercury-payments-empty" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          No payments yet. Click "+ New payment" to draft your first one.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="mercury-payments-table" style={{ width: '100%', fontSize: 13 }}>
          <thead>
            <tr>
              <th>#</th><th>State</th><th>Recipient</th><th style={{ textAlign: 'right' }}>Amount</th>
              <th>Description</th><th>Created</th><th>Funding txn</th><th>Payout txn</th><th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map(p => {
              const c = STATE_COLORS[p.state] || STATE_COLORS.Draft;
              return (
                <tr key={p.id} data-testid={`mercury-payment-row-${p.id}`}>
                  <td>{p.id}</td>
                  <td>
                    <span
                      data-testid={`mercury-payment-state-${p.id}`}
                      style={{ background: c.bg, color: c.fg, padding: '2px 8px', borderRadius: 4, fontSize: 11 }}
                    >
                      {p.state}
                    </span>
                  </td>
                  <td>{p.recipient_name || `#${p.recipient_id}`}</td>
                  <td style={{ textAlign: 'right', fontFamily: 'var(--cf-mono, ui-monospace)' }}>
                    {fmtAmount(p.amount_cents, p.currency)}
                  </td>
                  <td>{p.description || '—'}</td>
                  <td style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{p.created_at}</td>
                  <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 11 }}>
                    {p.funding_mercury_txn_id || '—'}
                    {p.funding_mercury_status && <div style={{ fontSize: 10, opacity: 0.7 }}>{p.funding_mercury_status}</div>}
                  </td>
                  <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 11 }}>
                    {p.payout_mercury_txn_id || '—'}
                    {p.payout_mercury_status && <div style={{ fontSize: 10, opacity: 0.7 }}>{p.payout_mercury_status}</div>}
                  </td>
                  <td style={{ whiteSpace: 'nowrap' }}>
                    {p.state === 'Draft' && (
                      <button className="btn btn--ghost" onClick={() => act(p.id, 'submit')} disabled={busyId === p.id} data-testid={`mercury-payment-submit-${p.id}`}>
                        Submit
                      </button>
                    )}
                    {p.state === 'PendingApproval' && (
                      <>
                        <button className="btn btn--ghost" onClick={() => act(p.id, 'approve')} disabled={busyId === p.id} data-testid={`mercury-payment-approve-${p.id}`}>
                          Approve
                        </button>
                        <button
                          className="btn btn--ghost"
                          onClick={() => {
                            const r = window.prompt('Reason for rejection');
                            if (r) act(p.id, 'reject', { reason: r });
                          }}
                          disabled={busyId === p.id}
                          data-testid={`mercury-payment-reject-${p.id}`}
                          style={{ marginLeft: 4 }}
                        >
                          Reject
                        </button>
                      </>
                    )}
                    {(p.state === 'Approved' || p.state === 'Funding' || p.state === 'Submitted') && (
                      <button
                        className="btn btn--ghost"
                        onClick={() => act(p.id, 'advance')}
                        disabled={busyId === p.id}
                        data-testid={`mercury-payment-advance-${p.id}`}
                        title="Run worker one step (cron does this automatically)"
                      >
                        {busyId === p.id ? 'Working…' : 'Run worker'}
                      </button>
                    )}
                    {['Draft', 'PendingApproval', 'Approved'].includes(p.state) && (
                      <button
                        className="btn btn--ghost"
                        onClick={() => {
                          if (window.confirm('Cancel this payment?')) act(p.id, 'cancel');
                        }}
                        disabled={busyId === p.id}
                        data-testid={`mercury-payment-cancel-${p.id}`}
                        style={{ marginLeft: 4 }}
                      >
                        Cancel
                      </button>
                    )}
                    <button
                      className="btn btn--ghost"
                      onClick={() => setShowDetail(p.id)}
                      data-testid={`mercury-payment-detail-${p.id}`}
                      style={{ marginLeft: 4 }}
                    >
                      Audit
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}

      {showCreate && (
        <CreatePaymentModal
          recipients={recipients.data?.rows ?? []}
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); list.reload(); setFlash({ kind: 'success', msg: 'Payment drafted.' }); }}
        />
      )}
      {showDetail && (
        <PaymentDetailModal id={showDetail} onClose={() => setShowDetail(null)} />
      )}
    </div>
  );
}

function CreatePaymentModal({ recipients, onClose, onCreated }) {
  const [form, setForm] = useState({
    recipient_id: recipients[0]?.id || '',
    amount: '',
    description: '',
    notes: '',
  });
  const [busy, setBusy] = useState(false);
  const [err,  setErr]  = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    if (!form.recipient_id) { setErr('Select a vendor recipient.'); return; }
    const cents = Math.round(Number(form.amount) * 100);
    if (!cents || cents <= 0) { setErr('Amount must be > 0.'); return; }
    setBusy(true); setErr(null);
    try {
      await api.post('/api/mercury_payments.php', {
        recipient_id: Number(form.recipient_id),
        amount_cents: cents,
        description:  form.description || null,
        notes:        form.notes || null,
      });
      onCreated?.();
    } catch (er) { setErr(er.message || String(er)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="mercury-payment-create-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <form onSubmit={submit} style={modalCard}>
        <h4 style={{ margin: '0 0 12px' }}>New Mercury payment</h4>
        {err && <div className="error" data-testid="mercury-payment-create-error" style={{ marginBottom: 8 }}>{err}</div>}
        <label style={fieldLabel}>
          Vendor recipient
          <select
            value={form.recipient_id}
            onChange={(e) => setForm(s => ({ ...s, recipient_id: e.target.value }))}
            className="input"
            required
            data-testid="mercury-payment-recipient"
            style={{ marginTop: 4 }}
          >
            <option value="">— select —</option>
            {recipients.map(r => (
              <option key={r.id} value={r.id}>{r.name} {r.bank_last4 ? `(••${r.bank_last4})` : ''}</option>
            ))}
          </select>
        </label>
        <label style={fieldLabel}>
          Amount (USD)
          <input
            type="number" step="0.01" min="0.01"
            value={form.amount}
            onChange={(e) => setForm(s => ({ ...s, amount: e.target.value }))}
            className="input" required
            data-testid="mercury-payment-amount"
            style={{ marginTop: 4 }}
          />
        </label>
        <label style={fieldLabel}>
          Description (shown on bank statement, ≤ 50 chars)
          <input
            type="text" maxLength={50}
            value={form.description}
            onChange={(e) => setForm(s => ({ ...s, description: e.target.value }))}
            className="input"
            data-testid="mercury-payment-description"
            style={{ marginTop: 4 }}
          />
        </label>
        <label style={fieldLabel}>
          Internal notes
          <textarea
            value={form.notes}
            onChange={(e) => setForm(s => ({ ...s, notes: e.target.value }))}
            className="input"
            rows={2}
            style={{ marginTop: 4, width: '100%' }}
          />
        </label>
        <div style={{ marginTop: 16, display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy} data-testid="mercury-payment-save-btn">
            {busy ? 'Saving…' : 'Create draft'}
          </button>
        </div>
      </form>
    </div>
  );
}

function PaymentDetailModal({ id, onClose }) {  const detail = useApi(`/api/mercury_payments.php?id=${id}`);
  return (
    <div data-testid="mercury-payment-detail-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div style={{ ...modalCard, maxWidth: 720 }}>
        <h4 style={{ margin: '0 0 12px' }}>Payment #{id} — audit trail</h4>
        {detail.loading && <p>Loading…</p>}
        {detail.error   && <p className="error">{detail.error.message}</p>}
        {detail.data && (
          <>
            <pre style={{ background: '#f9fafb', padding: 10, borderRadius: 4, fontSize: 11, maxHeight: 200, overflow: 'auto' }} data-testid="mercury-payment-detail-row">
              {JSON.stringify(detail.data.row, null, 2)}
            </pre>
            <h5 style={{ margin: '12px 0 6px', fontSize: 12 }}>State transitions</h5>
            <table style={{ width: '100%', fontSize: 12 }} data-testid="mercury-payment-audit-table">
              <thead><tr><th>From</th><th>To</th><th>Reason</th><th>When</th></tr></thead>
              <tbody>
                {(detail.data.audit || []).map((a, i) => (
                  <tr key={i}>
                    <td>{a.from_state || '—'}</td>
                    <td>{a.to_state}</td>
                    <td>{a.reason || '—'}</td>
                    <td style={{ fontSize: 11, color: '#64748b' }}>{a.created_at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}
        <div style={{ marginTop: 16, textAlign: 'right' }}>
          <button type="button" className="btn" onClick={onClose}>Close</button>
        </div>
      </div>
    </div>
  );
}

const fieldLabel = { display: 'block', marginBottom: 8, fontSize: 12, color: 'var(--cf-text-secondary)' };
const modalOverlay = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)',
  display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50,
};
const modalCard = {
  background: '#fff', padding: 20, borderRadius: 8, width: '100%', maxWidth: 480,
  maxHeight: '90vh', overflowY: 'auto',
};

function ReconKpi({ label, value, testid, accent }) {
  return (
    <div data-testid={testid} style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
      <span style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</span>
      <strong style={{ fontSize: 18, color: accent || '#0f172a' }}>{value}</strong>
    </div>
  );
}
