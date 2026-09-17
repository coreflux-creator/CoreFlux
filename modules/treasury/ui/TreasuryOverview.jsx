import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney, fmtRelative } from '../../../dashboard/src/lib/format';
import AccountLink from '../../../dashboard/src/components/AccountLink';

function plaidIssueText(code, message) {
  const detail = `${code || ''} ${message || ''}`.toUpperCase();
  if (detail.includes('ITEM_NOT_FOUND') || detail.includes('CANNOT BE FOUND')) {
    return 'Connection no longer available. Reconnect or disconnect it.';
  }
  if (detail.includes('ITEM_LOGIN_REQUIRED') || detail.includes('LOGIN_REQUIRED')) {
    return 'Bank sign-in needs to be renewed.';
  }
  return message || code || 'Connection needs attention.';
}

function plaidIssueIsRemoved(item) {
  const detail = `${item?.last_error_code || ''} ${item?.last_error_message || ''}`.toUpperCase();
  return detail.includes('ITEM_NOT_FOUND') || detail.includes('CANNOT BE FOUND');
}

function maskedConnectionId(itemId) {
  if (!itemId) return 'Connection ID unavailable';
  return `Connection …${String(itemId).slice(-6)}`;
}

export default function TreasuryOverview() {
  const dep = useApi('/modules/treasury/api/deposit_accounts.php');
  const lia = useApi('/modules/treasury/api/liability_accounts.php');

  const depositRows   = dep.data?.rows || [];
  const liabilityRows = lia.data?.rows || [];
  // Headline figures prefer the live Plaid balance (what's actually in the
  // bank right now) and fall back to the GL balance only when no feed exists.
  const balanceOf = (r) => (r.bank_balance !== null && r.bank_balance !== undefined)
    ? r.bank_balance
    : (r.gl_balance || 0);
  const depositTotal   = depositRows.reduce((s, r) => s + balanceOf(r), 0);
  // A debit balance on a card/loan is a credit or overpayment, not negative
  // debt and not cash in the bank. Keep it out of both headline figures.
  const liabilityTotal = liabilityRows.reduce((s, r) => s + Math.max(0, Number(balanceOf(r)) || 0), 0);
  const liabilityCreditTotal = liabilityRows.reduce(
    (s, r) => s + Math.max(0, -(Number(balanceOf(r)) || 0)),
    0
  );
  const netCash = depositTotal - liabilityTotal;

  return (
    <section className="treasury-overview" data-testid="treasury-overview">
      <header className="treasury-overview__header">
        <div>
          <h2>Treasury</h2>
          <p className="muted">
            Cash positions, credit exposure, and bank-feed health in one view.
            {' '}Use the <Link to="../forecast">13-week forecast</Link> to plan ahead or
            {' '}compare <Link to="../scenario">cash scenarios</Link> before committing.
          </p>
        </div>
      </header>

      <div className="treasury-overview__stats payroll-stats" data-testid="treasury-overview-stats">
        <div className="stat-card">
          <div className="stat-card__value" data-testid="treasury-overview-deposits-total">
            {fmtMoney(depositTotal)}
          </div>
          <div className="stat-card__label">Deposit accounts ({depositRows.length})</div>
        </div>
        <div className="stat-card stat-card--warn">
          <div className="stat-card__value" data-testid="treasury-overview-liabilities-total">
            {fmtMoney(liabilityTotal)}
          </div>
          <div className="stat-card__label">Debt outstanding ({liabilityRows.length})</div>
          {liabilityCreditTotal > 0 && (
            <div className="muted" style={{ marginTop: 4, fontSize: 12 }}>
              {fmtMoney(liabilityCreditTotal)} account credit excluded
            </div>
          )}
        </div>
        <div className="stat-card" data-testid="treasury-overview-net-cash">
          <div className="stat-card__value" style={{ color: netCash < 0 ? '#dc2626' : undefined }}>
            {fmtMoney(netCash)}
          </div>
          <div className="stat-card__label">Cash after outstanding debt</div>
        </div>
      </div>

      <section className="treasury-overview__section">
        <header className="treasury-overview__section-head">
          <h3>Deposit accounts</h3>
          <Link to="../deposits" className="btn btn--ghost" data-testid="treasury-overview-deposits-link">
            Manage →
          </Link>
        </header>
        {depositRows.length === 0 ? (
          <p className="empty-state">
            No deposit accounts yet.{' '}
            <Link to="../deposits">Create your first one</Link> or connect a bank via Plaid.
          </p>
        ) : (
          <table className="data-table" data-testid="treasury-overview-deposits-table">
            <thead>
              <tr><th>Name</th><th>Bank</th><th>Last 4</th><th>Feed</th><th style={{ textAlign: 'right' }}>Bank balance</th><th></th></tr>
            </thead>
            <tbody>
              {depositRows.slice(0, 5).map((r) => (
                <tr key={r.id}>
                  <td><AccountLink accountId={r.gl_account_id} accountCode={r.gl_account_code} entityId={r.entity_id}>{r.name}</AccountLink></td>
                  <td>{r.bank_name || '—'}</td>
                  <td>{r.last4 || '—'}</td>
                  <td>
                    {r.plaid_connected ? (
                      <span className="badge badge--active">plaid</span>
                    ) : (
                      <span className="badge">manual</span>
                    )}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {fmtMoney(balanceOf(r))}
                  </td>
                  <td><Link to={`../deposits/${r.id}`}>Activity</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section className="treasury-overview__section">
        <header className="treasury-overview__section-head">
          <h3>Liability accounts</h3>
          <Link to="../liabilities" className="btn btn--ghost" data-testid="treasury-overview-liabilities-link">
            Manage →
          </Link>
        </header>
        {liabilityRows.length === 0 ? (
          <p className="empty-state">
            No liability accounts yet.{' '}
            <Link to="../liabilities">Add a credit card, loan, or line of credit</Link>.
          </p>
        ) : (
          <table className="data-table" data-testid="treasury-overview-liabilities-table">
            <thead>
              <tr><th>Name</th><th>Type</th><th>Last 4</th><th style={{ textAlign: 'right' }}>Outstanding</th><th style={{ textAlign: 'right' }}>Limit</th><th></th></tr>
            </thead>
            <tbody>
              {liabilityRows.slice(0, 5).map((r) => (
                <tr key={r.id}>
                  <td><AccountLink accountId={r.id} accountCode={r.code}>{r.name}</AccountLink></td>
                  <td>{(r.subtype || 'other_liability').replace('_', ' ')}</td>
                  <td>{r.last4 || '—'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {fmtMoney(balanceOf(r))}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {r.credit_limit ? fmtMoney(r.credit_limit) : '—'}
                  </td>
                  <td><Link to={`../liabilities/${r.id}`}>Activity</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
      <section className="treasury-overview__section">
        <PlaidHealthBanner />
      </section>

      <section className="treasury-overview__section">
        <BankConnectCard onLinked={() => window.location.reload()} />
      </section>

      <section className="treasury-overview__section">
        <ConnectedInstitutions onChanged={() => window.location.reload()} />
      </section>

      <section className="treasury-overview__section">
        <PlaidTransferFundingCard />
      </section>
    </section>
  );
}

function PlaidHealthBanner() {
  // Any plaid_item with a non-null last_error_code or status != 'linked' is
  // in a broken state — usually ITEM_LOGIN_REQUIRED (credentials expired).
  // Show a blocker banner at the top of Treasury so the user doesn't spend
  // time wondering why balances are stale.
  const { data, reload } = useApi('/api/plaid_items.php');
  const [busy, setBusy]   = React.useState(null);
  const [err, setErr]     = React.useState(null);

  const needing = (data?.rows || []).filter(
    (r) => r.status !== 'disconnected'
      && (r.status === 'error' || (r.last_error_code && r.last_error_code !== 'item_remove_warning'))
  );
  const stale = needing.filter(plaidIssueIsRemoved);
  const reconnectable = needing.filter((item) => !plaidIssueIsRemoved(item));
  const staleGroups = Object.values(stale.reduce((groups, item) => {
    const label = item.institution_name || 'Unknown institution';
    if (!groups[label]) groups[label] = { label, items: [] };
    groups[label].items.push(item);
    return groups;
  }, {}));
  if (!data || needing.length === 0) return null;

  const removeStale = async (items) => {
    const mirrored = items.reduce(
      (sum, item) => sum + Number(item.mirrored_deposit_count || 0) + Number(item.mirrored_liability_count || 0),
      0
    );
    const message =
      `Remove ${items.length} expired ${items.length === 1 ? 'connection' : 'connections'} for ${items[0]?.institution_name || 'this institution'}?\n\n` +
      `Plaid no longer recognizes ${items.length === 1 ? 'this login' : 'these logins'}, so they cannot be reconnected. ` +
      `${mirrored ? `${mirrored} mirrored account${mirrored === 1 ? '' : 's'} will be hidden. ` : ''}` +
      'Historical transactions and journal entries will remain available.';
    if (!confirm(message)) return;

    const busyKey = `stale:${items.map((item) => item.id).join(',')}`;
    setBusy(busyKey); setErr(null);
    const failures = [];
    for (const item of items) {
      try {
        await fetch(`/api/plaid_items.php?id=${item.id}`, {
          method: 'DELETE', credentials: 'include',
        }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      } catch (e) {
        failures.push(e?.error || e?.message || `Connection ${item.id}`);
      }
    }
    await reload();
    setBusy(null);
    if (failures.length) {
      setErr(`${failures.length} stale connection${failures.length === 1 ? '' : 's'} could not be removed: ${failures.join('; ')}`);
    }
  };

  const reconnect = async (item) => {
    setBusy(item.id); setErr(null);
    try {
      // Plaid link-token in update mode — lets Link re-auth the same item
      // without losing historical transactions or the access_token PK.
      const tok = await fetch('/api/plaid_link_token.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ purpose: 'bank_feed', update_item_id: item.item_id }),
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      if (!tok.link_token) throw new Error('No link_token returned');

      await ensurePlaidLink();
      const handler = window.Plaid.create({
        token: tok.link_token,
        onSuccess: async () => {
          try {
            // Clear the cached error and trigger a sync so balances refresh.
            await fetch(`/api/plaid_items.php?id=${item.id}`, {
              method: 'GET', credentials: 'include',
            });
            await fetch('/api/plaid_sync_transactions.php', {
              method: 'POST', credentials: 'include',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ item_id: item.item_id }),
            });
            await reload();
          } catch (e) { setErr(e.message || 'Reconnect sync failed'); }
          finally { setBusy(null); }
        },
        onExit: (e) => { if (e) setErr(e.error_message || 'Cancelled'); setBusy(null); },
      });
      handler.open();
    } catch (e) {
      setErr(e.error || e.message || 'Reconnect failed');
      setBusy(null);
    }
  };

  return (
    <div
      data-testid="treasury-plaid-health-banner"
      style={{
        padding: 14, background: '#fef2f2', border: '1px solid #ef4444',
        borderRadius: 6, marginBottom: 20,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div>
          <strong style={{ color: '#991b1b' }}>
            {needing.length} bank connection{needing.length === 1 ? '' : 's'} need attention
          </strong>
          <p className="muted" style={{ fontSize: 13, margin: '4px 0 0' }}>
            Renew connections that need bank sign-in. Expired connections can be removed in one step.
          </p>
        </div>
      </div>
      <ul style={{ listStyle: 'none', padding: 0, margin: '12px 0 0' }}>
        {staleGroups.map((group) => {
          const busyKey = `stale:${group.items.map((item) => item.id).join(',')}`;
          return (
          <li
            key={`stale:${group.label}`}
            data-testid={`treasury-plaid-health-stale-${group.items[0].id}`}
            style={{
              display: 'flex', justifyContent: 'space-between', alignItems: 'center',
              padding: '8px 0', borderTop: '1px solid #fecaca', gap: 12,
            }}
          >
            <div>
              <strong>{group.label}</strong>
              <span className="muted" style={{ fontSize: 12, marginLeft: 8 }}>
                {group.items.length} expired bank connection{group.items.length === 1 ? '' : 's'} can no longer be refreshed.
              </span>
            </div>
            <button
              type="button"
              className="btn btn--ghost"
              onClick={() => removeStale(group.items)}
              disabled={busy === busyKey}
              data-testid={`treasury-plaid-health-remove-stale-${group.items[0].id}`}
              style={{ padding: '4px 14px', fontSize: 12, color: '#b91c1c' }}
            >
              {busy === busyKey ? 'Removing...' : `Remove ${group.items.length} stale`}
            </button>
          </li>
          );
        })}
        {reconnectable.map((item) => (
          <li
            key={item.id}
            data-testid={`treasury-plaid-health-row-${item.id}`}
            style={{
              display: 'flex', justifyContent: 'space-between', alignItems: 'center',
              padding: '8px 0', borderTop: '1px solid #fecaca',
            }}
          >
            <div>
              <strong>{item.institution_name || item.item_id}</strong>
              {item.last_error_code && (
                <span className="muted" style={{ fontSize: 12, marginLeft: 8 }}>
                  {plaidIssueText(item.last_error_code, item.last_error_message)}
                </span>
              )}
            </div>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => reconnect(item)}
              disabled={busy === item.id}
              data-testid={`treasury-plaid-health-reconnect-${item.id}`}
              style={{ padding: '4px 14px', fontSize: 12 }}
            >
              {busy === item.id ? 'Opening secure connection…' : 'Reconnect'}
            </button>
          </li>
        ))}
      </ul>
      {err && <p className="error" data-testid="treasury-plaid-health-err" style={{ marginTop: 8 }}>{err}</p>}
    </div>
  );
}

function ConnectedInstitutions({ onChanged }) {
  const { data, loading, reload } = useApi('/api/plaid_items.php');
  const dedupeApi = useApi('/api/plaid_dedupe.php');
  const allRows = data?.rows || [];
  const rows = allRows.filter((row) => row.status !== 'disconnected');
  const historyRows = allRows.filter((row) => row.status === 'disconnected');
  const dupeDeposits   = dedupeApi.data?.deposit_clusters?.length   || 0;
  const dupeLiabs      = dedupeApi.data?.liability_clusters?.length || 0;
  const dupeCount      = dupeDeposits + dupeLiabs;
  const [busy, setBusy] = React.useState(null);
  const [err, setErr]   = React.useState(null);
  const [notice, setNotice] = React.useState(null);

  const disconnect = async (item) => {
    const confirmMsg =
      `Disconnect ${item.institution_name || 'this institution'}?\n\n` +
      `• New balances and transactions will stop syncing.\n` +
      `• ${item.mirrored_deposit_count || 0} deposit account(s) and ${item.mirrored_liability_count || 0} liability account(s) will be hidden from Treasury.\n` +
      `• Historical journal entries and statement lines will remain.\n\n` +
      `Continue?`;
    if (!confirm(confirmMsg)) return;
    setBusy(item.id); setErr(null); setNotice(null);
    try {
      await fetch(`/api/plaid_items.php?id=${item.id}`, {
        method: 'DELETE', credentials: 'include',
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      await reload();
      if (onChanged) onChanged();
    } catch {
      setErr('The bank could not be disconnected. Try again or open Connections for help.');
    } finally { setBusy(null); }
  };

  const cleanupDupes = async () => {
    const msg =
      `Found ${dupeCount} duplicate cluster${dupeCount === 1 ? '' : 's'} ` +
      `(${dupeDeposits} deposit, ${dupeLiabs} liability). ` +
      `For each cluster, the most-recently-synced row will be kept and the others hidden. Continue?`;
    if (!confirm(msg)) return;
    setBusy('dedupe'); setErr(null); setNotice(null);
    try {
      const res = await fetch('/api/plaid_dedupe.php?action=run', {
        method: 'POST', credentials: 'include',
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      const hd = res.hidden_deposit_ids?.length || 0;
      const hl = res.hidden_liability_ids?.length || 0;
      setNotice(`Cleanup complete. Hid ${hd} duplicate deposit account${hd === 1 ? '' : 's'} and ${hl} duplicate liability account${hl === 1 ? '' : 's'}.`);
      await dedupeApi.reload();
      if (onChanged) onChanged();
    } catch {
      setErr('Duplicate accounts could not be cleaned up. Try again from Connections.');
    } finally { setBusy(null); }
  };

  return (
    <div data-testid="treasury-connected-institutions">
      <h3>Connected banks</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Each row represents one bank sign-in. Disconnecting stops future updates and hides its linked accounts;
        historical transactions remain available. Use <em>Hide</em> or <em>Delete</em> above to manage one account at a time.
      </p>
      {dupeCount > 0 && (
        <div
          data-testid="treasury-dedupe-banner"
          style={{
            padding: 10, background: '#fef3c7', border: '1px solid #f59e0b',
            borderRadius: 4, marginBottom: 12, color: '#78350f',
            display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12,
          }}
        >
          <span>
            <strong>{dupeCount} duplicate cluster{dupeCount === 1 ? '' : 's'} detected.</strong>
            {' '}Earlier bank reconnections created extra account rows. Cleanup keeps the newest row and hides the duplicates.
          </span>
          <button
            type="button"
            className="btn btn--primary"
            onClick={cleanupDupes}
            disabled={busy === 'dedupe'}
            data-testid="treasury-dedupe-run-btn"
          >
            {busy === 'dedupe' ? 'Cleaning…' : `Cleanup ${dupeCount} duplicate${dupeCount === 1 ? '' : 's'}`}
          </button>
        </div>
      )}
      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty-state" data-testid="treasury-connected-institutions-empty">
          No banks connected yet.
        </p>
      )}
      {rows.length > 0 && (
        <table className="data-table" data-testid="treasury-connected-institutions-table">
          <thead>
            <tr>
              <th>Institution</th><th>Status</th>
              <th style={{ textAlign: 'right' }}>Accounts</th>
              <th style={{ textAlign: 'right' }}>Mirrored</th>
              <th>Last webhook</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} data-testid={`treasury-plaid-item-row-${r.id}`}>
                <td>
                  <strong>{r.institution_name || '—'}</strong>
                  <div className="muted" style={{ fontSize: 11 }} title={r.item_id || undefined}>{maskedConnectionId(r.item_id)}</div>
                </td>
                <td>
                  {r.status === 'linked' && <span className="badge badge--active">linked</span>}
                  {r.status === 'disconnected' && <span className="badge">disconnected</span>}
                  {r.status === 'error' && <span className="badge" style={{ background: '#fee2e2', color: '#991b1b' }}>error</span>}
                  {r.last_error_message && (
                    <div className="muted" style={{ fontSize: 11 }}>{plaidIssueText(r.last_error_code, r.last_error_message)}</div>
                  )}
                </td>
                <td style={{ textAlign: 'right' }}>{r.account_count}</td>
                <td style={{ textAlign: 'right' }}>
                  {r.mirrored_deposit_count} dep · {r.mirrored_liability_count} liab
                </td>
                <td className="muted" style={{ fontSize: 12, whiteSpace: 'nowrap' }}>{r.last_webhook_at ? fmtRelative(r.last_webhook_at) : '—'}</td>
                <td style={{ textAlign: 'right' }}>
                  {r.status !== 'disconnected' && (
                    <button
                      type="button"
                      onClick={() => disconnect(r)}
                      disabled={busy === r.id}
                      className="btn btn--ghost"
                      data-testid={`treasury-plaid-item-disconnect-${r.id}`}
                      style={{ padding: '4px 10px', fontSize: 12, color: '#b91c1c' }}
                    >
                      {busy === r.id ? 'Disconnecting…' : 'Disconnect'}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {notice && <p className="success" data-testid="treasury-connected-institutions-notice">{notice}</p>}
      {historyRows.length > 0 && (
        <details data-testid="treasury-connected-institutions-history" style={{ marginTop: 12 }}>
          <summary style={{ cursor: 'pointer', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Connection history ({historyRows.length})
          </summary>
          <table className="data-table" style={{ marginTop: 8 }}>
            <thead>
              <tr><th>Institution</th><th>Status</th><th>Connection</th><th>Last webhook</th></tr>
            </thead>
            <tbody>
              {historyRows.map((row) => (
                <tr key={row.id}>
                  <td>{row.institution_name || 'Unknown institution'}</td>
                  <td><span className="badge">disconnected</span></td>
                  <td className="muted" title={row.item_id || undefined}>{maskedConnectionId(row.item_id)}</td>
                  <td className="muted">{row.last_webhook_at ? fmtRelative(row.last_webhook_at) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>
      )}
      {err && <p className="error" data-testid="treasury-connected-institutions-error">{err}</p>}
    </div>
  );
}

function BankConnectCard({ onLinked }) {
  const [busy, setBusy] = React.useState(false);
  const [msg, setMsg]   = React.useState(null);
  const [err, setErr]   = React.useState(null);
  const [diag, setDiag] = React.useState(null);
  const [backfilling, setBackfilling] = React.useState(false);

  // Post-Link account picker state
  const [picker, setPicker] = React.useState(null); // { publicToken, institution, accounts:[{id,name,mask,subtype,type}] }
  const [pickerSelected, setPickerSelected] = React.useState({}); // accountId -> bool
  const [createGlPerAccount, setCreateGlPerAccount] = React.useState(false);
  const [exchanging, setExchanging] = React.useState(false);

  const link = async () => {
    setBusy(true); setMsg(null); setErr(null);
    try {
      const tok = await fetch('/api/plaid_bank_link.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      if (!tok.link_token) throw new Error('The secure bank connection could not be started.');

      await ensurePlaidLink();
      const handler = window.Plaid.create({
        token: tok.link_token,
        onSuccess: (publicToken, meta) => {
          // Pop the picker — the user opts in account-by-account before we
          // mirror anything into Treasury. If Plaid returned no metadata
          // accounts (rare), fall back to "include all" exchange.
          const accounts = (meta?.accounts || []).map((a) => ({
            id: a.id, name: a.name, mask: a.mask,
            type: a.type, subtype: a.subtype,
          }));
          if (accounts.length === 0) {
            doExchange(publicToken, meta?.institution || {}, null);
            return;
          }
          // Default-select all (matches old behavior, but visible & toggleable).
          const sel = {};
          accounts.forEach((a) => { sel[a.id] = true; });
          setPickerSelected(sel);
          setPicker({ publicToken, institution: meta?.institution || {}, accounts });
        },
        onExit: (e) => { if (e) setErr('The bank connection was closed before it finished.'); },
      });
      handler.open();
    } catch {
      setErr('The bank connection could not be completed. Try again or open Connections for help.');
    } finally { setBusy(false); }
  };

  const doExchange = async (publicToken, institution, selectedIds) => {
    setExchanging(true); setErr(null);
    try {
      const body = {
        public_token: publicToken,
        institution: {
          name:           institution?.name           || null,
          institution_id: institution?.institution_id || null,
        },
        // Default OFF since 2026-02 — every connected bank shares one
        // "Cash — Checking" / "Cash — Savings" GL row instead of one row
        // per sub-account.  Operators who reconcile per-bank flip the
        // checkbox in the picker modal.
        create_gl_per_account: createGlPerAccount,
      };
      if (Array.isArray(selectedIds)) body.selected_account_ids = selectedIds;
      const res = await fetch('/api/plaid_bank_link.php?action=exchange', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'The selected accounts could not be added.');
      const dep = data.bank_accounts_created?.length || 0;
      const lia = data.liability_accounts_created?.length || 0;
      const skipped = data.skipped_opt_out?.length || 0;
      const errs = data.errors || [];
      const parts = [];
      if (dep) parts.push(`${dep} deposit${dep === 1 ? '' : 's'}`);
      if (lia) parts.push(`${lia} liabilit${lia === 1 ? 'y' : 'ies'}`);
      if (skipped) parts.push(`${skipped} skipped (you opted out)`);
      const summary = parts.length ? parts.join(' + ') : 'all selected accounts were already connected';
      setMsg(`Connected ${institution?.name || 'bank'} — ${summary}.`);
      if (errs.length) {
        setErr('Some accounts could not be added:\n• ' + errs.join('\n• '));
      }
      setPicker(null);
      setCreateGlPerAccount(false);
      if ((dep || lia) && onLinked) setTimeout(onLinked, 1200);
    } catch (e) { setErr(e.message || 'The selected accounts could not be added.'); }
    finally { setExchanging(false); }
  };

  const confirmPicker = () => {
    if (!picker) return;
    const ids = Object.entries(pickerSelected).filter(([, v]) => v).map(([k]) => k);
    if (ids.length === 0) {
      if (!confirm('No accounts selected — cancel this connection?')) return;
      setPicker(null);
      return;
    }
    doExchange(picker.publicToken, picker.institution, ids);
  };

  const runDiagnostics = async () => {
    setErr(null); setMsg(null);
    try {
      const res = await fetch('/api/plaid_diagnostics.php', { credentials: 'include' });
      const data = await res.json();
      console.log('[plaid_diagnostics]', data);
      setDiag(data);
    } catch (e) { setErr(e.message); }
  };

  const backfillOrphans = async () => {
    if (!confirm('Add the missing connected accounts to Treasury? You will not need to sign in to the bank again.')) return;
    setBackfilling(true); setErr(null); setMsg(null);
    try {
      const res = await fetch('/api/plaid_diagnostics.php?action=backfill', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      });
      const data = await res.json();
      if (!res.ok) throw new Error('The missing accounts could not be added.');
      const dep = data.bank_accounts_created?.length || 0;
      const lia = data.liability_accounts_created?.length || 0;
      const skipped = data.skipped?.length || 0;
      const errs = data.errors || [];
      setMsg(`Added ${dep} deposit account${dep === 1 ? '' : 's'} and ${lia} liability account${lia === 1 ? '' : 's'}; skipped ${skipped}.`);
      if (errs.length) {
        setErr('Some connected accounts still need attention. Open Connections to review them.');
      }
      // Refresh diagnostics view
      await runDiagnostics();
      if ((dep || lia) && onLinked) setTimeout(onLinked, 1200);
    } catch (e) {
      setErr(e.message || 'The missing accounts could not be added.');
    } finally { setBackfilling(false); }
  };

  const orphanCount = diag?.orphaned_plaid_accounts?.length || 0;

  return (
    <div data-testid="plaid-bank-connect-card">
      <h3>Connect accounts for balances and reconciliation</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Link checking, savings, credit cards, and loans so balances and transactions stay current for reconciliation.
        <strong> This connection can only read account activity; it cannot move money.</strong>{' '}
        Connect a separate payment account below when you are ready to send approved payments.
      </p>
      <button onClick={link} disabled={busy} className="btn btn--primary" data-testid="plaid-bank-connect-btn">
        {busy ? 'Opening secure connection…' : 'Connect bank'}
      </button>
      <button
        onClick={runDiagnostics}
        className="btn btn--ghost"
        data-testid="plaid-bank-diagnostics-btn"
        style={{ marginLeft: 8 }}
      >
        Run diagnostics
      </button>
      {diag && (
        <div data-testid="plaid-diagnostics-panel" style={{
          marginTop: 12, padding: 12, background: 'var(--cf-surface)',
          border: '1px solid var(--cf-border)', borderRadius: 6, fontSize: 13,
        }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 8, marginBottom: 8 }}>
            <DiagStat label="Bank connections" value={diag.plaid_items?.length || 0} />
            <DiagStat label="Linked accounts" value={diag.plaid_accounts?.length || 0} />
            <DiagStat label="Deposit accounts" value={diag.accounting_bank_accounts_for_plaid?.length || 0} />
            <DiagStat label="Liability accounts" value={diag.treasury_liability_accounts_for_plaid?.length || 0} />
            <DiagStat label="Missing from Treasury" value={orphanCount} warn={orphanCount > 0} />
          </div>
          {orphanCount > 0 && (
            <div data-testid="plaid-orphan-banner" style={{
              padding: 10, background: '#fef3c7', border: '1px solid #f59e0b',
              borderRadius: 4, marginBottom: 8, color: '#78350f',
            }}>
              <strong>{orphanCount} connected account{orphanCount === 1 ? ' is' : 's are'} missing from Treasury.</strong>
              {' '}Add the missing account records without signing in to the bank again.
              <ul style={{ margin: '6px 0 6px 20px', fontSize: 12 }}>
                {(diag.orphaned_plaid_accounts || []).slice(0, 5).map((o) => (
                  <li key={o.id}>
                    {o.name} {o.mask ? `…${o.mask}` : ''} — <em>{o.type}/{o.subtype || '—'}</em>
                  </li>
                ))}
              </ul>
              <button
                onClick={backfillOrphans}
                disabled={backfilling}
                className="btn btn--primary"
                data-testid="plaid-backfill-orphans-btn"
              >
                {backfilling ? 'Adding accounts…' : `Add ${orphanCount} missing account${orphanCount === 1 ? '' : 's'}`}
              </button>
            </div>
          )}
          {orphanCount === 0 && (
            <p style={{ margin: 0, color: '#065f46' }} data-testid="plaid-no-orphans">
              All connected accounts are available in Treasury.
            </p>
          )}
        </div>
      )}
      {msg && <p style={{ color: '#065f46', fontSize: 13, marginTop: 8 }} data-testid="plaid-bank-connect-success">{msg}</p>}
      {err && <p className="error" data-testid="plaid-bank-connect-error" style={{ whiteSpace: 'pre-line' }}>{err}</p>}
      {picker && (
        <div
          data-testid="plaid-account-picker-modal"
          style={{
            position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)',
            display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000,
          }}
        >
          <div style={{
            background: '#fff', borderRadius: 8, padding: 24, width: 'min(560px, 92vw)',
            maxHeight: '88vh', overflow: 'auto', boxShadow: '0 20px 50px rgba(0,0,0,0.25)',
          }}>
            <h3 style={{ marginTop: 0 }}>Choose accounts to add</h3>
            <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>
              {picker.institution?.name || 'Your bank'} returned {picker.accounts.length} account
              {picker.accounts.length === 1 ? '' : 's'}. Only checked accounts will be added to Treasury.
              Uncheck personal or out-of-scope accounts; you can add them later from diagnostics.
            </p>
            <div style={{ borderTop: '1px solid var(--cf-border, #e5e7eb)', margin: '12px 0' }} />
            <div data-testid="plaid-account-picker-list">
              {picker.accounts.map((a) => (
                <label
                  key={a.id}
                  data-testid={`plaid-account-picker-row-${a.id}`}
                  style={{
                    display: 'flex', alignItems: 'center', gap: 12,
                    padding: '8px 4px', borderBottom: '1px solid var(--cf-border, #f1f5f9)',
                    cursor: 'pointer',
                  }}
                >
                  <input
                    type="checkbox"
                    checked={!!pickerSelected[a.id]}
                    onChange={(e) => setPickerSelected((s) => ({ ...s, [a.id]: e.target.checked }))}
                    data-testid={`plaid-account-picker-cb-${a.id}`}
                  />
                  <div style={{ flex: 1 }}>
                    <div style={{ fontWeight: 500 }}>
                      {a.name} {a.mask ? <span className="muted">····{a.mask}</span> : null}
                    </div>
                    <div className="muted" style={{ fontSize: 12 }}>
                      {a.type}{a.subtype ? ` · ${a.subtype}` : ''}
                    </div>
                  </div>
                </label>
              ))}
            </div>
            <div style={{
              marginTop: 12, padding: '10px 12px',
              background: '#f8fafc', border: '1px solid var(--cf-border, #e2e8f0)',
              borderRadius: 6,
            }}>
              <label
                data-testid="plaid-create-gl-per-account-toggle"
                style={{ display: 'flex', alignItems: 'flex-start', gap: 10, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={createGlPerAccount}
                  onChange={(e) => setCreateGlPerAccount(e.target.checked)}
                  data-testid="plaid-create-gl-per-account-cb"
                  style={{ marginTop: 3 }}
                />
                <div style={{ fontSize: 13, lineHeight: 1.4 }}>
                  <strong>Create a separate Chart-of-Accounts line per bank account</strong>{' '}
                  <span className="muted">(advanced — for tenants who reconcile per-bank in the trial balance)</span>
                  <div className="muted" style={{ fontSize: 12, marginTop: 4 }}>
                    By default, every connected bank shares one <code>1000 Cash — Checking</code>
                    {' '}/{' '}<code>1010 Cash — Savings</code>{' '}GL row (and one
                    {' '}<code>2100 Credit Card Payable</code>{' '}/{' '}<code>2200 Notes Payable</code>{' '}
                    row for cards / loans). Treasury still tracks each bank as its own sub-ledger row.
                    Turn this on only if your accountant wants a per-bank breakdown directly on the trial balance.
                  </div>
                </div>
              </label>
            </div>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
              <button
                type="button"
                className="btn btn--ghost"
                onClick={() => setPicker(null)}
                disabled={exchanging}
                data-testid="plaid-account-picker-cancel"
              >
                Cancel
              </button>
              <button
                type="button"
                className="btn btn--primary"
                onClick={confirmPicker}
                disabled={exchanging}
                data-testid="plaid-account-picker-confirm"
              >
                {exchanging ? 'Saving…' : `Add ${Object.values(pickerSelected).filter(Boolean).length} account${Object.values(pickerSelected).filter(Boolean).length === 1 ? '' : 's'}`}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function PlaidTransferFundingCard() {
  const [busy, setBusy] = React.useState(false);
  const [msg, setMsg]   = React.useState(null);
  const [err, setErr]   = React.useState(null);

  const link = async () => {
    setBusy(true); setMsg(null); setErr(null);
    try {
      const tok = await fetch('/api/plaid_transfer_link.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      if (!tok.link_token) throw new Error('The secure bank connection could not be started.');

      // Plaid Link script must be loaded for `Plaid.create()`. Load on demand.
      await ensurePlaidLink();
      const handler = window.Plaid.create({
        token: tok.link_token,
        onSuccess: async (publicToken, meta) => {
          const accountId = meta?.accounts?.[0]?.id;
          if (!accountId) { setErr('No account selected'); return; }
          try {
            const res = await fetch('/api/plaid_transfer_link.php?action=exchange', {
              method: 'POST', credentials: 'include',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ public_token: publicToken, account_id: accountId }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error('The payment account could not be connected.');
            setMsg(`Connected ${meta?.institution?.name || 'bank'}${meta?.accounts?.[0]?.mask ? ` · account ending ${meta.accounts[0].mask}` : ''}.`);
          } catch (e) {
            setErr(e.message || 'The payment account could not be connected.');
          }
        },
        onExit: (e) => { if (e) setErr('The payment connection was closed before it finished.'); },
      });
      handler.open();
    } catch (e) {
      setErr(e.error || e.message || 'The bank connection could not be completed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid="plaid-transfer-funding-card">
      <h3>Online payments</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Connect the operating account used to send approved vendor and payroll payments.
        Set it up once and CoreFlux will reuse the secure connection for future payment runs.
      </p>
      <button onClick={link} disabled={busy} className="btn btn--primary" data-testid="plaid-transfer-link-btn">
        {busy ? 'Opening secure connection…' : 'Connect payment account'}
      </button>
      {msg && <p style={{ color: '#065f46', fontSize: 13, marginTop: 8 }} data-testid="plaid-transfer-link-success">{msg}</p>}
      {err && <p className="error" data-testid="plaid-transfer-link-error">{err}</p>}
    </div>
  );
}

async function ensurePlaidLink() {
  if (window.Plaid) return;
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
    s.onload = resolve; s.onerror = () => reject(new Error('Failed to load Plaid Link'));
    document.head.appendChild(s);
  });
}

function DiagStat({ label, value, warn }) {
  return (
    <div style={{
      padding: 8, background: warn ? '#fef3c7' : 'var(--cf-bg, #fff)',
      border: '1px solid var(--cf-border)', borderRadius: 4, textAlign: 'center',
    }}>
      <div style={{ fontSize: 18, fontWeight: 600, color: warn ? '#b45309' : 'inherit' }}>
        {value}
      </div>
      <div style={{ fontSize: 11, color: 'var(--cf-text-muted, #666)' }}>{label}</div>
    </div>
  );
}
