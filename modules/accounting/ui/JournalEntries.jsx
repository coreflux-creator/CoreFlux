import React, { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';

const JOURNAL_ENTRIES_API = '/api/v1/accounting/journal-entries';
const ACCOUNTS_API = '/api/v1/accounting/accounts';

/**
 * Journal Entries — list, detail, manual post, reverse.
 */
export default function JournalEntries() {
  const [view, setView] = useState({ mode: 'list' });

  return (
    <section data-testid="accounting-journal">
      {view.mode === 'list'   && <List   onOpen={(id) => setView({ mode: 'detail', id })} onNew={() => setView({ mode: 'new' })} />}
      {view.mode === 'detail' && <Detail id={view.id} onBack={() => setView({ mode: 'list' })} />}
      {view.mode === 'new'    && <ManualPost onDone={() => setView({ mode: 'list' })} onCancel={() => setView({ mode: 'list' })} />}
    </section>
  );
}

function List({ onOpen, onNew }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const { activeEntityId, activeEntity } = useActiveEntity();
  const accountCode = searchParams.get('account_code') || '';
  const from        = searchParams.get('from')         || '';
  const to          = searchParams.get('to')           || '';
  const qs = new URLSearchParams();
  if (accountCode) qs.set('account_code', accountCode);
  if (from)        qs.set('from', from);
  if (to)          qs.set('to', to);
  if (activeEntityId) qs.set('entity_id', String(activeEntityId));
  const apiUrl = JOURNAL_ENTRIES_API + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error } = useApi(apiUrl);
  const rows = data?.rows ?? [];
  const filterActive = !!(accountCode || from || to || activeEntityId);
  return (
    <div data-testid="accounting-journal-list">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <h2 style={{ margin: 0 }}>Journal Entries</h2>
        <button className="btn btn--primary" data-testid="accounting-journal-new" onClick={onNew}>+ New JE</button>
      </header>
      {filterActive && (
        <div data-testid="accounting-journal-filter-pill" style={{ display: 'inline-flex', gap: 8, alignItems: 'center', padding: '6px 10px', background: '#dbeafe', color: '#1e40af', borderRadius: 6, fontSize: 12, marginBottom: 8 }}>
          <span>
            Filtered by{accountCode ? <> account <code>{accountCode}</code></> : null}
            {from ? <> · from {from}</> : null}{to ? <> · to {to}</> : null}
            {activeEntity ? <> · entity <code data-testid="accounting-journal-filter-entity">{activeEntity.code}</code></> : null}
          </span>
          <button className="btn btn--ghost" data-testid="accounting-journal-filter-clear" onClick={() => setSearchParams({})} style={{ padding: '0 6px' }}>×</button>
        </div>
      )}
      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Number</th><th>Date</th><th>Source</th><th>Status</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th>Memo</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={7} className="empty" data-testid="accounting-journal-empty">No journal entries yet.</td></tr>}
          {rows.map((r) => (
            <tr key={r.id} data-testid={`accounting-journal-row-${r.je_number}`} onClick={() => onOpen(r.id)} style={{ cursor: 'pointer' }}>
              <td><code>{r.je_number}</code></td>
              <td>{r.posting_date}</td>
              <td>{r.source_module}{r.source_ref_type ? `:${r.source_ref_type}#${r.source_ref_id}` : ''}</td>
              <td><span className={`badge badge--${r.status}`}>{r.status}</span></td>
              <td style={{ textAlign: 'right' }}>{fmt(r.total_debit)}</td>
              <td style={{ textAlign: 'right' }}>{fmt(r.total_credit)}</td>
              <td style={{ color: '#666' }}>{r.memo || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Detail({ id, onBack }) {
  const { data, loading, error, reload } = useApi(`${JOURNAL_ENTRIES_API}/${id}`);
  const [reason, setReason] = useState('');
  const [busy, setBusy]     = useState(false);
  const [err2, setErr2]     = useState(null);
  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error">Error: {error.message}</p>;
  const je = data?.entry; const lines = data?.lines ?? [];
  if (!je) return null;

  const reverse = async () => {
    if (!reason.trim()) { setErr2({ message: 'reason required' }); return; }
    if (!confirm(`Reverse ${je.je_number}? A new JE with opposite signs will be posted.`)) return;
    setBusy(true); setErr2(null);
    try {
      await api.post(`${JOURNAL_ENTRIES_API}/${id}/reverse`, { reason });
      reload();
    } catch (e) { setErr2(e); }
    finally     { setBusy(false); }
  };

  return (
    <div data-testid="accounting-journal-detail">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div>
          <button type="button" className="btn btn--ghost" onClick={onBack} data-testid="accounting-journal-back">← Back</button>
          <h2 style={{ margin: '6px 0 0' }}>{je.je_number} <span className={`badge badge--${je.status}`}>{je.status}</span></h2>
          <p style={{ color: '#666', fontSize: 13 }}>{je.posting_date} · {je.source_module}{je.source_ref_type ? `:${je.source_ref_type}#${je.source_ref_id}` : ''} · {je.currency}</p>
        </div>
      </header>
      <p style={{ color: '#444' }}>{je.memo}</p>
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>#</th><th>Account</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th>Memo</th></tr></thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.id} data-testid={`accounting-journal-line-${l.line_no}`}>
              <td>{l.line_no}</td>
              <td><code>{l.account_code}</code> {l.account_name}</td>
              <td style={{ textAlign: 'right' }}>{fmt(l.debit)}</td>
              <td style={{ textAlign: 'right' }}>{fmt(l.credit)}</td>
              <td style={{ color: '#666' }}>{l.memo || '—'}</td>
            </tr>
          ))}
          <tr style={{ fontWeight: 700, borderTop: '2px solid #111' }}>
            <td colSpan={2}>TOTAL</td>
            <td style={{ textAlign: 'right' }} data-testid="accounting-journal-total-debit">{fmt(je.total_debit)}</td>
            <td style={{ textAlign: 'right' }} data-testid="accounting-journal-total-credit">{fmt(je.total_credit)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
      {je.status === 'posted' && (
        <div style={{ marginTop: 16, padding: 12, background: '#fffbea', borderRadius: 6, border: '1px solid #fde68a' }}>
          <h4 style={{ margin: '0 0 8px' }}>Reverse this entry</h4>
          <div style={{ display: 'flex', gap: 8 }}>
            <input className="input" placeholder="Reason (required)" value={reason} onChange={(e) => setReason(e.target.value)} data-testid="accounting-journal-reverse-reason" style={{ flex: 1 }} />
            <button type="button" className="btn" data-testid="accounting-journal-reverse" onClick={reverse} disabled={busy}>
              {busy ? 'Reversing…' : 'Reverse'}
            </button>
          </div>
          {err2 && <p className="error" data-testid="accounting-journal-reverse-error">Error: {err2.message}</p>}
        </div>
      )}
    </div>
  );
}

function ManualPost({ onDone, onCancel }) {
  const accts = useApi(`${ACCOUNTS_API}?postable=1&active=1`);
  const accounts = (accts.data?.rows ?? []).filter((a) => a.is_postable && a.active);

  const [header, setHeader] = useState({ posting_date: new Date().toISOString().slice(0, 10), memo: '' });
  const [lines, setLines]   = useState([
    { account_code: '', debit: '', credit: '', memo: '' },
    { account_code: '', debit: '', credit: '', memo: '' },
  ]);
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const td = lines.reduce((s, l) => s + (Number(l.debit)  || 0), 0);
  const tc = lines.reduce((s, l) => s + (Number(l.credit) || 0), 0);
  const balanced = Math.abs(td - tc) < 0.005 && td > 0;

  const setLine = (i, k, v) => {
    const out = [...lines];
    out[i] = { ...out[i], [k]: v };
    setLines(out);
  };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      const payload = {
        ...header,
        lines: lines
          .filter((l) => l.account_code && (Number(l.debit) || Number(l.credit)))
          .map((l) => ({ account_code: l.account_code, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0, memo: l.memo || null })),
      };
      await api.post(JOURNAL_ENTRIES_API, payload);
      onDone();
    } catch (e2) { setErr(e2); }
    finally      { setBusy(false); }
  };

  return (
    <form onSubmit={submit} data-testid="accounting-journal-new-form">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <h2 style={{ margin: 0 }}>New Journal Entry</h2>
        <button type="button" className="btn btn--ghost" onClick={onCancel} data-testid="accounting-journal-new-cancel">Cancel</button>
      </header>
      <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
        <input type="date" className="input" value={header.posting_date} onChange={(e) => setHeader({ ...header, posting_date: e.target.value })} data-testid="accounting-journal-new-date" required />
        <input className="input" placeholder="Memo" value={header.memo} onChange={(e) => setHeader({ ...header, memo: e.target.value })} data-testid="accounting-journal-new-memo" style={{ flex: 1 }} />
      </div>
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Account</th><th style={{ width: 140, textAlign: 'right' }}>Debit</th><th style={{ width: 140, textAlign: 'right' }}>Credit</th><th>Memo</th></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i} data-testid={`accounting-journal-new-line-${i}`}>
              <td>
                <select className="input" value={l.account_code} onChange={(e) => setLine(i, 'account_code', e.target.value)} data-testid={`accounting-journal-new-line-${i}-account`} required>
                  <option value="">— pick account —</option>
                  {accounts.map((a) => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}
                </select>
              </td>
              <td><input type="number" step="0.01" className="input" value={l.debit}  onChange={(e) => setLine(i, 'debit',  e.target.value)} data-testid={`accounting-journal-new-line-${i}-debit`} /></td>
              <td><input type="number" step="0.01" className="input" value={l.credit} onChange={(e) => setLine(i, 'credit', e.target.value)} data-testid={`accounting-journal-new-line-${i}-credit`} /></td>
              <td><input className="input" value={l.memo} onChange={(e) => setLine(i, 'memo', e.target.value)} data-testid={`accounting-journal-new-line-${i}-memo`} /></td>
            </tr>
          ))}
          <tr style={{ fontWeight: 700, background: '#f9fafb' }}>
            <td>Totals</td>
            <td style={{ textAlign: 'right' }} data-testid="accounting-journal-new-total-debit">{fmt(td)}</td>
            <td style={{ textAlign: 'right' }} data-testid="accounting-journal-new-total-credit">{fmt(tc)}</td>
            <td style={{ color: balanced ? '#065f46' : '#991b1b' }} data-testid="accounting-journal-new-balance">{balanced ? 'Balanced' : `Diff: ${fmt(td - tc)}`}</td>
          </tr>
        </tbody>
      </table>
      <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
        <button type="button" className="btn btn--ghost" onClick={() => setLines([...lines, { account_code: '', debit: '', credit: '', memo: '' }])} data-testid="accounting-journal-new-add-line">+ Add line</button>
        <button type="submit" className="btn btn--primary" disabled={!balanced || busy} data-testid="accounting-journal-new-submit">
          {busy ? 'Posting…' : 'Post JE'}
        </button>
      </div>
      {err && <p className="error" data-testid="accounting-journal-new-error">Error: {err.message}</p>}
    </form>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
