import React from 'react';
import { Link } from 'react-router-dom';
import { RefreshCw } from 'lucide-react';
import { useApi } from '../../../dashboard/src/lib/api';
import { fmtDate, fmtDateTime, fmtMoney } from '../../../dashboard/src/lib/format';

const BANK_BASE = '/modules/accounting/bank-rec';
const titles = {
  bank_match_integrity: 'Matched lines with inconsistent journals',
  bank_duplicate_journal_matches: 'Journals claimed by multiple lines',
  bank_unmatched_explicit_lineage: 'Unmatched lines with posted history',
};

function BankLineLink({ accountId, lineId, matched = false }) {
  if (!accountId || !lineId) return <>Line {lineId || 'unknown'}</>;
  return <Link to={`${BANK_BASE}/${accountId}?line_id=${lineId}${matched ? '&match_status=matched' : ''}`}>Line {lineId}</Link>;
}

function JournalLink({ journalId }) {
  if (!journalId) return <>None</>;
  return <Link to={`/modules/accounting/journal-entries/${journalId}`}>Journal {journalId}</Link>;
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
    <div className="data-table-wrap" role="region" aria-label="Unmatched bank lines with posted history" tabIndex={0}>
      <table className="data-table">
        <thead><tr><th>Bank line</th><th>Date</th><th>Amount</th><th>Linked journal</th></tr></thead>
        <tbody>{rows.map(row => (
          <tr key={row.bank_line_id}>
            <td><BankLineLink accountId={row.bank_account_id} lineId={row.bank_line_id} /></td>
            <td>{fmtDate(row.posted_date)}</td>
            <td>{fmtMoney(row.amount)}</td>
            <td><JournalLink journalId={row.matched_je_id} /></td>
          </tr>
        ))}</tbody>
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
          <h2 style={{ margin: 0 }}>Bank history review</h2>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 13 }}>All legal entities{data?.ran_at ? ` · Checked ${fmtDateTime(data.ran_at)}` : ''}</p>
        </div>
        <button className="btn btn--ghost" type="button" onClick={reload} disabled={loading} title="Run bank history checks again" data-testid="accounting-bank-integrity-refresh">
          <RefreshCw size={16} aria-hidden="true" /> Refresh
        </button>
      </header>
      {loading && !data && <p role="status">Checking bank history…</p>}
      {error && <p className="error" role="alert">Could not run the bank history review: {error.message}</p>}
      {data && <p role="status" data-testid="accounting-bank-integrity-status" style={{ color: state === 'clear' ? '#047857' : '#b45309', fontWeight: 600 }}>
        {state === 'clear' ? 'No bank history exceptions found' : state === 'incomplete' ? 'Bank history review incomplete' : `${data.issue_count} bank history exception${data.issue_count === 1 ? '' : 's'} found`}
      </p>}
      {checks.map(check => (
        <section key={check.key} style={{ borderTop: '1px solid #dbe4ed', padding: '16px 0' }} data-testid={`accounting-bank-integrity-${check.key}`}>
          <h3 style={{ margin: '0 0 6px', fontSize: 16 }}>{titles[check.key] || check.label} · {check.status === 'fail' ? check.issue_count : check.status}</h3>
          {check.status === 'error' && <p className="error" role="alert">This check could not finish. Contact support before relying on the review.</p>}
          {check.status === 'skipped' && <p className="error" role="alert">This check is unavailable: {check.reason}</p>}
          {check.status === 'fail' && check.issue_count > check.sample.length && <p style={{ fontSize: 12, color: '#64748b' }}>Showing {check.sample.length} of {check.issue_count} exceptions.</p>}
          {check.status === 'fail' && check.key === 'bank_match_integrity' && <MatchRows rows={check.sample} />}
          {check.status === 'fail' && check.key === 'bank_duplicate_journal_matches' && <DuplicateRows rows={check.sample} />}
          {check.status === 'fail' && check.key === 'bank_unmatched_explicit_lineage' && <UnmatchedRows rows={check.sample} />}
        </section>
      ))}
    </section>
  );
}
