import React from 'react';

/**
 * Rail-cards UI for AP / Payroll settings pages.
 *
 * Renders one card per rail with:
 *   - configured pill (green if env / keys present)
 *   - selected pill (blue if this is the tenant's current default)
 *   - cost badge ($/per-item + percentage)
 *   - settlement-window badge (e.g. "T+1" or "T+0 same-day")
 *   - feature pills (same-day ACH, RTP, pre-approval, funding link required)
 *   - fallback chain ("If declined, falls back to NACHA")
 *   - pros / cons bullet lists
 *
 * Data comes from /core/api/payment_rails.php (paymentRailsList()).
 *
 * Props:
 *   rails:    array from /core/api/payment_rails.php → response.rails
 *   value:    currently-selected rail id (e.g. 'nacha')
 *   onChange: (railId) => void
 *   testIdPrefix: e.g. 'ap-rail' or 'payroll-rail'
 */
export default function RailPicker({ rails, value, onChange, testIdPrefix = 'rail' }) {
  if (!Array.isArray(rails)) return null;
  return (
    <div
      data-testid={`${testIdPrefix}-picker`}
      style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))' }}
    >
      {rails.map((r) => (
        <RailCard
          key={r.id}
          rail={r}
          selected={value === r.id}
          onSelect={() => onChange?.(r.id)}
          testIdPrefix={testIdPrefix}
        />
      ))}
    </div>
  );
}

export function RailCard({ rail, selected, onSelect, testIdPrefix = 'rail' }) {
  const m = rail.metadata || {};
  const cost = formatCost(m.cost_per_item_dollars, m.cost_pct);
  const sd = m.settlement_business_days || {};
  const settlement = sd.min === 0
    ? (sd.max === 0 ? 'T+0 same-day' : `T+0 to T+${sd.max}`)
    : `T+${sd.min}${sd.max && sd.max !== sd.min ? ` to T+${sd.max}` : ''}`;
  return (
    <button
      type="button"
      data-testid={`${testIdPrefix}-card-${rail.id}`}
      onClick={onSelect}
      style={{
        textAlign: 'left',
        cursor: 'pointer',
        padding: 16,
        border: selected ? '2px solid #2563eb' : '1px solid var(--cf-border, #e5e7eb)',
        borderRadius: 8,
        background: selected ? '#eff6ff' : '#fff',
        display: 'flex', flexDirection: 'column', gap: 10,
      }}
    >
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
        <strong style={{ fontSize: 15 }}>{rail.name}</strong>
        <div style={{ display: 'flex', gap: 4 }}>
          {selected && (
            <Pill testid={`${testIdPrefix}-selected-${rail.id}`} bg="#dbeafe" fg="#1e40af">Selected</Pill>
          )}
          {rail.configured
            ? <Pill testid={`${testIdPrefix}-configured-${rail.id}`} bg="#d1fae5" fg="#065f46">Configured</Pill>
            : <Pill testid={`${testIdPrefix}-unconfigured-${rail.id}`} bg="#fef3c7" fg="#92400e">Not configured</Pill>}
        </div>
      </header>

      <p style={{ margin: 0, fontSize: 12, color: 'var(--cf-text-secondary, #6b7280)' }}>{rail.description}</p>

      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <Pill testid={`${testIdPrefix}-cost-${rail.id}`} bg="#f3f4f6" fg="#374151">{cost}</Pill>
        <Pill testid={`${testIdPrefix}-settlement-${rail.id}`} bg="#f3f4f6" fg="#374151">{settlement}</Pill>
        {m.supports_same_day_ach && <Pill testid={`${testIdPrefix}-sda-${rail.id}`} bg="#ede9fe" fg="#5b21b6">Same-day ACH</Pill>}
        {m.supports_rtp           && <Pill testid={`${testIdPrefix}-rtp-${rail.id}`} bg="#ede9fe" fg="#5b21b6">RTP</Pill>}
        {m.needs_pre_approval     && <Pill testid={`${testIdPrefix}-pre-approval-${rail.id}`} bg="#fee2e2" fg="#991b1b">Plaid pre-approval</Pill>}
        {m.needs_funding_link     && <Pill testid={`${testIdPrefix}-funding-${rail.id}`} bg="#fee2e2" fg="#991b1b">Funding link</Pill>}
      </div>

      {m.fallback_to && (
        <p style={{ margin: 0, fontSize: 11, color: 'var(--cf-text-secondary, #6b7280)' }} data-testid={`${testIdPrefix}-fallback-${rail.id}`}>
          If origination fails, falls back to <strong>{m.fallback_to}</strong>.
        </p>
      )}

      {(m.pros?.length || m.cons?.length) ? (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, fontSize: 11 }}>
          <ul data-testid={`${testIdPrefix}-pros-${rail.id}`} style={{ margin: 0, paddingLeft: 16, color: '#065f46' }}>
            {(m.pros || []).map((p, i) => <li key={i}>{p}</li>)}
          </ul>
          <ul data-testid={`${testIdPrefix}-cons-${rail.id}`} style={{ margin: 0, paddingLeft: 16, color: '#991b1b' }}>
            {(m.cons || []).map((c, i) => <li key={i}>{c}</li>)}
          </ul>
        </div>
      ) : null}
    </button>
  );
}

function Pill({ children, bg, fg, testid }) {
  return (
    <span
      data-testid={testid}
      style={{
        display: 'inline-block', padding: '2px 8px', borderRadius: 999,
        background: bg, color: fg, fontSize: 11, fontWeight: 600,
      }}
    >{children}</span>
  );
}

function formatCost(perItem, pct) {
  const items = [];
  if (perItem && perItem > 0) items.push(`$${perItem.toFixed(2)}/item`);
  if (pct && pct > 0)         items.push(`${(pct * 100).toFixed(2)}%`);
  return items.length === 0 ? 'No per-transfer fee' : items.join(' + ');
}
