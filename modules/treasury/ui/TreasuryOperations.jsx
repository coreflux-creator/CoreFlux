import React, { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import {
  AlertCircle, ArrowRightLeft, Check, CreditCard, Play, Plus,
  RefreshCw, Send, X,
} from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtDate, fmtMoney } from '../../../dashboard/src/lib/format';

const OPEN_STATUSES = new Set(['draft', 'pending_approval', 'approved', 'scheduled', 'failed']);

const STATUS_TONE = {
  draft: ['#475569', '#f1f5f9'],
  pending_approval: ['#92400e', '#fef3c7'],
  approved: ['#1d4ed8', '#dbeafe'],
  scheduled: ['#6d28d9', '#ede9fe'],
  executed: ['#047857', '#d1fae5'],
  failed: ['#b91c1c', '#fee2e2'],
  voided: ['#64748b', '#e2e8f0'],
  rejected: ['#991b1b', '#fee2e2'],
};

export default function TreasuryOperations({ kind }) {
  const isPayment = kind === 'payments';
  const { id: routeId } = useParams();
  const endpoint = isPayment ? '/api/treasury_payments.php' : '/api/treasury_transfers.php';
  const list = useApi(`${endpoint}?limit=500`);
  const accountsApi = useApi('/modules/treasury/api/deposit_accounts.php');
  const entitiesApi = useApi('/modules/accounting/api/entities.php');
  const ledgerApi = useApi('/modules/accounting/api/accounts.php?active=1&postable=1');
  const [filter, setFilter] = useState('open');
  const [selected, setSelected] = useState(() => new Set());
  const [showCreate, setShowCreate] = useState(false);
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState(null);

  const rows = useMemo(() => list.data?.rows || [], [list.data?.rows]);
  const accounts = useMemo(() => accountsApi.data?.rows || [], [accountsApi.data?.rows]);
  const entities = entitiesApi.data?.rows || [];
  const ledgerAccounts = ledgerApi.data?.rows || [];
  const accountById = useMemo(
    () => Object.fromEntries(accounts.map(account => [String(account.id), account])),
    [accounts],
  );
  const visible = useMemo(() => rows.filter(row => {
    if (routeId && String(row.id) !== String(routeId)) return false;
    return filter === 'all' || OPEN_STATUSES.has(String(row.status));
  }), [filter, routeId, rows]);

  useEffect(() => setSelected(new Set()), [kind, filter]);

  const toggle = id => setSelected(current => {
    const next = new Set(current);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  const reload = async () => {
    setSelected(new Set());
    await list.reload();
  };

  const act = async (row, action, options = {}) => {
    if (options.confirm && !window.confirm(options.confirm)) return false;
    setBusy(`${action}:${row.id}`);
    setMessage(null);
    try {
      await api.post(`${endpoint}?action=${action}&id=${row.id}`, options.body || {});
      setMessage({ kind: 'success', text: `${labelFor(kind)} ${rowNumber(row, isPayment)} ${pastTense(action)}.` });
      await reload();
      return true;
    } catch (error) {
      setMessage({ kind: 'error', text: error.message || `Could not ${action} this item.` });
      return false;
    } finally {
      setBusy('');
    }
  };

  const selectedRows = rows.filter(row => selected.has(Number(row.id)));
  const bulk = async (action, allowed) => {
    const eligible = selectedRows.filter(row => allowed.includes(String(row.status)));
    if (!eligible.length) return;
    if ((action === 'execute' || action === 'void') && !window.confirm(
      `${action === 'execute' ? 'Post' : 'Void'} ${eligible.length} selected ${labelFor(kind).toLowerCase()}${eligible.length === 1 ? '' : 's'}?`,
    )) return;
    setBusy(`bulk:${action}`);
    setMessage(null);
    const results = await Promise.allSettled(eligible.map(row => api.post(
      `${endpoint}?action=${action}&id=${row.id}`,
      {},
    )));
    const failed = results.filter(result => result.status === 'rejected');
    const completed = results.length - failed.length;
    setMessage({
      kind: failed.length ? 'error' : 'success',
      text: failed.length
        ? `${completed} completed; ${failed.length} failed. ${failed[0]?.reason?.message || 'Review permissions and workflow state.'}`
        : `${completed} selected ${labelFor(kind).toLowerCase()}${completed === 1 ? '' : 's'} ${pastTense(action)}.`,
    });
    setBusy('');
    await reload();
  };

  const allVisibleSelected = visible.length > 0 && visible.every(row => selected.has(Number(row.id)));

  return (
    <section className="treasury-operations" data-testid={`treasury-${kind}-operations`} style={{ padding: '0 24px 24px' }}>
      <header className="treasury-overview__header" style={{ alignItems: 'flex-end' }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
            {isPayment ? <CreditCard size={20} color="var(--cf-accent)" /> : <ArrowRightLeft size={20} color="var(--cf-accent)" />}
            {isPayment ? 'Payment approvals' : 'Account transfers'}
          </h2>
          <p className="muted">
            {isPayment
              ? 'Prepare, approve, and post cash disbursements from one queue.'
              : 'Move cash between accounts with the approval and ledger trail kept together.'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn btn--ghost" type="button" onClick={reload} disabled={list.loading || Boolean(busy)} title="Refresh">
            <RefreshCw size={15} /> Refresh
          </button>
          <button className="btn btn--primary" type="button" onClick={() => setShowCreate(open => !open)}>
            {showCreate ? <X size={15} /> : <Plus size={15} />} {showCreate ? 'Cancel' : isPayment ? 'New payment' : 'New transfer'}
          </button>
        </div>
      </header>

      {showCreate && (
        <CreateOperationForm
          kind={kind}
          endpoint={endpoint}
          accounts={accounts}
          entities={entities}
          ledgerAccounts={ledgerAccounts}
          onCreated={async () => { setShowCreate(false); await reload(); }}
          onMessage={setMessage}
        />
      )}

      {message && <div style={alertStyle(message.kind)} role="status">{message.text}</div>}
      {list.error && <div style={alertStyle('error')}><AlertCircle size={16} /> {list.error.message}</div>}

      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', margin: '16px 0 10px', flexWrap: 'wrap' }}>
        <div className="segmented-control" aria-label="Queue filter" style={{ display: 'inline-flex' }}>
          <button type="button" className={filter === 'open' ? 'is-active' : ''} onClick={() => setFilter('open')}>Open</button>
          <button type="button" className={filter === 'all' ? 'is-active' : ''} onClick={() => setFilter('all')}>All</button>
        </div>
        <BulkActions rows={selectedRows} busy={busy} onRun={bulk} />
      </div>

      <div style={{ background: '#fff', border: '1px solid var(--cf-border)', borderRadius: 7, overflowX: 'auto' }}>
        <table className="data-table" style={{ width: '100%', minWidth: 960 }}>
          <thead><tr>
            <th style={{ width: 38 }}><input type="checkbox" aria-label="Select visible rows" checked={allVisibleSelected} onChange={() => setSelected(allVisibleSelected ? new Set() : new Set(visible.map(row => Number(row.id))))} /></th>
            <th>Reference</th>
            <th>{isPayment ? 'Payee' : 'From / to'}</th>
            <th>Date</th>
            <th style={{ textAlign: 'right' }}>Amount</th>
            <th>Status</th>
            <th>Memo</th>
            <th style={{ textAlign: 'right' }}>Actions</th>
          </tr></thead>
          <tbody>
            {visible.map(row => (
              <tr key={row.id} style={routeId ? { background: 'var(--cf-blue-bg, #eff6ff)' } : undefined}>
                <td><input type="checkbox" aria-label={`Select ${rowNumber(row, isPayment)}`} checked={selected.has(Number(row.id))} onChange={() => toggle(Number(row.id))} /></td>
                <td><strong>{rowNumber(row, isPayment)}</strong><div className="muted" style={{ fontSize: 11 }}>{isPayment ? row.payment_method : row.transfer_kind}</div></td>
                <td>{isPayment ? row.payee_name : <AccountPair row={row} accountById={accountById} />}</td>
                <td>{fmtDate(isPayment ? row.payment_date : row.transfer_date)}</td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(row.amount, row.currency || 'USD')}</td>
                <td><StatusChip status={row.status} />{row.failure_reason ? <div style={{ color: '#b91c1c', fontSize: 11, marginTop: 4, maxWidth: 240 }}>{row.failure_reason}</div> : null}</td>
                <td style={{ maxWidth: 260 }}>{row.memo || <span className="muted">None</span>}</td>
                <td><RowActions row={row} busy={busy} onAction={act} /></td>
              </tr>
            ))}
            {!list.loading && !visible.length && <tr><td colSpan={8} style={{ padding: 34, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>{routeId ? 'This item was not found in the current workspace.' : `No ${filter === 'open' ? 'open ' : ''}${kind}.`}</td></tr>}
            {list.loading && <tr><td colSpan={8} style={{ padding: 34, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>Loading…</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function CreateOperationForm({ kind, endpoint, accounts, entities, ledgerAccounts, onCreated, onMessage }) {
  const isPayment = kind === 'payments';
  const today = new Date().toISOString().slice(0, 10);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState(isPayment ? {
    bank_account_id: '', entity_id: '', payee_name: '', amount: '', payment_date: today,
    payment_method: 'ach', counterparty_account_id: '', memo: '',
  } : { source_bank_account_id: '', destination_bank_account_id: '', amount: '', transfer_date: today, memo: '' });
  const set = (key, value) => setForm(current => ({ ...current, [key]: value }));

  const submit = async event => {
    event.preventDefault();
    setSaving(true);
    onMessage(null);
    try {
      const body = { ...form, amount: Number(form.amount) };
      if (isPayment) {
        body.entity_id = Number(form.entity_id);
        body.bank_account_id = Number(form.bank_account_id);
        body.counterparty_account_id = Number(form.counterparty_account_id);
      } else {
        body.source_bank_account_id = Number(form.source_bank_account_id);
        body.destination_bank_account_id = Number(form.destination_bank_account_id);
      }
      const result = await api.post(endpoint, body);
      onMessage({ kind: 'success', text: `${labelFor(kind)} ${result.payment_number || result.transfer_number} saved as a draft.` });
      await onCreated();
    } catch (error) {
      onMessage({ kind: 'error', text: error.message || `Could not create the ${labelFor(kind).toLowerCase()}.` });
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} style={{ border: '1px solid var(--cf-border)', borderLeft: '3px solid var(--cf-accent)', borderRadius: 7, background: '#fff', padding: 16, marginTop: 14 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12 }}>
        {isPayment ? <>
          <Field label="Pay from"><select value={form.bank_account_id} onChange={event => { const id = event.target.value; const account = accounts.find(row => String(row.id) === id); setForm(current => ({ ...current, bank_account_id: id, entity_id: account?.entity_id || current.entity_id })); }} required><option value="">Choose account</option>{accounts.map(row => <option key={row.id} value={row.id}>{row.name}{row.last4 ? ` · ${row.last4}` : ''}</option>)}</select></Field>
          <Field label="Entity"><select value={form.entity_id} onChange={event => set('entity_id', event.target.value)} required><option value="">Choose entity</option>{entities.map(row => <option key={row.id} value={row.id}>{row.code} · {row.legal_name}</option>)}</select></Field>
          <Field label="Payee"><input value={form.payee_name} onChange={event => set('payee_name', event.target.value)} required /></Field>
          <Field label="Offset account"><select value={form.counterparty_account_id} onChange={event => set('counterparty_account_id', event.target.value)} required><option value="">Choose GL account</option>{ledgerAccounts.filter(row => row.account_type !== 'asset').map(row => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</select></Field>
          <Field label="Method"><select value={form.payment_method} onChange={event => set('payment_method', event.target.value)}>{['ach', 'check', 'wire', 'card', 'other'].map(value => <option key={value} value={value}>{value.toUpperCase()}</option>)}</select></Field>
        </> : <>
          <Field label="From"><select value={form.source_bank_account_id} onChange={event => set('source_bank_account_id', event.target.value)} required><option value="">Choose source</option>{accounts.map(row => <option key={row.id} value={row.id}>{row.name}{row.last4 ? ` · ${row.last4}` : ''}</option>)}</select></Field>
          <Field label="To"><select value={form.destination_bank_account_id} onChange={event => set('destination_bank_account_id', event.target.value)} required><option value="">Choose destination</option>{accounts.filter(row => String(row.id) !== String(form.source_bank_account_id)).map(row => <option key={row.id} value={row.id}>{row.name}{row.last4 ? ` · ${row.last4}` : ''}</option>)}</select></Field>
        </>}
        <Field label="Amount"><input type="number" min="0.01" step="0.01" value={form.amount} onChange={event => set('amount', event.target.value)} required /></Field>
        <Field label="Date"><input type="date" value={form[isPayment ? 'payment_date' : 'transfer_date']} onChange={event => set(isPayment ? 'payment_date' : 'transfer_date', event.target.value)} required /></Field>
        <Field label="Memo"><input value={form.memo} onChange={event => set('memo', event.target.value)} /></Field>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}><button className="btn btn--primary" type="submit" disabled={saving}><Plus size={15} /> {saving ? 'Saving…' : 'Save draft'}</button></div>
    </form>
  );
}

function Field({ label, children }) {
  return <label style={{ display: 'grid', gap: 5, fontSize: 12, fontWeight: 600 }}>{label}{children}</label>;
}

function BulkActions({ rows, busy, onRun }) {
  if (!rows.length) return <span className="muted" style={{ fontSize: 12 }}>Select rows for bulk actions</span>;
  const count = status => rows.filter(row => status.includes(String(row.status))).length;
  return <div style={{ display: 'flex', gap: 7, alignItems: 'center', flexWrap: 'wrap' }}>
    <strong style={{ fontSize: 12 }}>{rows.length} selected</strong>
    {count(['draft']) > 0 && <button className="btn btn--ghost" type="button" disabled={Boolean(busy)} onClick={() => onRun('submit', ['draft'])}><Send size={14} /> Submit {count(['draft'])}</button>}
    {count(['pending_approval']) > 0 && <button className="btn btn--ghost" type="button" disabled={Boolean(busy)} onClick={() => onRun('approve', ['pending_approval'])}><Check size={14} /> Approve {count(['pending_approval'])}</button>}
    {count(['approved', 'scheduled']) > 0 && <button className="btn btn--primary" type="button" disabled={Boolean(busy)} onClick={() => onRun('execute', ['approved', 'scheduled'])}><Play size={14} /> Post {count(['approved', 'scheduled'])}</button>}
  </div>;
}

function RowActions({ row, busy, onAction }) {
  const isBusy = busy.endsWith(`:${row.id}`);
  const status = String(row.status);
  return <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 6, whiteSpace: 'nowrap' }}>
    {status === 'draft' && <button className="btn btn--ghost" type="button" disabled={isBusy} onClick={() => onAction(row, 'submit')} title="Submit for approval"><Send size={14} /> Submit</button>}
    {status === 'pending_approval' && <button className="btn btn--ghost" type="button" disabled={isBusy} onClick={() => onAction(row, 'approve')} title="Approve"><Check size={14} /> Approve</button>}
    {['approved', 'scheduled'].includes(status) && <button className="btn btn--primary" type="button" disabled={isBusy} onClick={() => onAction(row, 'execute', { confirm: `Post ${row.payment_number || row.transfer_number} to the ledger?` })} title="Post to ledger"><Play size={14} /> Post</button>}
    {['draft', 'pending_approval', 'approved', 'scheduled', 'failed', 'rejected'].includes(status) && <button className="btn btn--ghost" type="button" disabled={isBusy} onClick={() => onAction(row, 'void', { confirm: `Void ${row.payment_number || row.transfer_number}?` })} title="Void"><X size={14} /></button>}
  </div>;
}

function AccountPair({ row, accountById }) {
  const from = accountById[String(row.source_bank_account_id)];
  const to = accountById[String(row.destination_bank_account_id)];
  return <><strong>{from?.name || `Account ${row.source_bank_account_id}`}</strong><div className="muted" style={{ fontSize: 11 }}>to {to?.name || `Account ${row.destination_bank_account_id}`}</div></>;
}

function StatusChip({ status }) {
  const [color, background] = STATUS_TONE[status] || STATUS_TONE.draft;
  return <span style={{ display: 'inline-block', borderRadius: 4, padding: '3px 7px', color, background, fontSize: 11, fontWeight: 700 }}>{String(status).replace(/_/g, ' ')}</span>;
}

function rowNumber(row, isPayment) {
  return (isPayment ? row.payment_number : row.transfer_number) || `#${row.id}`;
}

function labelFor(kind) {
  return kind === 'payments' ? 'Payment' : 'Transfer';
}

function pastTense(action) {
  return ({ submit: 'submitted', approve: 'approved', execute: 'posted', void: 'voided' })[action] || `${action}d`;
}

function alertStyle(kind) {
  const error = kind === 'error';
  return { display: 'flex', alignItems: 'center', gap: 8, marginTop: 12, padding: '10px 12px', borderRadius: 6, borderLeft: `3px solid ${error ? '#dc2626' : '#059669'}`, background: error ? '#fef2f2' : '#ecfdf5', color: error ? '#991b1b' : '#065f46', fontSize: 13 };
}
