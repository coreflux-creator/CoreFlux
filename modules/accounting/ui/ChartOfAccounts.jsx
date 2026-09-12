import React, { useEffect, useState, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { SortIndicator } from '../../../dashboard/src/lib/useTableList';
import AccountLink from '../../../dashboard/src/components/AccountLink';
import BulkEditBar from '../../../dashboard/src/components/BulkEditBar';
import {
  Database, Landmark, MoveRight, Network, Plus, Search, X,
} from 'lucide-react';

const TYPES = ['asset','liability','equity','revenue','expense'];
const NORMAL = { asset: 'debit', expense: 'debit', liability: 'credit', equity: 'credit', revenue: 'credit' };

const DEFAULT_COA = [
  { code: '1000', name: 'Cash', account_type: 'asset' },
  { code: '1100', name: 'Accounts Receivable', account_type: 'asset' },
  { code: '2000', name: 'Accounts Payable', account_type: 'liability' },
  { code: '2100', name: 'Sales Tax Payable', account_type: 'liability' },
  { code: '3000', name: 'Retained Earnings', account_type: 'equity' },
  { code: '4000', name: 'Revenue', account_type: 'revenue' },
  { code: '5000', name: 'Cost of Services (Contractor Pay)', account_type: 'expense' },
  { code: '6000', name: 'Operating Expenses', account_type: 'expense' },
];

/**
 * Builds an ordered, indented list of accounts grouped by parent.
 * Returns array of { row, depth } where children are listed directly
 * after their parent. Cycle-safe (caps depth at 6).
 */
function buildTree(rows, sort = { key: 'code', dir: 'asc' }) {
  const byParent = new Map();   // parent_id (or null) → child rows
  rows.forEach((r) => {
    const pid = r.parent_account_id || 0;
    if (!byParent.has(pid)) byParent.set(pid, []);
    byParent.get(pid).push(r);
  });
  const direction = sort.dir === 'desc' ? -1 : 1;
  byParent.forEach((arr) => arr.sort((a, b) => {
    if (sort.key === 'active' || sort.key === 'is_postable') {
      return (Number(a[sort.key]) - Number(b[sort.key])) * direction;
    }
    return String(a[sort.key] ?? '').localeCompare(String(b[sort.key] ?? ''), undefined, { numeric: true }) * direction;
  }));

  const out = [];
  const seen = new Set();
  const walk = (parentId, depth) => {
    if (depth > 6) return;
    const kids = byParent.get(parentId) || [];
    for (const r of kids) {
      if (seen.has(r.id)) continue;
      seen.add(r.id);
      out.push({ row: r, depth });
      walk(r.id, depth + 1);
    }
  };
  walk(0, 0);
  // Surface any orphans whose parent_account_id points to a non-existent row.
  rows.forEach((r) => {
    if (!seen.has(r.id)) out.push({ row: r, depth: 0 });
  });
  return out;
}

/**
 * Returns the set of ids that are NOT eligible to become the parent of `accountId`
 * (i.e. self + every descendant — moving an account under its own descendant
 * would create a cycle).
 */
function descendantSet(rows, accountId) {
  const byParent = new Map();
  rows.forEach((r) => {
    const pid = r.parent_account_id || 0;
    if (!byParent.has(pid)) byParent.set(pid, []);
    byParent.get(pid).push(r);
  });
  const ids = new Set([accountId]);
  const queue = [accountId];
  while (queue.length) {
    const cur = queue.shift();
    const kids = byParent.get(cur) || [];
    for (const k of kids) {
      if (!ids.has(k.id)) { ids.add(k.id); queue.push(k.id); }
    }
  }
  return ids;
}

export default function ChartOfAccounts() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/accounts.php');
  const rows = useMemo(() => data?.rows ?? [], [data?.rows]);
  const [form, setForm]       = useState({ code: '', name: '', account_type: 'expense' });
  const [busy, setBusy]       = useState(false);
  const [seedBusy, setSeedBusy] = useState(false);
  const [autoBusy, setAutoBusy] = useState(false);
  const [notice, setNotice]   = useState(null);
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [search, setSearch]   = useState('');
  const [sort, setSort] = useState({ key: 'code', dir: 'asc' });
  const [selected, setSelected] = useState(() => new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkResult, setBulkResult] = useState(null);
  const [showAdd, setShowAdd] = useState(false);
  const [moveTarget, setMoveTarget] = useState(null);   // {id, code, name, account_type, parent_account_id}

  const filtered = useMemo(
    () => rows.filter((row) => (
      (!typeFilter || row.account_type === typeFilter)
      && (statusFilter === '' || Number(row.active) === Number(statusFilter))
    )),
    [rows, typeFilter, statusFilter]
  );
  const tree = useMemo(() => buildTree(filtered, sort), [filtered, sort]);
  const visibleTree = useMemo(() => {
    const needle = search.trim().toLowerCase();
    if (!needle) return tree;

    const byId = new Map(filtered.map(row => [row.id, row]));
    const visibleIds = new Set();
    filtered.forEach(row => {
      const haystack = `${row.code || ''} ${row.name || ''} ${row.account_type || ''}`.toLowerCase();
      if (!haystack.includes(needle)) return;
      let current = row;
      let guard = 0;
      while (current && guard < 8) {
        visibleIds.add(current.id);
        current = byId.get(current.parent_account_id);
        guard += 1;
      }
    });
    return tree.filter(({ row }) => visibleIds.has(row.id));
  }, [filtered, search, tree]);

  useEffect(() => {
    setSelected(new Set());
    setBulkResult(null);
  }, [search, typeFilter, statusFilter, sort]);

  const toggleSort = (key) => setSort(current => ({
    key,
    dir: current.key === key && current.dir === 'asc' ? 'desc' : 'asc',
  }));
  const sortProps = (key) => ({
    role: 'button',
    tabIndex: 0,
    'aria-sort': sort.key === key ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none',
    onClick: () => toggleSort(key),
    onKeyDown: (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggleSort(key);
      }
    },
  });
  const toggleRow = (id) => setSelected(current => {
    const next = new Set(current);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });
  const visibleRows = visibleTree.map(item => item.row);
  const allVisible = visibleRows.length > 0 && visibleRows.every(row => selected.has(row.id));
  const toggleAll = () => setSelected(current => {
    const next = new Set(current);
    if (allVisible) visibleRows.forEach(row => next.delete(row.id));
    else visibleRows.forEach(row => next.add(row.id));
    return next;
  });
  const bulkFields = useMemo(() => [
    { key: 'active', label: 'Status', type: 'select', placeholder: 'Choose status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] },
    { key: 'is_postable', label: 'Posting', type: 'select', placeholder: 'Choose posting behavior', options: [{ value: '1', label: 'Posting account' }, { value: '0', label: 'Header account' }] },
  ], []);
  const bulkUpdate = async (field, value, label) => {
    if (!selected.size) return;
    if (!confirm(`Change ${label.toLowerCase()} for ${selected.size} selected account${selected.size === 1 ? '' : 's'}?`)) return;
    setBulkBusy(true);
    setBulkResult(null);
    try {
      const result = await api.post('/modules/accounting/api/accounts.php?action=bulk_update', {
        ids: Array.from(selected), field, value,
      });
      setBulkResult(result);
      setSelected(new Set());
      reload();
    } catch (bulkError) {
      setBulkResult({ error: bulkError?.message || String(bulkError) });
    } finally {
      setBulkBusy(false);
    }
  };

  const add = async (e) => {
    e.preventDefault();
    setBusy(true); setNotice(null);
    try {
      await api.post('/modules/accounting/api/accounts.php', form);
      setForm({ code: '', name: '', account_type: 'expense' });
      setShowAdd(false);
      reload();
    } catch (err) { setNotice({ type: 'err', text: err.message }); }
    finally       { setBusy(false); }
  };

  const seed = async () => {
    setSeedBusy(true); setNotice(null);
    const existing = new Set(rows.map((r) => r.code));
    let created = 0, skipped = 0;
    for (const a of DEFAULT_COA) {
      if (existing.has(a.code)) { skipped++; continue; }
      try { await api.post('/modules/accounting/api/accounts.php', a); created++; } catch { /* ignore */ }
    }
    setSeedBusy(false);
    setNotice({ type: 'ok', text: `Seeded ${created} accounts (skipped ${skipped}).` });
    reload();
  };

  const autoGroupPlaid = async () => {
    if (!confirm('Auto-group Plaid-mirrored liability accounts (credit cards, loans) under their institution as parent?')) return;
    setAutoBusy(true); setNotice(null);
    try {
      const res = await api.post('/modules/accounting/api/accounts.php?action=auto_group_plaid', {});
      if (res.count === 0) {
        setNotice({ type: 'ok', text: 'Nothing to group — all Plaid liabilities already have a parent or no institution metadata.' });
      } else {
        const names = (res.reparented || []).map((x) => `${x.name} → ${x.institution}`).join(', ');
        setNotice({ type: 'ok', text: `Re-parented ${res.count} account${res.count === 1 ? '' : 's'}: ${names}` });
      }
      reload();
    } catch (e) { setNotice({ type: 'err', text: e.message }); }
    finally     { setAutoBusy(false); }
  };

  const reparent = async (childId, newParentId) => {
    try {
      await api.patch(
        `/modules/accounting/api/accounts.php?id=${childId}`,
        { parent_account_id: newParentId || null }
      );
      setMoveTarget(null);
      reload();
    } catch (e) {
      setNotice({ type: 'err', text: e.message });
    }
  };

  return (
    <section className="ledger-page" data-testid="accounting-accounts">
      <header className="ledger-page-header">
        <div className="ledger-page-header__title">
          <span className="ledger-page-header__icon" aria-hidden="true"><Landmark size={18} /></span>
          <div>
            <h2>Chart of accounts</h2>
            <p className="ledger-page-header__meta">{rows.length} accounts</p>
          </div>
        </div>
        <div className="ledger-page-header__actions">
          <button
            type="button"
            className="btn btn--ghost"
            data-testid="accounting-accounts-auto-group-plaid"
            onClick={autoGroupPlaid}
            disabled={autoBusy}
          >
            <Network size={14} aria-hidden="true" />{autoBusy ? 'Grouping…' : 'Group connected accounts'}
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            data-testid="accounting-accounts-seed"
            onClick={seed}
            disabled={seedBusy}
          >
            <Database size={14} aria-hidden="true" />{seedBusy ? 'Seeding…' : 'Seed standard accounts'}
          </button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => setShowAdd(open => !open)}
            aria-expanded={showAdd}
            data-testid="accounting-accounts-add-trigger"
          >
            {showAdd ? <X size={15} aria-hidden="true" /> : <Plus size={15} aria-hidden="true" />}
            {showAdd ? 'Close' : 'Add account'}
          </button>
        </div>
      </header>

      {notice && (
        <p
          data-testid="accounting-accounts-notice"
          style={{
            padding: '6px 10px', borderRadius: 6,
            background: notice.type === 'ok' ? '#ecfdf5' : '#fef2f2',
            color: notice.type === 'ok' ? '#065f46' : '#991b1b',
          }}
        >
          {notice.text}
        </p>
      )}

      <div className="account-toolbar">
        <div className="account-toolbar__filters">
          <label className="compact-search">
            <Search size={15} aria-hidden="true" />
            <input
              className="input"
              type="search"
              value={search}
              onChange={event => setSearch(event.target.value)}
              placeholder="Search account number, name, or type"
              aria-label="Search chart of accounts"
              data-testid="accounting-accounts-search"
            />
          </label>
          <select
            className="input"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            data-testid="accounting-accounts-type-filter"
            aria-label="Account type"
            style={{ width: 180 }}
          >
            <option value="">All account types</option>
            {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
          <select
            className="input"
            value={statusFilter}
            onChange={(event) => setStatusFilter(event.target.value)}
            data-testid="accounting-accounts-status-filter"
            aria-label="Account status"
            style={{ width: 160 }}
          >
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
        <span className="ledger-page-header__meta">{visibleTree.length} shown</span>
      </div>

      {showAdd && (
        <form onSubmit={add} className="inline-create-form" data-testid="accounting-accounts-form">
          <input className="input" placeholder="Number" aria-label="Account number" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} data-testid="accounting-accounts-code" required />
          <input className="input" placeholder="Account name" aria-label="Account name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} data-testid="accounting-accounts-name" required />
          <select className="input" aria-label="Account type" value={form.account_type} onChange={(e) => setForm({ ...form, account_type: e.target.value })} data-testid="accounting-accounts-type">
            {TYPES.map((t) => <option key={t} value={t}>{t} ({NORMAL[t]})</option>)}
          </select>
          <button className="btn btn--primary" data-testid="accounting-accounts-add" disabled={busy}>{busy ? 'Adding…' : 'Add account'}</button>
          <button type="button" className="btn btn--ghost" onClick={() => setShowAdd(false)}>Cancel</button>
        </form>
      )}

      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}

      <BulkEditBar
        count={selected.size}
        noun="account"
        fields={bulkFields}
        busy={bulkBusy}
        onApply={bulkUpdate}
        onClear={() => setSelected(new Set())}
        testid="accounting-accounts-bulk"
      />
      {bulkResult && (
        <p className={bulkResult.error ? 'error' : 'success'} data-testid="accounting-accounts-bulk-result">
          {bulkResult.error || `Updated ${bulkResult.updated}; skipped ${bulkResult.skipped}; failed ${bulkResult.failed}.`}
        </p>
      )}

      <div className="data-table-wrap">
      <table className="data-table" data-testid="accounting-accounts-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}><input type="checkbox" checked={allVisible} onChange={toggleAll} aria-label="Select all visible accounts" data-testid="accounting-accounts-select-all" /></th>
            <th {...sortProps('code')} style={{ width: 120 }}>Number <SortIndicator active={sort.key === 'code'} dir={sort.dir} /></th>
            <th {...sortProps('name')}>Account name <SortIndicator active={sort.key === 'name'} dir={sort.dir} /></th>
            <th {...sortProps('account_type')}>Type <SortIndicator active={sort.key === 'account_type'} dir={sort.dir} /></th>
            <th>Normal</th>
            <th {...sortProps('is_postable')}>Posting <SortIndicator active={sort.key === 'is_postable'} dir={sort.dir} /></th>
            <th {...sortProps('active')}>Status <SortIndicator active={sort.key === 'active'} dir={sort.dir} /></th>
            <th style={{ width: 90 }}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {visibleTree.length === 0 && (
            <tr><td colSpan={8} className="empty" data-testid="accounting-accounts-empty">No accounts match.</td></tr>
          )}
          {visibleTree.map(({ row: r, depth }) => (
            <tr key={r.id} data-testid={`accounting-accounts-row-${r.code}`}>
              <td><input type="checkbox" checked={selected.has(r.id)} onChange={() => toggleRow(r.id)} aria-label={`Select ${r.code} ${r.name}`} data-testid={`accounting-account-select-${r.id}`} /></td>
              <td>
                <AccountLink accountId={r.id} accountCode={r.code} className="account-code">
                  {r.code}
                </AccountLink>
              </td>
              <td>
                <span style={{ display: 'inline-block', width: depth * 20 }} aria-hidden />
                {depth > 0 && <span style={{ color: '#9eaaa7', marginRight: 6 }}>└</span>}
                <AccountLink accountId={r.id} accountCode={r.code} data-testid={`accounting-account-open-${r.code}`}>
                  <span style={{ fontWeight: r.is_postable ? 400 : 600 }}>{r.name}</span>
                </AccountLink>
                {!r.is_postable && (
                  <span className="badge" style={{ marginLeft: 6, fontSize: 10 }}>header</span>
                )}
                {Number(r.interest_schedule_count) > 0 && (
                  <span className="badge" style={{ marginLeft: 6, fontSize: 10 }}>interest terms</span>
                )}
              </td>
              <td>{r.account_type}</td>
              <td>{r.normal_side}</td>
              <td>{r.is_postable ? 'Posting' : 'Header'}</td>
              <td><span className={`account-status${r.active ? ' account-status--active' : ''}`}>{r.active ? 'Active' : 'Inactive'}</span></td>
              <td>
                <button
                  type="button"
                  className="btn btn--ghost"
                  data-testid={`accounting-accounts-move-${r.code}`}
                  onClick={() => setMoveTarget(r)}
                  style={{ padding: '2px 7px', fontSize: 11 }}
                >
                  <MoveRight size={13} aria-hidden="true" />Move
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      {moveTarget && (
        <MoveDialog
          target={moveTarget}
          allRows={rows}
          onCancel={() => setMoveTarget(null)}
          onSave={(newParentId) => reparent(moveTarget.id, newParentId)}
        />
      )}
    </section>
  );
}

function MoveDialog({ target, allRows, onCancel, onSave }) {
  const ineligible = useMemo(() => descendantSet(allRows, target.id), [allRows, target.id]);
  // Eligible parents: same type, NOT self or any descendant.
  const candidates = useMemo(
    () => allRows
      .filter((r) => r.account_type === target.account_type)
      .filter((r) => !ineligible.has(r.id))
      .sort((a, b) => (a.code || '').localeCompare(b.code || '')),
    [allRows, target.account_type, ineligible]
  );
  const [selected, setSelected] = useState(target.parent_account_id || '');

  return (
    <div
      data-testid="accounting-accounts-move-dialog"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50,
      }}
      onClick={onCancel}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          background: 'var(--cf-bg, #fff)', padding: 20, borderRadius: 8,
          minWidth: 420, maxWidth: 540, boxShadow: '0 10px 40px rgba(0,0,0,0.2)',
        }}
      >
        <h3 style={{ margin: '0 0 8px' }}>Move <code>{target.code}</code> {target.name}</h3>
        <p className="muted" style={{ fontSize: 13, margin: '0 0 12px' }}>
          Move under another <em>{target.account_type}</em> account, or pull it up to top-level.
        </p>
        <select
          className="input"
          value={selected}
          onChange={(e) => setSelected(e.target.value)}
          data-testid="accounting-accounts-move-parent-select"
          style={{ width: '100%', marginBottom: 14 }}
        >
          <option value="">— Top-level (no parent) —</option>
          {candidates.map((c) => (
            <option key={c.id} value={c.id}>
              {c.code} · {c.name}{c.is_postable === 0 ? ' (header)' : ''}
            </option>
          ))}
        </select>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--ghost" onClick={onCancel} data-testid="accounting-accounts-move-cancel">
            Cancel
          </button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => onSave(selected ? Number(selected) : null)}
            data-testid="accounting-accounts-move-save"
          >
            Save
          </button>
        </div>
      </div>
    </div>
  );
}
