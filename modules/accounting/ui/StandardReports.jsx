import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import AccountLink from '../../../dashboard/src/components/AccountLink';
import {
  Activity, BarChart3, ClipboardCheck, Download, FileClock, ScrollText,
} from 'lucide-react';

const REPORT_TABS = [
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
export default function StandardReports() {
  const [tab, setTab] = useState('gl_detail');
  return (
    <section className="report-page" data-testid="accounting-standard-reports">
      <header className="report-page__header">
        <div className="report-page__title">
          <span className="report-page__icon" aria-hidden="true"><BarChart3 size={18} /></span>
          <div>
            <h2>Standard reports</h2>
            <p className="report-page__meta">Five operational ledger views</p>
          </div>
        </div>
      </header>
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
      {tab === 'gl_detail'        && <GlDetail />}
      {tab === 'unposted_jes'     && <Unposted />}
      {tab === 'approval_queue'   && <ApprovalQueue />}
      {tab === 'audit_log'        && <AuditLog />}
      {tab === 'account_activity' && <AccountActivity />}
    </section>
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
function GlDetail() {
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0,10));
  const [to, setTo]     = useState(new Date().toISOString().slice(0,10));
  const [code, setCode] = useState('');
  const qs  = new URLSearchParams({ type: 'gl_detail', from, to, ...(code ? { account_code: code } : {}) }).toString();
  const { data, loading, error } = useApi(`/modules/accounting/api/standard_reports.php?${qs}`);
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv(`/modules/accounting/api/export.php?${qs}`, `gl-detail-${from}-${to}.csv`)}
        exportTestId="accounting-report-gl-detail-export"
      >
        <label>From <input type="date" className="input" value={from} onChange={e => setFrom(e.target.value)} data-testid="accounting-report-gl-from" /></label>
        <label>To <input type="date" className="input" value={to} onChange={e => setTo(e.target.value)} data-testid="accounting-report-gl-to" /></label>
        <label>Account code <input className="input" value={code} onChange={e => setCode(e.target.value)} placeholder="(all)" data-testid="accounting-report-gl-code" /></label>
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
              {(data.rows || []).map((r, i) => (
                <tr key={i}>
                  <td><a href={`#/modules/accounting/journal-entries/${r.je_id}`}>{r.je_number}</a></td>
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
        </>
      )}
    </div>
  );
}

// ── Unposted JEs ────────────────────────────────────────────────────────
function Unposted() {
  const { data, loading, error } = useApi('/modules/accounting/api/standard_reports.php?type=unposted_jes');
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv('/modules/accounting/api/export.php?type=unposted_jes', 'unposted-jes.csv')}
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
        </>
      )}
    </div>
  );
}

// ── Approval Queue ──────────────────────────────────────────────────────
function ApprovalQueue() {
  const { data, loading, error } = useApi('/modules/accounting/api/standard_reports.php?type=approval_queue');
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv('/modules/accounting/api/export.php?type=approval_queue', 'approval-queue.csv')}
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
              {(data.rows || []).map(r => (
                <tr key={r.id}>
                  <td><a href={`#/modules/accounting/journal-entries/${r.id}`}>{r.je_number}</a></td>
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
  const qs = new URLSearchParams({
    type: 'audit_log',
    ...(from ? { from } : {}),
    ...(to   ? { to   } : {}),
    ...(eventLike ? { event_like: eventLike } : {}),
  }).toString();
  const { data, loading, error } = useApi(`/modules/accounting/api/standard_reports.php?${qs}`);
  return (
    <div>
      <FilterBar
        onExport={() => downloadCsv(`/modules/accounting/api/export.php?${qs}`, 'audit-log.csv')}
        exportTestId="accounting-report-audit-export"
      >
        <label>From <input type="date" className="input" value={from} onChange={e => setFrom(e.target.value)} data-testid="accounting-report-audit-from" /></label>
        <label>To <input type="date" className="input" value={to} onChange={e => setTo(e.target.value)} data-testid="accounting-report-audit-to" /></label>
        <label>Event <input className="input" value={eventLike} onChange={e => setEvent(e.target.value)} placeholder="e.g. je.posted" data-testid="accounting-report-audit-event" /></label>
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
        </>
      )}
    </div>
  );
}

// ── Account Activity ────────────────────────────────────────────────────
function AccountActivity() {
  const [code, setCode] = useState('');
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0,10));
  const [to, setTo]     = useState(new Date().toISOString().slice(0,10));
  const qs = code ? new URLSearchParams({ type: 'account_activity', code, from, to }).toString() : '';
  const { data, loading, error } = useApi(code ? `/modules/accounting/api/standard_reports.php?${qs}` : null);
  return (
    <div>
      <FilterBar
        onExport={() => code && downloadCsv(`/modules/accounting/api/export.php?${qs}`, `account-activity-${code}.csv`)}
        exportTestId="accounting-report-account-export"
      >
        <label>Account code <input className="input" value={code} onChange={e => setCode(e.target.value)} placeholder="e.g. 1010" data-testid="accounting-report-account-code" /></label>
        <label>From <input type="date" className="input" value={from} onChange={e => setFrom(e.target.value)} data-testid="accounting-report-account-from" /></label>
        <label>To <input type="date" className="input" value={to} onChange={e => setTo(e.target.value)} data-testid="accounting-report-account-to" /></label>
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
