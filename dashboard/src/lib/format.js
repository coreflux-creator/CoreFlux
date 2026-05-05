// CoreFlux — shared display formatters.
// Keep this file dependency-free so any module can import without bloat.

/**
 * Money — always 2dp, locale-aware grouping, USD by default. Accepts strings
 * (e.g. "1234.5"), numbers, or null/undefined (returns "—").
 *   fmtMoney(1234.5)        → "$1,234.50"
 *   fmtMoney(-1234.5)       → "-$1,234.50"
 *   fmtMoney("1234.5", 'EUR') → "€1,234.50"
 *   fmtMoney(null)          → "—"
 */
export function fmtMoney(v, currency = 'USD') {
  if (v === null || v === undefined || v === '') return '—';
  const n = typeof v === 'number' ? v : parseFloat(v);
  if (!Number.isFinite(n)) return '—';
  return n.toLocaleString(undefined, { style: 'currency', currency });
}

/**
 * Date (no time) — "Apr 29, 2026" form. Accepts ISO date string ("2026-04-29"),
 * ISO datetime, Date, or epoch ms. Returns "—" on bad input.
 */
export function fmtDate(v) {
  if (!v) return '—';
  // Treat plain "YYYY-MM-DD" as a wall date — avoid the JS UTC midnight
  // shift that bumps Apr-29 to Apr-28 in negative-UTC zones.
  if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v)) {
    const [y, m, d] = v.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    return dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }
  const dt = v instanceof Date ? v : new Date(v);
  if (isNaN(dt.getTime())) return '—';
  return dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * Date + time — "Apr 29, 2026, 2:25 PM". Accepts the same inputs as fmtDate.
 */
export function fmtDateTime(v) {
  if (!v) return '—';
  const dt = v instanceof Date ? v : new Date(v);
  if (isNaN(dt.getTime())) return '—';
  return dt.toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit',
  });
}

/**
 * Compact "x ago" relative time — "5m ago", "2h ago", "yesterday", "Apr 29".
 * Falls back to absolute fmtDate for anything older than 7 days.
 */
export function fmtRelative(v) {
  if (!v) return '—';
  const dt = v instanceof Date ? v : new Date(v);
  if (isNaN(dt.getTime())) return '—';
  const diffMs = Date.now() - dt.getTime();
  const sec = Math.round(diffMs / 1000);
  if (sec < 60)        return `${Math.max(sec, 1)}s ago`;
  const min = Math.round(sec / 60);
  if (min < 60)        return `${min}m ago`;
  const hr = Math.round(min / 60);
  if (hr  < 24)        return `${hr}h ago`;
  const day = Math.round(hr / 24);
  if (day === 1)       return 'yesterday';
  if (day  < 7)        return `${day}d ago`;
  return fmtDate(dt);
}
