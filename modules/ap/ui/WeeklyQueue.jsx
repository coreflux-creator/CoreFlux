import React, { useMemo, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const BLOCKER_META = {
  awaiting_client:  { label: 'Awaiting client', color: '#0891b2', bg: '#cffafe' },
  missing_hours:    { label: 'Hours pending',   color: '#a16207', bg: '#fef3c7' },
  needs_review:     { label: 'AP review',       color: '#7c3aed', bg: '#ede9fe' },
  approver_pending: { label: 'With approver',   color: '#475569', bg: '#e2e8f0' },
  disputed:         { label: 'Disputed',        color: '#dc2626', bg: '#fee2e2' },
  none:             { label: 'Ready',           color: '#16a34a', bg: '#dcfce7' },
};

export default function WeeklyQueue() {
  const { data, loading, error, reload } = useApi('/modules/ap/api/weekly_queue.php?lookahead=7');
  const [selected, setSelected] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  const rows = data?.rows || [];
  const summary = data?.summary || {};
  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

  const eligible = useMemo(
    () => rows.filter(r => r.blocker === 'none' || r.blocker === 'needs_review'),
    [rows]
  );

  const toggle = (id) => {
    const next = new Set(selected);
    next.has(id) ? next.delete(id) : next.add(id);
    setSelected(next);
  };
  const selectAllEligible = () => setSelected(new Set(eligible.map(r => r.id)));
  const clearAll = () => setSelected(new Set());

  const finalize = async () => {
    if (selected.size === 0) return;
    if (!window.confirm(`Finalize ${selected.size} bill(s) and email the approver? Each approver gets a one-tap approve/reject link.`)) return;
    setBusy(true); setResult(null);
    try {
      const res = await api.post('/modules/ap/api/weekly_queue.php?action=finalize', {
        bill_ids: Array.from(selected),
      });
      setResult(res);
      clearAll();
      reload();
    } catch (e) {
      setResult({ error: e.message });
    } finally {
      setBusy(false);
    }
  };

  const resendApproverEmail = async (billId) => {
    if (!window.confirm('Re-send the one-tap approval email to the current approver(s)?')) return;
    try {
      const res = await api.post('/modules/ap/api/weekly_queue.php?action=send_approver_email', { bill_id: billId });
      alert(`Email dispatched to ${res.sent} approver(s).`);
    } catch (e) {
      alert(`Failed: ${e.message}`);
    }
  };

  if (loading) return <p data-testid="ap-weekly-queue-loading">Loading the weekly AP queue…</p>;
  if (error)   return <p className="error" data-testid="ap-weekly-queue-error">Error: {error.message}</p>;

  return (
    <section data-testid="ap-weekly-queue">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 16, gap: 16, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0 }}>Weekly AP Queue</h2>
          <p style={{ margin: '6px 0 0', color: 'var(--cf-text-secondary, #6b7280)', fontSize: 13 }}>
            Past-due and next-7-days bills. Select what's ready, hit Finalize, and each approver gets a one-tap email.
          </p>
        </div>
        <button
          className="btn btn--primary"
          onClick={finalize}
          disabled={busy || selected.size === 0}
          data-testid="ap-weekly-queue-finalize-batch"
        >
          {busy ? 'Finalizing…' : `Finalize ${selected.size > 0 ? selected.size : ''} bill(s) → send approver email`}
        </button>
      </header>

      <SummaryRibbon summary={summary} />

      {result && <ResultBlock result={result} />}

      <div style={{ display: 'flex', gap: 8, margin: '16px 0', alignItems: 'center' }}>
        <button className="btn btn--ghost" onClick={selectAllEligible} disabled={eligible.length === 0} data-testid="ap-weekly-queue-select-eligible">
          Select all ready ({eligible.length})
        </button>
        <button className="btn btn--ghost" onClick={clearAll} disabled={selected.size === 0} data-testid="ap-weekly-queue-clear">Clear</button>
        <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          {selected.size} of {rows.length} selected
        </span>
      </div>

      <table className="data-table" data-testid="ap-weekly-queue-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}></th>
            <th>Bill #</th>
            <th>Vendor</th>
            <th style={{ textAlign: 'right' }}>Due amt</th>
            <th>Due date</th>
            <th>Blocker</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {rows.map(b => {
            const isPast = b.due_date < today;
            const eligibleRow = b.blocker === 'none' || b.blocker === 'needs_review';
            const meta = BLOCKER_META[b.blocker] || BLOCKER_META.none;
            return (
              <tr key={b.id} data-testid={`ap-weekly-row-${b.id}`} style={{ background: isPast ? '#fef2f2' : undefined }}>
                <td>
                  <input
                    type="checkbox"
                    checked={selected.has(b.id)}
                    disabled={!eligibleRow}
                    onChange={() => toggle(b.id)}
                    data-testid={`ap-weekly-row-check-${b.id}`}
                    title={eligibleRow ? 'Select to finalize' : 'Not eligible — clear the blocker first'}
                  />
                </td>
                <td><a href={`#/modules/ap/bills/${b.id}`} style={{ color: 'var(--cf-text)', fontWeight: 600 }}>{b.bill_number || b.internal_ref}</a></td>
                <td>{b.vendor_name}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>${Number(b.amount_due).toFixed(2)}</td>
                <td>
                  {b.due_date}
                  {isPast && <span style={{ marginLeft: 6, fontSize: 11, color: '#dc2626', fontWeight: 600 }}>PAST DUE</span>}
                </td>
                <td>
                  <span style={{ display: 'inline-block', padding: '2px 8px', borderRadius: 10, background: meta.bg, color: meta.color, fontSize: 11, fontWeight: 600 }} data-testid={`ap-weekly-row-blocker-${b.id}`}>
                    {meta.label}
                  </span>
                  {b.blocker_detail && (
                    <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{b.blocker_detail}</div>
                  )}
                </td>
                <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{(b.status || '').replace(/_/g, ' ')}</td>
                <td>
                  {b.status === 'pending_approval' && (
                    <button className="btn btn--ghost" style={{ fontSize: 12 }} onClick={() => resendApproverEmail(b.id)} data-testid={`ap-weekly-row-resend-${b.id}`}>
                      Resend approver email
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
          {rows.length === 0 && (
            <tr><td colSpan={8} style={{ textAlign: 'center', padding: 24, color: 'var(--cf-text-secondary)' }}>Queue is empty — nothing past-due or coming due in the next 7 days.</td></tr>
          )}
        </tbody>
      </table>
    </section>
  );
}

function SummaryRibbon({ summary }) {
  const items = [
    { label: 'Past due',        value: summary.past_due_count || 0, sub: `$${Number(summary.past_due_amount || 0).toFixed(2)}`, color: '#dc2626' },
    { label: 'Due next 7 days', value: summary.due_soon_count || 0, sub: `$${Number(summary.due_soon_amount || 0).toFixed(2)}`, color: '#0f172a' },
    { label: 'Ready to finalize', value: summary.ready_count || 0,  sub: 'Eligible for batch',                                   color: '#16a34a' },
    { label: 'Blocked',         value: summary.blocked_count || 0,  sub: 'Need attention',                                       color: '#a16207' },
  ];
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginBottom: 12 }} data-testid="ap-weekly-queue-summary">
      {items.map(it => (
        <div key={it.label} style={{ padding: 12, background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: 8, border: '1px solid var(--cf-border, #e5e7eb)' }}>
          <div style={{ fontSize: 11, color: 'var(--cf-text-muted, #6b7280)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{it.label}</div>
          <div style={{ fontSize: 22, fontWeight: 700, color: it.color, marginTop: 4 }}>{it.value}</div>
          <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{it.sub}</div>
        </div>
      ))}
    </div>
  );
}

function ResultBlock({ result }) {
  if (result.error) return <p className="error" data-testid="ap-weekly-queue-result-error">{result.error}</p>;
  const ok    = (result.results || []).filter(r => r.ok);
  const skip  = (result.results || []).filter(r => !r.ok);
  return (
    <div style={{ margin: '12px 0', padding: 12, background: '#ecfdf5', borderLeft: '4px solid #10b981', borderRadius: 6, fontSize: 13 }} data-testid="ap-weekly-queue-result">
      <strong>{ok.length}</strong> bill(s) submitted to approver — emails dispatched.
      {skip.length > 0 && (
        <details style={{ marginTop: 6 }}>
          <summary style={{ cursor: 'pointer' }}>{skip.length} skipped</summary>
          <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
            {skip.map(s => <li key={s.bill_id}>Bill #{s.bill_id}: {s.reason}</li>)}
          </ul>
        </details>
      )}
    </div>
  );
}
