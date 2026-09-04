import React, { useEffect, useMemo, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney, fmtDate } from '../../../dashboard/src/lib/format';
import CsvUploadWidget from '../../../dashboard/src/components/CsvUploadWidget';

const ACCOUNTING_ACCOUNTS_API = '/modules/accounting/api/accounts.php';
const STATUS_TABS = [
  { id: 'pending', label: 'Pending', matchStatus: 'unmatched' },
  { id: 'posted', label: 'Posted', matchStatus: 'matched' },
  { id: 'excluded', label: 'Excluded', matchStatus: 'ignored' },
];

const statusLabel = (matchStatus) => (
  matchStatus === 'matched' ? 'Posted' : matchStatus === 'ignored' ? 'Excluded' : 'Pending'
);

const formatAccountMoney = (value, currency = 'USD') => {
  if (value === null || value === undefined || value === '') return '—';
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(Number(value));
  } catch {
    return fmtMoney(Number(value));
  }
};

/**
 * Shared transactions list used by both DepositDetail + LiabilityDetail.
 * For liability accounts, exposes row-level Categorize / Ignore / Unmatch
 * actions that auto-post a balanced JE via accountingPostJe (sign-aware:
 * charges debit the counterpart account, payments credit it).
 */
export default function AccountTransactions({ accountId, type, accountLabel }) {
  const [activeTab, setActiveTab] = useState('pending');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [order, setOrder] = useState('newest_first');
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [bulkAccountId, setBulkAccountId] = useState('');
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkMsg, setBulkMsg] = useState(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 250);
    return () => window.clearTimeout(timer);
  }, [search]);

  const feedPath = useMemo(() => (
    `/modules/treasury/api/account_transactions.php?account_id=${accountId}`
    + `&type=${type}&limit=500&status=${activeTab}&order=${order}`
    + `&q=${encodeURIComponent(debouncedSearch)}`
  ), [accountId, type, activeTab, order, debouncedSearch]);

  const { data, loading, reload } = useApi(feedPath);
  // Postable expense / revenue accounts for the categorize dropdown. Filtered
  // to is_postable=1 (no header rows) when the API supplies it.
  const { data: coa, mutate: mutateCoa } = useApi(`${ACCOUNTING_ACCOUNTS_API}?action=tree`);

  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState(null);
  const [syncErr, setSyncErr] = useState(null);
  const [categorizingId, setCategorizingId] = useState(null);
  const [rowError, setRowError] = useState(null);
  // Sprint 6h — AI cat. + Split/IC affordances now mirror Bank Rec.
  const [aiBusyId, setAiBusyId] = useState(null);
  const [aiPanelByLine, setAiPanelByLine] = useState({});  // { [lineId]: aiResp }
  const [splitId, setSplitId] = useState(null);

  useEffect(() => {
    setSelectedIds(new Set());
    setBulkAccountId('');
    setBulkMsg(null);
  }, [accountId, type, activeTab, order, debouncedSearch]);

  const fetchAiCat = async (lineId) => {
    setAiBusyId(lineId); setRowError(null);
    try {
      const res = await api.post(`/modules/accounting/api/bank_ai.php?action=suggest_categorize&line_id=${lineId}`);
      setAiPanelByLine(prev => ({ ...prev, [lineId]: { action: 'suggest_categorize', ...res } }));
    } catch (e) {
      setRowError(`AI suggestion failed: ${e.message}`);
    } finally {
      setAiBusyId(null);
    }
  };
  const dismissAi = (lineId) => setAiPanelByLine(prev => { const p = { ...prev }; delete p[lineId]; return p; });

  const rows  = data?.rows || [];
  const count = data?.count || 0;
  const inflow  = data?.inflow_total  || 0;
  const outflow = data?.outflow_total || 0;
  const plaidItemExternalId = data?.plaid_item_external_id;
  const currency            = data?.currency || 'USD';
  const statusCounts        = data?.status_counts || {};

  const eligibleAccounts = (coa?.rows || [])
    .filter((a) => a.is_postable !== 0 && a.id !== accountId);
  const accountsById = new Map(eligibleAccounts.map((a) => [a.id, a]));
  const selectedRows = rows.filter((row) => selectedIds.has(row.id));
  const allVisibleSelected = rows.length > 0 && rows.every((row) => selectedIds.has(row.id));

  const handleAccountCreated = (account) => {
    mutateCoa((current) => ({
      ...(current || {}),
      rows: [...(current?.rows || []), account]
        .sort((a, b) => String(a.code || '').localeCompare(String(b.code || ''))),
    }));
  };

  const toggleSelected = (lineId) => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(lineId)) next.delete(lineId);
      else next.add(lineId);
      return next;
    });
  };

  const toggleAllVisible = () => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (allVisibleSelected) rows.forEach((row) => next.delete(row.id));
      else rows.forEach((row) => next.add(row.id));
      return next;
    });
  };

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

  const runBulkAction = async (action, extra = {}) => {
    const ids = Array.from(selectedIds);
    if (!ids.length) return;
    if (action === 'categorize_and_post' && !extra.counterpart_account_id) {
      setRowError('Choose a G/L account before posting the selected transactions.');
      return;
    }
    setBulkBusy(true);
    setBulkMsg(null);
    setRowError(null);
    let updated = 0;
    const failures = [];
    // Keep JE creation sequential so auto-numbering remains deterministic.
    for (const lineId of ids) {
      try {
        await api.post(
          `/modules/treasury/api/account_transactions.php?action=${action}`,
          { line_id: lineId, type, ...extra }
        );
        updated += 1;
      } catch (error) {
        failures.push(`#${lineId}: ${error.message}`);
      }
    }
    setBulkBusy(false);
    setSelectedIds(new Set());
    setBulkAccountId('');
    setBulkMsg(`${updated} transaction${updated === 1 ? '' : 's'} updated.`);
    if (failures.length) {
      setRowError(`${failures.length} transaction${failures.length === 1 ? '' : 's'} could not be updated. ${failures.join(' · ')}`);
    }
    reload();
  };

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
            {type === 'deposit' ? 'Bank-feed transactions' : 'Card / loan activity'} · {data?.total_count ?? count} transaction{(data?.total_count ?? count) === 1 ? '' : 's'} ·{' '}
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

      {type === 'deposit' && (
        <div className="bank-feed-summary" data-testid="treasury-bank-feed-balances">
          <BalanceCard
            label="Balance in bank"
            value={formatAccountMoney(data?.bank_balance, currency)}
            detail={data?.balance_as_of ? `As of ${fmtDate(data.balance_as_of)}` : (plaidItemExternalId ? 'Latest bank-reported balance' : 'Connect a bank feed to see this')}
            testId="treasury-bank-balance"
          />
          <BalanceCard
            label="In the books"
            value={formatAccountMoney(data?.gl_balance, currency)}
            detail="Posted G/L balance"
            testId="treasury-gl-balance"
          />
          <BalanceCard
            label="Difference"
            value={formatAccountMoney(data?.balance_difference, currency)}
            detail={data?.balance_difference === null || data?.balance_difference === undefined
              ? 'Available after a bank balance is received'
              : Math.abs(Number(data.balance_difference)) < 0.005 ? 'Bank and books agree' : 'Needs review or reconciliation'}
            tone={Math.abs(Number(data?.balance_difference || 0)) < 0.005 ? 'good' : 'warn'}
            testId="treasury-balance-difference"
          />
          <BalanceCard
            label="Available"
            value={formatAccountMoney(data?.available_balance, currency)}
            detail="Available to spend"
            testId="treasury-available-balance"
          />
        </div>
      )}

      <div className="bank-feed-workspace" data-testid="treasury-bank-feed-workspace">
        <div className="bank-feed-tabs" role="tablist" aria-label="Transaction status">
          {STATUS_TABS.map((tab) => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={activeTab === tab.id}
              className={`bank-feed-tab${activeTab === tab.id ? ' is-active' : ''}`}
              onClick={() => setActiveTab(tab.id)}
              data-testid={`treasury-bank-feed-tab-${tab.id}`}
            >
              {tab.label}
              <span>{statusCounts[tab.id] ?? 0}</span>
            </button>
          ))}
        </div>
        <div className="bank-feed-filters">
          <label className="bank-feed-search">
            <span className="sr-only">Search transactions</span>
            <input
              type="search"
              className="input"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search description, amount, reference…"
              data-testid="treasury-bank-feed-search"
            />
          </label>
          <select
            className="input bank-feed-sort"
            value={order}
            onChange={(event) => setOrder(event.target.value)}
            aria-label="Sort transactions"
            data-testid="treasury-bank-feed-sort"
          >
            <option value="newest_first">Newest first</option>
            <option value="oldest_first">Oldest first</option>
            <option value="amount_desc">Largest amount</option>
          </select>
        </div>
      </div>

      {/* CSV upload — for deposit (bank) accounts without a Plaid feed,
          or for backfilling history beyond what Plaid retains. Lines
          land in accounting_bank_statement_lines exactly like Plaid-
          sourced rows, so the existing matching flow picks them up. */}
      {type === 'deposit' && (
        <CsvUploadWidget
          testIdPrefix={`treasury-${type}-csv`}
          endpoint="/api/v1/treasury/import-csv"
          extraFields={{ bank_account_id: accountId }}
          accept=".csv,text/csv"
          label={plaidItemExternalId
            ? 'Backfill this existing Plaid account from CSV'
            : 'Import a bank statement CSV — this account isn\'t connected to Plaid'}
          hint="Use CSV for dates Plaid did not provide. New Plaid connections request up to 730 days; an existing connection keeps its original history window. Header row required. Accepted columns: Date / Posting Date · Description / Memo · Amount (or Debit + Credit) · optional Reference / Check Number. Re-uploading the same file is a no-op (deduped via synthesised fitid)."
          onSuccess={() => reload()}
        />
      )}

      {loading && <p className="muted" data-testid="treasury-bank-feed-loading">Updating transactions…</p>}
      {!loading && rows.length === 0 && (
        <div
          data-testid={`treasury-${type}-transactions-empty`}
          style={{
            padding: 24, background: 'var(--cf-surface)', border: '1px dashed var(--cf-border)',
            borderRadius: 6, textAlign: 'center', color: 'var(--cf-text-muted, #6b7280)',
          }}
        >
          <p style={{ margin: '0 0 8px', fontSize: 14 }}>
            No {activeTab} transactions{debouncedSearch ? ' match this search' : ''}.
          </p>
          {!debouncedSearch && activeTab === 'pending' && (plaidItemExternalId
            ? <p style={{ margin: 0, fontSize: 12 }}>Everything is reviewed. Sync from Plaid to check for new activity.</p>
            : <p style={{ margin: 0, fontSize: 12 }}>Import a statement CSV or connect this account to start reviewing transactions.</p>)}
        </div>
      )}

      {rowError && (
        <p className="error" data-testid={`treasury-${type}-row-error`} style={{ marginBottom: 12 }}>
          {rowError}
        </p>
      )}

      {bulkMsg && (
        <p className="bank-feed-bulk-success" data-testid="treasury-bank-feed-bulk-success">{bulkMsg}</p>
      )}

      {selectedRows.length > 0 && (
        <div className="bank-feed-bulk" data-testid="treasury-bank-feed-bulk-toolbar">
          <strong>{selectedRows.length} selected</strong>
          {activeTab === 'pending' && (
            <>
              <div className="bank-feed-bulk__picker">
                <AccountPicker
                  accounts={eligibleAccounts}
                  value={bulkAccountId}
                  onChange={setBulkAccountId}
                  onAccountCreated={handleAccountCreated}
                  testIdPrefix="treasury-bank-feed-bulk-account"
                  placeholder="Choose G/L account for selected…"
                />
              </div>
              <button
                type="button"
                className="btn btn--primary"
                disabled={bulkBusy || !bulkAccountId}
                onClick={() => runBulkAction('categorize_and_post', { counterpart_account_id: Number(bulkAccountId) })}
                data-testid="treasury-bank-feed-bulk-post"
              >
                {bulkBusy ? 'Updating…' : 'Post selected'}
              </button>
              <button
                type="button"
                className="btn"
                disabled={bulkBusy}
                onClick={() => runBulkAction('ignore')}
                data-testid="treasury-bank-feed-bulk-exclude"
              >
                Exclude selected
              </button>
            </>
          )}
          {activeTab === 'posted' && (
            <button
              type="button"
              className="btn"
              disabled={bulkBusy}
              onClick={() => runBulkAction('unmatch')}
              data-testid="treasury-bank-feed-bulk-unpost"
            >
              {bulkBusy ? 'Updating…' : 'Move selected to pending'}
            </button>
          )}
          {activeTab === 'excluded' && (
            <button
              type="button"
              className="btn btn--primary"
              disabled={bulkBusy}
              onClick={() => runBulkAction('unmatch')}
              data-testid="treasury-bank-feed-bulk-restore"
            >
              {bulkBusy ? 'Updating…' : 'Restore selected'}
            </button>
          )}
          <button type="button" className="btn btn--ghost" onClick={() => setSelectedIds(new Set())}>
            Clear selection
          </button>
        </div>
      )}

      {rows.length > 0 && (
        <div className="bank-feed-table-wrap">
          <table className="data-table bank-feed-table" data-list-tools="off" data-testid={`treasury-${type}-transactions-table`}>
            <thead>
              <tr>
                <th className="bank-feed-check-cell">
                  <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    onChange={toggleAllVisible}
                    aria-label="Select all visible transactions"
                    data-testid="treasury-bank-feed-select-all"
                  />
                </th>
                <th>Date</th>
                <th>Description</th>
                {type === 'liability' && <th>Category</th>}
                <th style={{ textAlign: 'right' }}>Amount</th>
                {type === 'deposit' && <th style={{ textAlign: 'right' }}>Running balance</th>}
                <th>Status</th>
                <th style={{ minWidth: 240 }}>Actions</th>
              </tr>
            </thead>
            <tbody>
            {rows.map((r) => (
              <React.Fragment key={r.id}>
                <tr data-testid={`treasury-txn-row-${r.id}`} className={selectedIds.has(r.id) ? 'is-selected' : ''}>
                  <td className="bank-feed-check-cell">
                    <input
                      type="checkbox"
                      checked={selectedIds.has(r.id)}
                      onChange={() => toggleSelected(r.id)}
                      aria-label={`Select ${r.description || `transaction ${r.id}`}`}
                      data-testid={`treasury-txn-select-${r.id}`}
                    />
                  </td>
                  <td style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                    {fmtDate(r.posted_date)}
                  </td>
                  <td>
                    {r.description || r.merchant_name || '—'}
                    {r.merchant_name && r.merchant_name !== r.description && (
                      <span className="muted" style={{ fontSize: 11, marginLeft: 6 }}>
                        ({r.merchant_name})
                      </span>
                    )}
                    {Array.isArray(r.categorization) && r.categorization.length > 0 && (
                      <div
                        data-testid={`treasury-txn-category-${r.id}`}
                        style={{ display: 'flex', gap: 5, alignItems: 'center', flexWrap: 'wrap', marginTop: 4, fontSize: 11, color: '#475569' }}
                      >
                        <span>{r.categorization.length > 1 ? 'Split:' : 'Category:'}</span>
                        {r.categorization.map((category) => (
                          <span
                            key={`${category.line_no}-${category.account_id}`}
                            style={{ padding: '2px 6px', borderRadius: 10, background: '#ecfdf5', color: '#065f46' }}
                          >
                            <code>{category.account_code}</code> {category.account_name}
                            {r.categorization.length > 1 && (
                              <> · {fmtMoney(Math.max(Number(category.debit), Number(category.credit)))}</>
                            )}
                          </span>
                        ))}
                      </div>
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
                    {formatAccountMoney(r.amount, currency)}
                  </td>
                  {type === 'deposit' && (
                    <td className="bank-feed-running-balance" data-testid={`treasury-txn-running-balance-${r.id}`}>
                      {formatAccountMoney(r.running_balance, currency)}
                    </td>
                  )}
                  <td>
                    <span className={'badge ' + (
                      r.match_status === 'matched'  ? 'badge--active' :
                      r.match_status === 'ignored'  ? '' :
                                                      'badge--warn'
                    )}>
                      {statusLabel(r.match_status)}
                    </span>
                    {r.matched_je_id && (
                      <JournalEntryHover
                        transactionId={r.id}
                        journalEntry={r.journal_entry}
                        fallbackId={r.matched_je_id}
                      />
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
                          onClick={() => fetchAiCat(r.id)}
                          disabled={aiBusyId === r.id}
                          data-testid={`treasury-txn-ai-cat-${r.id}`}
                          style={{ padding: '2px 8px', fontSize: 11, marginRight: 4, color: '#0369a1' }}
                          title="Ask AI for a category suggestion"
                        >
                          {aiBusyId === r.id ? '…' : '✨ AI cat.'}
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost"
                          onClick={() => setSplitId(splitId === r.id ? null : r.id)}
                          data-testid={`treasury-txn-split-${r.id}`}
                          style={{ padding: '2px 8px', fontSize: 11, marginRight: 4 }}
                          title="Split this line across multiple accounts (intercompany supported)"
                        >
                          Split / IC
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost"
                          onClick={() => ignoreLine(r.id)}
                          data-testid={`treasury-txn-ignore-${r.id}`}
                          style={{ padding: '2px 8px', fontSize: 11 }}
                        >
                          Exclude
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
                    onAccountCreated={handleAccountCreated}
                    onSave={(counterpartId, memo) => categorizeAndPost(
                      r.id, counterpartId, memo, r.ai_suggestion?.suggestion_id
                    )}
                    onCancel={() => setCategorizingId(null)}
                  />
                )}
                {aiPanelByLine[r.id] && (
                  <tr data-testid={`treasury-txn-ai-result-${r.id}`}>
                    <td colSpan={7}
                        style={{ background: '#f0f9ff', padding: 12, borderLeft: '3px solid #0369a1' }}>
                      <TreasuryAiResultPanel
                        line={r}
                        ai={aiPanelByLine[r.id]}
                        onDismiss={() => dismissAi(r.id)}
                        onAccept={(accountId) => {
                          const sug = aiPanelByLine[r.id]?.suggestion || {};
                          dismissAi(r.id);
                          categorizeAndPost(r.id, accountId, sug.reasoning || null, sug.suggestion_id || null);
                        }}
                      />
                    </td>
                  </tr>
                )}
                {splitId === r.id && (
                  <tr data-testid={`treasury-txn-split-row-${r.id}`}>
                    <td colSpan={7}
                        style={{ background: '#fefce8', padding: 12, borderLeft: '3px solid #ca8a04' }}>
                      <SplitIcPanel
                        line={r}
                        accounts={eligibleAccounts}
                        onAccountCreated={handleAccountCreated}
                        onSubmit={async (splits) => {
                          try {
                            await api.post('/modules/treasury/api/account_transactions.php?action=split_categorize', {
                              line_id: r.id, type, splits,
                            });
                            setSplitId(null); reload();
                          } catch (e) { setRowError(`Split failed: ${e.message}`); }
                        }}
                        onCancel={() => setSplitId(null)}
                      />
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function BalanceCard({ label, value, detail, tone, testId }) {
  return (
    <div className={`bank-feed-balance-card${tone ? ` is-${tone}` : ''}`} data-testid={testId}>
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{detail}</small>
    </div>
  );
}

function AccountPicker({
  accounts,
  value,
  onChange,
  onAccountCreated,
  testIdPrefix,
  placeholder = 'Search G/L accounts…',
  autoFocus = false,
}) {
  const selected = accounts.find((account) => String(account.id) === String(value));
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [creating, setCreating] = useState(false);
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState(null);
  const [draft, setDraft] = useState({ code: '', name: '', account_type: 'expense' });

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return accounts
      .filter((account) => {
        if (!needle) return true;
        return [account.code, account.name, account.account_type]
          .some((part) => String(part || '').toLowerCase().includes(needle));
      })
      .slice(0, 80);
  }, [accounts, query]);

  const pick = (account) => {
    onChange(String(account.id));
    setQuery('');
    setOpen(false);
    setCreating(false);
  };

  const createAccount = async (event) => {
    event.preventDefault();
    if (!draft.code.trim() || !draft.name.trim()) return;
    setCreateBusy(true);
    setCreateError(null);
    try {
      const response = await api.post(ACCOUNTING_ACCOUNTS_API, {
        code: draft.code.trim(),
        name: draft.name.trim(),
        account_type: draft.account_type,
        is_postable: 1,
      });
      const account = {
        id: Number(response.id),
        code: draft.code.trim(),
        name: draft.name.trim(),
        account_type: draft.account_type,
        is_postable: 1,
      };
      onAccountCreated?.(account);
      pick(account);
      setDraft({ code: '', name: '', account_type: 'expense' });
    } catch (error) {
      setCreateError(error.message || 'Could not create this account.');
    } finally {
      setCreateBusy(false);
    }
  };

  return (
    <div className="gl-account-picker" data-testid={testIdPrefix}>
      <input
        type="search"
        className="input gl-account-picker__input"
        value={open ? query : (selected ? `${selected.code} · ${selected.name}` : query)}
        placeholder={placeholder}
        autoFocus={autoFocus}
        role="combobox"
        aria-expanded={open}
        aria-controls={`${testIdPrefix}-options`}
        onFocus={(event) => {
          setOpen(true);
          setQuery('');
          window.setTimeout(() => event.target.select(), 0);
        }}
        onChange={(event) => {
          setOpen(true);
          setQuery(event.target.value);
          if (value) onChange('');
        }}
        onKeyDown={(event) => {
          if (event.key === 'Escape') setOpen(false);
          if (event.key === 'Enter' && filtered.length === 1 && !creating) {
            event.preventDefault();
            pick(filtered[0]);
          }
        }}
        data-testid={`${testIdPrefix}-search`}
      />
      {open && (
        <div className="gl-account-picker__menu" id={`${testIdPrefix}-options`} role="listbox">
          <div className="gl-account-picker__options">
            {filtered.length === 0 && (
              <p className="muted">No matching G/L accounts.</p>
            )}
            {filtered.map((account) => (
              <button
                key={account.id}
                type="button"
                role="option"
                aria-selected={String(account.id) === String(value)}
                className="gl-account-picker__option"
                onClick={() => pick(account)}
                data-testid={`${testIdPrefix}-option-${account.id}`}
              >
                <code>{account.code}</code>
                <span>{account.name}</span>
                <small>{account.account_type}</small>
              </button>
            ))}
          </div>
          {!creating ? (
            <div className="gl-account-picker__footer">
              <button
                type="button"
                className="btn btn--ghost"
                onClick={() => setCreating(true)}
                data-testid={`${testIdPrefix}-add-new`}
              >
                + Add new G/L account
              </button>
              <button type="button" className="btn btn--ghost" onClick={() => setOpen(false)}>Close</button>
            </div>
          ) : (
            <form className="gl-account-picker__create" onSubmit={createAccount}>
              <strong>Add a G/L account</strong>
              <div>
                <input
                  className="input"
                  value={draft.code}
                  onChange={(event) => setDraft({ ...draft, code: event.target.value })}
                  placeholder="Code, e.g. 6400"
                  data-testid={`${testIdPrefix}-new-code`}
                />
                <input
                  className="input"
                  value={draft.name}
                  onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                  placeholder="Account name"
                  data-testid={`${testIdPrefix}-new-name`}
                />
                <select
                  className="input"
                  value={draft.account_type}
                  onChange={(event) => setDraft({ ...draft, account_type: event.target.value })}
                  data-testid={`${testIdPrefix}-new-type`}
                >
                  <option value="expense">Expense</option>
                  <option value="revenue">Revenue</option>
                  <option value="asset">Asset</option>
                  <option value="liability">Liability</option>
                  <option value="equity">Equity</option>
                </select>
              </div>
              {createError && <p className="error">{createError}</p>}
              <div>
                <button type="submit" className="btn btn--primary" disabled={createBusy || !draft.code.trim() || !draft.name.trim()}>
                  {createBusy ? 'Creating…' : 'Create and select'}
                </button>
                <button type="button" className="btn" onClick={() => setCreating(false)}>Cancel</button>
              </div>
            </form>
          )}
        </div>
      )}
    </div>
  );
}

function JournalEntryHover({ transactionId, journalEntry, fallbackId }) {
  const [open, setOpen] = useState(false);
  const jeId = journalEntry?.id || fallbackId;
  const label = journalEntry?.je_number || `JE #${jeId}`;
  const lines = journalEntry?.lines || [];

  return (
    <span
      style={{ position: 'relative', display: 'inline-block', marginLeft: 6 }}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <a
        href={`/modules/accounting/journal-entries/${jeId}`}
        className="muted"
        data-testid={`treasury-txn-je-${transactionId}`}
        style={{ fontSize: 11, whiteSpace: 'nowrap' }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
      >
        {label}
      </a>
      {open && journalEntry && (
        <div
          role="tooltip"
          data-testid={`treasury-txn-je-preview-${transactionId}`}
          style={{
            position: 'absolute', zIndex: 30, top: 'calc(100% + 6px)', right: 0,
            width: 380, padding: 12, borderRadius: 8,
            border: '1px solid #cbd5e1', background: '#fff', color: '#0f172a',
            boxShadow: '0 10px 28px rgba(15, 23, 42, 0.18)', fontSize: 11,
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginBottom: 6 }}>
            <strong>{journalEntry.je_number}</strong>
            <span>{fmtDate(journalEntry.posting_date)} · {journalEntry.status}</span>
          </div>
          {journalEntry.memo && (
            <div style={{ marginBottom: 8, color: '#475569' }}>{journalEntry.memo}</div>
          )}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 78px 78px', gap: '4px 8px', fontVariantNumeric: 'tabular-nums' }}>
            <strong>Account</strong><strong style={{ textAlign: 'right' }}>Debit</strong><strong style={{ textAlign: 'right' }}>Credit</strong>
            {lines.map((line) => (
              <React.Fragment key={`${line.line_no}-${line.account_id}`}>
                <span><code>{line.account_code}</code> {line.account_name}</span>
                <span style={{ textAlign: 'right' }}>{Number(line.debit) ? fmtMoney(Number(line.debit)) : '—'}</span>
                <span style={{ textAlign: 'right' }}>{Number(line.credit) ? fmtMoney(Number(line.credit)) : '—'}</span>
              </React.Fragment>
            ))}
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 14, marginTop: 8, paddingTop: 6, borderTop: '1px solid #e2e8f0', fontWeight: 600 }}>
            <span>DR {fmtMoney(Number(journalEntry.total_debit))}</span>
            <span>CR {fmtMoney(Number(journalEntry.total_credit))}</span>
          </div>
        </div>
      )}
    </span>
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

function CategorizeRow({ line, type, accounts, aiSuggestion, onAccountCreated, onSave, onCancel }) {
  // Charges (negative amount) typically debit an EXPENSE account.
  // Payments / refunds (positive amount) credit either revenue (rare for cards)
  // or, more commonly for cards, the bank deposit account that was charged
  // for the payment (asset). We default to expense for charges and asset
  // for payments; the user can override.
  const isCharge = Number(line.amount) < 0;
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
      <td colSpan={7}
          style={{ background: '#f8fafc', padding: 12 }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <label style={{ fontSize: 12, color: '#475569' }}>
            {isCharge ? 'Debit' : 'Credit'} this account:
          </label>
          <div style={{ minWidth: 320, flex: '0 1 420px' }}>
            <AccountPicker
              accounts={accounts}
              value={counterpartId}
              onChange={setCounterpartId}
              onAccountCreated={onAccountCreated}
              testIdPrefix={`treasury-txn-counterpart-${line.id}`}
              placeholder="Search code or G/L account name…"
              autoFocus
            />
          </div>
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

/**
 * Sprint 6h — AI categorization result panel (matches the Bank Rec
 * version). Renders the structured `bank_ai.php?action=suggest_categorize`
 * response as confidence + reasoning + Accept button instead of raw JSON.
 */
function TreasuryAiResultPanel({ line, ai, onDismiss, onAccept }) {
  // bank_ai.php returns { suggestion: {...}, review_required }.
  const sug = ai.suggestion || {};
  const conf = Math.round(((sug.confidence ?? 0)) * 100);
  const suggestedAccountId = sug.suggested_account_id ?? null;
  const reasoning          = sug.reasoning ?? '';
  const source             = sug.source    ?? 'none';
  const noSuggest = !suggestedAccountId || conf < 1;
  return (
    <div data-testid={`treasury-ai-result-${line.id}`}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <span style={{ fontSize: 12, color: '#0369a1', fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5 }}>
          ✨ AI category suggestion
        </span>
        {!noSuggest && (
          <span data-testid={`treasury-ai-result-confidence-${line.id}`}
                style={{ fontSize: 12, padding: '2px 8px', borderRadius: 12,
                         background: conf >= 80 ? '#dcfce7' : conf >= 50 ? '#fef3c7' : '#fee2e2',
                         color: conf >= 80 ? '#166534' : conf >= 50 ? '#92400e' : '#991b1b' }}>
            {conf}% · {source}
          </span>
        )}
      </div>
      {noSuggest ? (
        <p data-testid={`treasury-ai-result-empty-${line.id}`} style={{ margin: '4px 0', color: '#475569', fontSize: 13 }}>
          {reasoning || 'No confident suggestion — open the Categorize dialog to pick an account manually.'}
        </p>
      ) : (
        <>
          <p style={{ margin: '4px 0', fontSize: 13, color: '#0f172a' }}>
            Suggested counter account: <code data-testid={`treasury-ai-result-account-${line.id}`}
              style={{ background: '#fff', padding: '2px 6px', borderRadius: 4 }}>#{suggestedAccountId}</code>
          </p>
          {reasoning && <p style={{ margin: '4px 0', color: '#334155', fontSize: 12, lineHeight: 1.5 }}>{reasoning}</p>}
        </>
      )}
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        {!noSuggest && (
          <button type="button" className="btn btn--primary"
                  onClick={() => onAccept(suggestedAccountId)}
                  data-testid={`treasury-ai-result-accept-${line.id}`}
                  style={{ padding: '4px 12px', fontSize: 12 }}>
            Accept &amp; post
          </button>
        )}
        <button type="button" className="btn btn--ghost"
                onClick={onDismiss}
                data-testid={`treasury-ai-result-dismiss-${line.id}`}
                style={{ padding: '4px 12px', fontSize: 12 }}>
          Dismiss
        </button>
      </div>
    </div>
  );
}

/**
 * Sprint 6h — Split / Intercompany categorization. Lets the user post
 * one bank line as a balanced JE that DRs / CRs multiple accounts. The
 * sum of split lines must match the bank line's absolute amount.
 *
 * Intercompany support: each row has an optional `entity_id` so a
 * "transfer from Entity A to Entity B" line can be posted as an
 * intercompany JE in one shot.
 */
function SplitIcPanel({ line, accounts, onAccountCreated, onSubmit, onCancel }) {
  const total = Math.abs(Number(line.amount));
  const [rows, setRows] = useState([
    { account_id: '', amount: total.toFixed(2), entity_id: '', memo: '' },
  ]);
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const sum = rows.reduce((s, r) => s + (Number(r.amount) || 0), 0);
  const balanced = Math.abs(sum - total) < 0.005;

  const update = (i, key, v) => setRows(rs => rs.map((r, idx) => idx === i ? { ...r, [key]: v } : r));
  const addRow = () => setRows(rs => [...rs, { account_id: '', amount: '0.00', entity_id: '', memo: '' }]);
  const removeRow = (i) => setRows(rs => rs.filter((_, idx) => idx !== i));

  const submit = async () => {
    setErr(null);
    if (rows.some(r => !r.account_id)) { setErr('Pick an account on every row.'); return; }
    if (!balanced) { setErr('Splits must sum to the line amount.'); return; }
    setBusy(true);
    try {
      const splits = rows.map(r => ({
        account_id: Number(r.account_id),
        amount:     Number(r.amount),
        entity_id:  r.entity_id ? Number(r.entity_id) : null,
        memo:       r.memo || null,
      }));
      await onSubmit(splits);
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid={`treasury-txn-split-panel-${line.id}`}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <strong style={{ fontSize: 13 }}>Split this line</strong>
        <span style={{ fontSize: 12, color: balanced ? '#166534' : '#92400e' }}>
          {sum.toFixed(2)} of {total.toFixed(2)} {balanced ? '✓ balanced' : '— not balanced yet'}
        </span>
      </div>
      <table style={{ width: '100%', fontSize: 12 }}>
        <thead>
          <tr style={{ textAlign: 'left' }}>
            <th>Account</th><th>Entity (optional, IC)</th>
            <th style={{ textAlign: 'right' }}>Amount</th><th>Memo</th><th></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} data-testid={`treasury-txn-split-row-input-${line.id}-${i}`}>
              <td>
                <AccountPicker
                  accounts={accounts}
                  value={r.account_id}
                  onChange={(value) => update(i, 'account_id', value)}
                  onAccountCreated={onAccountCreated}
                  testIdPrefix={`treasury-txn-split-account-${line.id}-${i}`}
                  placeholder="Search G/L account…"
                />
              </td>
              <td>
                <input type="number" value={r.entity_id} onChange={e => update(i, 'entity_id', e.target.value)}
                       placeholder="entity id"
                       data-testid={`treasury-txn-split-entity-${line.id}-${i}`}
                       style={{ width: '100%' }} />
              </td>
              <td style={{ textAlign: 'right' }}>
                <input type="number" step="0.01" value={r.amount} onChange={e => update(i, 'amount', e.target.value)}
                       data-testid={`treasury-txn-split-amount-${line.id}-${i}`}
                       style={{ width: 90, textAlign: 'right' }} />
              </td>
              <td>
                <input type="text" value={r.memo} onChange={e => update(i, 'memo', e.target.value)}
                       data-testid={`treasury-txn-split-memo-${line.id}-${i}`}
                       style={{ width: '100%' }} />
              </td>
              <td>
                {rows.length > 1 && (
                  <button type="button" className="btn btn--ghost"
                          onClick={() => removeRow(i)}
                          data-testid={`treasury-txn-split-remove-${line.id}-${i}`}
                          style={{ padding: '2px 6px', fontSize: 11, color: '#dc2626' }}>×</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <button type="button" className="btn btn--ghost"
              onClick={addRow}
              data-testid={`treasury-txn-split-addrow-${line.id}`}
              style={{ padding: '2px 8px', fontSize: 11, marginTop: 6 }}>+ Add row</button>
      {err && <p className="error" style={{ fontSize: 12, margin: '6px 0 0' }}>{err}</p>}
      <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
        <button type="button" className="btn btn--primary"
                disabled={!balanced || busy}
                onClick={submit}
                data-testid={`treasury-txn-split-submit-${line.id}`}
                style={{ padding: '4px 12px', fontSize: 12 }}>
          {busy ? 'Posting…' : 'Post split JE'}
        </button>
        <button type="button" className="btn btn--ghost"
                onClick={onCancel}
                data-testid={`treasury-txn-split-cancel-${line.id}`}
                style={{ padding: '4px 12px', fontSize: 12 }}>
          Cancel
        </button>
      </div>
    </div>
  );
}
