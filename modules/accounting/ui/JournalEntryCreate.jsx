import React, { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import IntercompanySplitDialog from '../../../dashboard/src/components/IntercompanySplitDialog';

const JOURNAL_ENTRIES_API = '/api/v1/accounting/journal-entries';
const ACCOUNTS_API = '/api/v1/accounting/accounts';

/**
 * Manual Journal Entry creator.
 *
 * POST /api/v1/accounting/journal-entries       → create + post immediately
 * POST /api/v1/accounting/journal-entries/draft → save as draft
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
  const [icOpen, setIcOpen] = useState(false);
  const [icSeed, setIcSeed] = useState(null);

  useEffect(() => {
    api.get(`${ACCOUNTS_API}?postable=1&active=1`).then((d) => {
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
      const url = action === 'draft' ? `${JOURNAL_ENTRIES_API}/draft` : JOURNAL_ENTRIES_API;
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
        <button
          className="btn btn--ghost"
          onClick={() => {
            // Seed the IC dialog from the largest line as the "source offset"
            // (the single line that balances all the others); remaining lines
            // become the splits.
            const rows = lines.filter(l => l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0));
            if (rows.length < 2) { setError('Add at least 2 lines before splitting across entities'); return; }
            const byAmount = [...rows].sort((a, b) => (parseFloat(b.debit) + parseFloat(b.credit) || 0) - (parseFloat(a.debit) + parseFloat(a.credit) || 0));
            const offsetRow = byAmount[0];
            const offsetAmt = parseFloat(offsetRow.debit) || parseFloat(offsetRow.credit) || 0;
            const offsetSide = parseFloat(offsetRow.debit) > 0 ? 'debit' : 'credit';
            const splitSeeds = rows.filter(r => r !== offsetRow).map(r => ({
              entity_id: 1,
              account_code: r.account_code,
              amount: parseFloat(r.debit) || parseFloat(r.credit) || 0,
              memo: r.description,
            }));
            setIcSeed({
              amount: offsetAmt,
              sourceOffsetAccountCode: offsetRow.account_code,
              sourceOffsetSide: offsetSide,
              splits: splitSeeds,
            });
            setIcOpen(true);
          }}
          data-testid="accounting-je-ic-split"
          disabled={busy}
        >⊕ Split across entities</button>
      </div>
      {icOpen && icSeed && (
        <IntercompanySplitDialog
          open={icOpen}
          onClose={() => setIcOpen(false)}
          onPosted={(res) => {
            setIcOpen(false);
            if (res?.jes?.[0]?.je_id) navigate(`/modules/accounting/journal-entries/${res.jes[0].je_id}`);
          }}
          amount={icSeed.amount}
          sourceEntityId={1}
          sourceOffsetAccountCode={icSeed.sourceOffsetAccountCode}
          sourceOffsetSide={icSeed.sourceOffsetSide}
          defaultMemo={memo}
        />
      )}
    </section>
  );
}
