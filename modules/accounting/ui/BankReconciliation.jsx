import React, { useState } from 'react';
import { Routes, Route, Link, useParams, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney, fmtDate, fmtDateTime } from '../../../dashboard/src/lib/format';
import ReconciliationPacket from './ReconciliationPacket';
import IntercompanySplitDialog from '../../../dashboard/src/components/IntercompanySplitDialog';
import PlaidLinkButton from '../../../dashboard/src/components/PlaidLinkButton';

/**
 * Bank Reconciliation module.
 *  - /modules/accounting/bank-rec                            → list of bank accounts
 *  - /modules/accounting/bank-rec/:id                        → statement lines + matching grid + AI
 *  - /modules/accounting/bank-rec/:id/rules                  → rule list / AI-suggested rule queue
 */
export default function BankReconciliation() {
  return (
    <Routes>
      <Route index element={<AccountsList />} />
      <Route path=":id" element={<AccountDetail />} />
      <Route path=":id/rules" element={<RulesList />} />
      <Route path="reconciliations/:id" element={<ReconciliationsList />} />
      <Route path="packet/:id" element={<ReconciliationPacket />} />
    </Routes>
  );
}

function ReconciliationsList() {
  const { id } = useParams();
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/reconciliations.php?bank_account_id=${id}`);
  const [form, setForm] = useState({ period_end: new Date().toISOString().slice(0,10), statement_balance: '', gl_balance: '', notes: '' });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const open = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/modules/accounting/api/reconciliations.php?action=open', {
        bank_account_id: Number(id),
        period_end: form.period_end,
        statement_balance: parseFloat(form.statement_balance) || 0,
        gl_balance: parseFloat(form.gl_balance) || 0,
        notes: form.notes || null,
      });
      setForm({ period_end: new Date().toISOString().slice(0,10), statement_balance: '', gl_balance: '', notes: '' });
      reload();
    } catch (e2) { setErr(e2.message); }
    finally { setBusy(false); }
  };
  return (
    <section data-testid="accounting-reconciliations-list">
      <Link to={`/modules/accounting/bank-rec/${id}`} style={{ fontSize: 13, color: '#666' }}>← Bank account</Link>
      <h2 style={{ marginTop: 8 }}>Reconciliations</h2>
      <form onSubmit={open} style={{ display: 'flex', gap: 8, alignItems: 'end', marginBottom: 16, flexWrap: 'wrap', padding: 12, background: '#f9fafb', borderRadius: 6 }}>
        <label style={{ fontSize: 13 }}>Period end<input type="date" className="input" value={form.period_end} onChange={e => setForm({ ...form, period_end: e.target.value })} required data-testid="accounting-recon-period-end" style={{ display: 'block' }} /></label>
        <label style={{ fontSize: 13 }}>Statement balance<input className="input" type="number" step="0.01" value={form.statement_balance} onChange={e => setForm({ ...form, statement_balance: e.target.value })} data-testid="accounting-recon-statement-balance" style={{ display: 'block' }} /></label>
        <label style={{ fontSize: 13 }}>GL balance<input className="input" type="number" step="0.01" value={form.gl_balance} onChange={e => setForm({ ...form, gl_balance: e.target.value })} data-testid="accounting-recon-gl-balance" style={{ display: 'block' }} /></label>
        <label style={{ fontSize: 13, flex: 1 }}>Notes<input className="input" value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} data-testid="accounting-recon-notes" style={{ display: 'block', width: '100%' }} /></label>
        <button className="btn btn--primary" type="submit" disabled={busy} data-testid="accounting-recon-open">{busy ? 'Opening…' : 'Open reconciliation'}</button>
      </form>
      {err && <p className="error">{err}</p>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      <table className="data-table" data-testid="accounting-recon-table">
        <thead><tr><th>Period end</th><th>Status</th><th style={{textAlign:'right'}}>Statement</th><th style={{textAlign:'right'}}>GL</th><th style={{textAlign:'right'}}>Diff</th><th>Opened</th><th>Closed</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={8} style={{color:'#999'}}>No reconciliations yet for this account.</td></tr>
          )}
          {(data?.rows || []).map(r => (
            <tr key={r.id} data-testid={`accounting-recon-row-${r.id}`}>
              <td>{fmtDate(r.period_end)}</td>
              <td><span className="badge">{r.status}</span></td>
              <td style={{textAlign:'right', fontVariantNumeric: 'tabular-nums'}}>{fmtMoney(r.statement_balance)}</td>
              <td style={{textAlign:'right', fontVariantNumeric: 'tabular-nums'}}>{fmtMoney(r.gl_balance)}</td>
              <td style={{textAlign:'right', fontVariantNumeric: 'tabular-nums', color: Math.abs(parseFloat(r.difference||0)) < 0.01 ? '#065f46' : '#991b1b'}}>{fmtMoney(r.difference)}</td>
              <td>{fmtDateTime(r.opened_at)}</td>
              <td>{fmtDateTime(r.closed_at)}</td>
              <td><Link className="btn btn--ghost" to={`/modules/accounting/bank-rec/packet/${r.id}`} data-testid={`accounting-recon-packet-${r.id}`}>Open packet →</Link></td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function AccountsList() {
  const [showClosed, setShowClosed] = useState(false);
  const apiUrl = '/modules/accounting/api/bank_accounts.php' + (showClosed ? '?include_closed=1' : '');
  const { data, loading, error, reload } = useApi(apiUrl);
  const [showNew, setShow] = useState(false);
  const [busy, setBusy] = useState(null);
  const counts = data?.counts || {};
  const close = async (id) => {
    if (!confirm('Close this bank account? It will be hidden from the active list (and from Treasury). You can reopen it later.')) return;
    setBusy(id);
    try { await api.post(`/modules/accounting/api/bank_accounts.php?action=close&id=${id}`); await reload(); }
    catch (e) { alert('Could not close: ' + e.message); }
    finally { setBusy(null); }
  };
  const reopen = async (id) => {
    setBusy(id);
    try { await api.post(`/modules/accounting/api/bank_accounts.php?action=reopen&id=${id}`); await reload(); }
    catch (e) { alert('Could not reopen: ' + e.message); }
    finally { setBusy(null); }
  };
  return (
    <section data-testid="accounting-bank-accounts">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h2 style={{ margin: 0 }}>Bank Reconciliation</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
            <strong>{counts.active ?? 0}</strong> active
            {!!counts.closed && <> · <strong>{counts.closed}</strong> closed</>}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <label style={{ fontSize: 12, color: '#475569', display: 'flex', gap: 4, alignItems: 'center' }}>
            <input
              type="checkbox"
              checked={showClosed}
              onChange={e => setShowClosed(e.target.checked)}
              data-testid="accounting-bank-accounts-show-closed"
            />
            Show closed
          </label>
          <button className="btn btn--primary" onClick={() => setShow(true)} data-testid="accounting-bank-account-new">+ Add bank account</button>
        </div>
      </header>
      {loading && <p>Loading…</p>}
      {error   && <p className="error">{error.message}</p>}
      {showNew && <NewAccountForm onDone={() => { setShow(false); reload(); }} onCancel={() => setShow(false)} />}
      <table className="data-table" data-testid="accounting-bank-accounts-table">
        <thead><tr><th>Name</th><th>GL code</th><th>Bank</th><th>Last4</th><th>Feed</th><th>Last sync</th><th>Status</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={8} className="empty" data-testid="accounting-bank-accounts-empty">No bank accounts {showClosed ? '' : '(check "Show closed" to see archived ones)'}.</td></tr>
          )}
          {(data?.rows || []).map(a => (
            <tr key={a.id}
                data-testid={`accounting-bank-account-row-${a.id}`}
                style={{ opacity: a.status === 'closed' ? 0.55 : 1 }}>
              <td><Link to={`${a.id}`} data-testid={`accounting-bank-account-link-${a.id}`}>{a.name}</Link></td>
              <td><code>{a.gl_account_code}</code></td>
              <td>{a.bank_name || '—'}</td>
              <td>{a.last4 || '—'}</td>
              <td>{a.feed_provider || 'manual'}{a.plaid_account_id ? ' · plaid' : ''}</td>
              <td>{a.last_feed_synced_at || '—'}</td>
              <td>
                <span className="badge"
                      data-testid={`accounting-bank-account-status-${a.id}`}
                      style={a.status === 'closed' ? { background: '#fee2e2', color: '#991b1b' } : { background: '#dcfce7', color: '#166534' }}>
                  {a.status}
                </span>
              </td>
              <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <Link to={`${a.id}/rules`} data-testid={`accounting-bank-account-rules-${a.id}`} style={{ fontSize: 12 }}>Rules ↗</Link>
                {a.status === 'active' ? (
                  <button className="btn btn--ghost"
                          style={{ fontSize: 12, color: '#dc2626' }}
                          disabled={busy === a.id}
                          onClick={() => close(a.id)}
                          data-testid={`accounting-bank-account-close-${a.id}`}>
                    {busy === a.id ? '…' : 'Close'}
                  </button>
                ) : (
                  <button className="btn btn--ghost"
                          style={{ fontSize: 12, color: '#0369a1' }}
                          disabled={busy === a.id}
                          onClick={() => reopen(a.id)}
                          data-testid={`accounting-bank-account-reopen-${a.id}`}>
                    {busy === a.id ? '…' : 'Reopen'}
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function NewAccountForm({ onDone, onCancel }) {
  const [form, setForm] = useState({ name: '', gl_account_code: '', bank_name: '', last4: '' });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try { await api.post('/modules/accounting/api/bank_accounts.php', form); onDone(); }
    catch (e2) { setErr(e2.message); }
    finally { setBusy(false); }
  };
  return (
    <form onSubmit={submit} data-testid="accounting-bank-account-form" style={{ marginBottom: 16, padding: 12, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, background: '#fafbff' }}>
      <h3 style={{ margin: '0 0 12px', fontSize: 14 }}>New bank account</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 8 }}>
        <input className="input" placeholder="Name (e.g. Operating Chase ...4421)" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} data-testid="accounting-bank-account-name" required />
        <input className="input" placeholder="GL account code" value={form.gl_account_code} onChange={(e) => setForm({ ...form, gl_account_code: e.target.value })} data-testid="accounting-bank-account-gl" required />
        <input className="input" placeholder="Bank name" value={form.bank_name} onChange={(e) => setForm({ ...form, bank_name: e.target.value })} data-testid="accounting-bank-account-bank-name" />
        <input className="input" placeholder="Last 4" maxLength={4} value={form.last4} onChange={(e) => setForm({ ...form, last4: e.target.value })} data-testid="accounting-bank-account-last4" />
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
        <button className="btn btn--ghost" type="button" onClick={onCancel}>Cancel</button>
        <button className="btn btn--primary" type="submit" disabled={busy} data-testid="accounting-bank-account-save">{busy ? 'Saving…' : 'Save'}</button>
      </div>
      {err && <p className="error" data-testid="accounting-bank-account-error">{err}</p>}
    </form>
  );
}

function AccountDetail() {
  const { id } = useParams();
  const accountApi = useApi(`/modules/accounting/api/bank_accounts.php?id=${id}`);
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/bank_statements.php?bank_account_id=${id}&match_status=unmatched`);
  const [csv, setCsv]       = useState('');
  const [busy, setBusy]     = useState(null);
  const [actErr, setErr]    = useState(null);
  const bankAccount = accountApi.data?.account || null;

  const importCsv = async (e) => {
    e.preventDefault();
    setBusy('import'); setErr(null);
    try {
      await api.post(`/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=${id}`, { csv });
      setCsv('');
      reload();
    } catch (e2) { setErr(e2.message); }
    finally { setBusy(null); }
  };
  const applyRules = async () => {
    setBusy('apply'); setErr(null);
    try { await api.post(`/modules/accounting/api/bank_statements.php?action=apply_rules&bank_account_id=${id}`); reload(); }
    catch (e2) { setErr(e2.message); }
    finally { setBusy(null); }
  };

  return (
    <section data-testid="accounting-bank-account-detail">
      <Link to=".." style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Bank accounts</Link>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8, marginBottom: 16 }}>
        <h2 style={{ margin: 0 }}>Statement lines</h2>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <PlaidLinkButton
            purpose="bank_feed"
            accountingBankAccountId={Number(id)}
            label="Connect bank (Plaid)"
            testIdSuffix="bank-feed"
            onLinked={async (r) => {
              try {
                await api.post('/api/plaid_sync_transactions.php', { item_id: r.item_id, accounting_bank_account_id: Number(id) });
                reload();
              } catch (_e) {}
            }}
          />
          <Link to="rules" className="btn btn--ghost" data-testid="accounting-bank-rules-link">Rules</Link>
          <Link to={`/modules/accounting/bank-rec/reconciliations/${id}`} className="btn btn--ghost" data-testid="accounting-bank-packets-link">Reconciliations</Link>
          <button className="btn btn--ghost" onClick={applyRules} disabled={busy === 'apply'} data-testid="accounting-bank-apply-rules">
            {busy === 'apply' ? 'Applying…' : 'Apply rules now'}
          </button>
        </div>
      </header>

      <form onSubmit={importCsv} style={{ marginBottom: 16 }}>
        <details>
          <summary style={{ cursor: 'pointer', fontSize: 13, color: 'var(--cf-text-secondary)' }}>Import CSV</summary>
          <textarea
            value={csv}
            onChange={(e) => setCsv(e.target.value)}
            placeholder="date,description,amount,fitid&#10;2026-02-15,AWS Charge,-340.12,abc123"
            rows={6}
            className="input"
            data-testid="accounting-bank-csv-input"
            style={{ width: '100%', marginTop: 8, fontFamily: 'monospace', fontSize: 11 }}
          />
          <button className="btn btn--primary" type="submit" disabled={!csv.trim() || busy === 'import'} data-testid="accounting-bank-csv-import" style={{ marginTop: 8 }}>
            {busy === 'import' ? 'Importing…' : 'Import CSV'}
          </button>
          {actErr && <p className="error" data-testid="accounting-bank-import-error">{actErr}</p>}
        </details>
      </form>

      {loading && <p>Loading…</p>}
      {error   && <p className="error">{error.message}</p>}
      <table className="data-table" data-testid="accounting-bank-lines-table">
        <thead><tr><th>Date</th><th>Description</th><th style={{ textAlign: 'right' }}>Amount</th><th>Status</th><th>AI</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={6} className="empty" data-testid="accounting-bank-lines-empty">No unmatched lines. Import a statement above to get started.</td></tr>
          )}
          {(data?.rows || []).map(l => (
            <BankLineRow key={l.id} line={l} reload={reload} bankAccount={bankAccount} />
          ))}
        </tbody>
      </table>
    </section>
  );
}

function BankLineRow({ line, reload, bankAccount }) {
  const [busy, setBusy] = useState(null);
  const [aiResp, setAi] = useState(null);
  const [err, setErr]   = useState(null);
  const [splitOpen, setSplitOpen] = useState(false);

  const callAi = async (action) => {
    setBusy(action); setErr(null);
    try {
      const res = await api.post(`/modules/accounting/api/bank_ai.php?action=${action}&line_id=${line.id}`);
      setAi({ action, ...res });
    } catch (e) { setErr(e.message); }
    finally { setBusy(null); }
  };

  return (
    <>
      <tr data-testid={`accounting-bank-line-${line.id}`}>
        <td style={{ whiteSpace: 'nowrap' }}>{fmtDate(line.posted_date)}</td>
        <td>{line.description}</td>
        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: line.amount < 0 ? '#991b1b' : '#065f46' }}>{fmtMoney(line.amount)}</td>
        <td><span data-testid={`accounting-bank-line-status-${line.match_status}`}>{line.match_status}</span></td>
        <td style={{ fontSize: 11 }}>
          {line.applied_rule_id ? <span data-testid={`accounting-bank-line-applied-${line.id}`} style={{ background: '#d1fae5', color: '#065f46', padding: '2px 6px', borderRadius: 4 }}>⚙ Rule applied</span>
            : line.ai_suggested_rule_id ? <span data-testid={`accounting-bank-line-rule-suggested-${line.id}`} style={{ background: '#dbeafe', color: '#1e40af', padding: '2px 6px', borderRadius: 4 }}>✨ Rule suggested</span>
            : line.ai_suggested_account_code ? <span data-testid={`accounting-bank-line-cat-suggested-${line.id}`} style={{ background: '#fef3c7', color: '#92400e', padding: '2px 6px', borderRadius: 4 }}>✨ Cat. {line.ai_suggested_account_code}</span>
            : '—'}
        </td>
        <td>
          <div style={{ display: 'flex', gap: 4 }}>
            <button className="btn btn--ghost" onClick={() => callAi('suggest_match')}      disabled={busy} data-testid={`accounting-bank-ai-match-${line.id}`}>
              {busy === 'suggest_match' ? '…' : 'AI match'}
            </button>
            <button className="btn btn--ghost" onClick={() => callAi('suggest_categorize')} disabled={busy} data-testid={`accounting-bank-ai-cat-${line.id}`}>
              {busy === 'suggest_categorize' ? '…' : 'AI cat.'}
            </button>
            <button className="btn btn--ghost" onClick={() => callAi('suggest_rule')}       disabled={busy} data-testid={`accounting-bank-ai-rule-${line.id}`}>
              {busy === 'suggest_rule' ? '…' : 'AI rule'}
            </button>
            <button
              className="btn btn--primary"
              onClick={() => setSplitOpen(true)}
              disabled={!bankAccount || !bankAccount.entity_id || !bankAccount.gl_account_code}
              data-testid={`accounting-bank-ic-split-${line.id}`}
              title="Split this line across entities (intercompany)"
            >⊕ Split / IC</button>
          </div>
        </td>
      </tr>
      {splitOpen && (
        <IntercompanySplitDialog
          open={splitOpen}
          onClose={() => setSplitOpen(false)}
          onPosted={() => { setSplitOpen(false); reload(); }}
          amount={Math.abs(Number(line.amount))}
          sourceEntityId={Number(bankAccount?.entity_id)}
          sourceOffsetAccountCode={bankAccount?.gl_account_code}
          sourceOffsetSide={Number(line.amount) < 0 ? 'credit' : 'debit'}
          bankStatementLineId={line.id}
          defaultMemo={line.description}
        />
      )}
      {aiResp && (
        <tr data-testid={`accounting-bank-ai-result-${line.id}`}>
          <td colSpan={6} style={{ background: '#fafbff', padding: 12 }}>
            <strong style={{ fontSize: 12 }}>AI {aiResp.action.replace('suggest_', '')}:</strong>
            <pre style={{ fontSize: 11, whiteSpace: 'pre-wrap', margin: '4px 0' }}>{aiResp.ai_response || JSON.stringify(aiResp.candidates || aiResp, null, 2)}</pre>
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn--ghost" onClick={() => setAi(null)} data-testid={`accounting-bank-ai-dismiss-${line.id}`}>Dismiss</button>
            </div>
          </td>
        </tr>
      )}
      {err && (
        <tr><td colSpan={6}><p className="error" data-testid={`accounting-bank-ai-error-${line.id}`}>{err}</p></td></tr>
      )}
    </>
  );
}

function RulesList() {
  const { id } = useParams();
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/bank_rules.php?bank_account_id=${id}`);
  const [learnBusy, setLearnBusy] = useState(false);
  const [learnRes, setLearnRes]   = useState(null);
  const [learnErr, setLearnErr]   = useState(null);

  const flip = async (ruleId, action) => {
    try { await api.post(`/modules/accounting/api/bank_rules.php?action=${action}&id=${ruleId}`); reload(); }
    catch (e) { alert(e.message); }
  };

  const learn = async () => {
    setLearnBusy(true); setLearnErr(null); setLearnRes(null);
    try {
      const res = await api.post('/modules/accounting/api/bank_rules.php?action=learn');
      setLearnRes(res);
      reload();
    } catch (e) { setLearnErr(e.message); }
    finally { setLearnBusy(false); }
  };

  return (
    <section data-testid="accounting-bank-rules">
      <Link to=".." style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Statement lines</Link>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8, marginBottom: 12 }}>
        <h2 style={{ margin: 0 }}>Bank rules</h2>
        <button
          className="btn btn--ghost"
          onClick={learn}
          disabled={learnBusy}
          data-testid="accounting-bank-rules-learn"
          title="Find common patterns from your accepted AI categorizations and draft new rules"
        >
          {learnBusy ? 'Learning…' : '✨ Learn from accepts'}
        </button>
      </header>
      <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
        Rules categorize bank-statement lines automatically. <strong>Suggested</strong> rules fire as
        AI suggestions on new imports — a reviewer must accept before anything posts. Click <em>Approve</em>
        to flip a rule to <strong>auto-apply</strong>: future matches are categorized without review.
        The <em>Learn from accepts</em> button mines your accepted AI categorizations for common patterns
        and drafts new rules — once you've accepted the same merchant ≥3 times, CoreFlux will surface
        a one-click rule for it here.
      </p>
      {learnErr && <p className="error" data-testid="accounting-bank-rules-learn-error">{learnErr}</p>}
      {learnRes && (
        <div data-testid="accounting-bank-rules-learn-result" style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, padding: 12, marginBottom: 12, fontSize: 13 }}>
          {learnRes.drafted === 0
            ? <span data-testid="accounting-bank-rules-learn-empty">No new patterns yet — accept more AI categorizations and try again. Evaluated {learnRes.clusters_evaluated} categorization cluster(s).</span>
            : <>
                <strong data-testid="accounting-bank-rules-learn-count">Drafted {learnRes.drafted} new rule(s).</strong>
                {' '}They appear below as <em>Suggested</em>. Click <strong>Approve</strong> on any to switch them to auto-apply.
              </>}
        </div>
      )}
      {loading && <p>Loading…</p>}
      {error   && <p className="error">{error.message}</p>}
      <table className="data-table" data-testid="accounting-bank-rules-table">
        <thead><tr><th>Name</th><th>Pattern</th><th>Target</th><th>Direction</th><th>Mode</th><th>Applied</th><th>Source</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={8} className="empty" data-testid="accounting-bank-rules-empty">No rules yet. Use "AI rule" on an unmatched line to draft one — or click "Learn from accepts" above.</td></tr>
          )}
          {(data?.rows || []).map(r => (
            <tr key={r.id} data-testid={`accounting-bank-rule-row-${r.id}`} style={r.created_via === 'ai_learned' ? { background: '#fafbff' } : undefined}>
              <td>{r.name}{r.created_via === 'ai_learned' && <span data-testid={`accounting-bank-rule-learned-${r.id}`} style={{ marginLeft: 6, fontSize: 10, padding: '1px 6px', borderRadius: 999, background: '#e0e7ff', color: '#3730a3' }}>learned</span>}</td>
              <td><code style={{ fontSize: 11 }}>{r.pattern_kind}: {r.pattern}</code></td>
              <td><code>{r.target_account_code}</code></td>
              <td>{r.direction}</td>
              <td>
                {r.is_approved
                  ? <span data-testid={`accounting-bank-rule-mode-approved-${r.id}`} style={{ background: '#d1fae5', color: '#065f46', padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600 }}>Auto-apply</span>
                  : <span data-testid={`accounting-bank-rule-mode-suggested-${r.id}`} style={{ background: '#fef3c7', color: '#92400e', padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600 }}>Suggested</span>}
              </td>
              <td>{r.times_applied}</td>
              <td><span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{r.created_via}</span></td>
              <td>
                <div style={{ display: 'flex', gap: 4 }}>
                  {!r.is_approved && (
                    <button className="btn btn--primary" onClick={() => flip(r.id, 'approve')} data-testid={`accounting-bank-rule-approve-${r.id}`}>Approve</button>
                  )}
                  {r.status === 'active' && (
                    <button className="btn btn--ghost" onClick={() => flip(r.id, 'pause')} data-testid={`accounting-bank-rule-pause-${r.id}`}>Pause</button>
                  )}
                  <button className="btn btn--ghost" onClick={() => flip(r.id, 'archive')} data-testid={`accounting-bank-rule-archive-${r.id}`}>Archive</button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
