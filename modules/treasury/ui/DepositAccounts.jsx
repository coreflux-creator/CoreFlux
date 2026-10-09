import React, { useState } from 'react';
import { Routes, Route, Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import { fmtMoney, fmtRelative } from '../../../dashboard/src/lib/format';
import AccountTransactions from './AccountTransactions';
import AccountLink from '../../../dashboard/src/components/AccountLink';

export default function DepositAccounts() {
  return (
    <Routes>
      <Route index         element={<DepositList />} />
      <Route path=":id"    element={<DepositDetail />} />
    </Routes>
  );
}

function DepositList() {
  const { activeEntityId, entities, loaded } = useActiveEntity();
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedEntityId = Number(searchParams.get('entity_id'));
  const selectedEntityId = entities.some((entity) => entity.id === requestedEntityId)
    ? requestedEntityId : activeEntityId;
  const query = selectedEntityId ? `?entity_id=${selectedEntityId}` : '';
  const { data, error, loading, reload } = useApi(
    `/modules/treasury/api/deposit_accounts.php${query}`,
    { enabled: loaded && Boolean(selectedEntityId) },
  );
  const rows = (data?.rows || []).filter((row) => Number(row.entity_id) === selectedEntityId);
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
        {selectedEntityId ? (
          <Link className="btn btn--primary"
            to={`/modules/accounting/bank-rec?entity_id=${selectedEntityId}&new=1`}
            data-testid="treasury-deposit-new-btn">+ Add bank account</Link>
        ) : <button className="btn btn--primary" disabled>+ Add bank account</button>}
      </header>

      {loaded && entities.length > 0 && (
        <div data-testid="treasury-deposits-entity-scope" style={{ marginBottom: 16 }}>
          <label htmlFor="treasury-deposits-entity" style={{ display: 'block', fontSize: 12, marginBottom: 4 }}>
            Legal entity
          </label>
          <select
            id="treasury-deposits-entity"
            className="input"
            value={selectedEntityId || ''}
            onChange={(event) => {
              const next = new URLSearchParams(searchParams);
              next.set('entity_id', event.target.value);
              setSearchParams(next);
            }}
            style={{ maxWidth: 360 }}
          >
            {entities.map((entity) => (
              <option key={entity.id} value={entity.id}>
                {entity.code} - {entity.legal_name}
              </option>
            ))}
          </select>
        </div>
      )}

      {(!loaded || loading) && <p>Loading…</p>}
      {loaded && entities.length === 0 && <p className="empty-state">Create a legal entity before adding a deposit account.</p>}
      {error && <p className="error" role="alert">Could not load deposit accounts: {error.message} <button type="button" className="btn btn--ghost" onClick={reload}>Retry</button></p>}
      {loaded && !loading && !error && selectedEntityId && rows.length === 0 && (
        <p className="empty-state" data-testid="treasury-deposits-empty">
          No deposit accounts yet. Add a bank account to get started.
        </p>
      )}

      {rows.length > 0 && (
        <div role="region" aria-label="Deposit accounts table" tabIndex={0}
          style={{ maxWidth: '100%', overflowX: 'auto' }}>
        <table className="data-table" data-testid="treasury-deposits-table"
          style={{ minWidth: 1100 }}>
          <thead>
            <tr>
              <th>Name</th><th>GL code</th><th>Bank</th><th>Last 4</th>
              <th>Feed</th><th>Last sync</th>
              <th style={{ textAlign: 'right' }}>Bank balance</th>
              <th style={{ textAlign: 'right' }}>Available</th>
              <th style={{ textAlign: 'right' }}>GL balance</th>
              <th style={{ textAlign: 'right' }}>Difference</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <DepositRow key={r.id} row={r} onChanged={reload} navigate={navigate} />
            ))}
          </tbody>
        </table>
        </div>
      )}
    </section>
  );
}

function DepositRow({ row: r, onChanged, navigate }) {
  const [busy, setBusy] = useState(null); // 'sync' | 'hide' | 'delete' | null
  const [err, setErr]   = useState(null);

  // Absolute path so navigation works no matter where this list is mounted.
  const detailPath = `/modules/treasury/deposits/${r.id}?entity_id=${r.entity_id}`;
  const open = () => navigate(detailPath);

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
      <td><AccountLink accountId={r.gl_account_id} accountCode={r.gl_account_code} entityId={r.entity_id} onClick={(e) => e.stopPropagation()}>{r.name}</AccountLink></td>
      <td><AccountLink accountId={r.gl_account_id} accountCode={r.gl_account_code} entityId={r.entity_id} onClick={(e) => e.stopPropagation()}><code>{r.gl_account_code}</code></AccountLink></td>
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
        {r.available_balance !== null && r.available_balance !== undefined ? fmtMoney(r.available_balance) : '—'}
      </td>
      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
        {fmtMoney(r.gl_balance)}
      </td>
      <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: r.bank_balance != null && Math.abs(Number(r.bank_balance) - Number(r.gl_balance)) >= 0.005 ? '#b45309' : undefined }}>
        {r.bank_balance !== null && r.bank_balance !== undefined ? fmtMoney(Number(r.bank_balance) - Number(r.gl_balance)) : '—'}
      </td>
      <td onClick={(e) => e.stopPropagation()} style={{ whiteSpace: 'nowrap' }}>
        <Link
          to={detailPath}
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
        <td colSpan={11} style={{ color: '#b91c1c', fontSize: 12, paddingLeft: 16 }}>{err}</td>
      </tr>
    )}
    </>
  );
}

function DepositDetail() {
  // QuickBooks-style: click an account → see its transactions, right here.
  // No bouncing to other modules, no "open workspace" link. The bank-feed
  // table, sync button, and per-row Categorize/Ignore/Match all live below.
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const accountId = Number(id);
  const { data: listData } = useApi('/modules/treasury/api/deposit_accounts.php');
  const account = (listData?.rows || []).find((r) => r.id === accountId);
  const entityId = account?.entity_id || Number(searchParams.get('entity_id')) || null;
  const backPath = `/modules/treasury/deposits${entityId ? `?entity_id=${entityId}` : ''}`;
  const label = account
    ? `${account.name}${account.last4 ? ` · ····${account.last4}` : ''}`
    : `Deposit account #${accountId}`;

  return (
    <section data-testid="treasury-deposit-detail">
      <p style={{ marginBottom: 12 }}>
        <Link
          to={backPath}
          className="muted"
          style={{ fontSize: 13 }}
          data-testid="treasury-deposit-detail-back"
        >
          ← Back to deposit accounts
        </Link>
        {account?.gl_account_code && <>{' '}<AccountLink accountId={account.gl_account_id} accountCode={account.gl_account_code} entityId={account.entity_id}>Account terms</AccountLink></>}
      </p>
      <AccountTransactions
        accountId={accountId}
        type="deposit"
        accountLabel={label}
      />
    </section>
  );
}
