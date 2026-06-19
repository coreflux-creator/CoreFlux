import React, { useState, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Cross-tenant Intercompany Approval Workflow (Batch 3 — 2026-02).
 *
 * Distinct from the entity-split flow on the sibling "Intercompany"
 * page: this UI manages IC entries that move money across SEPARATE
 * tenants sharing the same master parent.
 *
 *   - "Inbox" tab — pending rows where THIS tenant is the counterparty
 *     and must Approve / Decline.
 *   - "Outbox" tab — pending rows THIS tenant proposed; for visibility.
 *   - "Propose new" — pick a sibling tenant, currency-aware amount,
 *     account codes (defaults 1700/2700 IC receivable/payable), TTL.
 *
 *   When the counterparty Approves, the target leg posts on their books.
 *   When the counterparty Declines, the source leg is reversed.
 *   If nobody acts within TTL (default 14 days), the daily cron expires
 *   the row and reverses the source leg.
 */

const STATUSES = ['pending', 'approved', 'declined', 'expired', 'reversed', 'all'];
const TAB_INBOX = 'inbox';
const TAB_OUTBOX = 'outbox';
const TAB_NEW = 'new';

const API = '/api/v1/accounting/intercompany';

export default function XTenantIntercompany() {
  const [tab, setTab] = useState(TAB_INBOX);
  const [status, setStatus] = useState('pending');

  const inboxApi  = useApi(`${API}?action=xtenant_inbox&status=${status}`,  { enabled: tab === TAB_INBOX });
  const outboxApi = useApi(`${API}?action=xtenant_outbox&status=${status}`, { enabled: tab === TAB_OUTBOX });
  const siblingsApi = useApi('/api/sub_tenants.php');

  return (
    <section data-testid="xtenant-ic-page">
      <h2 style={{ margin: '0 0 6px' }}>Cross-tenant Intercompany — Approvals</h2>
      <p style={{ fontSize: 13, color: '#666', maxWidth: 760, margin: '0 0 16px' }}>
        Move money across <strong>different tenants</strong> in the same master group (e.g.
        Seven Generations → Arabella). The source leg posts immediately; the counterparty
        approves or declines before the target leg lands on their books. Pending rows
        auto-expire after their TTL and reverse the source leg.
      </p>

      <nav style={{ display: 'flex', gap: 8, borderBottom: '1px solid #e5e7eb', marginBottom: 12 }}>
        <SubTab id={TAB_INBOX}  current={tab} setTab={setTab} label="Inbox (awaiting my approval)" />
        <SubTab id={TAB_OUTBOX} current={tab} setTab={setTab} label="Outbox (I proposed)" />
        <SubTab id={TAB_NEW}    current={tab} setTab={setTab} label="Propose new" />
      </nav>

      {(tab === TAB_INBOX || tab === TAB_OUTBOX) && (
        <div style={{ marginBottom: 12 }}>
          <label style={{ fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            Status
            <select className="input" value={status} onChange={e => setStatus(e.target.value)}
                    data-testid="xtenant-ic-status-filter">
              {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </label>
        </div>
      )}

      {tab === TAB_INBOX && (
        <QueueTable mode="inbox" apiState={inboxApi} reload={inboxApi.reload} />
      )}
      {tab === TAB_OUTBOX && (
        <QueueTable mode="outbox" apiState={outboxApi} reload={outboxApi.reload} />
      )}
      {tab === TAB_NEW && (
        <ProposeForm siblingsApi={siblingsApi} onProposed={() => setTab(TAB_OUTBOX)} />
      )}
    </section>
  );
}

function SubTab({ id, current, setTab, label }) {
  const active = current === id;
  return (
    <button
      type="button"
      data-testid={`xtenant-ic-tab-${id}`}
      onClick={() => setTab(id)}
      style={{
        padding: '6px 12px', border: 0, background: 'transparent',
        borderBottom: active ? '2px solid #2563eb' : '2px solid transparent',
        color: active ? '#2563eb' : '#444',
        fontWeight: active ? 600 : 400, cursor: 'pointer',
      }}
    >{label}</button>
  );
}

function QueueTable({ mode, apiState, reload }) {
  const { data, loading, error } = apiState;
  const rows = data?.rows || [];

  return (
    <>
      {loading && <p data-testid="xtenant-ic-loading">Loading…</p>}
      {error && <p className="error" data-testid="xtenant-ic-error">{error.message}</p>}
      {!loading && rows.length === 0 && (
        <p style={{ color: '#999' }} data-testid={`xtenant-ic-empty-${mode}`}>
          {mode === 'inbox'
            ? 'No pending intercompany approvals from sister tenants — you’re all caught up.'
            : 'You haven’t proposed any cross-tenant intercompany entries yet.'}
        </p>
      )}
      {rows.length > 0 && (
        <table className="data-table" data-testid={`xtenant-ic-table-${mode}`}>
          <thead>
            <tr>
              <th>Ref</th>
              <th>From → To</th>
              <th>Amount</th>
              <th>Memo</th>
              <th>Status</th>
              <th>Requested</th>
              <th>Expires</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <QueueRow key={r.id} row={r} mode={mode} reload={reload} />
            ))}
          </tbody>
        </table>
      )}
    </>
  );
}

function QueueRow({ row, mode, reload }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const [declineReason, setDeclineReason] = useState('');
  const [declineOpen, setDeclineOpen] = useState(false);

  const approve = async () => {
    if (!window.confirm(`Approve intercompany ${row.intercompany_ref} for ${formatMoney(row.target_amount, row.target_currency)}?`)) return;
    setBusy(true); setErr(null);
    try {
      await api.post(`${API}?action=xtenant_approve`, { queue_id: row.id });
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  const decline = async () => {
    if (!declineReason.trim()) { setErr('Decline reason required'); return; }
    setBusy(true); setErr(null);
    try {
      await api.post(`${API}?action=xtenant_decline`, { queue_id: row.id, reason: declineReason });
      setDeclineOpen(false); setDeclineReason('');
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  const isPending = row.status === 'pending';
  const showActions = mode === 'inbox' && isPending;

  return (
    <tr data-testid={`xtenant-ic-row-${row.id}`}>
      <td><code>{row.intercompany_ref}</code></td>
      <td style={{ fontSize: 12 }}>
        <strong>{row.source_tenant_name || row.source_tenant_id}</strong>
        {' → '}
        <strong>{row.target_tenant_name || row.target_tenant_id}</strong>
      </td>
      <td style={{ whiteSpace: 'nowrap' }}>
        {formatMoney(row.amount, row.currency)}
        {row.currency !== row.target_currency && (
          <span style={{ fontSize: 11, color: '#666', display: 'block' }}>
            → {formatMoney(row.target_amount, row.target_currency)} @ {Number(row.fx_rate).toFixed(4)}
          </span>
        )}
      </td>
      <td style={{ maxWidth: 240, fontSize: 12 }}>{row.memo}</td>
      <td><StatusPill status={row.status} /></td>
      <td style={{ fontSize: 11, color: '#666' }}>{row.requested_at}</td>
      <td style={{ fontSize: 11, color: '#666' }}>{row.expires_at}</td>
      <td>
        {showActions && !declineOpen && (
          <span style={{ display: 'inline-flex', gap: 6 }}>
            <button className="btn btn--primary" disabled={busy} onClick={approve}
                    data-testid={`xtenant-ic-approve-${row.id}`}>
              {busy ? '…' : 'Approve'}
            </button>
            <button className="btn btn--ghost" disabled={busy} onClick={() => setDeclineOpen(true)}
                    data-testid={`xtenant-ic-decline-open-${row.id}`}>
              Decline
            </button>
          </span>
        )}
        {showActions && declineOpen && (
          <span style={{ display: 'inline-flex', gap: 6 }}>
            <input className="input" value={declineReason} placeholder="Decline reason"
                   onChange={e => setDeclineReason(e.target.value)}
                   data-testid={`xtenant-ic-decline-reason-${row.id}`} />
            <button className="btn btn--danger" disabled={busy || !declineReason.trim()} onClick={decline}
                    data-testid={`xtenant-ic-decline-confirm-${row.id}`}>
              Confirm decline
            </button>
            <button className="btn btn--ghost" disabled={busy} onClick={() => { setDeclineOpen(false); setDeclineReason(''); }}>
              Cancel
            </button>
          </span>
        )}
        {err && <p className="error" style={{ margin: '4px 0 0', fontSize: 11 }}>{err}</p>}
      </td>
    </tr>
  );
}

function StatusPill({ status }) {
  const colors = {
    pending:  { bg: '#fef3c7', fg: '#92400e' },
    approved: { bg: '#dcfce7', fg: '#166534' },
    declined: { bg: '#fee2e2', fg: '#991b1b' },
    expired:  { bg: '#e5e7eb', fg: '#374151' },
    reversed: { bg: '#e5e7eb', fg: '#374151' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 999,
      background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600,
    }} data-testid={`xtenant-ic-status-${status}`}>{status}</span>
  );
}

function ProposeForm({ siblingsApi, onProposed }) {
  const subs = siblingsApi.data?.sub_tenants || [];
  const parent = siblingsApi.data?.parent || null;
  const parentId = siblingsApi.data?.parent_tenant_id || null;

  // Sibling list = parent + every sub (minus the active tenant — we don't
  // know which is active client-side without a callback, so we surface
  // every option and let the API reject same-tenant proposals).
  const counterparties = useMemo(() => {
    const list = [];
    if (parent) list.push({ id: parent.id, name: parent.name + ' (parent)' });
    subs.forEach(s => list.push({ id: s.id, name: s.name }));
    return list;
  }, [parent, subs]);

  const [form, setForm] = useState({
    to_tenant_id: '',
    amount: '',
    memo: '',
    posting_date: new Date().toISOString().slice(0, 10),
    from_account_code: '1700',
    to_account_code: '2700',
    from_offset_code: '1000',
    to_offset_code: '1000',
    from_currency: 'USD',
    to_currency: 'USD',
    fx_rate: '1.0',
    ttl_days: '14',
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [result, setResult] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null); setResult(null);
    try {
      const payload = {
        to_tenant_id: Number(form.to_tenant_id),
        amount: Number(form.amount),
        memo: form.memo,
        posting_date: form.posting_date,
        from_account_code: form.from_account_code,
        to_account_code: form.to_account_code,
        from_offset_code: form.from_offset_code,
        to_offset_code: form.to_offset_code,
        from_currency: form.from_currency,
        to_currency: form.to_currency,
        fx_rate: Number(form.fx_rate),
        ttl_days: Number(form.ttl_days),
      };
      const res = await api.post(`${API}?action=xtenant_propose`, payload);
      setResult(res);
      // Auto-flip the page after a short pause so the user sees confirmation
      setTimeout(() => { onProposed && onProposed(); }, 1500);
    } catch (e2) { setErr(e2.message); }
    finally { setBusy(false); }
  };

  const setF = (k, v) => setForm(f => ({ ...f, [k]: v }));

  return (
    <form onSubmit={submit} data-testid="xtenant-ic-propose-form"
          style={{ background: '#f9fafb', padding: 16, borderRadius: 6, maxWidth: 760 }}>
      <h3 style={{ margin: '0 0 12px', fontSize: 15 }}>Propose a new cross-tenant intercompany entry</h3>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <label style={{ fontSize: 12 }}>Counterparty tenant
          <select className="input" required value={form.to_tenant_id}
                  onChange={e => setF('to_tenant_id', e.target.value)}
                  data-testid="xtenant-ic-propose-to-tenant">
            <option value="">— select sibling / parent —</option>
            {counterparties.map(c => <option key={c.id} value={c.id}>{c.name} (#{c.id})</option>)}
          </select>
        </label>

        <label style={{ fontSize: 12 }}>Amount
          <input className="input" type="number" step="0.01" min="0.01" required
                 value={form.amount} onChange={e => setF('amount', e.target.value)}
                 data-testid="xtenant-ic-propose-amount" />
        </label>

        <label style={{ fontSize: 12 }}>Memo
          <input className="input" required value={form.memo}
                 onChange={e => setF('memo', e.target.value)}
                 data-testid="xtenant-ic-propose-memo" />
        </label>

        <label style={{ fontSize: 12 }}>Posting date
          <input className="input" type="date" required value={form.posting_date}
                 onChange={e => setF('posting_date', e.target.value)}
                 data-testid="xtenant-ic-propose-posting-date" />
        </label>

        <label style={{ fontSize: 12 }}>From IC account code
          <input className="input" value={form.from_account_code}
                 onChange={e => setF('from_account_code', e.target.value)}
                 data-testid="xtenant-ic-propose-from-acct" />
        </label>
        <label style={{ fontSize: 12 }}>To IC account code
          <input className="input" value={form.to_account_code}
                 onChange={e => setF('to_account_code', e.target.value)}
                 data-testid="xtenant-ic-propose-to-acct" />
        </label>

        <label style={{ fontSize: 12 }}>From offset (cash) code
          <input className="input" value={form.from_offset_code}
                 onChange={e => setF('from_offset_code', e.target.value)}
                 data-testid="xtenant-ic-propose-from-offset" />
        </label>
        <label style={{ fontSize: 12 }}>To offset (cash) code
          <input className="input" value={form.to_offset_code}
                 onChange={e => setF('to_offset_code', e.target.value)}
                 data-testid="xtenant-ic-propose-to-offset" />
        </label>

        <label style={{ fontSize: 12 }}>From currency
          <input className="input" maxLength={3} value={form.from_currency}
                 onChange={e => setF('from_currency', e.target.value.toUpperCase())}
                 data-testid="xtenant-ic-propose-from-currency" />
        </label>
        <label style={{ fontSize: 12 }}>To currency
          <input className="input" maxLength={3} value={form.to_currency}
                 onChange={e => setF('to_currency', e.target.value.toUpperCase())}
                 data-testid="xtenant-ic-propose-to-currency" />
        </label>

        <label style={{ fontSize: 12 }}>FX rate (to per from)
          <input className="input" type="number" step="0.000001" min="0" value={form.fx_rate}
                 onChange={e => setF('fx_rate', e.target.value)}
                 data-testid="xtenant-ic-propose-fx-rate" />
        </label>

        <label style={{ fontSize: 12 }}>TTL (days)
          <input className="input" type="number" min="1" max="60" value={form.ttl_days}
                 onChange={e => setF('ttl_days', e.target.value)}
                 data-testid="xtenant-ic-propose-ttl" />
        </label>
      </div>

      <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
        <button type="submit" className="btn btn--primary" disabled={busy}
                data-testid="xtenant-ic-propose-submit">
          {busy ? 'Proposing…' : 'Post source leg & request approval'}
        </button>
        {err && <span className="error" data-testid="xtenant-ic-propose-error">{err}</span>}
      </div>

      {result && (
        <div style={{ marginTop: 14, padding: 10, background: '#dcfce7', borderRadius: 4 }}
             data-testid="xtenant-ic-propose-result">
          Proposed <strong>{result.intercompany_ref}</strong> · source JE
          #{result.from?.je_number || result.from?.je_id} posted ·
          counterparty has until <strong>{result.expires_at}</strong> to respond.
        </div>
      )}
    </form>
  );
}

function formatMoney(amount, currency) {
  if (amount === null || amount === undefined) return '—';
  const n = Number(amount);
  if (Number.isNaN(n)) return String(amount);
  try {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(n);
  } catch {
    return `${currency || ''} ${n.toFixed(2)}`;
  }
}
