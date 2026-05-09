import React, { useState } from 'react';
import { useApi } from '../lib/api';
import { TrendingDown, TrendingUp, AlertTriangle, Wallet } from 'lucide-react';

/**
 * Liquidity Forecast page (P2).
 * Renders a 30/60/90-day cash projection: starting cash → daily inflows/
 * outflows → ending balance + runway-to-zero alert. Reads
 * /api/liquidity_forecast.php.
 */
export default function LiquidityForecast() {
  const [days, setDays] = useState(90);
  const { data, loading, error } = useApi(`/api/liquidity_forecast.php?days=${days}`);

  if (loading) return <p data-testid="liquidity-loading">Loading forecast…</p>;
  if (error)   return <p className="error" data-testid="liquidity-error">{error.message}</p>;

  const totals = data?.totals || {};
  const daily  = data?.daily  || [];
  const guards = data?.guards || {};
  const fmt = (n) => (n == null ? '—' : '$' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 }));

  // Sparkline-ish bar viz: each day's closing balance scaled within a min/max band.
  const closings = daily.map(d => d.closing);
  const lo = Math.min(...closings, 0);
  const hi = Math.max(...closings, 0);
  const span = hi - lo || 1;
  const zeroPct = ((0 - lo) / span) * 100;

  return (
    <section data-testid="liquidity-forecast-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <Wallet size={20} color="#0e7490" /> Liquidity Forecast
          </h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Projected daily cash position from scheduled inflows (open AR) and outflows (scheduled treasury payments + open AP).
          </p>
        </div>
        <select data-testid="liquidity-window-select"
                value={days} onChange={e => setDays(parseInt(e.target.value, 10))}
                className="input" style={{ fontSize: 13, padding: '4px 8px' }}>
          <option value={30}>30 days</option>
          <option value={60}>60 days</option>
          <option value={90}>90 days</option>
          <option value={180}>180 days</option>
        </select>
      </header>

      {!guards.has_bank_accounts && (
        <div data-testid="liquidity-no-banks"
             style={{ padding: 12, background: '#fef3c7', border: '1px solid #fde68a', borderRadius: 8, fontSize: 13, color: '#92400e' }}>
          No active bank accounts configured — starting cash is computed at $0. Add bank accounts under Accounting → Bank Accounts for an accurate forecast.
        </div>
      )}

      {totals.runway_days_to_zero != null && (
        <div data-testid="liquidity-runway-alert"
             style={{ padding: 14, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 10, display: 'flex', alignItems: 'flex-start', gap: 10 }}>
          <AlertTriangle size={18} color="#dc2626" />
          <div>
            <strong style={{ color: '#991b1b' }}>Cash runs out in {totals.runway_days_to_zero} day{totals.runway_days_to_zero === 1 ? '' : 's'}</strong>
            <div style={{ fontSize: 12, color: '#7f1d1d', marginTop: 2 }}>Lowest projected balance: {fmt(totals.lowest_balance)} on {totals.lowest_balance_date}.</div>
          </div>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 12 }}>
        <Tile testId="liquidity-tile-starting" label="Starting cash" value={fmt(data.starting_cash)} />
        <Tile testId="liquidity-tile-inflows"  label={`Inflows (${days}d)`}  value={fmt(totals.total_inflows)}  trend="up" />
        <Tile testId="liquidity-tile-outflows" label={`Outflows (${days}d)`} value={fmt(totals.total_outflows)} trend="down" />
        <Tile testId="liquidity-tile-ending"   label="Projected ending" value={fmt(totals.ending_cash)} highlight />
        <Tile testId="liquidity-tile-lowest"   label="Lowest balance" value={fmt(totals.lowest_balance)} subtitle={totals.lowest_balance_date} />
      </div>

      <div data-testid="liquidity-daily-card"
           style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
        <strong style={{ fontSize: 14 }}>Daily projection</strong>
        <div style={{ position: 'relative', height: 120, margin: '12px 0', borderTop: '1px dashed #cbd5e1', borderBottom: '1px solid #e2e8f0' }}>
          {/* zero line */}
          {lo < 0 && (
            <div style={{ position: 'absolute', left: 0, right: 0, bottom: `${zeroPct}%`, borderTop: '1px dashed #ef4444', fontSize: 10, color: '#ef4444', paddingLeft: 4 }}>$0</div>
          )}
          <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%', gap: 1 }}>
            {daily.map((d, i) => {
              const h = ((d.closing - lo) / span) * 100;
              const negative = d.closing < 0;
              return (
                <div key={i} title={`${d.date}: ${fmt(d.closing)}`}
                     data-testid={`liquidity-daily-bar-${i}`}
                     style={{ flex: 1, height: `${h}%`, background: negative ? '#ef4444' : '#0ea5e9',
                              opacity: i === 0 ? 1 : 0.7, minHeight: 1 }} />
              );
            })}
          </div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#64748b' }}>
          <span>{daily[0]?.date}</span>
          <span>{daily[daily.length - 1]?.date}</span>
        </div>
      </div>
    </section>
  );
}

function Tile({ testId, label, value, trend, highlight, subtitle }) {
  const Icon = trend === 'up' ? TrendingUp : trend === 'down' ? TrendingDown : null;
  const color = trend === 'up' ? '#059669' : trend === 'down' ? '#dc2626' : '#0e7490';
  return (
    <div data-testid={testId}
         style={{ padding: 14, background: highlight ? '#ecfeff' : '#fff',
                  border: `1px solid ${highlight ? '#a5f3fc' : '#e2e8f0'}`, borderRadius: 10 }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, color, marginTop: 4, display: 'flex', alignItems: 'center', gap: 4 }}>
        {Icon && <Icon size={16} />} {value}
      </div>
      {subtitle && <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>{subtitle}</div>}
    </div>
  );
}
