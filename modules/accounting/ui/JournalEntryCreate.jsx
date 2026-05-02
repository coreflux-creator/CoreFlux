import React, { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Manual Journal Entry creator.
 *
 * POST /modules/accounting/api/journal_entries.php          → create + post immediately
 * POST /modules/accounting/api/journal_entries.php?action=draft → save as draft
 *
 * Validation:
 *   - posting_date required
 *   - at least 2 lines
 *   - sum(debit) === sum(credit), > 0
 *   - each line has account_code AND exactly one of debit/credit > 0
 */
const newLine = () => ({ account_code: '', debit: '', credit: '', description: '' });

export default function JournalEntryCreate() {
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState([]);
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [memo, setMemo]   = useState('');
  const [lines, setLines] = useState([newLine(), newLine()]);
  const [busy, setBusy]   = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/modules/accounting/api/accounts.php').then((d) => {
      setAccounts(d?.rows || d?.accounts || []);
    }).catch(() => setAccounts([]));
  }, []);

  const updateLine = (i, field, val) => {
    const next = [...lines];
    next[i] = { ...next[i], [field]: val };
    setLines(next);
  };

  const totals = lines.reduce((acc, l) => {
    const d = parseFloat(l.debit)  || 0;
    const c = parseFloat(l.credit) || 0;
    return { debit: acc.debit + d, credit: acc.credit + c };
  }, { debit: 0, credit: 0 });
  const balanced = Math.abs(totals.debit - totals.credit) < 0.005 && totals.debit > 0;

  const submit = async (action) => {
    setBusy(true); setError(null);
    try {
      const payload = {
        posting_date: postingDate,
        memo,
        source_module: 'manual',
        lines: lines
          .filter(l => l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0))
          .map(l => ({
            account_code: l.account_code,
            debit:  parseFloat(l.debit)  || 0,
            credit: parseFloat(l.credit) || 0,
            description: l.description || null,
          })),
      };
      const url = '/modules/accounting/api/journal_entries.php' + (action === 'draft' ? '?action=draft' : '');
      const res = await api.post(url, payload);
      navigate(`/modules/accounting/journal-entries/${res.je_id}`);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section data-testid="accounting-je-create">
      <Link to="/modules/accounting/journal-entries" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Journal entries</Link>
      <h2 style={{ marginTop: 8 }}>New journal entry</h2>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 800 }}>
        <label style={{ fontSize: 13 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>Posting date</span>
          <input type="date" className="input" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} data-testid="accounting-je-date" style={{ display: 'block', width: '100%', marginTop: 4 }} />
        </label>
        <label style={{ fontSize: 13, gridColumn: 'span 2' }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>Memo</span>
          <input className="input" value={memo} onChange={(e) => setMemo(e.target.value)} data-testid="accounting-je-memo" style={{ display: 'block', width: '100%', marginTop: 4 }} placeholder="Optional — helps the auditor understand intent" />
        </label>
      </div>

      <h3 style={{ marginTop: 24 }}>Lines</h3>
      <table className="data-table" data-testid="accounting-je-lines">
        <thead>
          <tr><th style={{ width: 220 }}>Account</th><th>Description</th><th style={{ width: 120, textAlign: 'right' }}>Debit</th><th style={{ width: 120, textAlign: 'right' }}>Credit</th><th></th></tr>
        </thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td>
                <select
                  className="input"
                  value={l.account_code}
                  onChange={(e) => updateLine(i, 'account_code', e.target.value)}
                  data-testid={`accounting-je-line-account-${i}`}
                >
                  <option value="">— select —</option>
                  {accounts.map((a) => (
                    <option key={a.id} value={a.code}>{a.code} — {a.name}</option>
                  ))}
                </select>
              </td>
              <td>
                <input
                  className="input"
                  value={l.description}
                  onChange={(e) => updateLine(i, 'description', e.target.value)}
                  data-testid={`accounting-je-line-desc-${i}`}
                />
              </td>
              <td>
                <input
                  className="input"
                  type="number" step="0.01"
                  value={l.debit}
                  onChange={(e) => updateLine(i, 'debit', e.target.value)}
                  data-testid={`accounting-je-line-debit-${i}`}
                  style={{ textAlign: 'right' }}
                />
              </td>
              <td>
                <input
                  className="input"
                  type="number" step="0.01"
                  value={l.credit}
                  onChange={(e) => updateLine(i, 'credit', e.target.value)}
                  data-testid={`accounting-je-line-credit-${i}`}
                  style={{ textAlign: 'right' }}
                />
              </td>
              <td><button className="btn btn--ghost" onClick={() => setLines(lines.filter((_, j) => j !== i))} data-testid={`accounting-je-line-remove-${i}`}>×</button></td>
            </tr>
          ))}
          <tr>
            <td colSpan={2} style={{ fontWeight: 600 }}>Totals</td>
            <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid="accounting-je-total-debit">{totals.debit.toFixed(2)}</td>
            <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid="accounting-je-total-credit">{totals.credit.toFixed(2)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
      <button className="btn btn--ghost" onClick={() => setLines([...lines, newLine()])} data-testid="accounting-je-add-line" style={{ marginTop: 8 }}>+ Add line</button>

      <p style={{ marginTop: 16, fontSize: 13, color: balanced ? '#065f46' : '#991b1b' }} data-testid="accounting-je-balance-status">
        {balanced ? '✓ Entry is balanced.' : `Debits and credits must match. Diff: ${(totals.debit - totals.credit).toFixed(2)}`}
      </p>

      {error && <p className="error" data-testid="accounting-je-error">Error: {error}</p>}

      <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
        <button className="btn btn--ghost"   onClick={() => submit('draft')} disabled={busy || !balanced} data-testid="accounting-je-save-draft">{busy ? 'Saving…' : 'Save as draft'}</button>
        <button className="btn btn--primary" onClick={() => submit('post')}  disabled={busy || !balanced} data-testid="accounting-je-post">{busy ? 'Posting…' : 'Save & post'}</button>
      </div>
    </section>
  );
}
