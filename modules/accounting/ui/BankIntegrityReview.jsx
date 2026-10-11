import React from 'react';
import { Link } from 'react-router-dom';
import { RefreshCw } from 'lucide-react';
import { useApi } from '../../../dashboard/src/lib/api';
import { fmtDate, fmtDateTime, fmtMoney } from '../../../dashboard/src/lib/format';
import AccountLink from '../../../dashboard/src/components/AccountLink';

const BANK_BASE = '/modules/accounting/bank-rec';
const titles = {
  bank_account_mapping_integrity: 'Bank accounts needing ownership or cash-ledger review',
  bank_match_integrity: 'Matched lines with inconsistent journals',
  bank_duplicate_journal_matches: 'Journals claimed by multiple lines',
  bank_unmatched_explicit_lineage: 'Unmatched lines with ledger references',
};
const checkResult = check => check.status === 'fail'
  ? `${check.issue_count} found`
  : check.status === 'ok' ? 'No issues' : 'Unavailable';

function BankLineLink({ accountId, lineId, matched = false }) {
  if (!accountId || !lineId) return <>Line {lineId || 'unknown'}</>;
  return <Link to={`${BANK_BASE}/${accountId}?line_id=${lineId}${matched ? '&match_status=matched' : ''}`}>Line {lineId}</Link>;
}

function JournalLink({ journalId }) {
  if (!journalId) return <>None</>;
  return <Link to={`/modules/accounting/journal-entries/${journalId}`}>Journal {journalId}</Link>;
}

function mappingIssue(row) {
  if (!row.entity_id) return 'Legal entity not assigned';
  if (row.entity_active == null) return 'Legal entity no longer exists';
  if (!row.cash_account_id) return 'Cash ledger account not found';
  if (row.entity_currency !== row.bank_currency) return 'Bank and entity currencies differ';
  if (row.cash_account_currency && row.cash_account_currency !== row.bank_currency) return 'Cash ledger currency differs';
  if (row.status !== 'active') return 'Review historical ownership';
  if (Number(row.entity_active) !== 1) return 'Legal entity is inactive';
  if (Number(row.cash_account_active) !== 1) return 'Cash ledger account is inactive';
  if (Number(row.is_postable) !== 1) return 'Cash ledger account is not postable';
  if (row.account_type !== 'asset' || row.normal_side !== 'debit') return 'Cash ledger account is not a debit-side asset';
  const systemCash = row.gl_account_code === '1000' && Number(row.is_system_account) === 1;
  const taggedCash = Number(row.is_system_account) === 0 && row.cash_flow_tag === 'cash_and_equivalents';
  if (!systemCash && !taggedCash) return 'Cash ledger account is not marked Bank/cash';
  return 'Review bank mapping';
}

function MappingRows({ rows }) {
  return (
    <div className="data-table-wrap" role="region" aria-label="Bank account mapping exceptions" tabIndex={0}>
      <table className="data-table" data-testid="accounting-bank-integrity-mapping-table">
        <thead><tr><th>Bank account</th><th>Status</th><th>Entity</th><th>Cash ledger</th><th>Currency</th><th>Needs review</th></tr></thead>
        <tbody>{rows.map(row => (
          <tr key={row.bank_account_id}>
            <td><Link to={`${BANK_BASE}/${row.bank_account_id}`}>{row.bank_account_name || `Bank ${row.bank_account_id}`}</Link></td>
            <td>{row.status}</td>
            <td>{row.entity_id || 'None'}</td>
            <td>{row.cash_account_id
              ? <AccountLink accountId={row.cash_account_id} accountCode={row.gl_account_code} entityId={row.entity_id}>{row.gl_account_code}</AccountLink>
              : row.gl_account_code || 'None'}</td>
            <td>{row.bank_currency || 'None'} / {row.entity_currency || 'None'}</td>
            <td>{mappingIssue(row)}</td>
          </tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function MatchRows({ rows }) {
  return (
    <div className="data-table-wrap" role="region" aria-label="Inconsistent bank matches" tabIndex={0}>
      <table className="data-table">
        <thead><tr><th>Bank line</th><th>Date</th><th>Bank amount</th><th>Journal</th><th>Cash movement</th><th>Entity</th><th>Currency</th></tr></thead>
        <tbody>{rows.map(row => (
          <tr key={row.bank_line_id}>
            <td><BankLineLink accountId={row.bank_account_id} lineId={row.bank_line_id} matched /></td>
            <td>{fmtDate(row.posted_date)}</td>
            <td>{fmtMoney(row.amount)}</td>
            <td><JournalLink journalId={row.matched_je_id} />{row.journal_status && ` · ${row.journal_status}`}</td>
            <td>{row.cash_movement == null ? 'Unknown' : fmtMoney(row.cash_movement)}</td>
            <td>{row.bank_entity_id || 'None'} / {row.journal_entity_id || 'None'}</td>
            <td>{row.bank_currency || 'USD'} / {row.journal_currency || 'USD'}</td>
          </tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function DuplicateRows({ rows }) {
  return (
    <div className="data-table-wrap" role="region" aria-label="Duplicate bank journal claims" tabIndex={0}>
      <table className="data-table">
        <thead><tr><th>Journal</th><th>Bank lines</th></tr></thead>
        <tbody>{rows.map(row => (
          <tr key={row.matched_je_id}>
            <td><JournalLink journalId={row.matched_je_id} /></td>
            <td>{String(row.bank_line_refs || '').split(',').filter(Boolean).map(ref => {
              const [lineId, accountId] = ref.split(':');
              return <span key={ref} style={{ marginRight: 12 }}><BankLineLink accountId={accountId} lineId={lineId} matched /></span>;
            })}</td>
          </tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function UnmatchedRows({ rows }) {
  return (
    <div className="data-table-wrap" role="region" aria-label="Unmatched bank lines with ledger references" tabIndex={0}>
      <table className="data-table">
        <thead><tr><th>Bank line</th><th>Date</th><th>Amount</th><th>Stored match</th><th>Posted source</th></tr></thead>
        <tbody>{rows.map(row => {
          const postedIds = [...new Set([
            ...String(row.direct_posted_journal_ids || '').split(','),
            ...String(row.linked_posted_journal_ids || '').split(','),
          ].filter(id => /^\d+$/.test(id)))];
          return (
            <tr key={row.bank_line_id}>
              <td><BankLineLink accountId={row.bank_account_id} lineId={row.bank_line_id} /></td>
              <td>{fmtDate(row.posted_date)}</td>
              <td>{fmtMoney(row.amount)}</td>
              <td><JournalLink journalId={row.matched_je_id} /></td>
              <td>{postedIds.length ? postedIds.map(id => <span key={id} style={{ marginRight: 12 }}><JournalLink journalId={id} /></span>) : 'None'}</td>
            </tr>
          );
        })}</tbody>
      </table>
    </div>
  );
}

export default function BankIntegrityReview() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/bank_integrity.php');
  const checks = data?.checks || [];
  const state = data?.status;
  return (
    <section data-testid="accounting-bank-integrity">
      <Link to={BANK_BASE}>← Bank accounts</Link>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', flexWrap: 'wrap', margin: '12px 0 20px' }}>
        <div>
          <h2 style={{ margin: 0 }}>Bank integrity review</h2>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 13 }}>All legal entities{data?.ran_at ? ` · Checked ${fmtDateTime(data.ran_at)}` : ''}</p>
        </div>
        <button className="btn btn--ghost" type="button" onClick={reload} disabled={loading} title="Run bank integrity checks again" data-testid="accounting-bank-integrity-refresh">
          <RefreshCw size={16} aria-hidden="true" /> Refresh
        </button>
      </header>
      {loading && !data && <p role="status">Checking bank accounts and history…</p>}
      {error && <p className="error" role="alert">Could not run the bank integrity review: {error.message}</p>}
      {data && <p role="status" data-testid="accounting-bank-integrity-status" style={{ color: state === 'clear' ? '#047857' : '#b45309', fontWeight: 600 }}>
        {state === 'clear' ? 'No bank integrity issues found' : state === 'incomplete' ? 'Bank integrity review incomplete' : `${data.issue_count} bank integrity issue${data.issue_count === 1 ? '' : 's'} found`}
      </p>}
      {checks.map(check => (
        <section key={check.key} style={{ borderTop: '1px solid #dbe4ed', padding: '16px 0' }} data-testid={`accounting-bank-integrity-${check.key}`}>
          <h3 style={{ margin: '0 0 6px', fontSize: 16 }}>{titles[check.key] || check.label} · {checkResult(check)}</h3>
          {check.status === 'error' && <p className="error" role="alert">This check could not finish. Contact support before relying on the review.</p>}
          {check.status === 'skipped' && <p className="error" role="alert">This check is unavailable: {check.reason}</p>}
          {check.status === 'fail' && check.issue_count > check.sample.length && <p style={{ fontSize: 12, color: '#64748b' }}>Showing {check.sample.length} of {check.issue_count} exceptions.</p>}
          {check.status === 'fail' && check.key === 'bank_account_mapping_integrity' && <MappingRows rows={check.sample} />}
          {check.status === 'fail' && check.key === 'bank_match_integrity' && <MatchRows rows={check.sample} />}
          {check.status === 'fail' && check.key === 'bank_duplicate_journal_matches' && <DuplicateRows rows={check.sample} />}
          {check.status === 'fail' && check.key === 'bank_unmatched_explicit_lineage' && <UnmatchedRows rows={check.sample} />}
        </section>
      ))}
    </section>
  );
}
