import React from 'react';

/**
 * Standard amber "data not ready yet" banner — used by every report
 * page so the user sees a friendly message instead of a raw 500 or a
 * blank screen when the underlying SQL view / migration hasn't landed.
 *
 * Usage:
 *   {data?.data_warning && <DataWarning text={data.data_warning} />}
 *
 * Carries `data-testid="data-warning"` for e2e assertions.
 */
export default function DataWarning({ text, hint }) {
  if (!text) return null;
  return (
    <div
      data-testid="data-warning"
      style={{
        padding: 14,
        background: '#fffbeb',
        border: '1px solid #fde68a',
        borderRadius: 10,
        marginBottom: 16,
        color: '#92400e',
        fontSize: 13,
        lineHeight: 1.5,
      }}
    >
      <strong>Data not ready yet.</strong> {text}
      {hint && <div style={{ marginTop: 6, color: '#78350f' }}>{hint}</div>}
    </div>
  );
}
