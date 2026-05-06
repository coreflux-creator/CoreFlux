import React from 'react';

/**
 * PeriodSelector — shared dropdown for time-period filters.
 * Matches Reports.docx §Header Area: default 4 weeks, full set of preset codes.
 */
const OPTIONS = [
  { code: '1w',           label: '1 week' },
  { code: '2w',           label: '2 weeks' },
  { code: '4w',           label: '4 weeks' },
  { code: '8w',           label: '8 weeks' },
  { code: '12w',          label: '12 weeks' },
  { code: 'mtd',          label: 'Month to date' },
  { code: 'last_month',   label: 'Last month' },
  { code: 'qtd',          label: 'Quarter to date' },
  { code: 'last_quarter', label: 'Last quarter' },
  { code: 'ytd',          label: 'Year to date' },
  { code: 'last_12m',     label: 'Last 12 months' },
  { code: 'last_year',    label: 'Last year' },
];

export default function PeriodSelector({ value, onChange, testid = 'reports-period-selector' }) {
  return (
    <select
      className="input"
      value={value || '4w'}
      onChange={(e) => onChange(e.target.value)}
      data-testid={testid}
      style={{ maxWidth: 220 }}
    >
      {OPTIONS.map((o) => (
        <option key={o.code} value={o.code}>{o.label}</option>
      ))}
    </select>
  );
}

export { OPTIONS as PERIOD_OPTIONS };
