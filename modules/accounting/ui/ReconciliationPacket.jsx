import React, { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Reconciliation packet — print-friendly one-pager.
 * - Header: bank account, period_end, status with open/close/reopen timestamps
 * - Balance summary: statement balance, GL balance, difference
 * - Matched table: statement line ⇄ JE
 * - Unmatched table
 * - AI narrative (click Generate → persisted)
 * - Print / Save-as-PDF button
 */
export default function ReconciliationPacket() {
  const { id } = useParams();
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/reconciliations.php?action=packet&id=${id}`);
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const [reason, setReason] = useState('');

  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error">{error.message}</p>;
  if (!data)   return null;

  const r = data.reconciliation || {};
  const t = data.totals || {};

  const doAction = async (action, body) => {
    setBusy(true); setErr(null);
    try { await api.post(`/modules/accounting/api/reconciliations.php?action=${action}&id=${id}`, body || {}); reload(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <section data-testid="accounting-reconciliation-packet">
      <style>{`
        @media print {
          header.cf-no-print, button.cf-no-print, .cf-no-print { display: none !important; }
          body { background: white !important; }
          .data-table { page-break-inside: auto; }
          .data-table tr { page-break-inside: avoid; }
        }
        .packet-section { margin: 16px 0; page-break-inside: avoid; }
        .packet-kv { display: grid; grid-template-columns: 160px 1fr; gap: 4px; font-size: 13px; }
      `}</style>

      <header className="cf-no-print" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <Link to="/modules/accounting/bank-rec" style={{ fontSize: 13, color: '#666' }}>← Back to Bank Rec</Link>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn btn--ghost cf-no-print" onClick={() => window.print()} data-testid="accounting-packet-print">🖨 Print / Save PDF</button>
          <a
            className="btn btn--ghost cf-no-print"
            href={`/modules/accounting/api/export.php?type=bank_statements&bank_account_id=${r.bank_account_id}&to=${r.period_end}`}
            data-testid="accounting-packet-csv"
          >⬇ CSV (all lines)</a>
        </div>
      </header>

      <h2 style={{ margin: '0 0 4px' }}>Reconciliation Packet</h2>
      <p style={{ color: '#666', fontSize: 13, margin: '0 0 16px' }}>
        {data.bank_account?.name} · Period ending <strong>{r.period_end}</strong> · Status <span data-testid={`accounting-packet-status-${r.status}`} className="badge">{r.status}</span>
      </p>

      <div className="packet-section">
        <h3>Workflow</h3>
        <div className="packet-kv" data-testid="accounting-packet-workflow">
          <div>Opened:</div>      <div>{r.opened_at || '—'} {r.opened_by_user_id ? `by user #${r.opened_by_user_id}` : ''}</div>
          <div>Closed:</div>      <div>{r.closed_at || '—'} {r.closed_by_user_id ? `by user #${r.closed_by_user_id}` : ''}</div>
          <div>Reopened:</div>    <div>{r.reopened_at || '—'} {r.reopened_by_user_id ? `by user #${r.reopened_by_user_id}` : ''}</div>
          {r.reopen_reason && <><div>Reopen reason:</div><div>{r.reopen_reason}</div></>}
          {r.notes && <><div>Notes:</div><div>{r.notes}</div></>}
        </div>
      </div>

      <div className="packet-section">
        <h3>Balance summary</h3>
        <table className="data-table" data-testid="accounting-packet-summary" style={{ maxWidth: 520 }}>
          <tbody>
            <tr><td>Statement balance</td><td style={{ textAlign: 'right', fontWeight: 600 }}>{fmt(t.statement_balance)}</td></tr>
            <tr><td>GL balance</td><td style={{ textAlign: 'right', fontWeight: 600 }}>{fmt(t.gl_balance)}</td></tr>
            <tr style={{ background: Math.abs(t.difference || 0) < 0.01 ? '#ecfdf5' : '#fef2f2' }}>
              <td>Difference</td><td style={{ textAlign: 'right', fontWeight: 700 }} data-testid="accounting-packet-difference">{fmt(t.difference)}</td>
            </tr>
            <tr><td>Matched items</td><td style={{ textAlign: 'right' }}>{t.matched_count} · {fmt(t.matched_total)}</td></tr>
            <tr><td>Unmatched items</td><td style={{ textAlign: 'right' }}>{t.unmatched_count} · {fmt(t.unmatched_total)}</td></tr>
          </tbody>
        </table>
      </div>

      <div className="packet-section">
        <h3>Matched items ({t.matched_count})</h3>
        <table className="data-table" data-testid="accounting-packet-matched-table">
          <thead><tr><th>Date</th><th>Description</th><th style={{textAlign:'right'}}>Amount</th><th>JE</th><th>JE date</th></tr></thead>
          <tbody>
            {(data.matched || []).map(m => (
              <tr key={m.id}>
                <td>{m.posted_date}</td>
                <td>{m.description}</td>
                <td style={{textAlign:'right'}}>{fmt(m.amount)}</td>
                <td>{m.je_number || '—'}</td>
                <td>{m.je_posting_date || '—'}</td>
              </tr>
            ))}
            {(!data.matched || data.matched.length === 0) && <tr><td colSpan={5} style={{color:'#999'}}>No matched items.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="packet-section">
        <h3>Unmatched items ({t.unmatched_count})</h3>
        <table className="data-table" data-testid="accounting-packet-unmatched-table">
          <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th>Status</th><th style={{textAlign:'right'}}>Amount</th></tr></thead>
          <tbody>
            {(data.unmatched || []).map(u => (
              <tr key={u.id}>
                <td>{u.posted_date}</td>
                <td>{u.description}</td>
                <td>{u.bank_reference}</td>
                <td><span className="badge">{u.match_status}</span></td>
                <td style={{textAlign:'right'}}>{fmt(u.amount)}</td>
              </tr>
            ))}
            {(!data.unmatched || data.unmatched.length === 0) && <tr><td colSpan={5} style={{color:'#999'}}>No unmatched items.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="packet-section">
        <h3>AI narrative</h3>
        {data.ai_narrative ? (
          <div data-testid="accounting-packet-ai-narrative" style={{ background: '#f9fafb', border: '1px solid #e5e7eb', padding: 12, borderRadius: 6, whiteSpace: 'pre-wrap' }}>
            <div style={{ fontSize: 11, color: '#666', marginBottom: 6 }}>
              AI-generated · Generated {data.ai_narrative_generated_at} · Review before using externally.
            </div>
            {data.ai_narrative}
          </div>
        ) : (
          <p style={{ color: '#666', fontSize: 13 }} data-testid="accounting-packet-ai-empty">No narrative yet. Generate one to include context for reviewers.</p>
        )}
        <button
          className="btn btn--ghost cf-no-print"
          disabled={busy}
          onClick={() => doAction('generate_ai_narrative')}
          data-testid="accounting-packet-generate-narrative"
        >{busy ? 'Generating…' : (data.ai_narrative ? 'Regenerate narrative' : 'Generate AI narrative')}</button>
      </div>

      <div className="packet-section cf-no-print">
        <h3>Workflow actions</h3>
        {err && <p className="error">{err}</p>}
        {r.status !== 'closed' && (
          <button className="btn btn--primary" disabled={busy} onClick={() => doAction('close')} data-testid="accounting-packet-close">Close reconciliation</button>
        )}
        {r.status === 'closed' && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <input className="input" placeholder="Reason to reopen (required)" value={reason} onChange={e => setReason(e.target.value)} data-testid="accounting-packet-reopen-reason" />
            <button className="btn btn--ghost" disabled={busy || !reason.trim()} onClick={() => doAction('reopen', { reason })} data-testid="accounting-packet-reopen">Reopen</button>
          </div>
        )}
      </div>
    </section>
  );
}

function fmt(n) {
  const v = parseFloat(n);
  if (Number.isNaN(v)) return '—';
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
