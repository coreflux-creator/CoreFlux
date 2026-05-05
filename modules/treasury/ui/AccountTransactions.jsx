import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (n || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/**
 * Shared transactions list used by both DepositDetail + LiabilityDetail.
 *
 * Props:
 *   accountId: number  (deposit = accounting_bank_accounts.id, liability = accounting_accounts.id)
 *   type:      'deposit' | 'liability'
 *   accountLabel: string  (e.g. "Bank of America — Checking …6056")
 */
export default function AccountTransactions({ accountId, type, accountLabel }) {
  const { data, loading, reload } = useApi(
    `/modules/treasury/api/account_transactions.php?account_id=${accountId}&type=${type}&limit=200`
  );
  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState(null);
  const [syncErr, setSyncErr] = useState(null);

  const rows  = data?.rows || [];
  const count = data?.count || 0;
  const inflow  = data?.inflow_total  || 0;
  const outflow = data?.outflow_total || 0;
  const plaidItemPk         = data?.plaid_item_pk;
  const plaidItemExternalId = data?.plaid_item_external_id;

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

      {rows.length > 0 && (
        <table className="data-table" data-testid={`treasury-${type}-transactions-table`}>
          <thead>
            <tr>
              <th>Date</th>
              <th>Description</th>
              {type === 'liability' && <th>Category</th>}
              <th style={{ textAlign: 'right' }}>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} data-testid={`treasury-txn-row-${r.id}`}>
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
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
