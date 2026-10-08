import React, { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import { useAccountingEntityScope } from '../../../dashboard/src/lib/useAccountingEntityScope';
import AccountLink from '../../../dashboard/src/components/AccountLink';
import AccountingEntitySelector from '../../../dashboard/src/components/AccountingEntitySelector';
import FinancialReportLibrary from '../../../dashboard/src/components/FinancialReportLibrary';
import {
  Activity, BarChart3, ChevronLeft, ChevronRight, ClipboardCheck, Download, FileClock, RefreshCw, Scale, ScrollText,
} from 'lucide-react';

const REPORT_TABS = [
  ['source_control', 'AR/AP tie-out', 'accounting-report-tab-source_control', Scale],
  ['gl_detail', 'GL detail', 'accounting-report-tab-gl_detail', BarChart3],
  ['unposted_jes', 'Unposted entries', 'accounting-report-tab-unposted_jes', FileClock],
  ['approval_queue', 'Approval queue', 'accounting-report-tab-approval_queue', ClipboardCheck],
  ['audit_log', 'Audit log', 'accounting-report-tab-audit_log', ScrollText],
  ['account_activity', 'Account activity', 'accounting-report-tab-account_activity', Activity],
];

/**
 * Standard (operational) reports — 5 tabs:
 *   - GL Detail
 *   - Unposted JEs
 *   - Approval Queue
 *   - Audit Log
 *   - Account Activity
 *
 * Each report is a filter bar → on-screen table → CSV export button.
 */
export default function StandardReports({ session }) {
  const location = useLocation();
  const requestedTab = new URLSearchParams(location.search).get('tab');
  const [tab, setTab] = useState(REPORT_TABS.some(([key]) => key === requestedTab) ? requestedTab : 'gl_detail');
  useEffect(() => {
    if (REPORT_TABS.some(([key]) => key === requestedTab)) setTab(requestedTab);
  }, [requestedTab]);
  const scope = useAccountingEntityScope();
  const scopedTab = tab !== 'audit_log';
  return (
    <section className="report-page" data-testid="accounting-standard-reports">
      <header className="report-page__header">
        <div className="report-page__title">
          <span className="report-page__icon" aria-hidden="true"><BarChart3 size={18} /></span>
          <div>
            <h2>Reports</h2>
            <p className="report-page__meta">Financial statements, aging and ledger detail</p>
          </div>
        </div>
      </header>

      <FinancialReportLibrary session={session} />

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12 }}>
        <div className="report-library__section-label">
          <strong>{scopedTab ? 'Ledger operations' : 'Audit log · workspace-wide'}</strong>
          <span>{scopedTab ? 'Review activity for one legal entity.' : 'Review accounting history across the workspace.'}</span>
        </div>
        {scopedTab && <AccountingEntitySelector scope={scope} allowAll={false} testId="accounting-operational-report-entity" />}
      </div>
      <nav className="report-tabs" aria-label="Standard reports">
        {REPORT_TABS.map(([k, label, tid, Icon]) => (
          <button
            key={k}
            data-testid={tid}
            className={`report-tab${tab === k ? ' is-active' : ''}`}
            onClick={() => setTab(k)}
          ><Icon size={15} aria-hidden="true" />{label}</button>
        ))}
      </nav>
      {scopedTab && (scope.error || scope.allEntities) && (
        <p className="error" data-testid="accounting-operational-report-scope-error">
          {scope.error || 'Choose one legal entity to view ledger operations.'}
        </p>
      )}
      {scopedTab && !scope.loaded && <p>Loading legal entities...</p>}
      {tab === 'source_control'   && scope.ready && !scope.allEntities && <SourceControlTieOut scope={scope} />}
      {tab === 'gl_detail'        && scope.ready && !scope.allEntities && <GlDetail scope={scope} />}
      {tab === 'unposted_jes'     && scope.ready && !scope.allEntities && <Unposted scope={scope} />}
      {tab === 'approval_queue'   && scope.ready && !scope.allEntities && <ApprovalQueue scope={scope} />}
      {tab === 'audit_log'        && <AuditLog />}
      {tab === 'account_activity' && scope.ready && !scope.allEntities && <AccountActivity scope={scope} />}
    </section>
  );
}

function SourceControlTieOut({ scope }) {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const url = asOf ? `/modules/accounting/api/source_control_tie_out.php?${new URLSearchParams({
    entity_id: String(scope.entityId), as_of: asOf,
  })}` : null;
  const { data: response, loading, error, reload } = useApi(url, { enabled: Boolean(url) });
  const data = response?.entity_id === scope.entityId && response?.as_of === asOf ? response : null;
  const controls = data ? [data.controls.ar, data.controls.ap] : [];
  return (
    <div data-testid="accounting-source-control-tie-out">
      <div className="report-filter-bar">
        <div className="report-filter-bar__fields">
          <label>As of <input type="date" className="input" value={asOf}
            onChange={event => setAsOf(event.target.value)} data-testid="accounting-source-control-date" /></label>
        </div>
        <button type="button" className="btn" onClick={reload} disabled={loading || !url}
          data-testid="accounting-source-control-refresh"><RefreshCw size={15} aria-hidden="true" />Refresh</button>
      </div>
      {!asOf && <p>Choose an as-of date.</p>}
      {loading && <p>Comparing subledgers with the posted ledger...</p>}
      {error && <p className="error" role="alert">{error.message}</p>}
      {data && !loading && (
        <>
          <p data-testid="accounting-source-control-status" role="status">
            {!data.has_activity ? 'No posted AR/AP activity for this entity by this date.'
              : data.matched ? 'AR and AP control totals match.' : 'Review the AR/AP differences or currency warning below.'}
            {' '}{data.entity_code} · {data.as_of} · {data.base_currency}
          </p>
          <div className="data-table-wrap">
            <table className="data-table" data-testid="accounting-source-control-table">
              <thead><tr><th>Control account</th><th style={{ textAlign: 'right' }}>Open documents</th>
                <th style={{ textAlign: 'right' }}>Posted GL</th><th style={{ textAlign: 'right' }}>Difference (GL − documents)</th><th>Result</th></tr></thead>
              <tbody>{controls.map(control => (
                <tr key={control.account_code}>
                  <td>{control.account_code} · {control.label}</td>
                  <td style={{ textAlign: 'right' }}>{fmt(control.source_due)}</td>
                  <td style={{ textAlign: 'right' }}>{fmt(control.gl_balance)}</td>
                  <td style={{ textAlign: 'right' }}>{fmt(control.difference)}</td>
                  <td>{!data.has_activity ? 'No activity'
                    : data.foreign_currencies.length ? 'Currency review'
                      : control.matched ? 'Matches' : 'Difference'}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
          {data.foreign_currencies.length > 0 && <p className="error" role="alert" data-testid="accounting-source-control-currency-warning">
            Posted source documents include {data.foreign_currencies.join(', ')}. This comparison does not convert currencies; review those documents separately.
          </p>}
          <h3>Posted ledger sources</h3>
          <div className="data-table-wrap">
            <table className="data-table" data-testid="accounting-source-control-sources">
              <thead><tr><th>Control account</th><th>Source</th><th style={{ textAlign: 'right' }}>Journals</th>
                <th style={{ textAlign: 'right' }}>Net {data.base_currency}</th></tr></thead>
              <tbody>{controls.flatMap(control => control.sources.map(source => (
                <tr key={`${control.account_code}-${source.module}`}>
                  <td>{control.account_code}</td><td>{source.module}</td>
                  <td style={{ textAlign: 'right' }}>{source.journal_count}</td>
                  <td style={{ textAlign: 'right' }}>{fmt(source.net)}</td>
                </tr>
              )))}</tbody>
            </table>
          </div>
          <p className="report-page__meta">This is a control-total check. Offsetting errors and unposted documents can still be missed.</p>
        </>
      )}
    </div>
  );
}

function FilterBar({ children, onExport, exportTestId }) {
  return (
    <div className="report-filter-bar">
      <div className="report-filter-bar__fields">{children}</div>
      {onExport && (
        <button
          className="btn"
          onClick={onExport}
          data-testid={exportTestId}
        ><Download size={15} aria-hidden="true" />Export CSV</button>
      )}
    </div>
  );
}

function ReportSummary({ items, testId }) {
  return (
    <div className="report-summary" data-testid={testId}>
      {items.map(item => (
        <div className="report-summary__item" key={item.label}>
          <span className="report-summary__label">{item.label}</span>
          <span className="report-summary__value">{item.value}</span>
        </div>
      ))}
    </div>
  );
}

function useReportPaging(resetKey) {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(50);
  useEffect(() => setPage(1), [resetKey]);
  return { page, pageSize, setPage, setPageSize };
}

function ReportPager({ data, page, pageSize, setPage, setPageSize, loading, testId }) {
  return (
    <div data-testid={testId}
      style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 12, padding: '12px 0' }}>
      <span style={{ fontSize: 12, color: '#64748b' }}>
        {data.rows.length ? `${(page - 1) * pageSize + 1}–${(page - 1) * pageSize + data.rows.length}` : '0'} of {data.count} rows
      </span>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>Rows
          <select className="input" value={pageSize} aria-label="Report rows per page"
            onChange={event => { setPageSize(Number(event.target.value)); setPage(1); }}>
            {[25, 50, 100, 200].map(size => <option key={size} value={size}>{size}</option>)}
          </select>
        </label>
        <button type="button" className="btn btn--ghost btn--sm" aria-label="Previous report page"
          title="Previous page" disabled={loading || page <= 1}
          onClick={() => setPage(value => value - 1)}><ChevronLeft size={16} /></button>
        <span style={{ fontSize: 12, minWidth: 66, textAlign: 'center' }}>Page {page} of {Math.max(1, Math.ceil(data.count / pageSize))}</span>
        <button type="button" className="btn btn--ghost btn--sm" aria-label="Next report page"
          title="Next page" disabled={loading || !data.has_more}
          onClick={() => setPage(value => value + 1)}><ChevronRight size={16} /></button>
      </div>
    </div>
  );
}

function downloadCsv(url, filename) {
  const base = (typeof window !== 'undefined' && window.__cfApiBase) || '';
  const full = base + url;
  // Direct browser navigation so the Content-Disposition header triggers download.
  const link = document.createElement('a');
  link.href = full;
  link.download = filename || '';
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

// ── GL Detail ───────────────────────────────────────────────────────────
function GlDetail({ scope }) {
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0,10));
  const [to, setTo]     = useState(new Date().toISOString().slice(0,10));
  const [code, setCode] = useState('');
  const { page, pageSize, setPage, setPageSize } = useReportPaging(scope.entityId);
  const exportQs = new URLSearchParams({ type: 'gl_detail', from, to, entity_id: String(scope.entityId), ...(code ? { account_code: code } : {}) }).toString();
  const qs = `${exportQs}&page=${page}&page_size=${pageSize}`;
  const { data: response, loading, error } = useApi(`/modules/accounting/api/standard_reports.php?${qs}`);
  const data = !loading && response?.entity_id === scope.entityId
    && response?.from === from && response?.to === to && response?.account_code === code
    && response?.page === page && response?.page_size === pageSize ? response : null;
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv(`/modules/accounting/api/export.php?${exportQs}`, `gl-detail-${from}-${to}.csv`)}
        exportTestId="accounting-report-gl-detail-export"
      >
        <label>From <input type="date" className="input" value={from} onChange={e => { setFrom(e.target.value); setPage(1); }} data-testid="accounting-report-gl-from" /></label>
        <label>To <input type="date" className="input" value={to} onChange={e => { setTo(e.target.value); setPage(1); }} data-testid="accounting-report-gl-to" /></label>
        <label>Account code <input className="input" value={code} onChange={e => { setCode(e.target.value); setPage(1); }} placeholder="(all)" data-testid="accounting-report-gl-code" /></label>
      </FilterBar>
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <ReportSummary
            testId="accounting-report-gl-summary"
            items={[
              { label: 'Lines', value: data.count },
              { label: 'Debits', value: fmt(data.total_debit) },
              { label: 'Credits', value: fmt(data.total_credit) },
            ]}
          />
          <div className="data-table-wrap">
          <table className="data-table" data-testid="accounting-report-gl-detail-table">
            <thead><tr><th>JE</th><th>Date</th><th>Account</th><th>Memo</th><th style={{textAlign:'right'}}>Debit</th><th style={{textAlign:'right'}}>Credit</th><th>Source</th></tr></thead>
            <tbody>
              {data.rows.length === 0 && <tr><td colSpan={7} className="empty">No ledger lines in this period.</td></tr>}
              {(data.rows || []).map((r, i) => (
                <tr key={i}>
                  <td><Link to={`/modules/accounting/journal-entries/${r.je_id}`}>{r.je_number}</Link></td>
                  <td>{r.posting_date}</td>
                  <td><AccountLink accountId={r.account_id} accountCode={r.account_code} entityId={r.entity_id}><code>{r.account_code}</code> {r.account_name}</AccountLink></td>
                  <td>{r.line_memo || r.je_memo}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.credit)}</td>
                  <td><span className="badge">{r.source_module}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <ReportPager data={data} page={page} pageSize={pageSize} setPage={setPage} setPageSize={setPageSize}
            loading={loading} testId="accounting-report-gl-pagination" />
        </>
      )}
    </div>
  );
}

// ── Unposted JEs ────────────────────────────────────────────────────────
function Unposted({ scope }) {
  const { page, pageSize, setPage, setPageSize } = useReportPaging(scope.entityId);
  const exportQs = new URLSearchParams({ type: 'unposted_jes', entity_id: String(scope.entityId) }).toString();
  const qs = `${exportQs}&page=${page}&page_size=${pageSize}`;
  const { data: response, loading, error } = useApi(`/modules/accounting/api/standard_reports.php?${qs}`);
  const data = !loading && response?.entity_id === scope.entityId
    && response?.page === page && response?.page_size === pageSize ? response : null;
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv(`/modules/accounting/api/export.php?${exportQs}`, 'unposted-jes.csv')}
        exportTestId="accounting-report-unposted-export"
      />
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <ReportSummary testId="accounting-report-unposted-summary" items={[{ label: 'Unposted entries', value: data.count }]} />
          <div className="data-table-wrap">
          <table className="data-table" data-testid="accounting-report-unposted-table">
            <thead><tr><th>JE</th><th>Date</th><th>Status</th><th>Source</th><th>Memo</th><th style={{textAlign:'right'}}>Debit</th><th style={{textAlign:'right'}}>Credit</th></tr></thead>
            <tbody>
              {data.rows.length === 0 && <tr><td colSpan={7} className="empty">No unposted entries.</td></tr>}
              {(data.rows || []).map(r => (
                <tr key={r.id}>
                  <td>{r.je_number}</td>
                  <td>{r.posting_date}</td>
                  <td><span className="badge">{r.status}</span></td>
                  <td>{r.source_module}</td>
                  <td>{r.memo}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.total_debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.total_credit)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <ReportPager data={data} page={page} pageSize={pageSize} setPage={setPage} setPageSize={setPageSize}
            loading={loading} testId="accounting-report-unposted-pagination" />
        </>
      )}
    </div>
  );
}

// ── Approval Queue ──────────────────────────────────────────────────────
function ApprovalQueue({ scope }) {
  const { page, pageSize, setPage, setPageSize } = useReportPaging(scope.entityId);
  const exportQs = new URLSearchParams({ type: 'approval_queue', entity_id: String(scope.entityId) }).toString();
  const qs = `${exportQs}&page=${page}&page_size=${pageSize}`;
  const { data: response, loading, error } = useApi(`/modules/accounting/api/standard_reports.php?${qs}`);
  const data = !loading && response?.entity_id === scope.entityId
    && response?.page === page && response?.page_size === pageSize ? response : null;
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv(`/modules/accounting/api/export.php?${exportQs}`, 'approval-queue.csv')}
        exportTestId="accounting-report-approval-export"
      />
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <ReportSummary testId="accounting-report-approval-summary" items={[{ label: 'Awaiting approval', value: data.count }]} />
          <div className="data-table-wrap">
          <table className="data-table" data-testid="accounting-report-approval-table">
            <thead><tr><th>JE</th><th>Date</th><th>Source</th><th>Memo</th><th style={{textAlign:'right'}}>Amount</th><th>Created</th></tr></thead>
            <tbody>
              {data.rows.length === 0 && <tr><td colSpan={6} className="empty">No entries awaiting approval.</td></tr>}
              {(data.rows || []).map(r => (
                <tr key={r.id}>
                  <td><Link to={`/modules/accounting/journal-entries/${r.id}`}>{r.je_number}</Link></td>
                  <td>{r.posting_date}</td>
                  <td>{r.source_module}</td>
                  <td>{r.memo}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.total_debit)}</td>
                  <td>{r.created_at}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <ReportPager data={data} page={page} pageSize={pageSize} setPage={setPage} setPageSize={setPageSize}
            loading={loading} testId="accounting-report-approval-pagination" />
        </>
      )}
    </div>
  );
}

// ── Audit Log ───────────────────────────────────────────────────────────
function AuditLog() {
  const [from, setFrom] = useState('');
  const [to, setTo]     = useState('');
  const [eventLike, setEvent] = useState('');
  const { page, pageSize, setPage, setPageSize } = useReportPaging('audit');
  const exportQs = new URLSearchParams({
    type: 'audit_log',
    ...(from ? { from } : {}),
    ...(to   ? { to   } : {}),
    ...(eventLike ? { event_like: eventLike } : {}),
  }).toString();
  const qs = `${exportQs}&page=${page}&page_size=${pageSize}`;
  const { data: response, loading, error } = useApi(`/modules/accounting/api/standard_reports.php?${qs}`);
  const data = !loading && response?.from === from && response?.to === to
    && response?.event_like === eventLike && response?.page === page
    && response?.page_size === pageSize ? response : null;
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv(`/modules/accounting/api/export.php?${exportQs}`, 'audit-log.csv')}
        exportTestId="accounting-report-audit-export"
      >
        <label>From <input type="date" className="input" value={from} onChange={e => { setFrom(e.target.value); setPage(1); }} data-testid="accounting-report-audit-from" /></label>
        <label>To <input type="date" className="input" value={to} onChange={e => { setTo(e.target.value); setPage(1); }} data-testid="accounting-report-audit-to" /></label>
        <label>Event <input className="input" value={eventLike} onChange={e => { setEvent(e.target.value); setPage(1); }} placeholder="e.g. je.posted" data-testid="accounting-report-audit-event" /></label>
      </FilterBar>
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <ReportSummary testId="accounting-report-audit-summary" items={[{ label: 'Accounting events', value: data.count }]} />
          <div className="data-table-wrap">
          <table className="data-table" data-testid="accounting-report-audit-table">
            <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Target</th><th>Meta</th></tr></thead>
            <tbody>
              {data.rows.length === 0 && <tr><td colSpan={5} className="empty">No accounting events in this period.</td></tr>}
              {(data.rows || []).map(r => (
                <tr key={r.id}>
                  <td style={{whiteSpace:'nowrap'}}>{r.created_at}</td>
                  <td><code>{r.event}</code></td>
                  <td>{r.actor_user_id || '—'}</td>
                  <td>{r.target_id || '—'}</td>
                  <td style={{fontSize: 11, fontFamily: 'monospace', maxWidth: 420, overflow: 'hidden', textOverflow: 'ellipsis'}}>
                    {r.meta_json}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <ReportPager data={data} page={page} pageSize={pageSize} setPage={setPage} setPageSize={setPageSize}
            loading={loading} testId="accounting-report-audit-pagination" />
        </>
      )}
    </div>
  );
}

// ── Account Activity ────────────────────────────────────────────────────
function AccountActivity({ scope }) {
  const [code, setCode] = useState('');
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0,10));
  const [to, setTo]     = useState(new Date().toISOString().slice(0,10));
  const { page, pageSize, setPage, setPageSize } = useReportPaging(scope.entityId);
  const exportQs = code ? new URLSearchParams({ type: 'account_activity', code, from, to, entity_id: String(scope.entityId) }).toString() : '';
  const qs = code ? `${exportQs}&page=${page}&page_size=${pageSize}` : '';
  const { data: response, loading, error } = useApi(code ? `/modules/accounting/api/standard_reports.php?${qs}` : null);
  const data = !loading && response?.entity_id === scope.entityId
    && response?.account_code === code && response?.from === from && response?.to === to
    && response?.page === page && response?.page_size === pageSize ? response : null;
  return (
    <div>
      <FilterBar
        onExport={() => code && downloadCsv(`/modules/accounting/api/export.php?${exportQs}`, `account-activity-${code}.csv`)}
        exportTestId="accounting-report-account-export"
      >
        <label>Account code <input className="input" value={code} onChange={e => { setCode(e.target.value); setPage(1); }} placeholder="e.g. 1010" data-testid="accounting-report-account-code" /></label>
        <label>From <input type="date" className="input" value={from} onChange={e => { setFrom(e.target.value); setPage(1); }} data-testid="accounting-report-account-from" /></label>
        <label>To <input type="date" className="input" value={to} onChange={e => { setTo(e.target.value); setPage(1); }} data-testid="accounting-report-account-to" /></label>
      </FilterBar>
      {!code && <div className="report-empty-prompt">Choose an account to view activity.</div>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <ReportSummary
            testId="accounting-report-account-summary"
            items={[
              { label: 'Lines', value: data.count },
              { label: 'Debits', value: fmt(data.total_debit) },
              { label: 'Credits', value: fmt(data.total_credit) },
              { label: 'Ending balance', value: fmt(data.ending_balance) },
            ]}
          />
          <div className="data-table-wrap">
          <table className="data-table" data-testid="accounting-report-account-table">
            <thead><tr><th>JE</th><th>Date</th><th>Memo</th><th style={{textAlign:'right'}}>Debit</th><th style={{textAlign:'right'}}>Credit</th><th style={{textAlign:'right'}}>Running balance</th></tr></thead>
            <tbody>
              {data.rows.length === 0 && <tr><td colSpan={6} className="empty">No activity in this period.</td></tr>}
              {(data.rows || []).map((r, i) => (
                <tr key={i}>
                  <td>{r.je_number}</td>
                  <td>{r.posting_date}</td>
                  <td>{r.memo}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.credit)}</td>
                  <td style={{textAlign:'right', fontWeight: 600}}>{fmt(r.running_balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
          <ReportPager data={data} page={page} pageSize={pageSize} setPage={setPage} setPageSize={setPageSize}
            loading={loading} testId="accounting-report-account-pagination" />
        </>
      )}
    </div>
  );
}

function fmt(n) {
  const v = parseFloat(n);
  if (Number.isNaN(v)) return n || '';
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
