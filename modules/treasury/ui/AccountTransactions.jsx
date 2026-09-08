import React, { useDeferredValue, useEffect, useMemo, useState } from 'react';
import {
  ArrowDown, ArrowUp, ArrowUpDown, CheckSquare, ChevronLeft, ChevronRight,
  ListFilter, RefreshCw, Save, Search, SlidersHorizontal, WandSparkles, X,
} from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney, fmtDate } from '../../../dashboard/src/lib/format';
import CsvUploadWidget from '../../../dashboard/src/components/CsvUploadWidget';
import AccountLink from '../../../dashboard/src/components/AccountLink';

const ACCOUNTING_ACCOUNTS_API = '/modules/accounting/api/accounts.php';

const fmtMoneyOriginal = (n) =>
  (n || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });
// Keep backwards compatibility for inline calls; prefer the imported fmtMoney
// from ../../../dashboard/src/lib/format which handles null/empty/strings.

function DuplicateActivityRepair({ accountId, onRepaired }) {
  const { data, loading, reload } = useApi(`/api/bank_transaction_dedupe.php?account_id=${accountId}`);
  const [repairing, setRepairing] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);

  if (loading || !data || Number(data.duplicate_rows || 0) === 0) {
    if (!result?.conflicts?.length) return null;
  }

  const duplicateRows = Number(data?.duplicate_rows || 0);
  const generatedEntries = Number(data?.reversible_rows || 0);
  const conflictRows = Number(data?.conflict_rows || 0);

  const repair = async () => {
    const accountingNote = generatedEntries > 0
      ? ` CoreFlux will create ${generatedEntries} formal reversal entr${generatedEntries === 1 ? 'y' : 'ies'} for duplicate postings.`
      : '';
    if (!window.confirm(`Repair ${duplicateRows} duplicate bank-feed row${duplicateRows === 1 ? '' : 's'}? Original feed rows remain in the audit trail.${accountingNote}`)) return;

    setRepairing(true); setError(null); setResult(null);
    try {
      const response = await api.post('/api/bank_transaction_dedupe.php?action=run', { account_id: accountId });
      setResult(response);
      await reload();
      onRepaired();
    } catch (e) {
      setError(e.message || 'Duplicate repair failed');
    } finally {
      setRepairing(false);
    }
  };

  return (
    <div
      data-testid="treasury-duplicate-activity-banner"
      style={{
        border: '1px solid #f59e0b', background: '#fffbeb', color: '#78350f',
        padding: 12, marginBottom: 14, display: 'flex', gap: 12,
        alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap',
      }}
    >
      <div>
        {duplicateRows > 0 && (
          <>
            <strong>{duplicateRows} duplicate bank transaction{duplicateRows === 1 ? '' : 's'} detected</strong>
            <div style={{ fontSize: 12, marginTop: 3 }}>
              Same bank events were replayed by a prior connection.
              {generatedEntries > 0 ? ` ${generatedEntries} duplicate CoreFlux posting${generatedEntries === 1 ? '' : 's'} will be reversed.` : ''}
              {conflictRows > 0 ? ` ${conflictRows} manually linked row${conflictRows === 1 ? '' : 's'} will be left for review.` : ''}
            </div>
          </>
        )}
        {result && (
          <div style={{ fontSize: 12, marginTop: 3 }}>
            Repaired {result.rows_marked || 0} rows and reversed {result.journal_entries_reversed || 0} duplicate postings.
            {result.conflicts?.length ? ` ${result.conflicts.length} manual conflict${result.conflicts.length === 1 ? '' : 's'} remain.` : ''}
          </div>
        )}
        {error && <div className="error" style={{ fontSize: 12, marginTop: 3 }}>{error}</div>}
      </div>
      {duplicateRows > 0 && (
        <button
          type="button"
          className="btn btn--primary"
          onClick={repair}
          disabled={repairing}
          data-testid="treasury-duplicate-activity-repair"
        >
          {repairing ? 'Repairing...' : 'Repair duplicate activity'}
        </button>
      )}
    </div>
  );
}

/**
 * Shared transactions list used by both DepositDetail + LiabilityDetail.
 * For liability accounts, exposes row-level Categorize / Ignore / Unmatch
 * actions that auto-post a balanced JE via accountingPostJe (sign-aware:
 * charges debit the counterpart account, payments credit it).
 */
export default function AccountTransactions({ accountId, type, accountLabel }) {
  const [filters, setFilters] = useState({
    q: '', status: '', direction: '', dateFrom: '', dateTo: '',
    amountMin: '', amountMax: '', categoryAccountId: '',
  });
  const [sort, setSort] = useState({ by: 'date', dir: 'desc' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const deferredQuery = useDeferredValue(filters.q);
  const requestUrl = useMemo(() => {
    const params = new URLSearchParams({
      account_id: String(accountId), type, page: String(page),
      per_page: String(perPage), sort_by: sort.by, sort_dir: sort.dir,
    });
    const values = { ...filters, q: deferredQuery };
    const names = {
      q: 'q', status: 'status', direction: 'direction', dateFrom: 'date_from',
      dateTo: 'date_to', amountMin: 'amount_min', amountMax: 'amount_max',
      categoryAccountId: 'category_account_id',
    };
    Object.entries(names).forEach(([key, name]) => {
      if (values[key] !== '') params.set(name, String(values[key]));
    });
    return `/modules/treasury/api/account_transactions.php?${params.toString()}`;
  }, [accountId, type, page, perPage, sort, filters, deferredQuery]);
  const { data, loading, error: loadError, reload } = useApi(requestUrl);
  // Postable expense / revenue accounts for the categorize dropdown. Filtered
  // to is_postable=1 (no header rows) when the API supplies it.
  const { data: coa } = useApi(`${ACCOUNTING_ACCOUNTS_API}?action=tree`);

  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState(null);
  const [syncErr, setSyncErr] = useState(null);
  const [categorizingId, setCategorizingId] = useState(null);
  const [rowError, setRowError] = useState(null);
  // Sprint 6h — AI cat. + Split/IC affordances now mirror Bank Rec.
  const [aiBusyId, setAiBusyId] = useState(null);
  const [aiPanelByLine, setAiPanelByLine] = useState({});  // { [lineId]: aiResp }
  const [splitId, setSplitId] = useState(null);
  const [selectedIds, setSelectedIds] = useState([]);
  const [bulkAccountId, setBulkAccountId] = useState('');
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkNotice, setBulkNotice] = useState(null);
  const [showFilters, setShowFilters] = useState(false);
  const [showRule, setShowRule] = useState(false);

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
  const totalCount = data?.total_count ?? count;
  const inflow  = data?.inflow_total  || 0;
  const outflow = data?.outflow_total || 0;
  const balance = data?.balance || {};
  const statusCounts = data?.status_counts || {};
  const pagination = data?.pagination || { page, per_page: perPage, total_pages: 1 };
  const plaidItemPk         = data?.plaid_item_pk;
  const plaidItemExternalId = data?.plaid_item_external_id;

  const eligibleAccounts = (coa?.rows || [])
    .filter((a) => a.is_postable !== 0 && a.id !== accountId);
  const accountsById = new Map(eligibleAccounts.map((a) => [a.id, a]));
  const selectedRows = rows.filter((row) => selectedIds.includes(row.id));
  const allPageSelected = rows.length > 0 && rows.every((row) => selectedIds.includes(row.id));
  const hasFilters = Object.values(filters).some((value) => value !== '');

  useEffect(() => { setPage(1); }, [deferredQuery, filters.status, filters.direction,
    filters.dateFrom, filters.dateTo, filters.amountMin, filters.amountMax, filters.categoryAccountId]);
  useEffect(() => { setSelectedIds([]); }, [requestUrl]);

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

  const toggleRow = (lineId) => setSelectedIds((current) => (
    current.includes(lineId) ? current.filter((id) => id !== lineId) : [...current, lineId]
  ));
  const togglePage = () => setSelectedIds(allPageSelected ? [] : rows.map((row) => row.id));

  const runBulkState = async (bulkAction) => {
    if (!selectedRows.length) return;
    const labels = { ignore: 'ignore', restore: 'restore', unmatch: 'unmatch' };
    if (bulkAction === 'unmatch'
      && !window.confirm('Unmatch the selected transactions? Their journal entries will remain posted, but the bank links will be cleared.')) return;
    setBulkBusy(true); setRowError(null); setBulkNotice(null);
    try {
      const result = await api.post('/modules/treasury/api/account_transactions.php?action=bulk_update', {
        account_id: accountId, type, line_ids: selectedRows.map((row) => row.id), bulk_action: bulkAction,
      });
      setBulkNotice(`${result.updated || 0} transaction${result.updated === 1 ? '' : 's'} ${labels[bulkAction]}d.`);
      setSelectedIds([]);
      reload();
    } catch (e) {
      setRowError(`Bulk update failed: ${e.message}`);
    } finally { setBulkBusy(false); }
  };

  const runBulkCategorize = async () => {
    const accountIdToUse = Number(bulkAccountId || 0);
    const pending = selectedRows.filter((row) => row.match_status === 'unmatched');
    if (!accountIdToUse || !pending.length) return;
    setBulkBusy(true); setRowError(null); setBulkNotice(null);
    let updated = 0;
    try {
      for (const row of pending) {
        await api.post('/modules/treasury/api/account_transactions.php?action=categorize_and_post', {
          line_id: row.id, type, counterpart_account_id: accountIdToUse,
          memo: row.description || row.merchant_name || null,
          ai_suggestion_id: row.ai_suggestion?.suggestion_id || null,
        });
        updated += 1;
      }
      setBulkNotice(`${updated} transaction${updated === 1 ? '' : 's'} categorized and posted.`);
      setSelectedIds([]); setBulkAccountId(''); reload();
    } catch (e) {
      setRowError(`Bulk categorize stopped after ${updated}: ${e.message}`);
      reload();
    } finally { setBulkBusy(false); }
  };

  const changeSort = (by) => setSort((current) => (
    current.by === by
      ? { by, dir: current.dir === 'asc' ? 'desc' : 'asc' }
      : { by, dir: by === 'description' || by === 'status' ? 'asc' : 'desc' }
  ));
  const setFilter = (name, value) => setFilters((current) => ({ ...current, [name]: value }));
  const clearFilters = () => setFilters({
    q: '', status: '', direction: '', dateFrom: '', dateTo: '',
    amountMin: '', amountMax: '', categoryAccountId: '',
  });

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
            {type === 'deposit' ? 'Bank activity' : 'Card / loan activity'} · {count === totalCount ? count : `${count} of ${totalCount}`} transaction{totalCount === 1 ? '' : 's'} ·{' '}
            <span style={{ color: '#065f46' }}>Inflow {fmtMoney(inflow)}</span> ·{' '}
            <span style={{ color: '#b91c1c' }}>Outflow {fmtMoney(outflow)}</span>
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          {type === 'deposit' && (
            <button
              type="button" className="btn btn--ghost"
              onClick={() => setShowRule((value) => !value)}
              disabled={selectedRows.length === 0}
              title={selectedRows.length ? 'Create a categorization rule from the selected activity' : 'Select at least one transaction to create a rule'}
              data-testid="treasury-create-rule-btn"
            >
              <WandSparkles size={14} style={{ marginRight: 5, verticalAlign: 'middle' }} /> Set rule
            </button>
          )}
          <button type="button" className="btn btn--ghost" onClick={reload} title="Refresh account activity">
            <RefreshCw size={14} style={{ verticalAlign: 'middle' }} />
          </button>
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
        </div>
      </header>

      <BalanceStrip balance={balance} connected={!!plaidItemExternalId} type={type} />

      <div data-testid="treasury-transaction-toolbar" style={toolbarStyle}>
        <label style={{ position: 'relative', flex: '1 1 280px', minWidth: 220 }}>
          <Search size={15} style={{ position: 'absolute', left: 10, top: 10, color: '#64748b' }} />
          <input
            className="input" value={filters.q} onChange={(event) => setFilter('q', event.target.value)}
            placeholder="Search description, reference, merchant, or category"
            data-testid="treasury-transactions-search" style={{ width: '100%', paddingLeft: 32 }}
          />
        </label>
        <select className="input" value={filters.status} onChange={(event) => setFilter('status', event.target.value)} data-testid="treasury-transactions-status-filter">
          <option value="">All statuses</option>
          <option value="unmatched">Unmatched</option>
          <option value="matched">Matched</option>
          <option value="ignored">Ignored</option>
        </select>
        <select className="input" value={filters.direction} onChange={(event) => setFilter('direction', event.target.value)} data-testid="treasury-transactions-direction-filter">
          <option value="">All money flows</option>
          <option value="inflow">Money in</option>
          <option value="outflow">Money out</option>
        </select>
        <button type="button" className="btn btn--ghost" onClick={() => setShowFilters((value) => !value)} aria-expanded={showFilters}>
          <SlidersHorizontal size={14} style={{ marginRight: 5, verticalAlign: 'middle' }} /> Filters
        </button>
        {hasFilters && (
          <button type="button" className="btn btn--ghost" onClick={clearFilters} title="Clear filters">
            <X size={14} style={{ verticalAlign: 'middle' }} />
          </button>
        )}
      </div>

      {showFilters && (
        <div data-testid="treasury-transaction-advanced-filters" style={advancedFiltersStyle}>
          <label style={filterLabelStyle}>From<input type="date" className="input" value={filters.dateFrom} onChange={(e) => setFilter('dateFrom', e.target.value)} /></label>
          <label style={filterLabelStyle}>To<input type="date" className="input" value={filters.dateTo} onChange={(e) => setFilter('dateTo', e.target.value)} /></label>
          <label style={filterLabelStyle}>Amount from<input type="number" step="0.01" className="input" value={filters.amountMin} onChange={(e) => setFilter('amountMin', e.target.value)} placeholder="-500.00" /></label>
          <label style={filterLabelStyle}>Amount to<input type="number" step="0.01" className="input" value={filters.amountMax} onChange={(e) => setFilter('amountMax', e.target.value)} placeholder="500.00" /></label>
          <label style={{ ...filterLabelStyle, minWidth: 240 }}>Posted category<select className="input" value={filters.categoryAccountId} onChange={(e) => setFilter('categoryAccountId', e.target.value)}><option value="">All categories</option>{eligibleAccounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</select></label>
        </div>
      )}

      {showRule && type === 'deposit' && (
        <QuickRuleBuilder
          accountId={accountId} selectedRows={selectedRows} accounts={eligibleAccounts}
          onCancel={() => setShowRule(false)}
          onSaved={(message) => { setShowRule(false); setSelectedIds([]); setBulkNotice(message); reload(); }}
        />
      )}

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
      {loadError && <p className="error" data-testid="treasury-transactions-load-error">{loadError.message}</p>}
      {bulkNotice && (
        <p data-testid="treasury-bulk-success" style={{ color: '#065f46', fontSize: 13, marginBottom: 12 }}>{bulkNotice}</p>
      )}

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
            ? 'Import a CSV (e.g. older history beyond Plaid\'s retention window)'
            : 'Import a bank statement CSV — this account isn\'t connected to Plaid'}
          hint="Header row required. Accepted columns: Date / Posting Date · Description / Memo · Amount (or Debit + Credit) · optional Reference / Check Number. Re-uploading the same file is a no-op (deduped via synthesised fitid)."
          onSuccess={() => reload()}
        />
      )}

      {type === 'deposit' && (
        <DuplicateActivityRepair accountId={accountId} onRepaired={reload} />
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
          <p style={{ margin: '0 0 8px', fontSize: 14 }}>{hasFilters ? 'No transactions match these filters.' : 'No transactions yet.'}</p>
          {hasFilters
            ? <button type="button" className="btn btn--ghost" onClick={clearFilters}>Clear filters</button>
            : plaidItemExternalId
            ? <p style={{ margin: 0, fontSize: 12 }}>Click <strong>Sync from Plaid</strong> above to pull the most recent activity.</p>
            : <p style={{ margin: 0, fontSize: 12 }}>This account isn't connected to Plaid; transactions will appear here once a feed is wired.</p>}
        </div>
      )}

      {rowError && (
        <p className="error" data-testid={`treasury-${type}-row-error`} style={{ marginBottom: 12 }}>
          {rowError}
        </p>
      )}

      <div style={statusSummaryStyle} data-testid="treasury-transaction-status-summary">
        <span><ListFilter size={13} style={{ verticalAlign: 'middle', marginRight: 5 }} />Filtered activity</span>
        <span><strong>{statusCounts.unmatched || 0}</strong> unmatched</span>
        <span><strong>{statusCounts.matched || 0}</strong> matched</span>
        <span><strong>{statusCounts.ignored || 0}</strong> ignored</span>
      </div>

      {selectedRows.length > 0 && (
        <div style={bulkBarStyle} data-testid="treasury-bulk-actions">
          <span style={{ fontWeight: 600, whiteSpace: 'nowrap' }}>
            <CheckSquare size={14} style={{ marginRight: 5, verticalAlign: 'middle' }} />
            {selectedRows.length} selected
          </span>
          <select className="input" value={bulkAccountId} onChange={(event) => setBulkAccountId(event.target.value)} style={{ minWidth: 250 }} aria-label="Bulk category">
            <option value="">Choose category</option>
            {eligibleAccounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}
          </select>
          <button type="button" className="btn btn--primary" onClick={runBulkCategorize}
            disabled={bulkBusy || !bulkAccountId || !selectedRows.some((row) => row.match_status === 'unmatched')}>
            Categorize and post
          </button>
          <button type="button" className="btn btn--ghost" onClick={() => runBulkState('ignore')}
            disabled={bulkBusy || !selectedRows.some((row) => row.match_status === 'unmatched')}>Ignore</button>
          <button type="button" className="btn btn--ghost" onClick={() => runBulkState('restore')}
            disabled={bulkBusy || !selectedRows.some((row) => row.match_status === 'ignored')}>Restore</button>
          <button type="button" className="btn btn--ghost" onClick={() => runBulkState('unmatch')}
            disabled={bulkBusy || !selectedRows.some((row) => row.match_status === 'matched')}>Unmatch</button>
          <button type="button" className="btn btn--ghost" onClick={() => setSelectedIds([])} disabled={bulkBusy} title="Clear selection"><X size={14} /></button>
        </div>
      )}

      {rows.length > 0 && (
        <>
        <table className="data-table" data-testid={`treasury-${type}-transactions-table`}>
          <thead>
            <tr>
              <th style={{ width: 36 }}><input type="checkbox" checked={allPageSelected} onChange={togglePage} aria-label="Select all visible transactions" /></th>
              <SortableHeader label="Date" sortKey="date" sort={sort} onSort={changeSort} />
              <SortableHeader label="Description" sortKey="description" sort={sort} onSort={changeSort} />
              {type === 'liability' && <th>Category</th>}
              <SortableHeader label="Amount" sortKey="amount" sort={sort} onSort={changeSort} align="right" />
              <SortableHeader label="Status" sortKey="status" sort={sort} onSort={changeSort} />
              <th style={{ width: 240 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <React.Fragment key={r.id}>
                <tr data-testid={`treasury-txn-row-${r.id}`}>
                  <td><input type="checkbox" checked={selectedIds.includes(r.id)} onChange={() => toggleRow(r.id)} aria-label={`Select ${r.description || 'transaction'}`} /></td>
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
                    {r.bank_reference && (
                      <div className="muted" style={{ fontSize: 11, marginTop: 3 }}>Reference {r.bank_reference}</div>
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
                            <AccountLink accountId={category.account_id}>
                              <code>{category.account_code}</code> {category.account_name}
                            </AccountLink>
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
                      <JournalEntryHover
                        transactionId={r.id}
                        journalEntry={r.journal_entry}
                        fallbackId={r.matched_je_id}
                      />
                    )}
                    {!r.matched_je_id && r.ai_suggested_account_code && (
                      <div style={{ fontSize: 11, color: r.applied_rule_id ? '#065f46' : '#92400e', marginTop: 4 }}>
                        {r.applied_rule_id ? 'Rule applied' : 'Rule suggested'} · {r.ai_suggested_account_code}
                      </div>
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
                {aiPanelByLine[r.id] && (
                  <tr data-testid={`treasury-txn-ai-result-${r.id}`}>
                    <td colSpan={type === 'liability' ? 7 : 6}
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
                    <td colSpan={type === 'liability' ? 7 : 6}
                        style={{ background: '#fefce8', padding: 12, borderLeft: '3px solid #ca8a04' }}>
                      <SplitIcPanel
                        line={r}
                        accounts={eligibleAccounts}
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
        <div style={paginationStyle} data-testid="treasury-transaction-pagination">
          <span>
            Showing {((pagination.page - 1) * pagination.per_page) + 1}–{Math.min(pagination.page * pagination.per_page, count)} of {count}
          </span>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>Rows
            <select className="input" value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1); }} style={{ padding: '5px 26px 5px 8px' }}>
              {[25, 50, 100, 200].map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
          </label>
          <button type="button" className="btn btn--ghost" onClick={() => setPage((value) => Math.max(1, value - 1))} disabled={pagination.page <= 1} title="Previous page"><ChevronLeft size={15} /></button>
          <span>Page {pagination.page} of {pagination.total_pages}</span>
          <button type="button" className="btn btn--ghost" onClick={() => setPage((value) => Math.min(pagination.total_pages, value + 1))} disabled={pagination.page >= pagination.total_pages} title="Next page"><ChevronRight size={15} /></button>
        </div>
        </>
      )}
    </section>
  );
}

function BalanceStrip({ balance, connected, type }) {
  const institutionLabel = type === 'deposit' ? 'Bank balance' : 'Institution balance';
  const difference = balance.difference;
  const isBalanced = difference !== null && difference !== undefined && Math.abs(Number(difference)) < 0.005;
  return (
    <section style={balanceStripStyle} data-testid="treasury-account-balances">
      <BalanceMetric
        label={institutionLabel}
        value={balance.institution_balance == null ? (connected ? 'Unavailable' : 'Not connected') : fmtMoney(balance.institution_balance)}
        detail={balance.institution_as_of ? `As of ${fmtDate(balance.institution_as_of)}` : 'Latest institution feed'}
      />
      <BalanceMetric
        label="Available balance"
        value={balance.available_balance == null ? 'Unavailable' : fmtMoney(balance.available_balance)}
        detail="Institution-reported funds available"
      />
      <BalanceMetric
        label="Ledger balance"
        value={fmtMoney(balance.ledger_balance || 0)}
        detail="Posted CoreFlux journal entries"
      />
      <BalanceMetric
        label="Difference"
        value={difference == null ? 'Unavailable' : fmtMoney(difference)}
        detail={difference == null ? 'Connect a feed to compare' : (isBalanced ? 'Bank and ledger agree' : 'Unposted or reconciling activity')}
        tone={difference == null || isBalanced ? 'neutral' : 'warning'}
      />
    </section>
  );
}

function BalanceMetric({ label, value, detail, tone = 'neutral' }) {
  return (
    <div style={{ minWidth: 170, flex: '1 1 180px' }}>
      <div style={{ fontSize: 11, color: '#64748b' }}>{label}</div>
      <strong style={{ display: 'block', marginTop: 3, fontSize: 16, color: tone === 'warning' ? '#b45309' : '#0f172a', fontVariantNumeric: 'tabular-nums' }}>{value}</strong>
      <div style={{ marginTop: 3, fontSize: 11, color: '#94a3b8' }}>{detail}</div>
    </div>
  );
}

function SortableHeader({ label, sortKey, sort, onSort, align = 'left' }) {
  const active = sort.by === sortKey;
  const Icon = !active ? ArrowUpDown : (sort.dir === 'asc' ? ArrowUp : ArrowDown);
  return (
    <th style={{ textAlign: align }}>
      <button
        type="button" onClick={() => onSort(sortKey)}
        style={{ border: 0, background: 'transparent', padding: 0, color: 'inherit', font: 'inherit', fontWeight: 'inherit', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 5 }}
        aria-label={`Sort by ${label}`}
      >
        {label}<Icon size={12} />
      </button>
    </th>
  );
}

function QuickRuleBuilder({ accountId, selectedRows, accounts, onSaved, onCancel }) {
  const source = selectedRows[0] || {};
  const sourceText = String(source.merchant_name || source.description || '').trim();
  const existingCategory = source.categorization?.[0]?.account_id || '';
  const signs = new Set(selectedRows.map((row) => Number(row.amount) < 0 ? 'debit' : 'credit'));
  const [form, setForm] = useState({
    name: sourceText ? `Categorize ${sourceText.slice(0, 55)}` : '',
    pattern_kind: 'contains',
    pattern: sourceText,
    target_account_id: String(existingCategory),
    direction: signs.size === 1 ? [...signs][0] : 'any',
    is_approved: false,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const setField = (name, value) => setForm((current) => ({ ...current, [name]: value }));
  const save = async (event) => {
    event.preventDefault();
    const target = accounts.find((account) => String(account.id) === String(form.target_account_id));
    if (!target) return;
    setSaving(true); setError(null);
    try {
      await api.post('/modules/accounting/api/bank_rules.php', {
        bank_account_id: accountId,
        name: form.name.trim(), pattern_kind: form.pattern_kind, pattern: form.pattern.trim(),
        target_account_code: target.code, direction: form.direction,
        is_approved: form.is_approved, created_via: 'manual',
      });
      const applied = await api.post(`/modules/accounting/api/bank_statements.php?action=apply_rules&bank_account_id=${accountId}`, {});
      onSaved(`Rule saved. ${applied.auto_applied || 0} transaction${applied.auto_applied === 1 ? '' : 's'} matched automatically and ${applied.suggested || 0} suggested for review.`);
    } catch (e) {
      setError(e.message || 'Could not save rule');
    } finally { setSaving(false); }
  };

  return (
    <form onSubmit={save} style={ruleBuilderStyle} data-testid="treasury-quick-rule-builder">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
        <div><strong>Create categorization rule</strong><div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>Apply the same treatment when future bank descriptions match.</div></div>
        <button type="button" className="btn btn--ghost" onClick={onCancel} title="Close"><X size={14} /></button>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(180px, 1fr) 150px minmax(220px, 2fr) minmax(220px, 1.5fr) 130px', gap: 8, marginTop: 12 }}>
        <label style={filterLabelStyle}>Rule name<input className="input" value={form.name} onChange={(e) => setField('name', e.target.value)} required /></label>
        <label style={filterLabelStyle}>Match<select className="input" value={form.pattern_kind} onChange={(e) => setField('pattern_kind', e.target.value)}><option value="contains">Contains</option><option value="starts_with">Starts with</option><option value="equals">Equals</option><option value="regex">Pattern</option></select></label>
        <label style={filterLabelStyle}>Bank description<input className="input" value={form.pattern} onChange={(e) => setField('pattern', e.target.value)} required /></label>
        <label style={filterLabelStyle}>Post to<select className="input" value={form.target_account_id} onChange={(e) => setField('target_account_id', e.target.value)} required><option value="">Choose category</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</select></label>
        <label style={filterLabelStyle}>Flow<select className="input" value={form.direction} onChange={(e) => setField('direction', e.target.value)}><option value="any">Any</option><option value="debit">Money out</option><option value="credit">Money in</option></select></label>
      </div>
      <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 10, flexWrap: 'wrap' }}>
        <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}><input type="checkbox" checked={form.is_approved} onChange={(e) => setField('is_approved', e.target.checked)} />Apply automatically to matching activity</label>
        <button className="btn btn--primary" disabled={saving || !form.name.trim() || !form.pattern.trim() || !form.target_account_id}><Save size={14} style={{ marginRight: 5, verticalAlign: 'middle' }} />{saving ? 'Saving...' : 'Save rule'}</button>
        <a className="btn btn--ghost" href={`/modules/accounting/bank-rec/${accountId}/rules`}>Manage account rules</a>
        {error && <span className="error" style={{ fontSize: 12 }}>{error}</span>}
      </div>
    </form>
  );
}

const balanceStripStyle = { display: 'flex', gap: 18, padding: '14px 0', marginBottom: 14, borderTop: '1px solid #e2e8f0', borderBottom: '1px solid #e2e8f0', flexWrap: 'wrap' };
const toolbarStyle = { display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10, flexWrap: 'wrap' };
const advancedFiltersStyle = { display: 'flex', gap: 8, alignItems: 'flex-end', padding: '10px 0 14px', flexWrap: 'wrap' };
const filterLabelStyle = { display: 'flex', flexDirection: 'column', gap: 4, color: '#475569', fontSize: 11 };
const statusSummaryStyle = { display: 'flex', gap: 18, padding: '8px 0', marginBottom: 8, color: '#475569', fontSize: 12, borderBottom: '1px solid #e2e8f0', flexWrap: 'wrap' };
const bulkBarStyle = { display: 'flex', gap: 8, alignItems: 'center', padding: 10, marginBottom: 8, background: '#eff6ff', border: '1px solid #bfdbfe', flexWrap: 'wrap' };
const ruleBuilderStyle = { padding: 12, marginBottom: 14, background: '#f8fafc', border: '1px solid #cbd5e1' };
const paginationStyle = { display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8, padding: '12px 0', fontSize: 12, color: '#475569', flexWrap: 'wrap' };

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
                <AccountLink accountId={line.account_id}>
                  <code>{line.account_code}</code> {line.account_name}
                </AccountLink>
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
      <td colSpan={type === 'liability' ? 7 : 6}
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
function SplitIcPanel({ line, accounts, onSubmit, onCancel }) {
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
                <select value={r.account_id} onChange={e => update(i, 'account_id', e.target.value)}
                        data-testid={`treasury-txn-split-account-${line.id}-${i}`}
                        style={{ width: '100%' }}>
                  <option value="">— pick —</option>
                  {accounts.map(a => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                </select>
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
