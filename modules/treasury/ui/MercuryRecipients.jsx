import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

/**
 * <MercuryRecipients /> — Slice 2 Recipient Vault UI.
 *
 * Manages two recipient kinds:
 *   - vendor          → pushed to Mercury as a counterparty before payout
 *   - funding_source  → tenant's external bank Mercury debits to pre-fund
 *                       AP payouts. Slice 3 enforces:
 *                       (1) debit funding source → credit Mercury operating,
 *                       (2) poll until that transfer clears,
 *                       (3) then originate ACH from Mercury → vendor.
 *
 * The "Set as funding default" CTA persists the chosen funding_source +
 * the Mercury operating account into mercury_connections so Slice 3 can
 * look it up at payment-approval time.
 */
export default function MercuryRecipients() {
  const list   = useApi('/api/mercury_recipients.php');
  const accts  = useApi('/api/mercury_accounts.php');
  const fundingDefault = useApi('/api/mercury_recipients.php?action=funding_default');

  const [showCreate, setShowCreate] = useState(false);
  const [showSetDefault, setShowSetDefault] = useState(null); // recipient row
  const [flash, setFlash] = useState(null);
  const [pushingId, setPushingId] = useState(null);

  const rows = list.data?.rows ?? [];
  const accountRows = accts.data?.rows ?? [];
  const fd = fundingDefault.data?.funding_default || null;

  const reloadAll = () => {
    list.reload();
    fundingDefault.reload();
  };

  const handlePush = async (row) => {
    setPushingId(row.id);
    setFlash(null);
    try {
      const res = await api.post(`/api/mercury_recipients.php?action=push&id=${row.id}`, {});
      setFlash({ kind: 'success', msg: `Pushed to Mercury (counterparty ${res.mercury_id}).` });
      list.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    } finally {
      setPushingId(null);
    }
  };

  const handleRevoke = async (row) => {
    if (!window.confirm(`Revoke ${row.name}? This soft-deletes the recipient; cached payments referencing it remain intact.`)) return;
    setFlash(null);
    try {
      await api.delete(`/api/mercury_recipients.php?id=${row.id}`);
      setFlash({ kind: 'success', msg: 'Recipient revoked.' });
      reloadAll();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    }
  };

  return (
    <section data-testid="mercury-recipients" style={{ maxWidth: 960, marginTop: 32 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Mercury Recipients & Funding Source</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          <strong>Vendors</strong> are pushed to Mercury as counterparties before payout.
          <strong> Funding sources</strong> are the external bank account Mercury debits to pre-fund AP runs.
          The designated default funding source + Mercury operating account drive the Slice 3 payout flow:
          <em> debit → verify clearance → push ACH to vendor.</em>
        </p>
      </header>

      {flash && (
        <div
          data-testid={`mercury-recipients-flash-${flash.kind}`}
          style={{
            padding: '10px 14px', borderRadius: 6, marginBottom: 16,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color:      flash.kind === 'success' ? 'var(--cf-green, #047857)'    : 'var(--cf-red, #b91c1c)',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

      {/* Funding-default summary */}
      <div
        data-testid="mercury-funding-default"
        className="card"
        style={{ padding: 14, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, marginBottom: 16, background: fd ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-amber-bg, #fef3c7)' }}
      >
        <strong style={{ fontSize: 13 }}>Funding default: </strong>
        {fd ? (
          <span data-testid="mercury-funding-default-set">
            <code style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>{fd.recipient?.name || '?'}</code>
            {' → debits → '}
            <code style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>Mercury acct {fd.mercury_account_id}</code>
          </span>
        ) : (
          <span data-testid="mercury-funding-default-unset" style={{ fontSize: 12 }}>
            Not configured. Add a funding_source recipient below and click "Set as funding default" — required before AP can use Mercury for payouts.
          </span>
        )}
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>Recipients ({rows.length})</h4>
        <div style={{ display: 'flex', gap: 8 }}>
          <a
            href="/api/mercury_recipients_csv_import.php?action=template"
            className="btn btn--ghost"
            data-testid="mercury-recipients-csv-template-btn"
            title="Download CSV template for bulk vendor import"
          >
            CSV template
          </a>
          <label
            className="btn btn--ghost"
            data-testid="mercury-recipients-csv-import-btn"
            style={{ cursor: 'pointer' }}
            title="Import vendor recipients from a CSV"
          >
            Import CSV
            <input
              type="file"
              accept=".csv,text/csv"
              style={{ display: 'none' }}
              data-testid="mercury-recipients-csv-input"
              onChange={async (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                e.target.value = '';
                const text = await file.text();
                const postCsv = async (url) => {
                  const res = await fetch(url, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'text/csv', Accept: 'application/json' },
                    body: text,
                  });
                  const body = await res.json().catch(() => ({}));
                  if (!res.ok) throw new Error(body.error || res.statusText);
                  return body;
                };
                try {
                  const dry = await postCsv('/api/mercury_recipients_csv_import.php?action=dry_run');
                  if ((dry.error_count ?? 0) > 0) {
                    if (!window.confirm(`CSV has ${dry.error_count} invalid row(s). Skip them and import the rest?`)) return;
                  }
                  const out = await postCsv('/api/mercury_recipients_csv_import.php?action=commit&skip_invalid=1');
                  setFlash({ kind: 'success', msg: `Imported ${out.inserted ?? out.committed ?? 0} recipient(s).` });
                  list.reload();
                } catch (err) {
                  setFlash({ kind: 'error', msg: err.message || String(err) });
                }
              }}
            />
          </label>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => setShowCreate(true)}
            data-testid="mercury-recipient-create-btn"
          >
            + New recipient
          </button>
        </div>
      </div>

      {list.loading && <p>Loading…</p>}
      {list.error && <p className="error">Error: {list.error.message}</p>}
      {!list.loading && rows.length === 0 && (
        <p data-testid="mercury-recipients-empty" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          No recipients yet. Add a vendor or funding source to get started.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="mercury-recipients-table" style={{ width: '100%', fontSize: 13 }}>
          <thead>
            <tr>
              <th>Kind</th><th>Name</th><th>Email</th><th>Method</th>
              <th>Bank ••</th><th>Mercury ID</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} data-testid={`mercury-recipient-row-${r.id}`}>
                <td>
                  <span className={`badge badge--${r.kind === 'funding_source' ? 'amber' : 'blue'}`}
                        style={{ padding: '2px 6px', borderRadius: 4, fontSize: 11 }}>
                    {r.kind === 'funding_source' ? 'funding' : 'vendor'}
                  </span>
                </td>
                <td>{r.name}</td>
                <td>{r.email || '—'}</td>
                <td>{r.payment_method}</td>
                <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>••{r.bank_last4 || '????'}</td>
                <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)', fontSize: 11 }}>{r.mercury_id || '—'}</td>
                <td>
                  <span className={`badge badge--${r.status}`}>{r.status}</span>
                </td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  {r.kind === 'vendor' && !r.mercury_id && (
                    <button
                      type="button"
                      className="btn btn--ghost"
                      onClick={() => handlePush(r)}
                      disabled={pushingId === r.id || r.status !== 'active'}
                      data-testid={`mercury-recipient-push-${r.id}`}
                    >
                      {pushingId === r.id ? 'Pushing…' : 'Push to Mercury'}
                    </button>
                  )}
                  {r.kind === 'funding_source' && r.status === 'active' && (
                    <button
                      type="button"
                      className="btn btn--ghost"
                      onClick={() => setShowSetDefault(r)}
                      data-testid={`mercury-set-funding-default-${r.id}`}
                      disabled={accountRows.length === 0}
                      title={accountRows.length === 0 ? 'Sync Mercury accounts first' : ''}
                    >
                      Set as funding default
                    </button>
                  )}
                  <button
                    type="button"
                    className="btn btn--ghost"
                    onClick={() => handleRevoke(r)}
                    data-testid={`mercury-recipient-revoke-${r.id}`}
                    style={{ marginLeft: 6 }}
                  >
                    Revoke
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {showCreate && (
        <CreateRecipientModal
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); list.reload(); setFlash({ kind: 'success', msg: 'Recipient created.' }); }}
        />
      )}

      {showSetDefault && (
        <SetFundingDefaultModal
          recipient={showSetDefault}
          accounts={accountRows}
          onClose={() => setShowSetDefault(null)}
          onSaved={() => {
            setShowSetDefault(null);
            setFlash({ kind: 'success', msg: 'Funding default updated.' });
            reloadAll();
          }}
        />
      )}
    </section>
  );
}

// ============================================================================
function CreateRecipientModal({ onClose, onCreated }) {
  const [form, setForm] = useState({
    kind: 'vendor',
    name: '',
    email: '',
    payment_method: 'ach',
    notes: '',
    bank: { routing_number: '', account_number: '', account_type: 'checking', nickname: '' },
  });
  const [busy, setBusy] = useState(false);
  const [err,  setErr]  = useState(null);

  const set    = (k, v) => setForm(s => ({ ...s, [k]: v }));
  const setBnk = (k, v) => setForm(s => ({ ...s, bank: { ...s.bank, [k]: v } }));

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/api/mercury_recipients.php', form);
      onCreated?.();
    } catch (er) { setErr(er.message || String(er)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="mercury-recipient-create-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <form onSubmit={submit} style={modalCard}>
        <h4 style={{ margin: '0 0 12px' }}>New recipient</h4>
        {err && <div className="error" data-testid="mercury-recipient-create-error" style={{ marginBottom: 8 }}>{err}</div>}

        <Field label="Kind">
          <select
            value={form.kind}
            onChange={(e) => set('kind', e.target.value)}
            className="input"
            disabled={busy}
            data-testid="mercury-recipient-kind"
          >
            <option value="vendor">Vendor (you pay them)</option>
            <option value="funding_source">Funding source (Mercury debits this account to pre-fund payouts)</option>
          </select>
        </Field>
        <Field label="Name">
          <input value={form.name} onChange={(e) => set('name', e.target.value)} className="input" required data-testid="mercury-recipient-name" />
        </Field>
        <Field label="Email (optional)">
          <input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} className="input" />
        </Field>
        <Field label="Payment method">
          <select value={form.payment_method} onChange={(e) => set('payment_method', e.target.value)} className="input">
            <option value="ach">ACH</option>
            <option value="wire">Wire</option>
            <option value="check">Check</option>
          </select>
        </Field>

        <h5 style={{ margin: '14px 0 6px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>Bank details (encrypted at rest)</h5>
        <Field label="Routing number">
          <input value={form.bank.routing_number} onChange={(e) => setBnk('routing_number', e.target.value)} className="input" required pattern="[0-9]{9}" data-testid="mercury-recipient-routing" />
        </Field>
        <Field label="Account number">
          <input value={form.bank.account_number} onChange={(e) => setBnk('account_number', e.target.value)} className="input" required data-testid="mercury-recipient-account" />
        </Field>
        <Field label="Account type">
          <select value={form.bank.account_type} onChange={(e) => setBnk('account_type', e.target.value)} className="input">
            <option value="checking">Checking</option>
            <option value="savings">Savings</option>
          </select>
        </Field>

        <div style={{ marginTop: 16, display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy} data-testid="mercury-recipient-save-btn">
            {busy ? 'Saving…' : 'Create recipient'}
          </button>
        </div>
      </form>
    </div>
  );
}

function SetFundingDefaultModal({ recipient, accounts, onClose, onSaved }) {
  const [mercuryAcct, setMercuryAcct] = useState(accounts[0]?.mercury_account_id || '');
  const [busy, setBusy] = useState(false);
  const [err,  setErr]  = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/api/mercury_recipients.php?action=set_funding_default', {
        recipient_id:       recipient.id,
        mercury_account_id: mercuryAcct,
      });
      onSaved?.();
    } catch (er) { setErr(er.message || String(er)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="mercury-set-funding-default-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <form onSubmit={submit} style={modalCard}>
        <h4 style={{ margin: '0 0 8px' }}>Set funding default</h4>
        <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Mercury will debit <strong>{recipient.name}</strong> and credit the Mercury operating account below whenever AP needs to pre-fund a payout batch.
        </p>
        {err && <div className="error" data-testid="mercury-set-funding-default-error" style={{ marginBottom: 8 }}>{err}</div>}
        <Field label="Mercury operating account (credited)">
          <select
            value={mercuryAcct}
            onChange={(e) => setMercuryAcct(e.target.value)}
            className="input"
            required
            data-testid="mercury-funding-default-account"
          >
            {accounts.map(a => (
              <option key={a.id} value={a.mercury_account_id}>
                {a.nickname || a.mercury_account_id} {a.account_number_last4 ? `(••${a.account_number_last4})` : ''}
              </option>
            ))}
          </select>
        </Field>
        <div style={{ marginTop: 16, display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={busy} data-testid="mercury-set-funding-default-save">
            {busy ? 'Saving…' : 'Save'}
          </button>
        </div>
      </form>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'block', marginBottom: 8, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
      {label}
      <div style={{ marginTop: 4 }}>{children}</div>
    </label>
  );
}

const modalOverlay = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)',
  display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50,
};
const modalCard = {
  background: '#fff', padding: 20, borderRadius: 8, width: '100%', maxWidth: 480,
  maxHeight: '90vh', overflowY: 'auto',
};
