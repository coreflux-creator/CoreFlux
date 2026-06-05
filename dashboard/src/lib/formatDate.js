/**
 * Date formatting — single source of truth.
 *
 * CoreFlux UI standard is MM/DD/YYYY everywhere a date is rendered.
 * Backends emit a mix of ISO-8601 dates ('2026-02-14'), full ISO
 * timestamps ('2026-02-14T09:30:00Z'), and SQL datetimes
 * ('2026-02-14 09:30:00'). This helper handles all three shapes and
 * always falls back to a safe em-dash so a missing date never blows
 * up a row render.
 *
 * Why not toLocaleDateString('en-US')? Two reasons:
 *  1. It depends on the browser locale and can drift to MM/DD/YY in
 *     some envs, which breaks audit screenshots.
 *  2. It implicitly converts in the user's local timezone — a bill
 *     dated 2026-02-14 in the DB would render as 2026-02-13 for a
 *     user in PT on the morning of the 14th. We want pure date
 *     formatting with no TZ shift.
 */

const EM_DASH = '—';

/**
 * Strict MM/DD/YYYY formatter with no timezone math.
 *
 * @param  input  String (ISO date / ISO timestamp / SQL datetime) or Date.
 * @param  opts.fallback  String to return for empty/invalid input. Default '—'.
 * @returns A `MM/DD/YYYY` string, or the fallback.
 */
export function fmtDate(input, { fallback = EM_DASH } = {}) {
  if (input === null || input === undefined || input === '') return fallback;

  // Date instance — read the calendar parts directly, no TZ math.
  if (input instanceof Date) {
    if (Number.isNaN(input.getTime())) return fallback;
    return _mmddyyyy(input.getMonth() + 1, input.getDate(), input.getFullYear());
  }

  if (typeof input !== 'string') return fallback;
  const s = input.trim();
  if (!s) return fallback;

  // Match `YYYY-MM-DD` at the start — works for both pure dates and
  // ISO/SQL timestamps. Pure-string parse means no implicit local
  // timezone shift.
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) {
    const yyyy = Number(m[1]);
    const mm = Number(m[2]);
    const dd = Number(m[3]);
    if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return fallback;
    return _mmddyyyy(mm, dd, yyyy);
  }

  // Last-resort: let Date parse it. Used for non-ISO strings emitted by
  // exotic legacy endpoints.
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) return fallback;
  return _mmddyyyy(d.getMonth() + 1, d.getDate(), d.getFullYear());
}

/**
 * Same as fmtDate but appends the time of day as 'HH:MM' when the
 * input carries a time portion. For empty/dateless input returns the
 * fallback unchanged.
 */
export function fmtDateTime(input, { fallback = EM_DASH } = {}) {
  if (input === null || input === undefined || input === '') return fallback;
  if (typeof input === 'string') {
    const s = input.trim();
    // 'YYYY-MM-DD HH:MM' or 'YYYY-MM-DDTHH:MM' — extract the time.
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (m) {
      const datePart = fmtDate(s, { fallback });
      if (datePart === fallback) return fallback;
      return `${datePart} ${m[4]}:${m[5]}`;
    }
  }
  return fmtDate(input, { fallback });
}

function _mmddyyyy(mm, dd, yyyy) {
  return `${String(mm).padStart(2, '0')}/${String(dd).padStart(2, '0')}/${yyyy}`;
}
