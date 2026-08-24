const TABLE_COLLATOR = new Intl.Collator(undefined, {
  numeric: true,
  sensitivity: 'base',
});

const EMPTY_VALUES = new Set(['', '-', '—', 'n/a', 'none', 'null']);

/**
 * Return the user-visible text for a table cell without leaking layout
 * whitespace into search, filters, or CSV exports.
 */
export function cleanCellText(value) {
  return String(value ?? '')
    .replace(/[\u200B-\u200D\uFEFF]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Stable identifier used for saved column preferences. The index is retained
 * so duplicate labels such as "Status" or blank action columns stay distinct.
 */
export function makeColumnId(label, index) {
  const slug = cleanCellText(label)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '') || 'column';
  return `${index}-${slug}`;
}

/**
 * Infer a comparable value from rendered table text. This intentionally
 * handles the formats used throughout CoreFlux (money, percentages, ISO and
 * US dates) while falling back to locale-aware natural text sorting.
 */
export function sortableCellValue(value) {
  const text = cleanCellText(value);
  if (EMPTY_VALUES.has(text.toLowerCase())) return { type: 'empty', value: null };

  const negativeParens = /^\(.*\)$/.test(text);
  const numericCandidate = text
    .replace(/^\((.*)\)$/, '$1')
    .replace(/[,$£€¥%]/g, '')
    .replace(/\s+(USD|CAD|EUR|GBP|JPY)$/i, '')
    .trim();
  if (/^[+-]?\d+(?:\.\d+)?$/.test(numericCandidate)) {
    const number = Number(numericCandidate) * (negativeParens ? -1 : 1);
    if (Number.isFinite(number)) return { type: 'number', value: number };
  }

  const looksLikeDate =
    /^\d{4}-\d{1,2}-\d{1,2}(?:[T\s].*)?$/.test(text)
    || /^\d{1,2}\/\d{1,2}\/\d{2,4}$/.test(text)
    || /^[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}$/.test(text);
  if (looksLikeDate) {
    const timestamp = Date.parse(text);
    if (!Number.isNaN(timestamp)) return { type: 'date', value: timestamp };
  }

  return { type: 'text', value: text };
}

export function compareCellText(left, right, direction = 'asc') {
  const a = sortableCellValue(left);
  const b = sortableCellValue(right);

  // Empty values remain at the bottom in both directions. This is more useful
  // than promoting missing dates or amounts when a sort is reversed.
  if (a.type === 'empty' && b.type === 'empty') return 0;
  if (a.type === 'empty') return 1;
  if (b.type === 'empty') return -1;

  const multiplier = direction === 'desc' ? -1 : 1;
  if (a.type === b.type && (a.type === 'number' || a.type === 'date')) {
    return (a.value - b.value) * multiplier;
  }
  return TABLE_COLLATOR.compare(String(a.value), String(b.value)) * multiplier;
}

export function escapeCsvCell(value) {
  const text = cleanCellText(value);
  if (!/[",\r\n]/.test(text)) return text;
  return `"${text.replace(/"/g, '""')}"`;
}

export function tableRowsToCsv(headers, rows) {
  const lines = [headers, ...rows]
    .map(row => row.map(escapeCsvCell).join(','));
  return `\uFEFF${lines.join('\r\n')}\r\n`;
}

export function tableExportFileName(pathname, tableLabel, date = new Date()) {
  const routePart = cleanCellText(pathname)
    .replace(/^\/+|\/+$/g, '')
    .replace(/[^a-z0-9]+/gi, '-')
    .replace(/^-|-$/g, '') || 'list';
  const tablePart = cleanCellText(tableLabel)
    .replace(/[^a-z0-9]+/gi, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase();
  const day = date.toISOString().slice(0, 10);
  return `${routePart.toLowerCase()}${tablePart ? `-${tablePart}` : ''}-${day}.csv`;
}
