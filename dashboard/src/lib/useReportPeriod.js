// CoreFlux — shared period/comparison state hook for report views.
//
// Owns {from, to, compareMode} and derives the prior-period + prior-year
// windows so every report has identical comparison semantics.
//
// Usage:
//   const period = useReportPeriod({ defaultFrom: '2026-01-01', defaultTo: '2026-02-28' });
//   period.from, period.to                      → current window
//   period.priorFrom, period.priorTo            → preceding window of equal length
//   period.priorYearFrom, period.priorYearTo    → same window 1 year back
//   period.compareMode                          → 'none' | 'prior_period' | 'prior_year' | 'both'
//   period.setFrom / setTo / setCompareMode
//   period.windowDays                           → integer days in current window
//
// Reusable from any Tier-1/2/3 report.
import { useMemo, useState } from 'react';

function daysBetween(from, to) {
  const a = new Date(from + 'T00:00:00');
  const b = new Date(to   + 'T00:00:00');
  return Math.max(1, Math.round((b - a) / 86400000) + 1);
}

function shiftDays(iso, days) {
  const d = new Date(iso + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function shiftYears(iso, years) {
  const d = new Date(iso + 'T00:00:00');
  d.setFullYear(d.getFullYear() + years);
  return d.toISOString().slice(0, 10);
}

export function useReportPeriod({ defaultFrom, defaultTo, defaultCompare = 'both' } = {}) {
  const today = new Date().toISOString().slice(0, 10);
  const yearStart = `${today.slice(0, 4)}-01-01`;
  const [from, setFrom] = useState(defaultFrom || yearStart);
  const [to,   setTo]   = useState(defaultTo   || today);
  const [compareMode, setCompareMode] = useState(defaultCompare);

  const derived = useMemo(() => {
    const days = daysBetween(from, to);
    return {
      windowDays:    days,
      priorTo:       shiftDays(from, -1),
      priorFrom:     shiftDays(from, -days),
      priorYearFrom: shiftYears(from, -1),
      priorYearTo:   shiftYears(to,   -1),
    };
  }, [from, to]);

  return {
    from, to, setFrom, setTo,
    compareMode, setCompareMode,
    ...derived,
    showPriorPeriod: compareMode === 'prior_period' || compareMode === 'both',
    showPriorYear:   compareMode === 'prior_year'   || compareMode === 'both',
  };
}

/**
 * Variance helper — used by ComparisonTable + MetricCard.
 * Returns { abs, pct, direction: 'up'|'down'|'flat', color }.
 * Treats "favourable" as up by default; for expense lines pass `inverse=true`
 * so cost reductions render green.
 */
export function variance(curr, prior, { inverse = false } = {}) {
  const c = Number(curr || 0);
  const p = Number(prior || 0);
  const abs = c - p;
  const pct = p === 0 ? (c === 0 ? 0 : null) : (abs / Math.abs(p)) * 100;
  const direction = Math.abs(abs) < 0.005 ? 'flat' : (abs > 0 ? 'up' : 'down');
  const favourable = direction === 'flat' ? null
    : (inverse ? direction === 'down' : direction === 'up');
  const color = direction === 'flat' ? '#64748b'
              : favourable           ? '#16a34a'
                                     : '#dc2626';
  return { abs, pct, direction, color, favourable };
}
