import React from 'react';
import { Link } from 'react-router-dom';

/**
 * TimesheetLifecycleTimeline — 2026-02
 *
 * Vertical timeline of every downstream artifact that flowed from a
 * timesheet (or a single time_entry, when narrowed):
 *
 *   • Submission + approval audit
 *   • Accrual JEs (posting_engine events)
 *   • AR invoices, billing JE, AR cash receipts
 *   • AP bills, AP JE, PWP link/release events, vendor disbursements
 *     (incl. rail dispatch metadata: Mercury/Plaid/Nacha)
 *
 * Pure presentation — driven by the cascade object returned by
 * `/api/staffing/lifecycle.php`.
 */

const fmt$ = (n) => '$' + (Number(n || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (d) => (d ? new Date(d).toLocaleString() : '—');
const fmtDateOnly = (d) => (d ? String(d).slice(0, 10) : '—');

function StatusPill({ status, kind = 'neutral' }) {
  const PALETTE = {
    paid: '#10b981', triggered: '#10b981', cleared: '#10b981',
    sent: '#0ea5e9', approved: '#0ea5e9', submitted: '#0ea5e9', posted: '#0ea5e9',
    awaiting_ar: '#f59e0b', pending_approval: '#f59e0b', partially_paid: '#f59e0b', draft: '#9ca3af',
    rejected: '#ef4444', failed: '#ef4444', void: '#9ca3af',
    not_pwp: '#6b7280', pending: '#9ca3af',
  };
  const color = PALETTE[status] || '#6b7280';
  return (
    <span
      style={{
        display: 'inline-block', padding: '2px 8px', borderRadius: 12,
        background: color + '22', color, fontSize: 11, fontWeight: 600,
        textTransform: 'uppercase', letterSpacing: 0.4,
      }}
      data-testid={`lifecycle-pill-${kind}-${status || 'none'}`}
    >
      {status || '—'}
    </span>
  );
}

function Step({ icon, color, title, when, children, testid }) {
  return (
    <div data-testid={testid} style={{ display: 'flex', gap: 12, marginBottom: 16 }}>
      <div style={{ flex: '0 0 32px', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
        <div style={{
          width: 28, height: 28, borderRadius: 14, background: color, color: '#fff',
          display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 14, fontWeight: 700,
        }}>{icon}</div>
        <div style={{ width: 2, flex: 1, background: '#e5e7eb', marginTop: 4 }} />
      </div>
      <div style={{ flex: 1, paddingBottom: 8 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 12 }}>
          <strong style={{ fontSize: 14 }}>{title}</strong>
          <span style={{ fontSize: 11, color: '#6b7280' }}>{when}</span>
        </div>
        <div style={{ marginTop: 6, fontSize: 13, color: '#374151' }}>{children}</div>
      </div>
    </div>
  );
}

export default function TimesheetLifecycleTimeline({ cascade }) {
  if (!cascade) return null;
  const { timesheet, approvals, accrual_events: accruals, ar = [], ap = [], summary, focused_entry } = cascade;

  return (
    <section data-testid="timesheet-lifecycle-timeline" style={{ marginTop: 16 }}>
      {/* Summary band */}
      <div
        data-testid="timesheet-lifecycle-summary"
        style={{
          display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 8,
          padding: 12, marginBottom: 16, background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 8,
        }}
      >
        <Stat label="Revenue billed" value={fmt$(summary?.revenue_billed)} testid="lifecycle-stat-revenue-billed" />
        <Stat label="AR collected"   value={fmt$(summary?.ar_collected)}   testid="lifecycle-stat-ar-collected" />
        <Stat label="Vendor owed"    value={fmt$(summary?.vendor_owed)}    testid="lifecycle-stat-vendor-owed" />
        <Stat label="Vendor paid"    value={fmt$(summary?.vendor_paid)}    testid="lifecycle-stat-vendor-paid" />
      </div>

      {focused_entry && (
        <div data-testid="lifecycle-focused-entry" style={{ marginBottom: 12, padding: 8, background: '#eff6ff', borderRadius: 6, fontSize: 12 }}>
          Focused on time entry <strong>#{focused_entry.id}</strong> · {fmtDateOnly(focused_entry.work_date)} · {Number(focused_entry.hours).toFixed(2)}h · placement {focused_entry.placement_id}
        </div>
      )}

      {/* Step 1: Submission + Approval */}
      <Step
        icon="1"
        color="#0ea5e9"
        title={`Timesheet ${timesheet?.id} — ${approvals?.submitted_at ? 'Submitted' : 'Draft'}`}
        when={approvals?.submitted_at ? fmtDate(approvals.submitted_at) : 'Not submitted'}
        testid="lifecycle-step-approval"
      >
        <div>Worker: <strong>{timesheet?.first_name} {timesheet?.last_name}</strong> · Period {fmtDateOnly(timesheet?.period_start)} → {fmtDateOnly(timesheet?.period_end)}</div>
        <div style={{ marginTop: 4 }}>Status: <StatusPill status={timesheet?.status} kind="ts" /></div>
        {approvals?.approved_at && (
          <div style={{ marginTop: 4 }} data-testid="lifecycle-approved-at">Approved {fmtDate(approvals.approved_at)}</div>
        )}
        {approvals?.rejection_reason && (
          <div style={{ marginTop: 4, color: '#b91c1c' }} data-testid="lifecycle-rejected-reason">Rejected — {approvals.rejection_reason}</div>
        )}
        {approvals?.audit_events?.length > 0 && (
          <details style={{ marginTop: 6 }} data-testid="lifecycle-approval-audit">
            <summary style={{ cursor: 'pointer', color: '#6b7280', fontSize: 12 }}>Audit log ({approvals.audit_events.length})</summary>
            <ul style={{ fontSize: 11, color: '#6b7280', margin: '4px 0 0 16px' }}>
              {approvals.audit_events.map(e => (
                <li key={e.id}>{e.event} — {fmtDate(e.created_at)} by user {e.actor_user_id || '—'}</li>
              ))}
            </ul>
          </details>
        )}
      </Step>

      {/* Step 2: Accrual JEs */}
      <Step
        icon="2"
        color={accruals?.length ? '#8b5cf6' : '#9ca3af'}
        title={`Accrual JEs (${accruals?.length || 0})`}
        when={accruals?.[0]?.created_at ? fmtDate(accruals[0].created_at) : '—'}
        testid="lifecycle-step-accruals"
      >
        {accruals?.length ? (
          <ul style={{ margin: 0, paddingLeft: 16 }}>
            {accruals.map(a => (
              <li key={a.id} data-testid={`lifecycle-accrual-${a.id}`} style={{ marginBottom: 4 }}>
                <strong>{a.event_type}</strong> ·
                {a.je_number ? (
                  <Link to={`/modules/accounting/journal-entries/${a.journal_entry_id}`} style={{ marginLeft: 4 }} data-testid={`lifecycle-accrual-je-link-${a.id}`}>
                    JE {a.je_number}
                  </Link>
                ) : <span style={{ color: '#9ca3af', marginLeft: 4 }}>{a.status}</span>}
                {a.total_debit && <span style={{ marginLeft: 8, color: '#6b7280' }}>{fmt$(a.total_debit)}</span>}
                {a.error_message && <div style={{ color: '#b91c1c', fontSize: 12 }}>↳ {a.error_message}</div>}
              </li>
            ))}
          </ul>
        ) : <span style={{ color: '#9ca3af' }}>No accrual events posted yet — these fire when the timesheet is approved + matched against a posting rule.</span>}
      </Step>

      {/* Step 3: AR — Invoices + Cash */}
      <Step
        icon="3"
        color={ar.length ? '#10b981' : '#9ca3af'}
        title={`AR Invoices (${ar.length})`}
        when={ar[0]?.invoice?.issue_date ? fmtDateOnly(ar[0].invoice.issue_date) : '—'}
        testid="lifecycle-step-ar"
      >
        {ar.length ? ar.map(({ invoice, lines, je, payments }) => (
          <div key={invoice.id} data-testid={`lifecycle-ar-${invoice.id}`} style={{ marginBottom: 12, paddingTop: 8, borderTop: '1px dashed #e5e7eb' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
              <span>
                <Link to={`/modules/billing/invoices/${invoice.id}`} data-testid={`lifecycle-ar-link-${invoice.id}`}>
                  <strong>{invoice.invoice_number || `Invoice #${invoice.id}`}</strong>
                </Link>
                <span style={{ marginLeft: 8 }}>{invoice.client_name}</span>
              </span>
              <StatusPill status={invoice.status} kind="ar" />
            </div>
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>
              {fmt$(invoice.total)} total · {fmt$(invoice.amount_paid)} paid · {fmt$(invoice.amount_due)} due
              · {lines.length} line(s) from this timesheet
            </div>
            {je && (
              <div style={{ marginTop: 4, fontSize: 12 }} data-testid={`lifecycle-ar-je-${invoice.id}`}>
                ↳ <Link to={`/modules/accounting/journal-entries/${je.id}`}>Billing JE {je.je_number}</Link>
                {' '}<StatusPill status={je.status} kind="je" />
              </div>
            )}
            {payments.length > 0 && (
              <div style={{ marginTop: 4 }} data-testid={`lifecycle-ar-payments-${invoice.id}`}>
                <strong style={{ fontSize: 12 }}>Cash received:</strong>
                <ul style={{ margin: '2px 0 0 16px', fontSize: 12 }}>
                  {payments.map((p, i) => (
                    <li key={p.payment_id + '-' + i} data-testid={`lifecycle-ar-pay-${invoice.id}-${i}`}>
                      {fmt$(p.amount_applied)} · {fmtDateOnly(p.received_at)} · {p.payment_method || '—'}
                      {p.source_system === 'qbo' && <span style={{ marginLeft: 4, fontSize: 10, background: '#dbeafe', padding: '1px 4px', borderRadius: 3 }}>QBO</span>}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )) : <span style={{ color: '#9ca3af' }}>Not yet invoiced.</span>}
      </Step>

      {/* Step 4: AP — Bills, PWP, Vendor Disbursement */}
      <Step
        icon="4"
        color={ap.length ? '#f97316' : '#9ca3af'}
        title={`Vendor Payables (${ap.length})`}
        when={ap[0]?.bill?.bill_date ? fmtDateOnly(ap[0].bill.bill_date) : '—'}
        testid="lifecycle-step-ap"
      >
        {ap.length ? ap.map(({ bill, lines, je, payments, pwp_events }) => (
          <div key={bill.id} data-testid={`lifecycle-ap-${bill.id}`} style={{ marginBottom: 12, paddingTop: 8, borderTop: '1px dashed #e5e7eb' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
              <span>
                <Link to={`/modules/ap/bills/${bill.id}`} data-testid={`lifecycle-ap-link-${bill.id}`}>
                  <strong>{bill.internal_ref || `Bill #${bill.id}`}</strong>
                </Link>
                <span style={{ marginLeft: 8 }}>{bill.vendor_name}</span>
                {bill.vendor_type && <span style={{ marginLeft: 6, fontSize: 10, color: '#6b7280' }}>({bill.vendor_type})</span>}
              </span>
              <StatusPill status={bill.status} kind="ap" />
            </div>
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>
              {fmt$(bill.total)} total · {fmt$(bill.amount_paid)} paid · {fmt$(bill.amount_due)} due
              · {lines.length} line(s) from this timesheet
            </div>
            {bill.payment_terms?.startsWith?.('PWP') && (
              <div
                data-testid={`lifecycle-pwp-banner-${bill.id}`}
                style={{
                  marginTop: 6, padding: 6, background: bill.pwp_status === 'triggered' ? '#ecfdf5' : '#fef3c7',
                  border: '1px solid ' + (bill.pwp_status === 'triggered' ? '#a7f3d0' : '#fde68a'),
                  borderRadius: 4, fontSize: 12,
                }}
              >
                <strong>Pay-When-Paid · {bill.payment_terms}</strong> →
                {bill.pwp_status === 'triggered' ? (
                  <span> released {fmtDate(bill.pwp_released_at)} (AR #{bill.linked_ar_invoice_id} paid)</span>
                ) : bill.pwp_status === 'awaiting_ar' ? (
                  <span> awaiting AR <Link to={`/modules/billing/invoices/${bill.linked_ar_invoice_id}`}>#{bill.linked_ar_invoice_id}</Link> payment before vendor cash leaves</span>
                ) : <span> {bill.pwp_status}</span>}
              </div>
            )}
            {je && (
              <div style={{ marginTop: 4, fontSize: 12 }} data-testid={`lifecycle-ap-je-${bill.id}`}>
                ↳ <Link to={`/modules/accounting/journal-entries/${je.id}`}>AP JE {je.je_number}</Link>
                {' '}<StatusPill status={je.status} kind="je" />
              </div>
            )}
            {pwp_events?.length > 0 && (
              <details style={{ marginTop: 4 }} data-testid={`lifecycle-pwp-events-${bill.id}`}>
                <summary style={{ cursor: 'pointer', color: '#6b7280', fontSize: 11 }}>PWP audit trail ({pwp_events.length})</summary>
                <ul style={{ margin: '2px 0 0 16px', fontSize: 11, color: '#6b7280' }}>
                  {pwp_events.map(e => <li key={e.id}>{e.event} · {fmtDate(e.created_at)}</li>)}
                </ul>
              </details>
            )}
            {payments.length > 0 && (
              <div style={{ marginTop: 4 }} data-testid={`lifecycle-ap-payments-${bill.id}`}>
                <strong style={{ fontSize: 12 }}>Vendor disbursement:</strong>
                <ul style={{ margin: '2px 0 0 16px', fontSize: 12 }}>
                  {payments.map((p, i) => (
                    <li key={p.payment_id + '-' + i} data-testid={`lifecycle-ap-pay-${bill.id}-${i}`}>
                      {fmt$(p.amount_applied)} · {fmtDateOnly(p.pay_date)} ·
                      <StatusPill status={p.payment_status} kind="paystat" />
                      {p.disbursement_rail && (
                        <span style={{ marginLeft: 6, fontSize: 10, background: '#fed7aa', padding: '1px 4px', borderRadius: 3 }}>
                          {p.disbursement_rail}
                        </span>
                      )}
                      {p.rail_external_ref && <span style={{ marginLeft: 4, color: '#6b7280', fontSize: 11 }}>ref {String(p.rail_external_ref).slice(0, 18)}</span>}
                      {p.rail_status && <span style={{ marginLeft: 4, color: '#6b7280', fontSize: 11 }}>· {p.rail_status}</span>}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )) : <span style={{ color: '#9ca3af' }}>No vendor payables generated yet.</span>}
      </Step>
    </section>
  );
}

function Stat({ label, value, testid }) {
  return (
    <div data-testid={testid}>
      <div style={{ fontSize: 11, color: '#6b7280', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 600, marginTop: 2 }}>{value}</div>
    </div>
  );
}
