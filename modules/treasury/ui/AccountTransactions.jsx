import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (n || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/**
 * Shared transactions list used by both DepositDetail + LiabilityDetail.
 * For liability accounts, exposes row-level Categorize / Ignore / Unmatch
 * actions that auto-post a balanced JE via accountingPostJe (sign-aware:
 * charges debit the counterpart account, payments credit it).
 */
export default function AccountTransactions({ accountId, type, accountLabel }) {
  const { data, loading, reload } = useApi(
    `/modules/treasury/api/account_transactions.php?account_id=${accountId}&type=${type}&limit=200`
  );
  // Postable expense / revenue accounts for the categorize dropdown. Filtered
  // to is_postable=1 (no header rows) when the API supplies it.
  const { data: coa } = useApi('/modules/accounting/api/accounts.php?action=tree');

  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState(null);
  const [syncErr, setSyncErr] = useState(null);
  const [categorizingId, setCategorizingId] = useState(null);
  const [rowError, setRowError] = useState(null);

  const rows  = data?.rows || [];
  const count = data?.count || 0;
  const inflow  = data?.inflow_total  || 0;
  const outflow = data?.outflow_total || 0;
  const plaidItemPk         = data?.plaid_item_pk;
  const plaidItemExternalId = data?.plaid_item_external_id;

  const eligibleAccounts = (coa?.rows || [])
    .filter((a) => a.is_postable !== 0 && a.id !== accountId);
  const accountsById = new Map(eligibleAccounts.map((a) => [a.id, a]));

  const lineAction = async (lineId, action, extra = {}) => {
    setRowError(null);
    try {
      await api.post(
        `/modules/treasury/api/account_transactions.php?action=${action}`,
        { line_id: lineId, type, ...extra }
      );
      setCategorizingId(null);
      reload();
    } catch (e) {
      setRowError(`${action} failed: ${e.message}`);
    }
  };

  const categorizeAndPost = (lineId, counterpartId, memo, aiSuggestionId) =>
    lineAction(lineId, 'categorize_and_post', {
      counterpart_account_id: counterpartId,
      memo: memo || null,
      ai_suggestion_id: aiSuggestionId || null,
    });

  const ignoreLine  = (lineId) => lineAction(lineId, 'ignore');
  const unmatchLine = (lineId) => lineAction(lineId, 'unmatch');

  const syncNow = async () => {
    if (!plaidItemExternalId) {
      setSyncErr('This account is not connected to a Plaid item — cannot sync.');
      return;
    }
    setSyncing(true); setSyncErr(null); setSyncMsg(null);
    try {
      // Direct call to the real endpoint — no proxy. Plaid /transactions/sync
      // can take 30-60s on first sync (Plaid backfills historical activity),
      // so do not race the result; show progress instead.
      const res = await api.post('/api/plaid_sync_transactions.php', {
        item_id: plaidItemExternalId,
      });
      const added    = res.added    || 0;
      const modified = res.modified || 0;
      const removed  = res.removed  || 0;
      const unmapped = res.unmapped || 0;
      const total = added + modified + removed;
      const summary = total === 0
        ? `Up to date — no new transactions from Plaid (${res.pages || 0} page${res.pages === 1 ? '' : 's'} checked).`
            + (unmapped ? ` ${unmapped} txn${unmapped === 1 ? '' : 's'} skipped (account not mirrored).` : '')
        : `Pulled ${added} new + ${modified} updated`
            + (removed  ? ` − ${removed} removed`            : '')
            + (unmapped ? ` (skipped ${unmapped} unmapped)`  : '')
            + ` across ${res.pages || 0} page${res.pages === 1 ? '' : 's'}.`;
      setSyncMsg(summary);
      reload();
    } catch (e) {
      setSyncErr(e.message || 'Sync failed');
    } finally {
      setSyncing(false);
    }
  };

  return (
    <section className="treasury-account-transactions" data-testid={`treasury-${type}-transactions`}>
      <header className="treasury-overview__header" style={{ marginBottom: 16 }}>
        <div>
          <h2 style={{ marginBottom: 4 }}>{accountLabel}</h2>
          <p className="muted" style={{ fontSize: 13 }}>
            {type === 'deposit' ? 'Bank-feed transactions' : 'Card / loan activity'} · {count} row{count === 1 ? '' : 's'} ·{' '}
            <span style={{ color: '#065f46' }}>Inflow {fmtMoney(inflow)}</span> ·{' '}
            <span style={{ color: '#b91c1c' }}>Outflow {fmtMoney(outflow)}</span>
          </p>
        </div>
        {plaidItemExternalId && (
          <button
            onClick={syncNow}
            disabled={syncing}
            className="btn btn--primary"
            data-testid={`treasury-${type}-sync-btn`}
          >
            {syncing ? 'Syncing…' : 'Sync from Plaid'}
          </button>
        )}
      </header>

      {syncMsg && (
        <p data-testid={`treasury-${type}-sync-success`} style={{ color: '#065f46', fontSize: 13, marginBottom: 12 }}>
          {syncMsg}
        </p>
      )}
      {syncErr && (
        <p className="error" data-testid={`treasury-${type}-sync-error`} style={{ marginBottom: 12 }}>
          {syncErr}
        </p>
      )}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <div
          data-testid={`treasury-${type}-transactions-empty`}
          style={{
            padding: 24, background: 'var(--cf-surface)', border: '1px dashed var(--cf-border)',
            borderRadius: 6, textAlign: 'center', color: 'var(--cf-text-muted, #6b7280)',
          }}
        >
          <p style={{ margin: '0 0 8px', fontSize: 14 }}>No transactions yet.</p>
          {plaidItemExternalId
            ? <p style={{ margin: 0, fontSize: 12 }}>Click <strong>Sync from Plaid</strong> above to pull the most recent activity.</p>
            : <p style={{ margin: 0, fontSize: 12 }}>This account isn't connected to Plaid; transactions will appear here once a feed is wired.</p>}
        </div>
      )}

      {rowError && (
        <p className="error" data-testid={`treasury-${type}-row-error`} style={{ marginBottom: 12 }}>
          {rowError}
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid={`treasury-${type}-transactions-table`}>
          <thead>
            <tr>
              <th>Date</th>
              <th>Description</th>
              {type === 'liability' && <th>Category</th>}
              <th style={{ textAlign: 'right' }}>Amount</th>
              <th>Status</th>
              <th style={{ width: 240 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <React.Fragment key={r.id}>
                <tr data-testid={`treasury-txn-row-${r.id}`}>
                  <td style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                    {r.posted_date}
                  </td>
                  <td>
                    {r.description || r.merchant_name || '—'}
                    {r.merchant_name && r.merchant_name !== r.description && (
                      <span className="muted" style={{ fontSize: 11, marginLeft: 6 }}>
                        ({r.merchant_name})
                      </span>
                    )}
                  </td>
                  {type === 'liability' && (
                    <td className="muted" style={{ fontSize: 12 }}>{r.category || '—'}</td>
                  )}
                  <td
                    style={{
                      textAlign: 'right',
                      fontVariantNumeric: 'tabular-nums',
                      color: Number(r.amount) >= 0 ? '#065f46' : '#b91c1c',
                    }}
                  >
                    {fmtMoney(Number(r.amount))}
                  </td>
                  <td>
                    <span className={'badge ' + (
                      r.match_status === 'matched'  ? 'badge--active' :
                      r.match_status === 'ignored'  ? '' :
                                                      'badge--warn'
                    )}>
                      {r.match_status}
                    </span>
                    {r.matched_je_id && (
                      <a
                        href={`#/modules/accounting/journal-entries/${r.matched_je_id}`}
                        className="muted"
                        data-testid={`treasury-txn-je-${r.id}`}
                        style={{ fontSize: 11, marginLeft: 6 }}
                      >
                        JE #{r.matched_je_id}
                      </a>
                    )}
                  </td>
                  <td>
                    {r.match_status === 'unmatched' && r.ai_suggestion?.suggested_account_id && (
                      <AiSuggestionPill
                        suggestion={r.ai_suggestion}
                        suggestedAccount={accountsById.get(r.ai_suggestion.suggested_account_id)}
                        onAccept={() => categorizeAndPost(
                          r.id,
                          r.ai_suggestion.suggested_account_id,
                          null,
                          r.ai_suggestion.suggestion_id
                        )}
                      />
                    )}
                    {r.match_status === 'unmatched' && (
                      <>
                        <button
                          type="button"
                          className="btn btn--primary"
                          onClick={() => setCategorizingId(categorizingId === r.id ? null : r.id)}
                          data-testid={`treasury-txn-categorize-${r.id}`}
                          style={{ padding: '2px 8px', fontSize: 11, marginRight: 4 }}
                        >
                          Categorize…
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost"
                          onClick={() => ignoreLine(r.id)}
                          data-testid={`treasury-txn-ignore-${r.id}`}
                          style={{ padding: '2px 8px', fontSize: 11 }}
                        >
                          Ignore
                        </button>
                      </>
                    )}
                    {r.match_status === 'matched' && (
                      <button
                        type="button"
                        className="btn btn--ghost"
                        onClick={() => unmatchLine(r.id)}
                        data-testid={`treasury-txn-unmatch-${r.id}`}
                        style={{ padding: '2px 8px', fontSize: 11 }}
                      >
                        Unmatch
                      </button>
                    )}
                    {r.match_status === 'ignored' && (
                      <button
                        type="button"
                        className="btn btn--ghost"
                        onClick={() => unmatchLine(r.id)}
                        data-testid={`treasury-txn-unignore-${r.id}`}
                        style={{ padding: '2px 8px', fontSize: 11 }}
                      >
                        Restore
                      </button>
                    )}
                  </td>
                </tr>
                {categorizingId === r.id && (
                  <CategorizeRow
                    line={r}
                    type={type}
                    accounts={eligibleAccounts}
                    aiSuggestion={r.ai_suggestion}
                    onSave={(counterpartId, memo) => categorizeAndPost(
                      r.id, counterpartId, memo, r.ai_suggestion?.suggestion_id
                    )}
                    onCancel={() => setCategorizingId(null)}
                  />
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function AiSuggestionPill({ suggestion, suggestedAccount, onAccept }) {
  if (!suggestedAccount) return null;
  const conf = Math.round((suggestion.confidence || 0) * 100);
  // Color: ≥90% green (auto-accept threshold), 70-89% blue, 40-69% amber, <40% gray.
  const color = conf >= 90 ? '#065f46'
              : conf >= 70 ? '#1d4ed8'
              : conf >= 40 ? '#b45309'
              :              '#6b7280';
  const bg    = conf >= 90 ? '#d1fae5'
              : conf >= 70 ? '#dbeafe'
              : conf >= 40 ? '#fef3c7'
              :              '#f3f4f6';
  return (
    <div
      data-testid={`treasury-txn-ai-pill-${suggestion.suggestion_id}`}
      style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4, fontSize: 11 }}
    >
      <span
        title={suggestion.reasoning}
        style={{
          padding: '2px 6px', borderRadius: 10, background: bg, color,
          fontWeight: 600, whiteSpace: 'nowrap',
        }}
        data-testid={`treasury-txn-ai-confidence-${suggestion.suggestion_id}`}
      >
        AI: {conf}%
      </span>
      <span style={{ color: '#475569' }}>
        suggests <code>{suggestedAccount.code}</code> {suggestedAccount.name}
      </span>
      <button
        type="button"
        className="btn btn--ghost"
        onClick={onAccept}
        data-testid={`treasury-txn-ai-accept-${suggestion.suggestion_id}`}
        style={{ padding: '0 6px', fontSize: 11, color, borderColor: color }}
      >
        Accept
      </button>
      <span className="muted" style={{ fontSize: 10 }}>
        ({suggestion.source})
      </span>
    </div>
  );
}

function CategorizeRow({ line, type, accounts, aiSuggestion, onSave, onCancel }) {
  // Charges (negative amount) typically debit an EXPENSE account.
  // Payments / refunds (positive amount) credit either revenue (rare for cards)
  // or, more commonly for cards, the bank deposit account that was charged
  // for the payment (asset). We default to expense for charges and asset
  // for payments; the user can override.
  const isCharge = Number(line.amount) < 0;
  const preferredTypes = isCharge
    ? (type === 'liability' ? ['expense']           : ['expense','asset'])
    : (type === 'liability' ? ['asset','revenue']   : ['revenue','expense']);

  const grouped = preferredTypes.map((t) => ({
    type: t,
    rows: accounts.filter((a) => a.account_type === t)
                  .sort((a, b) => (a.code || '').localeCompare(b.code || '')),
  })).filter((g) => g.rows.length);
  const fallback = accounts
    .filter((a) => !preferredTypes.includes(a.account_type))
    .sort((a, b) => (a.code || '').localeCompare(b.code || ''));

  const [counterpartId, setCounterpartId] = useState(
    aiSuggestion?.suggested_account_id ? String(aiSuggestion.suggested_account_id) : ''
  );
  const [memo, setMemo]                   = useState('');
  const [busy, setBusy]                   = useState(false);

  const submit = async () => {
    if (!counterpartId) return;
    setBusy(true);
    try { await onSave(Number(counterpartId), memo); }
    finally { setBusy(false); }
  };

  return (
    <tr data-testid={`treasury-txn-categorize-row-${line.id}`}>
      <td colSpan={type === 'liability' ? 6 : 5}
          style={{ background: '#f8fafc', padding: 12 }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <label style={{ fontSize: 12, color: '#475569' }}>
            {isCharge ? 'Debit' : 'Credit'} this account:
          </label>
          <select
            className="input"
            value={counterpartId}
            onChange={(e) => setCounterpartId(e.target.value)}
            data-testid={`treasury-txn-counterpart-${line.id}`}
            style={{ minWidth: 280 }}
            autoFocus
          >
            <option value="">— Pick a GL account —</option>
            {grouped.map((g) => (
              <optgroup key={g.type} label={g.type.toUpperCase()}>
                {g.rows.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} · {a.name}
                  </option>
                ))}
              </optgroup>
            ))}
            {fallback.length > 0 && (
              <optgroup label="OTHER">
                {fallback.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} · {a.name} ({a.account_type})
                  </option>
                ))}
              </optgroup>
            )}
          </select>
          <input
            className="input"
            placeholder="Memo (optional, defaults to description)"
            value={memo}
            onChange={(e) => setMemo(e.target.value)}
            data-testid={`treasury-txn-memo-${line.id}`}
            style={{ flex: 1, minWidth: 220 }}
          />
          <button
            type="button"
            className="btn btn--primary"
            disabled={!counterpartId || busy}
            onClick={submit}
            data-testid={`treasury-txn-categorize-save-${line.id}`}
            style={{ padding: '4px 12px', fontSize: 12 }}
          >
            {busy ? 'Posting…' : 'Post JE'}
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={onCancel}
            data-testid={`treasury-txn-categorize-cancel-${line.id}`}
            style={{ padding: '4px 12px', fontSize: 12 }}
          >
            Cancel
          </button>
        </div>
        <p className="muted" style={{ fontSize: 11, margin: '6px 0 0' }}>
          Will create a balanced JE: {isCharge
            ? <>DR <strong>chosen account</strong> {fmtMoney(Math.abs(Number(line.amount)))} · CR <strong>{type === 'liability' ? 'this card' : 'this bank account'}</strong> {fmtMoney(Math.abs(Number(line.amount)))}</>
            : <>DR <strong>{type === 'liability' ? 'this card' : 'this bank account'}</strong> {fmtMoney(Math.abs(Number(line.amount)))} · CR <strong>chosen account</strong> {fmtMoney(Math.abs(Number(line.amount)))}</>
          }, post_date {line.posted_date}, idempotency-keyed so re-clicks don't double-post.
        </p>
      </td>
    </tr>
  );
}
