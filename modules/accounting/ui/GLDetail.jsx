import React, { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import DataWarning from '../../../dashboard/src/components/DataWarning';

/**
 * GL Detail — every JE line that hit a single account between two dates.
 *
 * URL params drive state:
 *   ?account_id=N | ?account_code=5100
 *   &start=YYYY-MM-DD&end=YYYY-MM-DD&entity_id=N&include_unposted=1
 *
 * Rows are clickable into Journal Entry Detail. Running balance is
 * computed server-side from the account's normal side (debit accounts
 * climb on debits, credit accounts climb on credits).
 */
export default function GLDetail() {
  const [params, setParams] = useSearchParams();
  const accountId   = params.get('account_id') || '';
  const accountCode = params.get('account_code') || '';
  const start       = params.get('start') || isoMonthStart();
  const end         = params.get('end')   || isoToday();
  const entityId    = params.get('entity_id') || '';
  const includeUnposted = params.get('include_unposted') === '1';

  const [draftCode, setDraftCode] = useState(accountCode);
  useEffect(() => { setDraftCode(accountCode); }, [accountCode]);

  // Fetch postable accounts for the picker.
  const accountsApi = useApi('/modules/accounting/api/accounts.php?postable=1');
  const accounts = accountsApi.data?.rows || [];

  const queryReady = !!(accountId || accountCode);
  const url = queryReady
    ? '/api/gl_detail.php?'
      + (accountId ? `account_id=${accountId}` : `account_code=${encodeURIComponent(accountCode)}`)
      + `&start=${start}&end=${end}`
      + (entityId ? `&entity_id=${entityId}` : '')
      + (includeUnposted ? '&include_unposted=1' : '')
    : null;
  const { data, error, loading, reload } = useApi(url);

  const setParam = (k, v) => {
    const p = new URLSearchParams(params);
    if (v === '' || v == null) p.delete(k);
    else p.set(k, String(v));
    setParams(p, { replace: true });
  };

  return (
    <section data-testid="accounting-gl-detail-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>GL Detail</h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Every posted journal-entry line that touched this account, with running balance.
          </p>
        </div>
        <button data-testid="accounting-gl-detail-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>
          Refresh
        </button>
      </header>

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, alignItems: 'flex-end', marginBottom: 14 }}>
        <label style={lbl}>Account
          <select className="input"
                  data-testid="accounting-gl-detail-account"
                  value={draftCode}
                  onChange={e => { setDraftCode(e.target.value); setParam('account_code', e.target.value); }}>
            <option value="">— pick an account —</option>
            {accounts.map(a => (
              <option key={a.id} value={a.code}>{a.code} — {a.name}{a.account_type ? ` (${a.account_type})` : ''}</option>
            ))}
          </select>
        </label>
        <label style={lbl}>Start
          <input type="date" className="input" data-testid="accounting-gl-detail-start"
                 value={start} onChange={e => setParam('start', e.target.value)} />
        </label>
        <label style={lbl}>End
          <input type="date" className="input" data-testid="accounting-gl-detail-end"
                 value={end} onChange={e => setParam('end', e.target.value)} />
        </label>
        <label style={{ ...lbl, flexDirection: 'row', alignItems: 'center', gap: 4 }}>
          <input type="checkbox" data-testid="accounting-gl-detail-include-unposted"
                 checked={includeUnposted}
                 onChange={e => setParam('include_unposted', e.target.checked ? '1' : '')} />
          <span style={{ fontSize: 12, color: '#475569' }}>Include unposted</span>
        </label>
      </div>

      {!queryReady && (
        <div data-testid="accounting-gl-detail-empty-state" style={emptyHero}>
          Select an account to load its GL detail.
        </div>
      )}
      {loading && <p data-testid="accounting-gl-detail-loading">Loading…</p>}
      {error && <p data-testid="accounting-gl-detail-error" className="error">Error: {error.message}</p>}
      {data?.data_warning && <DataWarning text={data.data_warning} />}

      {data?.account && (
        <div data-testid="accounting-gl-detail-summary"
             style={{ display: 'flex', gap: 24, padding: 14, background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, marginBottom: 12, flexWrap: 'wrap' }}>
          <SummaryStat label="Account">
            <code data-testid="accounting-gl-detail-account-code">{data.account.code}</code> · {data.account.name}
          </SummaryStat>
          <SummaryStat label="Opening" value={fmt(data.opening_balance)} testId="accounting-gl-detail-opening" />
          <SummaryStat label="Debits"  value={fmt(data.totals.debit)}    testId="accounting-gl-detail-total-debit" />
          <SummaryStat label="Credits" value={fmt(data.totals.credit)}   testId="accounting-gl-detail-total-credit" />
          <SummaryStat label="Net"     value={fmt(data.totals.net)}      testId="accounting-gl-detail-total-net" />
          <SummaryStat label="Ending"  value={fmt(data.totals.ending_balance)} bold testId="accounting-gl-detail-ending" />
        </div>
      )}

      {data?.lines && (
        <table className="data-table" style={{ width: '100%' }} data-testid="accounting-gl-detail-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>JE #</th>
              <th>Source</th>
              <th>Memo</th>
              <th style={{ textAlign: 'right' }}>Debit</th>
              <th style={{ textAlign: 'right' }}>Credit</th>
              <th style={{ textAlign: 'right' }}>Running</th>
            </tr>
          </thead>
          <tbody>
            {data.lines.length === 0 && (
              <tr><td colSpan={7} className="empty" data-testid="accounting-gl-detail-empty">No activity in the selected window.</td></tr>
            )}
            {data.lines.map((l, idx) => (
              <tr key={`${l.je_id}-${idx}`} data-testid={`accounting-gl-detail-row-${l.je_id}-${idx}`}>
                <td>{l.posting_date}</td>
                <td>
                  <Link to={`/modules/accounting/journal-entries/${l.je_id}`} data-testid={`accounting-gl-detail-je-link-${l.je_id}`}>
                    {l.je_number}
                  </Link>
                </td>
                <td style={{ fontSize: 12, color: '#64748b' }}>
                  {l.source_module ? `${l.source_module}${l.source_ref_id ? ` · ${l.source_ref_id}` : ''}` : '—'}
                </td>
                <td style={{ fontSize: 12 }}>{l.description || l.memo || ''}</td>
                <td style={{ textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>{l.debit > 0 ? fmt(l.debit) : ''}</td>
                <td style={{ textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>{l.credit > 0 ? fmt(l.credit) : ''}</td>
                <td style={{ textAlign: 'right', fontFamily: 'ui-monospace, monospace', color: l.running >= 0 ? '#0f172a' : '#dc2626' }}>{fmt(l.running)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };
const emptyHero = { padding: 36, textAlign: 'center', color: '#64748b', background: '#f8fafc', border: '1px dashed #e2e8f0', borderRadius: 10 };

function SummaryStat({ label, value, children, bold, testId }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 120 }}>
      <span style={{ fontSize: 11, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</span>
      <span data-testid={testId}
            style={{ fontFamily: 'ui-monospace, monospace', fontSize: 13, fontWeight: bold ? 700 : 400, color: '#0f172a' }}>
        {children ?? value}
      </span>
    </div>
  );
}
function fmt(n) {
  return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function isoMonthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}
function isoToday() { return new Date().toISOString().slice(0, 10); }
