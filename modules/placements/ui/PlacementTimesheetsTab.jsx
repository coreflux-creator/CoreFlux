import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, FilePlus2 } from 'lucide-react';
import { useApi } from '../../../dashboard/src/lib/api';
import SuggestInvoiceModal from '../../billing/ui/SuggestInvoiceModal';

/**
 * PlacementTimesheetsTab — Batch 2 (2026-02).
 *
 * "Show me every timesheet that touched THIS placement" view, embedded
 * inside PlacementDetail. Splits results into:
 *   - Pending (status='submitted') — needs approval.
 *   - History (everything else) — sortable list.
 *
 * Each row links to the timesheet detail with `?placement_id=N`
 * pre-applied so the operator lands on the placement-scoped view.
 *
 * The "Create new timesheet" button routes to the existing weekly
 * timesheet grid; the worker can then enter hours that include this
 * placement.
 */
export default function PlacementTimesheetsTab({ pid, placement }) {
  const [showSuggest, setShowSuggest] = useState(false);
  const { data, loading, error, reload } = useApi(
    `/modules/staffing/api/timesheets.php?action=list_for_placement&placement_id=${pid}`,
    [pid]
  );
  const rows = data?.rows ?? [];

  const pending = rows.filter(r => r.status === 'submitted');
  const history = rows.filter(r => r.status !== 'submitted');
  const unbilledBillableHours = rows.reduce(
    (sum, row) => sum + (Number(row.unbilled_billable_hours) || 0),
    0
  );
  const canPrepareInvoice = !loading && unbilledBillableHours > 0;
  const weekParams = new URLSearchParams({ placement_id: String(pid) });
  if (placement?.person_id) weekParams.set('person_id', String(placement.person_id));

  return (
    <div data-testid="placement-timesheets-tab">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
        <div>
          <h3 style={{ margin: 0 }}>Timesheets for this placement</h3>
          <p style={{ color: '#666', fontSize: 13, margin: '4px 0 0' }}>
            Review submitted time and prepare invoices from approved, unbilled hours.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => setShowSuggest(true)}
            disabled={!canPrepareInvoice}
            title={canPrepareInvoice
              ? `${unbilledBillableHours.toFixed(2)} approved, unbilled hours available`
              : 'No approved, unbilled time is available for this placement'}
            data-testid="placement-timesheets-suggest-invoice"
          >
            <FilePlus2 size={15} aria-hidden="true" />
            Prepare invoice
          </button>
          <Link
            to={`/modules/staffing/timesheets/week?${weekParams.toString()}`}
            className="btn btn--ghost"
            data-testid="placement-timesheets-create-new"
          >
            Open current week <ArrowRight size={14} aria-hidden="true" />
          </Link>
        </div>
      </header>

      {loading && <p data-testid="placement-timesheets-loading">Loading…</p>}
      {error && <p className="error" data-testid="placement-timesheets-error">{error.message}</p>}

      <h4 style={{ marginTop: 16, fontSize: 13, color: '#475569', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
        Pending approval ({pending.length})
      </h4>
      {pending.length === 0 ? (
        <p style={{ color: '#999' }} data-testid="placement-timesheets-pending-empty">
          No timesheets awaiting approval for this placement.
        </p>
      ) : (
        <SectionTable rows={pending} pid={pid} mode="pending" />
      )}

      <h4 style={{ marginTop: 24, fontSize: 13, color: '#475569', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
        History ({history.length})
      </h4>
      {history.length === 0 ? (
        <p style={{ color: '#999' }} data-testid="placement-timesheets-history-empty">
          No prior timesheets for this placement yet.
        </p>
      ) : (
        <SectionTable rows={history} pid={pid} mode="history" />
      )}

      {showSuggest && (
        <SuggestInvoiceModal
          placementId={pid}
          placementTitle={placement?.title}
          onClose={() => setShowSuggest(false)}
          onCreated={() => { setShowSuggest(false); reload(); }}
        />
      )}
    </div>
  );
}

function SectionTable({ rows, pid, mode }) {
  return (
    <table className="data-table" data-testid={`placement-timesheets-${mode}-table`}>
      <thead>
        <tr>
          <th>ID</th>
          <th>Worker</th>
          <th>Week</th>
          <th>This placement</th>
          <th>Billable</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {rows.map(r => (
          <tr key={r.id} data-testid={`placement-timesheets-${mode}-row-${r.id}`}>
            <td><code>#{r.id}</code></td>
            <td>
              {r.first_name || r.last_name
                ? `${r.first_name || ''} ${r.last_name || ''}`.trim()
                : `Person #${r.person_id}`}
            </td>
            <td style={{ fontSize: 12 }}>{r.period_start} – {r.period_end}</td>
            <td style={{ fontWeight: 600 }}>{Number(r.placement_hours || 0).toFixed(2)}h</td>
            <td>{Number(r.billable_hours || 0).toFixed(2)}h</td>
            <td><StatusBadge status={r.status} /></td>
            <td>
              <Link
                to={`/modules/staffing/timesheets/${r.id}?placement_id=${pid}`}
                className="btn btn--ghost"
                data-testid={`placement-timesheets-open-${r.id}`}
              >
                Open <ArrowRight size={14} aria-hidden="true" />
              </Link>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function StatusBadge({ status }) {
  const colors = {
    draft:         { bg: '#e0e7ff', fg: '#3730a3' },
    submitted:     { bg: '#fef3c7', fg: '#92400e' },
    approved:      { bg: '#dcfce7', fg: '#166534' },
    rejected:      { bg: '#fee2e2', fg: '#991b1b' },
    locked:        { bg: '#e5e7eb', fg: '#374151' },
    payroll_ready: { bg: '#cffafe', fg: '#155e75' },
    billing_ready: { bg: '#fae8ff', fg: '#86198f' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 999,
      background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600,
    }}>{humanizeStatus(status)}</span>
  );
}

function humanizeStatus(status) {
  const labels = {
    draft: 'Draft',
    submitted: 'Submitted for approval',
    approved: 'Approved',
    rejected: 'Needs correction',
    locked: 'Locked',
    payroll_ready: 'Ready for payroll',
    billing_ready: 'Ready for billing',
  };
  if (labels[status]) return labels[status];
  return String(status || 'unknown')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}
