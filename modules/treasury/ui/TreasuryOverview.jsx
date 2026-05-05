import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney, fmtRelative } from '../../../dashboard/src/lib/format';

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
  const liabilityTotal = liabilityRows.reduce((s, r) => s + balanceOf(r), 0);
  const netCash = depositTotal - liabilityTotal;

  return (
    <section className="treasury-overview" data-testid="treasury-overview">
      <header className="treasury-overview__header">
        <div>
          <h2>Treasury</h2>
          <p className="muted">
            Cash positions, credit exposure, and bank-feed health — one view.
            Dashboards + 13-week forecast coming soon.
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
          <div className="stat-card__label">Liabilities ({liabilityRows.length})</div>
        </div>
        <div className="stat-card" data-testid="treasury-overview-net-cash">
          <div className="stat-card__value" style={{ color: netCash < 0 ? '#dc2626' : undefined }}>
            {fmtMoney(netCash)}
          </div>
          <div className="stat-card__label">Net cash position</div>
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
              <tr><th>Name</th><th>Bank</th><th>Last 4</th><th>Feed</th><th style={{ textAlign: 'right' }}>Bank balance</th></tr>
            </thead>
            <tbody>
              {depositRows.slice(0, 5).map((r) => (
                <tr key={r.id}>
                  <td><Link to={`../deposits/${r.id}`}>{r.name}</Link></td>
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
              <tr><th>Name</th><th>Type</th><th>Last 4</th><th style={{ textAlign: 'right' }}>Outstanding</th><th style={{ textAlign: 'right' }}>Limit</th></tr>
            </thead>
            <tbody>
              {liabilityRows.slice(0, 5).map((r) => (
                <tr key={r.id}>
                  <td><Link to={`../liabilities/${r.id}`}>{r.name}</Link></td>
                  <td>{(r.subtype || 'other_liability').replace('_', ' ')}</td>
                  <td>{r.last4 || '—'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {fmtMoney(balanceOf(r))}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {r.credit_limit ? fmtMoney(r.credit_limit) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
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

function ConnectedInstitutions({ onChanged }) {
  const { data, loading, reload } = useApi('/api/plaid_items.php');
  const dedupeApi = useApi('/api/plaid_dedupe.php');
  const rows = data?.rows || [];
  const dupeDeposits   = dedupeApi.data?.deposit_clusters?.length   || 0;
  const dupeLiabs      = dedupeApi.data?.liability_clusters?.length || 0;
  const dupeCount      = dupeDeposits + dupeLiabs;
  const [busy, setBusy] = React.useState(null);
  const [err, setErr]   = React.useState(null);

  const disconnect = async (item) => {
    const confirmMsg =
      `Disconnect ${item.institution_name || 'this institution'}?\n\n` +
      `• Plaid will revoke our access token (no more transaction syncs).\n` +
      `• ${item.mirrored_deposit_count || 0} deposit account(s) and ${item.mirrored_liability_count || 0} liability account(s) will be hidden from Treasury.\n` +
      `• Historical journal entries and statement lines stay intact.\n\n` +
      `Continue?`;
    if (!confirm(confirmMsg)) return;
    setBusy(item.id); setErr(null);
    try {
      await fetch(`/api/plaid_items.php?id=${item.id}`, {
        method: 'DELETE', credentials: 'include',
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      await reload();
      if (onChanged) onChanged();
    } catch (e) {
      setErr(e.error || e.message || 'Disconnect failed');
    } finally { setBusy(null); }
  };

  const cleanupDupes = async () => {
    const msg =
      `Found ${dupeCount} duplicate cluster${dupeCount === 1 ? '' : 's'} ` +
      `(${dupeDeposits} deposit, ${dupeLiabs} liability). ` +
      `For each cluster, the most-recently-synced row will be kept and the others hidden. Continue?`;
    if (!confirm(msg)) return;
    setBusy('dedupe'); setErr(null);
    try {
      const res = await fetch('/api/plaid_dedupe.php?action=run', {
        method: 'POST', credentials: 'include',
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      const hd = res.hidden_deposit_ids?.length || 0;
      const hl = res.hidden_liability_ids?.length || 0;
      alert(`Cleanup complete — hid ${hd} deposit + ${hl} liability duplicate(s).`);
      await dedupeApi.reload();
      if (onChanged) onChanged();
    } catch (e) {
      setErr(e.error || e.message || 'Dedupe failed');
    } finally { setBusy(null); }
  };

  return (
    <div data-testid="treasury-connected-institutions">
      <h3>Connected institutions</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Each row is a single Plaid login — disconnecting revokes the token at
        Plaid and hides every mirrored deposit / liability from this list.
        Use <em>Hide</em> or <em>Delete</em> on individual rows above for
        per-account control.
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
            {' '}Earlier reconnects spawned extra rows in Treasury. One click consolidates them.
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
                  <div className="muted" style={{ fontSize: 11 }}>{r.item_id}</div>
                </td>
                <td>
                  {r.status === 'linked' && <span className="badge badge--active">linked</span>}
                  {r.status === 'disconnected' && <span className="badge">disconnected</span>}
                  {r.status === 'error' && <span className="badge" style={{ background: '#fee2e2', color: '#991b1b' }}>error</span>}
                  {r.last_error_message && (
                    <div className="muted" style={{ fontSize: 11 }}>{r.last_error_message}</div>
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
  const [exchanging, setExchanging] = React.useState(false);

  const link = async () => {
    setBusy(true); setMsg(null); setErr(null);
    try {
      const tok = await fetch('/api/plaid_bank_link.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      if (!tok.link_token) throw new Error('No link_token returned');

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
        onExit: (e) => { if (e) setErr(e.error_message || 'Cancelled'); },
      });
      handler.open();
    } catch (e) {
      setErr(e.error || e.message || 'Plaid Link failed');
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
      };
      if (Array.isArray(selectedIds)) body.selected_account_ids = selectedIds;
      const res = await fetch('/api/plaid_bank_link.php?action=exchange', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Exchange failed');
      const dep = data.bank_accounts_created?.length || 0;
      const lia = data.liability_accounts_created?.length || 0;
      const skipped = data.skipped_opt_out?.length || 0;
      const errs = data.errors || [];
      const parts = [];
      if (dep) parts.push(`${dep} deposit${dep === 1 ? '' : 's'}`);
      if (lia) parts.push(`${lia} liabilit${lia === 1 ? 'y' : 'ies'}`);
      if (skipped) parts.push(`${skipped} skipped (you opted out)`);
      const summary = parts.length ? parts.join(' + ') : 'no new accounts (already linked?)';
      setMsg(`Linked ${institution?.name || 'bank'} — ${summary}.`);
      if (errs.length) {
        setErr('Some accounts could not be added:\n• ' + errs.join('\n• '));
      }
      setPicker(null);
      if ((dep || lia) && onLinked) setTimeout(onLinked, 1200);
    } catch (e) { setErr(e.message); }
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
    if (!confirm('Backfill orphaned Plaid accounts into Treasury? This will create the missing deposit and liability rows from the cached Plaid account list — no Plaid re-authentication needed.')) return;
    setBackfilling(true); setErr(null); setMsg(null);
    try {
      const res = await fetch('/api/plaid_diagnostics.php?action=backfill', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Backfill failed');
      const dep = data.bank_accounts_created?.length || 0;
      const lia = data.liability_accounts_created?.length || 0;
      const skipped = data.skipped?.length || 0;
      const errs = data.errors || [];
      setMsg(`Backfilled ${dep} deposit${dep === 1 ? '' : 's'} + ${lia} liabilit${lia === 1 ? 'y' : 'ies'} (processed ${data.orphans_processed}, skipped ${skipped}).`);
      if (errs.length) {
        setErr('Some accounts could not be backfilled:\n• ' + errs.join('\n• '));
      }
      // Refresh diagnostics view
      await runDiagnostics();
      if ((dep || lia) && onLinked) setTimeout(onLinked, 1200);
    } catch (e) {
      setErr(e.error || e.message || 'Backfill failed');
    } finally { setBackfilling(false); }
  };

  const orphanCount = diag?.orphaned_plaid_accounts?.length || 0;

  return (
    <div data-testid="plaid-bank-connect-card">
      <h3>Connect a bank (read-only feed)</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Link checking, savings, credit cards, and loans so balances and
        transactions auto-sync for reconciliation. Depository accounts land
        on the deposits tab; cards and loans land on the liabilities tab.
        <strong> No money moves</strong> — this is a read-only feed using
        Plaid Transactions (+ Auth on deposits, Liabilities on cards/loans
        when supported). To enable outbound ACH payments, use{' '}
        <em>Outbound disbursements</em> below.
      </p>
      <button onClick={link} disabled={busy} className="btn btn--primary" data-testid="plaid-bank-connect-btn">
        {busy ? 'Opening Plaid…' : 'Connect bank'}
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
            <DiagStat label="Plaid Items"           value={diag.plaid_items?.length || 0} />
            <DiagStat label="Plaid Accounts"        value={diag.plaid_accounts?.length || 0} />
            <DiagStat label="Mirrored as Deposit"   value={diag.accounting_bank_accounts_for_plaid?.length || 0} />
            <DiagStat label="Mirrored as Liability" value={diag.treasury_liability_accounts_for_plaid?.length || 0} />
            <DiagStat label="Orphaned (not mirrored)" value={orphanCount} warn={orphanCount > 0} />
          </div>
          {orphanCount > 0 && (
            <div data-testid="plaid-orphan-banner" style={{
              padding: 10, background: '#fef3c7', border: '1px solid #f59e0b',
              borderRadius: 4, marginBottom: 8, color: '#78350f',
            }}>
              <strong>{orphanCount} Plaid account{orphanCount === 1 ? '' : 's'} not yet mirrored into Treasury.</strong>
              {' '}One-click backfill creates the missing deposit / liability rows from the cached Plaid metadata — no re-authentication needed.
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
                {backfilling ? 'Backfilling…' : `Backfill ${orphanCount} orphan${orphanCount === 1 ? '' : 's'}`}
              </button>
            </div>
          )}
          {orphanCount === 0 && (
            <p style={{ margin: 0, color: '#065f46' }} data-testid="plaid-no-orphans">
              All Plaid accounts are mirrored into Treasury. ✓
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
            <h3 style={{ marginTop: 0 }}>Choose accounts to ingest</h3>
            <p className="muted" style={{ fontSize: 13, marginTop: 0 }}>
              {picker.institution?.name || 'Plaid'} returned {picker.accounts.length} account
              {picker.accounts.length === 1 ? '' : 's'}. Only the ones you check
              below will be mirrored into Treasury — uncheck personal / out-of-scope
              accounts. You can always backfill later from <em>Run diagnostics</em>.
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
      if (!tok.link_token) throw new Error('No link_token returned');

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
            if (!res.ok) throw new Error(data.error || 'Exchange failed');
            setMsg(`Linked ${meta?.institution?.name || 'bank'} · account ${meta?.accounts?.[0]?.mask || ''}`);
          } catch (e) {
            setErr(e.message);
          }
        },
        onExit: (e) => { if (e) setErr(e.error_message || 'Cancelled'); },
      });
      handler.open();
    } catch (e) {
      setErr(e.error || e.message || 'Plaid Link failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid="plaid-transfer-funding-card">
      <h3>Outbound disbursements (Plaid Transfer)</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Link a funding bank account to enable programmatic ACH/RTP credits
        out of CoreFlux for AP payments and Payroll runs. One-time setup;
        subsequent originate calls reuse this token.
      </p>
      <button onClick={link} disabled={busy} className="btn btn--primary" data-testid="plaid-transfer-link-btn">
        {busy ? 'Opening Plaid…' : 'Link funding source'}
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
