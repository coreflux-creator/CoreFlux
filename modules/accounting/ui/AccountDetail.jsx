import React, { useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ExternalLink, RefreshCw } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney } from '../../../dashboard/src/lib/format';

const localDate = (date) => [
  date.getFullYear(),
  String(date.getMonth() + 1).padStart(2, '0'),
  String(date.getDate()).padStart(2, '0'),
].join('-');
const today = () => localDate(new Date());
const monthEnd = () => {
  const d = new Date();
  return localDate(new Date(d.getFullYear(), d.getMonth() + 1, 0));
};

const emptyTerms = (accountId, entityId) => ({
  entity_id: entityId || '',
  interest_enabled: false,
  interest_direction: 'earned',
  annual_rate_percent: '',
  balance_method: 'average_daily_balance',
  day_count_basis: 'actual_365',
  posting_cadence: 'monthly',
  next_post_date: monthEnd(),
  auto_post: true,
  posting_account_id: accountId || '',
  offset_account_id: '',
  effective_from: today(),
  maturity_date: '',
  counterparty_name: '',
  agreement_reference: '',
  terms_note: '',
});

export default function AccountDetail() {
  const { id } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const code = searchParams.get('code') || '';
  const accountApi = useApi(id
    ? `/modules/accounting/api/accounts.php?id=${id}`
    : `/modules/accounting/api/accounts.php?code=${encodeURIComponent(code)}`);
  const account = accountApi.data?.account || null;
  const workspace = useApi(account?.id
    ? `/modules/accounting/api/account_terms.php?account_id=${account.id}`
    : null);
  const [entityId, setEntityId] = useState(searchParams.get('entity_id') || '');
  const [form, setForm] = useState(emptyTerms(null, null));
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const entities = useMemo(() => workspace.data?.entities || [], [workspace.data?.entities]);
  const terms = useMemo(() => workspace.data?.terms || [], [workspace.data?.terms]);
  const selectedTerms = terms.find((row) => String(row.entity_id) === String(entityId)) || null;
  const selectedBalance = (workspace.data?.balances || []).find((row) => String(row.entity_id) === String(entityId));

  useEffect(() => {
    if (!entityId && entities.length) setEntityId(String(entities[0].id));
  }, [entityId, entities]);

  useEffect(() => {
    if (!account || !entityId) return;
    const source = selectedTerms || emptyTerms(account.id, entityId);
    setForm({
      ...emptyTerms(account.id, entityId),
      ...source,
      entity_id: String(entityId),
      posting_account_id: source.posting_account_id || account.id,
      offset_account_id: source.offset_account_id || '',
      annual_rate_percent: source.annual_rate_percent ?? '',
      effective_from: source.effective_from || '',
      maturity_date: source.maturity_date || '',
      next_post_date: source.next_post_date || '',
      counterparty_name: source.counterparty_name || '',
      agreement_reference: source.agreement_reference || '',
      terms_note: source.terms_note || '',
    });
  }, [account, entityId, selectedTerms]);

  const selectEntity = (value) => {
    setEntityId(value);
    const next = new URLSearchParams(searchParams);
    if (value) next.set('entity_id', value); else next.delete('entity_id');
    setSearchParams(next, { replace: true });
    setNotice(null);
  };

  const setField = (field, value) => setForm((current) => ({ ...current, [field]: value }));
  const save = async (event) => {
    event.preventDefault();
    setBusy(true); setNotice(null);
    try {
      await api.put(`/modules/accounting/api/account_terms.php?account_id=${account.id}`, {
        ...form,
        entity_id: Number(entityId),
        annual_rate_percent: Number(form.annual_rate_percent || 0),
        posting_account_id: Number(form.posting_account_id || account.id),
        offset_account_id: form.offset_account_id ? Number(form.offset_account_id) : null,
      });
      setNotice({ type: 'ok', text: form.interest_enabled
        ? `Terms saved. The next interest period ends ${form.next_post_date}.`
        : 'Account terms saved.' });
      workspace.reload();
    } catch (error) {
      setNotice({ type: 'err', text: error.message });
    } finally { setBusy(false); }
  };

  const runDue = async () => {
    if (!window.confirm(`Create the due interest entry for ${account.name}?`)) return;
    setBusy(true); setNotice(null);
    try {
      const result = await api.post(
        `/modules/accounting/api/account_terms.php?action=run_due&account_id=${account.id}&entity_id=${entityId}`,
        {}
      );
      setNotice({ type: 'ok', text: result.status === 'not_due'
        ? `Nothing is due. Next posting date is ${result.next_post_date}.`
        : `Interest ${result.status}: ${fmtMoney(result.amount || 0)} for ${result.period_end}.` });
      workspace.reload();
    } catch (error) {
      setNotice({ type: 'err', text: error.message });
    } finally { setBusy(false); }
  };

  if (accountApi.loading) return <p>Loading account...</p>;
  if (accountApi.error) return <p className="error">{accountApi.error.message}</p>;
  if (!account) return null;

  const canBearInterest = ['asset', 'liability'].includes(account.account_type);
  const incomeAccounts = (workspace.data?.offset_accounts || []).filter((row) => row.account_type === 'revenue');
  const expenseAccounts = (workspace.data?.offset_accounts || []).filter((row) => row.account_type === 'expense');
  const offsetAccounts = form.interest_direction === 'earned' ? incomeAccounts : expenseAccounts;
  const due = form.interest_enabled && form.next_post_date && form.next_post_date < today();

  return (
    <section data-testid="accounting-account-detail">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, marginBottom: 18, flexWrap: 'wrap' }}>
        <div>
          <Link to="/modules/accounting/accounts" style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 13, textDecoration: 'none' }}>
            <ArrowLeft size={14} /> Chart of Accounts
          </Link>
          <h2 style={{ margin: '8px 0 2px' }}><code>{account.code}</code> {account.name}</h2>
          <p style={{ margin: 0, color: '#64748b', fontSize: 13 }}>
            {account.account_type} account, normal {account.normal_side}{account.description ? ` · ${account.description}` : ''}
          </p>
        </div>
        <button className="btn btn--ghost" onClick={() => { accountApi.reload(); workspace.reload(); }}>
          <RefreshCw size={14} style={{ marginRight: 5, verticalAlign: 'middle' }} /> Refresh
        </button>
      </header>

      {notice && <p data-testid="accounting-account-detail-notice" style={noticeStyle(notice.type)}>{notice.text}</p>}
      {workspace.error && <p className="error">{workspace.error.message}</p>}

      <section style={bandStyle} data-testid="accounting-account-overview">
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 18, flexWrap: 'wrap' }}>
          <label style={{ ...labelStyle, minWidth: 260 }}>Legal entity
            <select className="input" value={entityId} onChange={(event) => selectEntity(event.target.value)}>
              {entities.map((entity) => <option key={entity.id} value={entity.id}>{entity.legal_name}</option>)}
            </select>
          </label>
          <Stat label="Current posted balance" value={selectedBalance?.balance == null ? 'Unavailable' : fmtMoney(selectedBalance.balance)} />
          <Stat label="Currency" value={selectedBalance?.currency || account.currency || 'USD'} />
          <Stat label="Posting" value={account.is_postable ? 'Postable' : 'Header only'} />
          <Stat label="Status" value={account.active ? 'Active' : 'Inactive'} />
          <Link className="btn btn--ghost" to={`/modules/accounting/gl-detail?account_id=${account.id}${entityId ? `&entity_id=${entityId}` : ''}`}>
            View account activity <ExternalLink size={13} style={{ marginLeft: 5, verticalAlign: 'middle' }} />
          </Link>
        </div>
      </section>

      <section style={bandStyle} data-testid="accounting-account-connections">
        <h3 style={headingStyle}>Connected workflows</h3>
        {workspace.loading && <p>Loading connections...</p>}
        {(workspace.data?.connections?.bank_accounts || []).map((bank) => (
          <div key={`bank-${bank.id}`} style={connectionRow}>
            <div><strong>{bank.name}</strong><div style={subtle}>Bank feed{bank.bank_name ? ` · ${bank.bank_name}` : ''}{bank.last4 ? ` · ...${bank.last4}` : ''}</div></div>
            <Link className="btn btn--ghost" to={`/modules/accounting/bank-rec/${bank.id}`}>Open Bank Rec</Link>
          </div>
        ))}
        {(workspace.data?.connections?.liability_accounts || []).map((liability) => (
          <div key={`liability-${liability.id}`} style={connectionRow}>
            <div><strong>{liability.institution_name || account.name}</strong><div style={subtle}>{liability.subtype?.replaceAll('_', ' ') || 'Liability feed'}{liability.last4 ? ` · ...${liability.last4}` : ''}</div></div>
            <Link className="btn btn--ghost" to={`/modules/treasury/liabilities/${liability.account_id}`}>Open liability activity</Link>
          </div>
        ))}
        {!workspace.loading
          && !(workspace.data?.connections?.bank_accounts || []).length
          && !(workspace.data?.connections?.liability_accounts || []).length
          && <p style={subtle}>No bank or liability feed is attached. The account workspace and its terms still operate from ledger activity.</p>}
      </section>

      <form onSubmit={save} style={bandStyle} data-testid="accounting-account-terms-form">
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
          <div><h3 style={headingStyle}>Terms and automatic interest</h3><p style={subtle}>Terms are specific to this account and legal entity.</p></div>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontWeight: 600 }}>
            <input type="checkbox" checked={!!form.interest_enabled} disabled={!canBearInterest}
              onChange={(event) => setField('interest_enabled', event.target.checked)} />
            Calculate interest automatically
          </label>
        </div>
        {!canBearInterest && <p style={warningStyle}>Interest scheduling is available for asset and liability accounts. General terms can still be recorded below.</p>}

        <div style={gridStyle}>
          <label style={labelStyle}>Counterparty<input className="input" value={form.counterparty_name} onChange={(e) => setField('counterparty_name', e.target.value)} placeholder="Borrower, lender, or institution" /></label>
          <label style={labelStyle}>Agreement reference<input className="input" value={form.agreement_reference} onChange={(e) => setField('agreement_reference', e.target.value)} placeholder="Note or contract number" /></label>
          <label style={labelStyle}>Effective date<input type="date" className="input" value={form.effective_from} onChange={(e) => setField('effective_from', e.target.value)} /></label>
          <label style={labelStyle}>Maturity date<input type="date" className="input" value={form.maturity_date} onChange={(e) => setField('maturity_date', e.target.value)} /></label>
        </div>

        {form.interest_enabled && <>
          <div style={gridStyle}>
            <label style={labelStyle}>Interest type<select className="input" value={form.interest_direction} onChange={(e) => { setField('interest_direction', e.target.value); setField('offset_account_id', ''); }}><option value="earned">Interest earned</option><option value="charged">Interest charged</option></select></label>
            <label style={labelStyle}>Annual rate (%)<input type="number" min="0" step="0.000001" className="input" required value={form.annual_rate_percent} onChange={(e) => setField('annual_rate_percent', e.target.value)} /></label>
            <label style={labelStyle}>Balance basis<select className="input" value={form.balance_method} onChange={(e) => setField('balance_method', e.target.value)}><option value="average_daily_balance">Average daily balance</option><option value="closing_balance">Closing ledger balance</option></select></label>
            <label style={labelStyle}>Day count<select className="input" value={form.day_count_basis} onChange={(e) => setField('day_count_basis', e.target.value)}><option value="actual_365">Actual / 365</option><option value="actual_360">Actual / 360</option></select></label>
            <label style={labelStyle}>Posting frequency<select className="input" value={form.posting_cadence} onChange={(e) => setField('posting_cadence', e.target.value)}><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="annual">Annual</option></select></label>
            <label style={labelStyle}>Next period end<input type="date" className="input" required value={form.next_post_date} onChange={(e) => setField('next_post_date', e.target.value)} /></label>
            <label style={labelStyle}>Post interest to<select className="input" value={form.posting_account_id} onChange={(e) => setField('posting_account_id', e.target.value)}><option value={account.id}>{account.code} · {account.name} (compound here)</option>{(workspace.data?.posting_accounts || []).filter((row) => row.id !== account.id).map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</select></label>
            <label style={labelStyle}>{form.interest_direction === 'earned' ? 'Interest income account' : 'Interest expense account'}<select className="input" required value={form.offset_account_id} onChange={(e) => setField('offset_account_id', e.target.value)}><option value="">Choose account</option>{offsetAccounts.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}</select></label>
          </div>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 7, marginTop: 12 }}>
            <input type="checkbox" checked={!!form.auto_post} onChange={(e) => setField('auto_post', e.target.checked)} />
            Post automatically when the period ends; otherwise create a draft for review
          </label>
        </>}

        <label style={{ ...labelStyle, marginTop: 14 }}>Other terms<textarea className="input" rows={4} value={form.terms_note} onChange={(e) => setField('terms_note', e.target.value)} placeholder="Payment terms, covenants, collateral, fees, renewal language, or other account-specific details" /></label>
        <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
          <button className="btn btn--primary" disabled={busy || !entityId}>{busy ? 'Saving...' : 'Save account terms'}</button>
          {due && <button type="button" className="btn btn--ghost" onClick={runDue} disabled={busy}>Run due interest now</button>}
        </div>
      </form>

      <section style={bandStyle} data-testid="accounting-account-interest-history">
        <h3 style={headingStyle}>Interest history</h3>
        <table className="data-table" style={{ width: '100%' }}>
          <thead><tr><th>Period</th><th>Entity</th><th>Basis</th><th>APR</th><th>Interest</th><th>Status</th><th>Journal entry</th></tr></thead>
          <tbody>
            {(workspace.data?.interest_runs || []).length === 0 && <tr><td colSpan={7} className="empty">No interest periods have been calculated.</td></tr>}
            {(workspace.data?.interest_runs || []).map((run) => <tr key={run.id}>
              <td>{run.period_start} to {run.period_end}</td><td>{run.entity_name}</td><td>{fmtMoney(run.balance_basis_amount)}</td>
              <td>{Number(run.annual_rate_percent).toLocaleString(undefined, { maximumFractionDigits: 6 })}%</td>
              <td>{fmtMoney(run.interest_amount)}</td><td><span className="badge">{run.status}</span></td>
              <td>{run.journal_entry_id ? <Link to={`/modules/accounting/journal-entries/${run.journal_entry_id}`}>{run.je_number || `JE #${run.journal_entry_id}`}</Link> : 'None'}</td>
            </tr>)}
          </tbody>
        </table>
      </section>
    </section>
  );
}

const bandStyle = { borderTop: '1px solid #e2e8f0', padding: '18px 0', marginTop: 0 };
const headingStyle = { margin: '0 0 4px', fontSize: 16 };
const subtle = { margin: '3px 0', color: '#64748b', fontSize: 13 };
const gridStyle = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginTop: 14 };
const labelStyle = { display: 'flex', flexDirection: 'column', gap: 5, color: '#475569', fontSize: 12 };
const connectionRow = { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '9px 0', borderTop: '1px solid #f1f5f9' };
const warningStyle = { background: '#fff7ed', color: '#9a3412', padding: '8px 10px', fontSize: 13 };
const noticeStyle = (type) => ({ padding: '9px 12px', background: type === 'ok' ? '#ecfdf5' : '#fef2f2', color: type === 'ok' ? '#065f46' : '#991b1b', border: `1px solid ${type === 'ok' ? '#a7f3d0' : '#fecaca'}` });
function Stat({ label, value }) { return <div><div style={{ color: '#64748b', fontSize: 11 }}>{label}</div><strong style={{ display: 'block', marginTop: 3 }}>{value}</strong></div>; }
