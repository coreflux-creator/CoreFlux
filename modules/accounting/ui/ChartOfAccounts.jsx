import React, { useState, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const TYPES = ['asset','liability','equity','revenue','expense'];
const NORMAL = { asset: 'debit', expense: 'debit', liability: 'credit', equity: 'credit', revenue: 'credit' };
const ACCOUNTS_API = '/api/v1/accounting/accounts';

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
function buildTree(rows) {
  const byParent = new Map();   // parent_id (or null) → child rows
  rows.forEach((r) => {
    const pid = r.parent_account_id || 0;
    if (!byParent.has(pid)) byParent.set(pid, []);
    byParent.get(pid).push(r);
  });
  byParent.forEach((arr) => arr.sort((a, b) => (a.code || '').localeCompare(b.code || '')));

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
  const { data, loading, error, reload } = useApi(ACCOUNTS_API);
  const rows = data?.rows ?? [];
  const [form, setForm]       = useState({ code: '', name: '', account_type: 'expense' });
  const [busy, setBusy]       = useState(false);
  const [seedBusy, setSeedBusy] = useState(false);
  const [autoBusy, setAutoBusy] = useState(false);
  const [notice, setNotice]   = useState(null);
  const [typeFilter, setTypeFilter] = useState('');
  const [moveTarget, setMoveTarget] = useState(null);   // {id, code, name, account_type, parent_account_id}

  const filtered = useMemo(
    () => (typeFilter ? rows.filter((r) => r.account_type === typeFilter) : rows),
    [rows, typeFilter]
  );
  const tree = useMemo(() => buildTree(filtered), [filtered]);

  const add = async (e) => {
    e.preventDefault();
    setBusy(true); setNotice(null);
    try {
      await api.post(ACCOUNTS_API, form);
      setForm({ code: '', name: '', account_type: 'expense' });
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
      try { await api.post(ACCOUNTS_API, a); created++; } catch { /* ignore */ }
    }
    setSeedBusy(false);
    setNotice({ type: 'ok', text: `Seeded ${created} accounts (skipped ${skipped}).` });
    reload();
  };

  const autoGroupPlaid = async () => {
    if (!confirm('Auto-group Plaid-mirrored liability accounts (credit cards, loans) under their institution as parent?')) return;
    setAutoBusy(true); setNotice(null);
    try {
      const res = await api.post(`${ACCOUNTS_API}?action=auto_group_plaid`, {});
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
        `${ACCOUNTS_API}?id=${childId}`,
        { parent_account_id: newParentId || null }
      );
      setMoveTarget(null);
      reload();
    } catch (e) {
      setNotice({ type: 'err', text: e.message });
    }
  };

  return (
    <section data-testid="accounting-accounts">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12, gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0 }}>Chart of Accounts</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
            Tenant-shared accounts. Indented = parent / child. Move accounts under any same-type parent (e.g. group AmEx + Discover under "Credit Cards").
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select
            className="input"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            data-testid="accounting-accounts-type-filter"
            style={{ maxWidth: 160 }}
          >
            <option value="">All types</option>
            {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
          <button
            type="button"
            className="btn btn--ghost"
            data-testid="accounting-accounts-auto-group-plaid"
            onClick={autoGroupPlaid}
            disabled={autoBusy}
          >
            {autoBusy ? 'Grouping…' : 'Auto-group Plaid liabilities'}
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            data-testid="accounting-accounts-seed"
            onClick={seed}
            disabled={seedBusy}
          >
            {seedBusy ? 'Seeding…' : 'Seed standard COA'}
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

      <form onSubmit={add} style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }} data-testid="accounting-accounts-form">
        <input className="input" placeholder="Code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} data-testid="accounting-accounts-code" required style={{ maxWidth: 100 }} />
        <input className="input" placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} data-testid="accounting-accounts-name" required style={{ flex: 1, minWidth: 200 }} />
        <select className="input" value={form.account_type} onChange={(e) => setForm({ ...form, account_type: e.target.value })} data-testid="accounting-accounts-type">
          {TYPES.map((t) => <option key={t} value={t}>{t} ({NORMAL[t]})</option>)}
        </select>
        <button className="btn btn--primary" data-testid="accounting-accounts-add" disabled={busy}>{busy ? '…' : 'Add account'}</button>
      </form>

      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}

      <table className="data-table" style={{ width: '100%' }} data-testid="accounting-accounts-table">
        <thead>
          <tr>
            <th style={{ width: '40%' }}>Account</th>
            <th>Type</th><th>Normal</th><th>Postable</th><th>Active</th>
            <th style={{ width: 130 }}></th>
          </tr>
        </thead>
        <tbody>
          {tree.length === 0 && (
            <tr><td colSpan={6} className="empty" data-testid="accounting-accounts-empty">No accounts yet. Click <em>Seed standard COA</em> to get started.</td></tr>
          )}
          {tree.map(({ row: r, depth }) => (
            <tr key={r.id} data-testid={`accounting-accounts-row-${r.code}`}>
              <td>
                <span style={{ display: 'inline-block', width: depth * 20 }} aria-hidden />
                {depth > 0 && <span style={{ color: '#94a3b8', marginRight: 6 }}>└─</span>}
                <code>{r.code}</code>
                {' '}
                <span style={{ fontWeight: r.is_postable ? 400 : 600 }}>{r.name}</span>
                {!r.is_postable && (
                  <span className="badge" style={{ marginLeft: 6, fontSize: 10 }}>header</span>
                )}
              </td>
              <td>{r.account_type}</td>
              <td>{r.normal_side}</td>
              <td>{r.is_postable ? '✓' : '—'}</td>
              <td>{r.active ? '✓' : '—'}</td>
              <td>
                <button
                  type="button"
                  className="btn btn--ghost"
                  data-testid={`accounting-accounts-move-${r.code}`}
                  onClick={() => setMoveTarget(r)}
                  style={{ padding: '2px 8px', fontSize: 12 }}
                >
                  Move…
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

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
