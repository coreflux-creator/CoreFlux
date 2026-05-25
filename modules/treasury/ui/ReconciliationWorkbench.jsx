import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Mercury reconciliation workbench — three-pane UI on top of the
 * existing reconciliation engine.
 *
 *   LEFT   "Unmatched"   Settled payouts the engine hasn't tied to a
 *                        bank-feed transaction yet. Operator clicks
 *                        "Run auto-match" to let the engine try.
 *
 *   MIDDLE "Discrepancies"  Matches the engine recorded BUT couldn't
 *                           confidently reconcile (amount/currency/date
 *                           mismatch, missing txn, etc.).
 *
 *   RIGHT  "Reconciled"   Fully matched + auto-confirmed.
 *
 * Backend: GET /api/mercury_reconciliation.php?action=workbench
 *          POST /api/mercury_reconciliation.php?action=run
 */
export default function ReconciliationWorkbench() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState(null);
  const [flash, setFlash] = useState(null);

  const reload = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/mercury_reconciliation.php?action=workbench');
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { reload(); }, [reload]);

  const handleRunAutoMatch = async () => {
    if (!window.confirm('Run the auto-matcher across every unreconciled settled payout? Matches are recorded immediately.')) return;
    setRunning(true); setError(null); setFlash(null);
    try {
      const r = await api.post('/api/mercury_reconciliation.php?action=run', {});
      setFlash({
        kind: 'success',
        msg: `Engine ran: ${r.matched ?? 0} matched, ${r.discrepancy ?? 0} discrepancies, ${r.skipped ?? 0} skipped.`,
      });
      await reload();
    } catch (e) { setError(e.message || 'Run failed'); }
    finally { setRunning(false); }
  };

  const stats       = data?.stats || {};
  const unmatched   = data?.unmatched || [];
  const discrepancy = data?.discrepancy || [];
  const matched     = data?.matched || [];

  return (
    <section data-testid="reconciliation-workbench" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: '1rem', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '0.75rem' }}>
        <div>
          <h2 style={{ margin: 0 }}>Reconciliation workbench</h2>
          <p style={{ color: '#64748b', fontSize: 13, marginTop: 4 }}>
            Match settled Mercury payouts to bank-feed transactions. Auto-matcher uses amount + counterparty + settlement window.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={reload}
            disabled={loading}
            data-testid="reconciliation-refresh-btn"
          >{loading ? 'Refreshing…' : 'Refresh'}</button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={handleRunAutoMatch}
            disabled={running}
            data-testid="reconciliation-run-btn"
          >{running ? 'Running…' : 'Run auto-match'}</button>
        </div>
      </header>

      <div data-testid="reconciliation-stats" style={{
        display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
        gap: '0.5rem', marginBottom: '1rem',
      }}>
        <Stat label="Settled, unreconciled"     value={stats.settled_unreconciled}  tone="warn"
              testid="recon-stat-unreconciled" />
        <Stat label="Reconciled"                value={stats.reconciled_total}      tone="ok"
              testid="recon-stat-reconciled" />
        <Stat label="Recent discrepancies"      value={stats.discrepancy_count}     tone="bad"
              testid="recon-stat-discrepancies" />
        <Stat label="Oldest unmatched (days)"   value={stats.oldest_unreconciled_days ?? '—'} tone="warn"
              testid="recon-stat-oldest" />
      </div>

      {error && <div className="error" data-testid="reconciliation-error" style={{ marginBottom: 8 }}>{error}</div>}
      {flash && (
        <div data-testid="reconciliation-flash" style={{
          background: flash.kind === 'success' ? '#ecfdf5' : '#fef2f2',
          border: `1px solid ${flash.kind === 'success' ? '#a7f3d0' : '#fecaca'}`,
          color:  flash.kind === 'success' ? '#064e3b' : '#7f1d1d',
          padding: '0.5rem 0.75rem', borderRadius: 6, marginBottom: 8, fontSize: 13,
        }}>{flash.msg}</div>
      )}

      <div style={{
        display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '0.75rem',
        alignItems: 'start',
      }}>
        <Pane title="Unmatched" tone="warn" count={unmatched.length} testid="recon-pane-unmatched"
              hint="Settled payouts awaiting a matching bank-feed transaction.">
          {unmatched.length === 0
            ? <Empty testid="recon-empty-unmatched">No unmatched payouts — well done.</Empty>
            : unmatched.map(row => (
                <PaymentRow key={row.id} row={row} testid={`recon-unmatched-${row.id}`} />
              ))}
        </Pane>

        <Pane title="Discrepancies" tone="bad" count={discrepancy.length} testid="recon-pane-discrepancy"
              hint="Engine found a candidate match but the amount/currency/date didn't agree. Review and resolve manually.">
          {discrepancy.length === 0
            ? <Empty testid="recon-empty-discrepancy">No open discrepancies.</Empty>
            : discrepancy.map(row => (
                <MatchRow key={row.id} row={row} testid={`recon-discrepancy-${row.id}`} kind="discrepancy" />
              ))}
        </Pane>

        <Pane title="Reconciled" tone="ok" count={matched.length} testid="recon-pane-reconciled"
              hint="Engine matched the payout to a confirmed bank-feed transaction.">
          {matched.length === 0
            ? <Empty testid="recon-empty-reconciled">Nothing reconciled yet. Run the auto-matcher above.</Empty>
            : matched.map(row => (
                <MatchRow key={row.id} row={row} testid={`recon-matched-${row.id}`} kind="matched" />
              ))}
        </Pane>
      </div>
    </section>
  );
}

function Pane({ title, count, tone, hint, children, testid }) {
  const palette = {
    warn: { border: '#fde68a', bg: '#fffbeb', accent: '#b45309' },
    bad:  { border: '#fecaca', bg: '#fef2f2', accent: '#991b1b' },
    ok:   { border: '#bbf7d0', bg: '#f0fdf4', accent: '#166534' },
  }[tone] || { border: '#e2e8f0', bg: '#f8fafc', accent: '#475569' };
  return (
    <div data-testid={testid} style={{
      border: `1px solid ${palette.border}`,
      borderRadius: 8,
      background: palette.bg,
      minHeight: 240,
    }}>
      <header style={{
        padding: '0.5rem 0.75rem',
        borderBottom: `1px solid ${palette.border}`,
        display: 'flex', justifyContent: 'space-between', alignItems: 'baseline',
      }}>
        <strong style={{ color: palette.accent, fontSize: 13 }}>{title}</strong>
        <span style={{
          fontSize: 11, padding: '1px 8px', borderRadius: 10,
          background: 'rgba(255,255,255,0.6)', color: palette.accent,
        }} data-testid={`${testid}-count`}>{count}</span>
      </header>
      {hint && <p style={{ fontSize: 11, color: '#64748b', margin: '6px 12px 8px' }}>{hint}</p>}
      <div style={{ padding: '0 0.5rem 0.5rem', display: 'flex', flexDirection: 'column', gap: 6 }}>
        {children}
      </div>
    </div>
  );
}

function Stat({ label, value, tone, testid }) {
  const fg = { ok: '#15803d', warn: '#b45309', bad: '#991b1b' }[tone] || '#1f2937';
  return (
    <div data-testid={testid} style={{
      padding: '0.5rem 0.75rem', border: '1px solid #e2e8f0',
      borderRadius: 6, background: '#fff',
    }}>
      <div style={{ fontSize: 11, color: '#64748b' }}>{label}</div>
      <div style={{ fontSize: 18, fontWeight: 600, color: fg }} data-testid={`${testid}-value`}>
        {value ?? 0}
      </div>
    </div>
  );
}

function PaymentRow({ row, testid }) {
  return (
    <div data-testid={testid} style={{
      padding: '6px 8px', background: '#fff', borderRadius: 4,
      border: '1px solid #fde68a', fontSize: 12,
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
        <strong>{row.recipient_name || 'Unknown recipient'}</strong>
        <code>{(row.amount_cents / 100).toLocaleString('en-US', { style: 'currency', currency: row.currency || 'USD' })}</code>
      </div>
      <div style={{ color: '#64748b', fontSize: 11, marginTop: 2 }}>
        #{row.id} · settled {row.payout_settled_at || '—'}
        {row.payout_mercury_txn_id && <> · txn <code>{row.payout_mercury_txn_id}</code></>}
      </div>
    </div>
  );
}

function MatchRow({ row, testid, kind }) {
  const palette = kind === 'matched'
    ? { border: '#bbf7d0' }
    : { border: '#fecaca' };
  return (
    <div data-testid={testid} style={{
      padding: '6px 8px', background: '#fff', borderRadius: 4,
      border: `1px solid ${palette.border}`, fontSize: 12,
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
        <strong>{row.recipient_name || 'Unknown recipient'}</strong>
        {row.pi_amount != null && (
          <code>{(row.pi_amount / 100).toLocaleString('en-US', { style: 'currency', currency: row.pi_currency || 'USD' })}</code>
        )}
      </div>
      <div style={{ color: '#64748b', fontSize: 11, marginTop: 2 }}>
        instr #{row.instruction_id} · matched {row.matched_at}
        {row.txn_id && <> · txn <code>{row.txn_id}</code></>}
        {row.reason && <> · {row.reason}</>}
      </div>
    </div>
  );
}

function Empty({ children, testid }) {
  return (
    <p data-testid={testid} style={{ color: '#94a3b8', fontSize: 12, fontStyle: 'italic', margin: '8px 4px' }}>
      {children}
    </p>
  );
}
