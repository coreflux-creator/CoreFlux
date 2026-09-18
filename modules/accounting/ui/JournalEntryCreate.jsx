import React, { useState, useEffect } from 'react';
import { useNavigate, Link, useParams, useSearchParams } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import IntercompanySplitDialog from '../../../dashboard/src/components/IntercompanySplitDialog';
import { ArrowLeft, Copy, Plus, Save, Send, Trash2 } from 'lucide-react';

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
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const copyFrom = searchParams.get('copy_from');
  const isEdit = Boolean(id);
  const [accounts, setAccounts] = useState([]);
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [memo, setMemo]   = useState('');
  const [lines, setLines] = useState([newLine(), newLine()]);
  const [busy, setBusy]   = useState(false);
  const [loadingExisting, setLoadingExisting] = useState(Boolean(id || copyFrom));
  const [error, setError] = useState(null);
  const [sourceEntry, setSourceEntry] = useState(null);
  const [icOpen, setIcOpen] = useState(false);
  const [icSeed, setIcSeed] = useState(null);

  useEffect(() => {
    api.get('/modules/accounting/api/accounts.php').then((d) => {
      setAccounts(d?.rows || d?.accounts || []);
    }).catch(() => setAccounts([]));
  }, []);

  useEffect(() => {
    const sourceId = id || copyFrom;
    if (!sourceId) return;
    let cancelled = false;
    setLoadingExisting(true);
    setError(null);
    api.get(`/modules/accounting/api/journal_entries.php?id=${sourceId}`)
      .then((data) => {
        if (cancelled) return;
        const entry = data?.entry;
        if (!entry) throw new Error('Journal entry not found.');
        if (isEdit && entry.status !== 'draft') {
          throw new Error('Only draft journal entries can be edited. Reverse a posted entry instead.');
        }
        if (isEdit && (entry.source_module === 'system' || ['ai_workflow', 'workflow_run'].includes(entry.source_ref_type))) {
          throw new Error('System-generated drafts must be reviewed in AI Agents.');
        }
        setSourceEntry(entry);
        setPostingDate(isEdit ? entry.posting_date : new Date().toISOString().slice(0, 10));
        setMemo(isEdit
          ? (entry.memo || '')
          : `Copy of ${entry.je_number}${entry.memo ? `: ${entry.memo}` : ''}`);
        setLines((data?.lines || []).map((line) => ({
          account_code: line.account_code || '',
          debit: Number(line.debit) > 0 ? String(line.debit) : '',
          credit: Number(line.credit) > 0 ? String(line.credit) : '',
          description: line.description || line.memo || '',
          counterparty_company_id: line.counterparty_company_id || null,
          counterparty_person_id: line.counterparty_person_id || null,
          counterparty_entity_id: line.counterparty_entity_id || null,
          dims: parseDims(line.dim_json),
        })));
      })
      .catch((e) => { if (!cancelled) setError(e.message || String(e)); })
      .finally(() => { if (!cancelled) setLoadingExisting(false); });
    return () => { cancelled = true; };
  }, [id, copyFrom, isEdit]);

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
        ...(sourceEntry?.entity_id ? { entity_id: sourceEntry.entity_id } : {}),
        posting_date: postingDate,
        currency: sourceEntry?.currency || 'USD',
        memo,
        source_module: 'manual',
        lines: lines
          .filter(l => l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0))
          .map(l => ({
            account_code: l.account_code,
            debit:  parseFloat(l.debit)  || 0,
            credit: parseFloat(l.credit) || 0,
            description: l.description || null,
            counterparty_company_id: l.counterparty_company_id || null,
            counterparty_person_id: l.counterparty_person_id || null,
            counterparty_entity_id: l.counterparty_entity_id || null,
            dims: l.dims || {},
          })),
      };
      let res;
      if (isEdit) {
        res = await api.patch(`/modules/accounting/api/journal_entries.php?id=${id}`, payload);
        if (action === 'post') {
          res = await api.post(`/modules/accounting/api/journal_entries.php?action=post_draft&id=${id}`, {});
        }
      } else {
        const url = '/modules/accounting/api/journal_entries.php' + (action === 'draft' ? '?action=draft' : '');
        res = await api.post(url, payload);
      }
      navigate(`/modules/accounting/journal-entries/${res.je_id}`);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setBusy(false);
    }
  };

  if (loadingExisting) {
    return <p data-testid="accounting-je-create-loading">Loading journal entry…</p>;
  }
  if ((isEdit || copyFrom) && !sourceEntry && error) {
    return (
      <section className="ledger-page" data-testid="accounting-je-create-source-error">
        <Link to="/modules/accounting/journal-entries" className="entry-detail__back-link"><ArrowLeft size={14} aria-hidden="true" />Journal entries</Link>
        <p className="error">Could not prepare this entry: {error}</p>
      </section>
    );
  }

  return (
    <section className="ledger-page" data-testid="accounting-je-create">
      <Link
        to={isEdit ? `/modules/accounting/journal-entries/${id}` : '/modules/accounting/journal-entries'}
        className="entry-detail__back-link"
      ><ArrowLeft size={14} aria-hidden="true" />{isEdit ? 'Journal entry' : 'Journal entries'}</Link>
      <header className="entry-editor__header">
        <div>
          <h2>{isEdit ? `Edit draft ${sourceEntry?.je_number || ''}` : 'New journal entry'}</h2>
          <p>{isEdit ? 'Changes remain off the ledger until you post the draft.' : 'Build a balanced entry, then save a draft or post it.'}</p>
        </div>
      </header>

      {!isEdit && sourceEntry && (
        <div className="entry-status-note entry-status-note--info" data-testid="accounting-je-copy-notice">
          <Copy size={16} aria-hidden="true" />
          <span>Copied from <Link to={`/modules/accounting/journal-entries/${sourceEntry.id}`}>{sourceEntry.je_number}</Link>. Review every line before posting; the original entry is unchanged.</span>
        </div>
      )}

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
              <td>
                <button
                  type="button"
                  className="btn btn--ghost btn--icon"
                  onClick={() => setLines(lines.filter((_, j) => j !== i))}
                  data-testid={`accounting-je-line-remove-${i}`}
                  aria-label={`Remove line ${i + 1}`}
                  title={`Remove line ${i + 1}`}
                ><Trash2 size={15} aria-hidden="true" /></button>
              </td>
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
      <button type="button" className="btn btn--ghost" onClick={() => setLines([...lines, newLine()])} data-testid="accounting-je-add-line" style={{ marginTop: 8 }}><Plus size={15} aria-hidden="true" />Add line</button>

      <p style={{ marginTop: 16, fontSize: 13, color: balanced ? '#065f46' : '#991b1b' }} data-testid="accounting-je-balance-status">
        {balanced ? '✓ Entry is balanced.' : `Debits and credits must match. Diff: ${(totals.debit - totals.credit).toFixed(2)}`}
      </p>

      {error && <p className="error" data-testid="accounting-je-error">Error: {error}</p>}

      <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
        <button type="button" className="btn btn--ghost" onClick={() => submit('draft')} disabled={busy || !balanced} data-testid="accounting-je-save-draft"><Save size={15} aria-hidden="true" />{busy ? 'Saving…' : (isEdit ? 'Save draft' : 'Save as draft')}</button>
        <button type="button" className="btn btn--primary" onClick={() => submit('post')} disabled={busy || !balanced} data-testid="accounting-je-post"><Send size={15} aria-hidden="true" />{busy ? 'Posting…' : 'Save & post'}</button>
        {!isEdit && <button
          type="button"
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
        ><Plus size={15} aria-hidden="true" />Split across entities</button>}
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

function parseDims(value) {
  if (!value) return {};
  if (typeof value === 'object') return value;
  try { return JSON.parse(value) || {}; }
  catch { return {}; }
}
