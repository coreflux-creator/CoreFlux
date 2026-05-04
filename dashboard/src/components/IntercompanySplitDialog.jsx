import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * <IntercompanySplitDialog /> — the ONLY UI that posts an intercompany
 * split. Reused from:
 *   - Bank Reconciliation (match unmatched statement line)
 *   - Manual JE creator (split across entities)
 *   - AP Bill post (next-phase wiring)
 *
 * Props:
 *   - open        boolean; render as modal when true
 *   - onClose     () => void
 *   - onPosted    (result) => void  — called with { group_id, jes[] } on success
 *   - amount      number         — the fixed total to be distributed (e.g. bank line amount)
 *   - sourceEntityId  number
 *   - sourceOffsetAccountCode  string  — pre-filled (e.g. bank cash account or CC liability)
 *   - sourceOffsetSide  'credit'|'debit'  — 'credit' = money left source (expense side)
 *   - bankStatementLineId?  number  — optional; when present, auto-matches on post
 *   - defaultMemo?  string
 */
export default function IntercompanySplitDialog({
  open, onClose, onPosted,
  amount, sourceEntityId, sourceOffsetAccountCode, sourceOffsetSide = 'credit',
  bankStatementLineId = null, defaultMemo = '',
  postUrl = '/modules/accounting/api/intercompany.php?action=post_split',
}) {
  const [entities, setEntities] = useState([]);
  const [accounts, setAccounts] = useState([]);
  const [mappings, setMappings] = useState([]);
  const [splits, setSplits]     = useState([{ entity_id: sourceEntityId, account_code: '', amount: String(amount || 0) }]);
  const [memo, setMemo]         = useState(defaultMemo);
  const [postingDate, setDate]  = useState(new Date().toISOString().slice(0,10));
  const [busy, setBusy]         = useState(false);
  const [err, setErr]           = useState(null);

  useEffect(() => {
    if (!open) return;
    Promise.all([
      api.get('/modules/accounting/api/entities.php').catch(() => ({ rows: [] })),
      api.get('/modules/accounting/api/accounts.php'),
      api.get('/modules/accounting/api/intercompany.php'),
    ]).then(([ents, accts, maps]) => {
      setEntities(ents?.rows || ents?.entities || []);
      setAccounts(accts?.rows || accts?.accounts || []);
      setMappings(maps?.rows || []);
    });
  }, [open]);

  // Keep splits in sync if `amount` changes (new bank line opened)
  useEffect(() => {
    if (!open) return;
    setSplits([{ entity_id: sourceEntityId, account_code: '', amount: String(amount || 0) }]);
  }, [amount, sourceEntityId, open]);

  if (!open) return null;

  const totalSplit = splits.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
  const diff       = +(totalSplit - (amount || 0)).toFixed(2);
  const balanced   = Math.abs(diff) < 0.005;

  // For any cross-entity split, try to pre-resolve mapping for UI preview.
  const resolveMapping = (targetEntityId) => mappings.find(
    m => Number(m.from_entity_id) === Number(sourceEntityId) && Number(m.to_entity_id) === Number(targetEntityId) && Number(m.active) === 1
  );

  const addRow    = () => setSplits([...splits, { entity_id: sourceEntityId, account_code: '', amount: '' }]);
  const removeRow = (i) => setSplits(splits.filter((_, j) => j !== i));
  const update    = (i, field, val) => {
    const next = [...splits]; next[i] = { ...next[i], [field]: val }; setSplits(next);
  };

  const submit = async () => {
    setBusy(true); setErr(null);
    try {
      const payload = {
        posting_date: postingDate,
        memo,
        source: {
          entity_id: sourceEntityId,
          offset_line: {
            account_code: sourceOffsetAccountCode,
            amount: parseFloat(amount),
            side: sourceOffsetSide,
          },
        },
        splits: splits
          .filter(s => s.account_code && parseFloat(s.amount) > 0)
          .map(s => ({
            entity_id: Number(s.entity_id),
            account_code: s.account_code,
            amount: parseFloat(s.amount),
            memo: s.memo || null,
          })),
        bank_statement_line_id: bankStatementLineId || null,
      };
      const res = await api.post(postUrl, payload);
      if (onPosted) onPosted(res);
      onClose();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div
      role="dialog"
      data-testid="ic-split-dialog"
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 50, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', paddingTop: 40 }}
      onClick={onClose}
    >
      <div
        style={{ background: 'white', borderRadius: 8, padding: 20, width: '95%', maxWidth: 900, maxHeight: '85vh', overflow: 'auto' }}
        onClick={e => e.stopPropagation()}
      >
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <h3 style={{ margin: 0 }}>Split transaction across entities</h3>
          <button className="btn btn--ghost" onClick={onClose} data-testid="ic-split-close">✕</button>
        </header>
        <p style={{ color: '#666', fontSize: 13, margin: '0 0 12px' }}>
          Source entity offset: <code>{sourceOffsetAccountCode}</code> · {sourceOffsetSide === 'credit' ? 'Credit' : 'Debit'} ${Number(amount).toFixed(2)}. Each cross-entity split auto-books an intercompany due-from / due-to pair using the configured mapping.
        </p>

        <div style={{ display: 'flex', gap: 12, marginBottom: 12 }}>
          <label style={{ fontSize: 13 }}>Posting date<input type="date" className="input" value={postingDate} onChange={e => setDate(e.target.value)} data-testid="ic-split-date" /></label>
          <label style={{ fontSize: 13, flex: 1 }}>Memo<input className="input" value={memo} onChange={e => setMemo(e.target.value)} placeholder="e.g. Parent card — shared AWS bill" data-testid="ic-split-memo" /></label>
        </div>

        <table className="data-table" data-testid="ic-split-table">
          <thead>
            <tr><th>Entity</th><th>Expense / asset account</th><th style={{textAlign:'right'}}>Amount</th><th>IC mapping</th><th></th></tr>
          </thead>
          <tbody>
            {splits.map((s, i) => {
              const isCross = Number(s.entity_id) !== Number(sourceEntityId);
              const m       = isCross ? resolveMapping(Number(s.entity_id)) : null;
              return (
                <tr key={i} data-testid={`ic-split-row-${i}`}>
                  <td>
                    <select className="input" value={s.entity_id} onChange={e => update(i, 'entity_id', Number(e.target.value))} data-testid={`ic-split-entity-${i}`}>
                      {entities.map(e => <option key={e.id} value={e.id}>{e.legal_name || e.code}{Number(e.id) === Number(sourceEntityId) ? ' (source)' : ''}</option>)}
                    </select>
                  </td>
                  <td>
                    <select className="input" value={s.account_code} onChange={e => update(i, 'account_code', e.target.value)} data-testid={`ic-split-account-${i}`}>
                      <option value="">— select —</option>
                      {accounts.map(a => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}
                    </select>
                  </td>
                  <td><input className="input" type="number" step="0.01" value={s.amount} onChange={e => update(i, 'amount', e.target.value)} data-testid={`ic-split-amount-${i}`} style={{textAlign:'right'}} /></td>
                  <td style={{ fontSize: 11 }}>
                    {!isCross && <span style={{ color: '#999' }}>— (same entity)</span>}
                    {isCross && m && (
                      <span data-testid={`ic-split-mapping-${i}`} style={{ color: '#065f46' }}>
                        DR {m.due_from_account_code} / CR {m.due_to_account_code}
                      </span>
                    )}
                    {isCross && !m && (
                      <span data-testid={`ic-split-mapping-missing-${i}`} style={{ color: '#b91c1c' }}>
                        ⚠ No mapping — configure in Settings
                      </span>
                    )}
                  </td>
                  <td>{splits.length > 1 && <button className="btn btn--ghost" onClick={() => removeRow(i)} data-testid={`ic-split-remove-${i}`}>×</button>}</td>
                </tr>
              );
            })}
            <tr>
              <td colSpan={2} style={{fontWeight:600}}>Totals</td>
              <td style={{textAlign:'right', fontWeight:600, color: balanced ? '#065f46' : '#b91c1c'}} data-testid="ic-split-total">
                ${totalSplit.toFixed(2)}
              </td>
              <td colSpan={2} style={{fontSize:12}}>
                {balanced ? '✓ Balances against source offset' : `Diff $${diff.toFixed(2)} — splits must equal source amount`}
              </td>
            </tr>
          </tbody>
        </table>
        <button className="btn btn--ghost" onClick={addRow} data-testid="ic-split-add" style={{marginTop:8}}>+ Add split</button>

        {err && <p className="error" data-testid="ic-split-error" style={{marginTop:8}}>{err}</p>}
        <footer style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16, borderTop: '1px solid #e5e7eb', paddingTop: 12 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="ic-split-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !balanced} data-testid="ic-split-post">
            {busy ? 'Posting…' : `Post ${new Set(splits.map(s => Number(s.entity_id))).size} JE(s)`}
          </button>
        </footer>
      </div>
    </div>
  );
}
