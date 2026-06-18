import React, { useState, useEffect } from 'react';
import { Routes, Route, Link, useNavigate, useParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const RECURRING_JOURNAL_ENTRIES_API = '/api/v1/accounting/recurring-journal-entries';
const ACCOUNTS_API = '/api/v1/accounting/accounts';

/**
 * Recurring journal entries module.
 *  - /modules/accounting/recurring                → list + Run-due button
 *  - /modules/accounting/recurring/new            → create form
 *  - /modules/accounting/recurring/:id            → edit form (header + lines)
 */
export default function RecurringJournalEntries() {
  return (
    <Routes>
      <Route index           element={<List />} />
      <Route path="new"      element={<Editor />} />
      <Route path=":id"      element={<Editor edit />} />
    </Routes>
  );
}

function List() {
  const { data, loading, error, reload } = useApi(RECURRING_JOURNAL_ENTRIES_API);
  const [busy, setBusy]   = useState(null);
  const [runRes, setRun]  = useState(null);
  const [err, setErr]     = useState(null);

  const act = async (id, action) => {
    setBusy(`${action}-${id}`); setErr(null);
    try { await api.post(`${RECURRING_JOURNAL_ENTRIES_API}/${id}/${action}`); reload(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(null); }
  };
  const runDue = async () => {
    setBusy('run-due'); setErr(null); setRun(null);
    try { setRun(await api.post(`${RECURRING_JOURNAL_ENTRIES_API}/run_due`)); reload(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(null); }
  };

  return (
    <section data-testid="accounting-recurring">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2 style={{ margin: 0 }}>Recurring journal entries</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn btn--ghost"   onClick={runDue} disabled={busy === 'run-due'} data-testid="accounting-recurring-run-due">{busy === 'run-due' ? 'Running…' : 'Run due now'}</button>
          <Link className="btn btn--primary" to="new" data-testid="accounting-recurring-new">+ New template</Link>
        </div>
      </header>
      <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
        Templates with <code>next_run_date ≤ today</code> are posted automatically by the daily cron.
        <code>auto_post=1</code> goes straight to posted; <code>auto_post=0</code> stages a draft for review.
        Idempotent on (template, run_date) — re-running the cron in the same day is safe.
      </p>
      {runRes && (
        <div data-testid="accounting-recurring-run-result" style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, padding: 12, marginBottom: 12, fontSize: 13 }}>
          Cron ran: <strong>{runRes.ran}</strong> posted, <strong>{runRes.skipped}</strong> skipped (past end-date), <strong>{runRes.errors}</strong> error(s).
        </div>
      )}
      {err && <p className="error" data-testid="accounting-recurring-error">{err}</p>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}

      <table className="data-table" data-testid="accounting-recurring-table">
        <thead><tr><th>Name</th><th>Cadence</th><th>Next run</th><th>End date</th><th>Mode</th><th>Status</th><th>Last run</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={8} className="empty" data-testid="accounting-recurring-empty">No recurring templates yet.</td></tr>
          )}
          {(data?.rows || []).map(r => (
            <tr key={r.id} data-testid={`accounting-recurring-row-${r.id}`}>
              <td><Link to={`${r.id}`}>{r.name}</Link></td>
              <td>{r.cadence}</td>
              <td>{r.next_run_date}</td>
              <td>{r.end_date || '—'}</td>
              <td>
                {Number(r.auto_post) === 1
                  ? <span data-testid={`accounting-recurring-mode-auto-${r.id}`} style={{ background: '#d1fae5', color: '#065f46', padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600 }}>Auto-post</span>
                  : <span data-testid={`accounting-recurring-mode-draft-${r.id}`} style={{ background: '#fef3c7', color: '#92400e', padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600 }}>Stage as draft</span>}
              </td>
              <td>
                <span data-testid={`accounting-recurring-status-${r.status}`} style={{ background: pillBg(r.status), color: pillFg(r.status), padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>{r.status}</span>
              </td>
              <td>{r.last_run_at ? <Link to={`/modules/accounting/journal-entries/${r.last_run_je_id}`}>{r.last_run_at}</Link> : '—'}</td>
              <td>
                <div style={{ display: 'flex', gap: 4 }}>
                  {r.status === 'active' && (
                    <>
                      <button className="btn btn--ghost" onClick={() => act(r.id, 'run_now')} disabled={!!busy} data-testid={`accounting-recurring-run-now-${r.id}`}>Run now</button>
                      <button className="btn btn--ghost" onClick={() => act(r.id, 'pause')}    disabled={!!busy} data-testid={`accounting-recurring-pause-${r.id}`}>Pause</button>
                    </>
                  )}
                  {r.status === 'paused' && (
                    <button className="btn btn--primary" onClick={() => act(r.id, 'resume')} disabled={!!busy} data-testid={`accounting-recurring-resume-${r.id}`}>Resume</button>
                  )}
                  {r.status !== 'ended' && (
                    <button className="btn btn--ghost" onClick={() => act(r.id, 'end')} disabled={!!busy} data-testid={`accounting-recurring-end-${r.id}`}>End</button>
                  )}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function pillBg(s) { return { active: '#d1fae5', paused: '#fef3c7', ended: '#f3f4f6' }[s] || '#f3f4f6'; }
function pillFg(s) { return { active: '#065f46', paused: '#92400e', ended: '#374151' }[s] || '#374151'; }

const newLine = () => ({ account_code: '', debit: '', credit: '', description: '' });

function Editor({ edit }) {
  const navigate = useNavigate();
  const { id } = useParams();
  const [accounts, setAccounts] = useState([]);
  const [form, setForm] = useState({ name: '', cadence: 'monthly', next_run_date: new Date().toISOString().slice(0,10), end_date: '', auto_post: 1, memo: '' });
  const [lines, setLines] = useState([newLine(), newLine()]);
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  useEffect(() => {
    api.get(`${ACCOUNTS_API}?postable=1&active=1`).then(d => setAccounts(d?.rows || d?.accounts || []));
    if (edit && id) {
      api.get(`${RECURRING_JOURNAL_ENTRIES_API}/${id}`).then(d => {
        if (d?.template) setForm({
          name: d.template.name || '',
          cadence: d.template.cadence || 'monthly',
          next_run_date: d.template.next_run_date || '',
          end_date: d.template.end_date || '',
          auto_post: Number(d.template.auto_post) === 1 ? 1 : 0,
          memo: d.template.memo || '',
        });
        if (d?.lines?.length) setLines(d.lines.map(l => ({ ...l, debit: String(l.debit), credit: String(l.credit) })));
      });
    }
  }, [edit, id]);

  const totals = lines.reduce((acc, l) => ({ debit: acc.debit + (parseFloat(l.debit) || 0), credit: acc.credit + (parseFloat(l.credit) || 0) }), { debit: 0, credit: 0 });
  const balanced = Math.abs(totals.debit - totals.credit) < 0.005 && totals.debit > 0;

  const submit = async () => {
    setBusy(true); setErr(null);
    try {
      const payload = {
        ...form,
        end_date: form.end_date || null,
        lines: lines.filter(l => l.account_code && (parseFloat(l.debit) > 0 || parseFloat(l.credit) > 0)).map(l => ({
          account_code: l.account_code,
          debit: parseFloat(l.debit) || 0, credit: parseFloat(l.credit) || 0,
          description: l.description || null,
        })),
      };
      if (edit && id) {
        await api.put(`${RECURRING_JOURNAL_ENTRIES_API}/${id}`, payload);
        await api.post(`${RECURRING_JOURNAL_ENTRIES_API}/${id}/replace_lines`, { lines: payload.lines });
        navigate('/modules/accounting/recurring');
      } else {
        await api.post(RECURRING_JOURNAL_ENTRIES_API, payload);
        navigate('/modules/accounting/recurring');
      }
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <section data-testid="accounting-recurring-editor">
      <Link to="/modules/accounting/recurring" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Recurring entries</Link>
      <h2 style={{ marginTop: 8 }}>{edit ? 'Edit' : 'New'} recurring template</h2>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 900 }}>
        <label style={{ fontSize: 13 }}>Name<input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} data-testid="accounting-recurring-name" required style={{ display: 'block', width: '100%', marginTop: 4 }} /></label>
        <label style={{ fontSize: 13 }}>Cadence
          <select className="input" value={form.cadence} onChange={(e) => setForm({ ...form, cadence: e.target.value })} data-testid="accounting-recurring-cadence" style={{ display: 'block', width: '100%', marginTop: 4 }}>
            <option value="weekly">weekly</option><option value="biweekly">biweekly</option>
            <option value="monthly">monthly</option><option value="quarterly">quarterly</option>
            <option value="yearly">yearly</option>
          </select>
        </label>
        <label style={{ fontSize: 13 }}>Next run date<input type="date" className="input" value={form.next_run_date} onChange={(e) => setForm({ ...form, next_run_date: e.target.value })} data-testid="accounting-recurring-next-run" style={{ display: 'block', width: '100%', marginTop: 4 }} /></label>
        <label style={{ fontSize: 13 }}>End date (optional)<input type="date" className="input" value={form.end_date || ''} onChange={(e) => setForm({ ...form, end_date: e.target.value })} data-testid="accounting-recurring-end-date" style={{ display: 'block', width: '100%', marginTop: 4 }} /></label>
        <label style={{ fontSize: 13, gridColumn: 'span 2' }}>Memo<input className="input" value={form.memo} onChange={(e) => setForm({ ...form, memo: e.target.value })} data-testid="accounting-recurring-memo" style={{ display: 'block', width: '100%', marginTop: 4 }} /></label>
        <label style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
          <input type="checkbox" checked={!!form.auto_post} onChange={(e) => setForm({ ...form, auto_post: e.target.checked ? 1 : 0 })} data-testid="accounting-recurring-auto-post" />
          Auto-post on run (uncheck to stage as draft for review)
        </label>
      </div>

      <h3 style={{ marginTop: 24 }}>Lines</h3>
      <table className="data-table" data-testid="accounting-recurring-lines">
        <thead><tr><th>Account</th><th>Description</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th></th></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td>
                <select className="input" value={l.account_code} onChange={(e) => upd(i, 'account_code', e.target.value)} data-testid={`accounting-recurring-line-account-${i}`}>
                  <option value="">— select —</option>
                  {accounts.map(a => <option key={a.id} value={a.code}>{a.code} — {a.name}</option>)}
                </select>
              </td>
              <td><input className="input" value={l.description || ''} onChange={(e) => upd(i, 'description', e.target.value)} data-testid={`accounting-recurring-line-desc-${i}`} /></td>
              <td><input className="input" type="number" step="0.01" value={l.debit}  onChange={(e) => upd(i, 'debit', e.target.value)}  data-testid={`accounting-recurring-line-debit-${i}`}  style={{ textAlign: 'right' }} /></td>
              <td><input className="input" type="number" step="0.01" value={l.credit} onChange={(e) => upd(i, 'credit', e.target.value)} data-testid={`accounting-recurring-line-credit-${i}`} style={{ textAlign: 'right' }} /></td>
              <td><button className="btn btn--ghost" onClick={() => setLines(lines.filter((_, j) => j !== i))} data-testid={`accounting-recurring-line-remove-${i}`}>×</button></td>
            </tr>
          ))}
          <tr>
            <td colSpan={2} style={{ fontWeight: 600 }}>Totals</td>
            <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid="accounting-recurring-total-debit">{totals.debit.toFixed(2)}</td>
            <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid="accounting-recurring-total-credit">{totals.credit.toFixed(2)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
      <button className="btn btn--ghost" onClick={() => setLines([...lines, newLine()])} data-testid="accounting-recurring-add-line" style={{ marginTop: 8 }}>+ Add line</button>

      <p style={{ marginTop: 16, fontSize: 13, color: balanced ? '#065f46' : '#991b1b' }} data-testid="accounting-recurring-balance-status">
        {balanced ? '✓ Lines balance.' : `Lines must balance and total > 0. Diff: ${(totals.debit - totals.credit).toFixed(2)}`}
      </p>
      {err && <p className="error" data-testid="accounting-recurring-editor-error">{err}</p>}
      <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
        <button className="btn btn--primary" onClick={submit} disabled={busy || !balanced || !form.name} data-testid="accounting-recurring-save">{busy ? 'Saving…' : 'Save template'}</button>
      </div>
    </section>
  );

  function upd(i, field, val) {
    const next = [...lines]; next[i] = { ...next[i], [field]: val }; setLines(next);
  }
}
