import React, { useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Activity } from 'lucide-react';
import { useApi } from '../lib/api';
import { fmtMoney } from '../lib/format';
import KpiNote from '../components/KpiNote';

/**
 * Cash Cycle Health tile.
 *
 * Single dashboard card pulled from /api/billing/cash_cycle_health.php.
 * Mid-density: 4 numbers + one "drill in" link. Each stat carries an
 * optional one-line operator note (managers edit; line staff read-only).
 * Quietly hides while loading and on error so it never breaks the home
 * page.
 */
export default function CashCycleHealthTile() {
  const { data, loading, error } = useApi('/api/v1/billing/cash-cycle-health');
  const { data: notesData } = useApi('/api/kpi_notes.php');
  const [notes, setNotes] = useState({});
  const [canWriteNotes, setCanWriteNotes] = useState(false);

  // Hydrate local notes cache once the GET returns. Memoised via React's
  // strict-equality on the response identity from useApi.
  React.useEffect(() => {
    if (!notesData) return;
    setNotes(notesData.notes || {});
    setCanWriteNotes(Boolean(notesData.can_write));
  }, [notesData]);

  const handleNoteSaved = useCallback((key, text) => {
    setNotes(prev => ({
      ...prev,
      [key]: text ? { text, updated_at: new Date().toISOString() } : undefined,
    }));
  }, []);

  // Hide the tile entirely while we wait — the rest of the dashboard
  // can render normally; we don't want to push everything down then
  // pop it up a beat later.
  if (loading || error || !data) return null;

  const dso         = data.dso_days;
  const arOut       = data.ar_outstanding_total || 0;
  const awaiting    = data.pwp_awaiting_ar       || { count: 0, total_amount: 0 };
  const released    = data.pwp_released_last_week|| { count: 0, total_amount: 0, ar_invoice_count: 0 };
  const blocked     = data.weekly_queue_blocked_count || 0;

  // Compact "DSO trend tone": green ≤30, neutral 31-45, amber 46-60, red >60.
  // Conservative defaults if DSO is null (no paid invoices yet).
  const dsoTone = dso == null ? 'neutral'
                : dso <= 30 ? 'good'
                : dso <= 45 ? 'neutral'
                : dso <= 60 ? 'warn'
                            : 'bad';
  const dsoColor = { good: '#16a34a', neutral: '#0f172a', warn: '#a16207', bad: '#dc2626' }[dsoTone];

  return (
    <section className="workspace-panel dashboard-cash-cycle" data-testid="cash-cycle-health-tile">
      <div className="workspace-panel__header">
        <div>
          <span className="workspace-eyebrow"><Activity size={13} aria-hidden="true" /> Cash management</span>
          <h2>Cash cycle health</h2>
        </div>
        <div className="dashboard-cash-cycle__actions">
          <Link to="/modules/billing/money-movement" className="text-link" data-testid="cash-cycle-health-money-movement">
            Weekly digest <ArrowRight size={14} aria-hidden="true" />
          </Link>
          <Link to="/modules/ap/weekly-queue" className="text-link" data-testid="cash-cycle-health-drill-in">
            AP queue <ArrowRight size={14} aria-hidden="true" />
          </Link>
        </div>
      </div>
      <div className="dashboard-cash-cycle__stats">
        <Stat
          label="Days sales outstanding"
          value={dso == null ? '—' : `${dso} d`}
          sub={dso == null ? 'No paid invoices in last 90d' : 'Avg over last 90 days'}
          color={dsoColor}
          testid="cash-cycle-dso"
          noteKey="cash_cycle_dso" note={notes['cash_cycle_dso']} canWrite={canWriteNotes} onNoteSaved={handleNoteSaved}
        />
        <Stat
          label="AR outstanding"
          value={fmtMoney(arOut)}
          sub="Sent + partially paid + overdue"
          color={arOut > 0 ? '#0f172a' : '#16a34a'}
          testid="cash-cycle-ar-outstanding"
          noteKey="cash_cycle_ar" note={notes['cash_cycle_ar']} canWrite={canWriteNotes} onNoteSaved={handleNoteSaved}
        />
        <Stat
          label="PWP bills awaiting AR"
          value={awaiting.count}
          sub={`${fmtMoney(awaiting.total_amount)} gated on client payment`}
          color={awaiting.count > 0 ? '#0891b2' : '#16a34a'}
          testid="cash-cycle-pwp-awaiting"
          noteKey="cash_cycle_pwp_awaiting" note={notes['cash_cycle_pwp_awaiting']} canWrite={canWriteNotes} onNoteSaved={handleNoteSaved}
        />
        <Stat
          label="PWP released (last 7d)"
          value={fmtMoney(released.total_amount)}
          sub={`${released.count} bill(s) freed by ${released.ar_invoice_count} client payment(s)`}
          color={released.total_amount > 0 ? '#16a34a' : '#64748b'}
          testid="cash-cycle-pwp-released"
          noteKey="cash_cycle_pwp_released" note={notes['cash_cycle_pwp_released']} canWrite={canWriteNotes} onNoteSaved={handleNoteSaved}
        />
      </div>
      {blocked > 0 && (
        <div
          data-testid="cash-cycle-blocked-banner"
          className="dashboard-cash-cycle__alert"
        >
          <strong>{blocked}</strong> AP bill{blocked === 1 ? '' : 's'} blocked in the weekly queue —{' '}
          <Link to="/modules/ap/weekly-queue" style={{ color: '#7c2d12', textDecoration: 'underline' }}>
            review and unblock →
          </Link>
        </div>
      )}
    </section>
  );
}

function Stat({ label, value, sub, color, testid, noteKey, note, canWrite, onNoteSaved }) {
  return (
    <div className="cash-cycle-stat" data-testid={testid}>
      <div className="cash-cycle-stat__label">{label}</div>
      <div className="cash-cycle-stat__value" style={{ color }}>{value}</div>
      {sub && <div className="cash-cycle-stat__sub">{sub}</div>}
      {noteKey && <KpiNote noteKey={noteKey} note={note} canWrite={canWrite} onSaved={onNoteSaved} />}
    </div>
  );
}
