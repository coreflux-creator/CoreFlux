import React from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Activity } from 'lucide-react';
import { Section } from '../components/UIComponents';
import { useApi } from '../lib/api';
import { fmtMoney } from '../lib/format';

/**
 * Cash Cycle Health tile.
 *
 * Single dashboard card pulled from /api/billing/cash_cycle_health.php.
 * Mid-density: 4 numbers + one "drill in" link. Quietly hides while
 * loading and on error so it never breaks the home page.
 */
export default function CashCycleHealthTile() {
  const { data, loading, error } = useApi('/modules/billing/api/cash_cycle_health.php');

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
    <Section
      title={<span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}><Activity size={16} /> Cash cycle health</span>}
      action={
        <Link to="/modules/ap/weekly-queue" className="btn btn--ghost" data-testid="cash-cycle-health-drill-in" style={{ fontSize: 13 }}>
          Open AP weekly queue <ArrowRight size={14} />
        </Link>
      }
    >
      <div
        data-testid="cash-cycle-health-tile"
        style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}
      >
        <Stat
          label="Days sales outstanding"
          value={dso == null ? '—' : `${dso} d`}
          sub={dso == null ? 'No paid invoices in last 90d' : 'Avg over last 90 days'}
          color={dsoColor}
          testid="cash-cycle-dso"
        />
        <Stat
          label="AR outstanding"
          value={fmtMoney(arOut)}
          sub="Sent + partially paid + overdue"
          color={arOut > 0 ? '#0f172a' : '#16a34a'}
          testid="cash-cycle-ar-outstanding"
        />
        <Stat
          label="PWP bills awaiting AR"
          value={awaiting.count}
          sub={`${fmtMoney(awaiting.total_amount)} gated on client payment`}
          color={awaiting.count > 0 ? '#0891b2' : '#16a34a'}
          testid="cash-cycle-pwp-awaiting"
        />
        <Stat
          label="PWP released (last 7d)"
          value={fmtMoney(released.total_amount)}
          sub={`${released.count} bill(s) freed by ${released.ar_invoice_count} client payment(s)`}
          color={released.total_amount > 0 ? '#16a34a' : '#64748b'}
          testid="cash-cycle-pwp-released"
        />
      </div>
      {blocked > 0 && (
        <div
          data-testid="cash-cycle-blocked-banner"
          style={{
            marginTop: 12, padding: '10px 14px', borderRadius: 6, background: '#fef3c7',
            borderLeft: '4px solid #a16207', color: '#7c2d12', fontSize: 13,
          }}
        >
          <strong>{blocked}</strong> AP bill{blocked === 1 ? '' : 's'} blocked in the weekly queue —{' '}
          <Link to="/modules/ap/weekly-queue" style={{ color: '#7c2d12', textDecoration: 'underline' }}>
            review and unblock →
          </Link>
        </div>
      )}
    </Section>
  );
}

function Stat({ label, value, sub, color, testid }) {
  return (
    <div
      data-testid={testid}
      style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '14px 16px' }}
    >
      <div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.4, color: 'var(--cf-text-secondary)', marginBottom: 4 }}>
        {label}
      </div>
      <div style={{ fontSize: 22, fontWeight: 700, color }}>{value}</div>
      {sub && <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{sub}</div>}
    </div>
  );
}
