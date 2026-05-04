import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

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
    <section data-testid="accounting-standard-reports">
      <h2 style={{ margin: '0 0 8px' }}>Standard reports</h2>
      <nav style={{ display: 'flex', gap: 8, borderBottom: '1px solid #e5e7eb', marginBottom: 16, flexWrap: 'wrap' }}>
        {[
          ['gl_detail',        'GL Detail',        'accounting-report-tab-gl_detail'],
          ['unposted_jes',     'Unposted JEs',     'accounting-report-tab-unposted_jes'],
          ['approval_queue',   'Approval Queue',   'accounting-report-tab-approval_queue'],
          ['audit_log',        'Audit Log',        'accounting-report-tab-audit_log'],
          ['account_activity', 'Account Activity', 'accounting-report-tab-account_activity'],
        ].map(([k, label, tid]) => (
          <button
            key={k}
            data-testid={tid}
            className={tab === k ? 'tab tab--active' : 'tab'}
            style={{
              padding: '0.5rem 1rem',
              border: 'none',
              background: 'none',
              borderBottom: tab === k ? '2px solid #2563eb' : '2px solid transparent',
              color: tab === k ? '#2563eb' : '#444',
              fontWeight: tab === k ? 600 : 400,
              cursor: 'pointer',
            }}
            onClick={() => setTab(k)}
          >{label}</button>
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
    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
      {children}
      <span style={{ flex: 1 }} />
      {onExport && (
        <button
          className="btn btn--ghost"
          onClick={onExport}
          data-testid={exportTestId}
        >⬇ Export CSV</button>
      )}
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
          <p style={{ fontSize: 13, color: '#444' }} data-testid="accounting-report-gl-summary">
            {data.count} lines · Debits {fmt(data.total_debit)} · Credits {fmt(data.total_credit)}
          </p>
          <table className="data-table" data-testid="accounting-report-gl-detail-table">
            <thead><tr><th>JE</th><th>Date</th><th>Account</th><th>Memo</th><th style={{textAlign:'right'}}>Debit</th><th style={{textAlign:'right'}}>Credit</th><th>Source</th></tr></thead>
            <tbody>
              {(data.rows || []).map((r, i) => (
                <tr key={i}>
                  <td><a href={`#/modules/accounting/journal-entries/${r.je_id}`}>{r.je_number}</a></td>
                  <td>{r.posting_date}</td>
                  <td><code>{r.account_code}</code> {r.account_name}</td>
                  <td>{r.line_memo || r.je_memo}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(r.credit)}</td>
                  <td><span className="badge">{r.source_module}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
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
          <p style={{ fontSize: 13 }} data-testid="accounting-report-unposted-summary">{data.count} entries</p>
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
          <p style={{ fontSize: 13 }} data-testid="accounting-report-approval-summary">{data.count} draft JEs awaiting approval</p>
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
          <p style={{ fontSize: 13 }} data-testid="accounting-report-audit-summary">{data.count} accounting events</p>
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
      {!code && <p style={{color:'#666'}}>Enter an account code to see activity.</p>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <p style={{ fontSize: 13 }} data-testid="accounting-report-account-summary">
            {data.count} lines · Debits {fmt(data.total_debit)} · Credits {fmt(data.total_credit)} ·
            <strong> Ending balance {fmt(data.ending_balance)}</strong>
          </p>
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
