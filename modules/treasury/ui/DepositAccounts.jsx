import React, { useState } from 'react';
import { Routes, Route, Link, useNavigate, useParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney, fmtRelative } from '../../../dashboard/src/lib/format';
import AccountTransactions from './AccountTransactions';

export default function DepositAccounts() {
  return (
    <Routes>
      <Route index         element={<DepositList />} />
      <Route path=":id"    element={<DepositDetail />} />
    </Routes>
  );
}

function DepositList() {
  const { data, loading, reload } = useApi('/modules/treasury/api/deposit_accounts.php');
  const rows = data?.rows || [];
  const [showNew, setShowNew] = useState(false);
  const navigate = useNavigate();

  return (
    <section className="treasury-deposits" data-testid="treasury-deposits">
      <header className="treasury-overview__header">
        <div>
          <h2>Deposit accounts</h2>
          <p className="muted">
            Checking, savings, and cash-on-hand accounts. Connect via Plaid to
            pull live bank-feed transactions into the ledger.
          </p>
        </div>
        <button
          className="btn btn--primary"
          onClick={() => setShowNew((v) => !v)}
          data-testid="treasury-deposit-new-btn"
        >
          {showNew ? 'Cancel' : '+ New deposit account'}
        </button>
      </header>

      {showNew && <NewDepositForm onDone={() => { setShowNew(false); reload(); }} />}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty-state" data-testid="treasury-deposits-empty">
          No deposit accounts yet. Click <em>+ New deposit account</em> to add one.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="treasury-deposits-table">
          <thead>
            <tr>
              <th>Name</th><th>GL code</th><th>Bank</th><th>Last 4</th>
              <th>Feed</th><th>Last sync</th>
              <th style={{ textAlign: 'right' }}>Bank balance</th>
              <th style={{ textAlign: 'right' }}>GL balance</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <DepositRow key={r.id} row={r} onChanged={reload} navigate={navigate} />
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function DepositRow({ row: r, onChanged, navigate }) {
  const [busy, setBusy] = useState(null); // 'sync' | 'hide' | 'delete' | null
  const [err, setErr]   = useState(null);

  const open = () => navigate(`./${r.id}`);

  const sync = async (e) => {
    e.stopPropagation();
    setBusy('sync'); setErr(null);
    try {
      // Resolve item_id from plaid_account_id via diagnostics — avoids re-opening
      // Plaid Link just to refresh transactions.
      const diag = await api.get('/api/plaid_diagnostics.php');
      const acc = (diag.plaid_accounts || []).find((a) => a.account_id === r.plaid_account_id);
      const itemPk = acc?.plaid_item_pk;
      const item = (diag.plaid_items || []).find((i) => i.id === itemPk);
      if (!item) throw new Error('Plaid item not found for this account. Try Reconnect.');
      await api.post('/api/plaid_sync_transactions.php', {
        item_id: item.item_id,
        accounting_bank_account_id: r.id,
      });
      onChanged && onChanged();
    } catch (e) { setErr(e.message || 'Sync failed'); }
    finally { setBusy(null); }
  };

  const hide = async (e) => {
    e.stopPropagation();
    if (!confirm(`Hide "${r.name}"? It will be removed from the list but historical transactions remain.`)) return;
    setBusy('hide'); setErr(null);
    try {
      await api.delete(`/modules/treasury/api/deposit_accounts.php?id=${r.id}&mode=hide`);
      onChanged && onChanged();
    } catch (e) { setErr(e.message || 'Hide failed'); }
    finally { setBusy(null); }
  };

  const hardDelete = async (e) => {
    e.stopPropagation();
    if (!confirm(`Permanently DELETE "${r.name}" and all its imported statement lines?\n\nThis cannot be undone. (Allowed only when no posted journal entries reference this account.)`)) return;
    setBusy('delete'); setErr(null);
    try {
      await api.delete(`/modules/treasury/api/deposit_accounts.php?id=${r.id}&mode=delete`);
      onChanged && onChanged();
    } catch (e) { setErr(e.message || 'Delete failed'); }
    finally { setBusy(null); }
  };

  return (
    <>
    <tr
      data-testid={`treasury-deposit-row-${r.id}`}
      style={{ cursor: 'pointer' }}
      onClick={open}
    >
      <td>{r.name}</td>
      <td><code>{r.gl_account_code}</code></td>
      <td>{r.bank_name || '—'}</td>
      <td>{r.last4 || '—'}</td>
      <td>
        {r.plaid_connected ? (
          <span className="badge badge--active">plaid</span>
        ) : (
          <span className="badge">manual</span>
        )}
      </td>
      <td className="muted" style={{ whiteSpace: 'nowrap' }}>{r.last_feed_synced_at ? fmtRelative(r.last_feed_synced_at) : '—'}</td>
      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }} data-testid={`treasury-deposit-bank-balance-${r.id}`}>
        {r.bank_balance !== null && r.bank_balance !== undefined ? fmtMoney(r.bank_balance) : '—'}
      </td>
      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
        {fmtMoney(r.gl_balance)}
      </td>
      <td onClick={(e) => e.stopPropagation()} style={{ whiteSpace: 'nowrap' }}>
        <Link
          to={`./${r.id}`}
          className="btn btn--ghost"
          data-testid={`treasury-deposit-view-${r.id}`}
          style={{ padding: '4px 10px', fontSize: 12, marginRight: 6 }}
        >
          Transactions →
        </Link>
        {r.plaid_connected && (
          <button
            type="button"
            onClick={sync}
            disabled={busy === 'sync'}
            className="btn btn--ghost"
            data-testid={`treasury-deposit-sync-${r.id}`}
            style={{ padding: '4px 10px', fontSize: 12, marginRight: 6 }}
          >
            {busy === 'sync' ? 'Syncing…' : 'Sync'}
          </button>
        )}
        <button
          type="button"
          onClick={hide}
          disabled={busy === 'hide'}
          className="btn btn--ghost"
          data-testid={`treasury-deposit-hide-${r.id}`}
          style={{ padding: '4px 10px', fontSize: 12, marginRight: 6 }}
          title="Hide this account from Treasury (keeps history)"
        >
          {busy === 'hide' ? 'Hiding…' : 'Hide'}
        </button>
        <button
          type="button"
          onClick={hardDelete}
          disabled={busy === 'delete'}
          className="btn btn--ghost"
          data-testid={`treasury-deposit-delete-${r.id}`}
          style={{ padding: '4px 10px', fontSize: 12, color: '#b91c1c' }}
          title="Permanently delete this account and its statement lines"
        >
          {busy === 'delete' ? 'Deleting…' : 'Delete'}
        </button>
      </td>
    </tr>
    {err && (
      <tr data-testid={`treasury-deposit-err-${r.id}`}>
        <td colSpan={9} style={{ color: '#b91c1c', fontSize: 12, paddingLeft: 16 }}>{err}</td>
      </tr>
    )}
    </>
  );
}

function NewDepositForm({ onDone }) {
  const [f, setF] = useState({ name: '', gl_account_code: '', bank_name: '', last4: '' });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const submit = async () => {
    setBusy(true); setErr(null);
    try { await api.post('/modules/treasury/api/deposit_accounts.php', f); onDone(); }
    catch (e) { setErr(e.message); } finally { setBusy(false); }
  };
  return (
    <div
      data-testid="treasury-deposit-new-form"
      style={{
        padding: 16, marginBottom: 16, background: 'var(--cf-surface)',
        border: '1px solid var(--cf-border)', borderRadius: 8,
      }}
    >
      <h3 style={{ margin: '0 0 12px', fontSize: 14 }}>New deposit account</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 8 }}>
        <input className="input" placeholder="Name (Operating Chase ...4421)"
          value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })}
          data-testid="treasury-deposit-name" required />
        <input className="input" placeholder="GL account code (1010)"
          value={f.gl_account_code} onChange={(e) => setF({ ...f, gl_account_code: e.target.value })}
          data-testid="treasury-deposit-gl" required />
        <input className="input" placeholder="Bank name (optional)"
          value={f.bank_name} onChange={(e) => setF({ ...f, bank_name: e.target.value })} />
        <input className="input" placeholder="Last 4" maxLength={4}
          value={f.last4} onChange={(e) => setF({ ...f, last4: e.target.value })} />
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
        <button
          type="button" className="btn btn--primary"
          onClick={submit} disabled={busy || !f.name || !f.gl_account_code}
          data-testid="treasury-deposit-save">
          {busy ? 'Saving…' : 'Save'}
        </button>
      </div>
      {err && <p className="error" data-testid="treasury-deposit-error">{err}</p>}
    </div>
  );
}

function DepositDetail() {
  // Render the bank-feed transactions inline in Treasury — same component
  // the liability detail uses, so users get a consistent place to view
  // activity, sync, and categorize without bouncing to the Accounting
  // module. The full reconciliation editor (statement lines + matching
  // grid) still lives at /modules/accounting/bank-rec/:id and can be
  // opened from there when the user wants the heavy-duty workspace.
  const { id } = useParams();
  const accountId = Number(id);
  const { data: listData } = useApi('/modules/treasury/api/deposit_accounts.php');
  const account = (listData?.rows || []).find((r) => r.id === accountId);
  const label = account
    ? `${account.name}${account.last4 ? ` · ····${account.last4}` : ''}`
    : `Deposit account #${accountId}`;

  return (
    <section data-testid="treasury-deposit-detail">
      <p style={{ marginBottom: 12 }}>
        <Link to=".." className="muted" style={{ fontSize: 13 }}>← Back to deposit accounts</Link>
        {' · '}
        <Link
          to={`/modules/accounting/bank-rec/${accountId}`}
          className="muted"
          style={{ fontSize: 13 }}
          data-testid="treasury-deposit-detail-bankrec-link"
        >
          Open full reconciliation workspace →
        </Link>
      </p>
      <AccountTransactions
        accountId={accountId}
        type="deposit"
        accountLabel={label}
      />
    </section>
  );
}
