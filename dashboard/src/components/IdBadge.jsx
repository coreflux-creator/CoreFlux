import React, { useState } from 'react';

/**
 * <IdBadge id={1042} prefix="PL" />
 *
 * Renders a small mono-spaced pill showing a row's numeric ID with a
 * one-click "copy" affordance. Operators paste these into CSV
 * imports (placement_id / person_id columns) instead of relying on
 * email lookups — eliminates the typo + hidden-Unicode bug class.
 *
 * Optional `prefix` is presentational only (PL-1042 reads better than
 * a bare 1042 in a busy table). The clipboard always copies the bare
 * integer so the CSV cell stays clean.
 *
 * data-testid: id-badge-{prefix?}-{id}
 */
export default function IdBadge({ id, prefix, title }) {
  const [copied, setCopied] = useState(false);
  if (id == null) return <span style={{ color: '#94a3b8' }}>—</span>;
  const display = prefix ? `${prefix}-${id}` : String(id);
  const testid = prefix ? `id-badge-${prefix.toLowerCase()}-${id}` : `id-badge-${id}`;
  const handleCopy = async (e) => {
    e.preventDefault();
    e.stopPropagation();
    try {
      await navigator.clipboard.writeText(String(id));
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* clipboard blocked — silent */ }
  };
  return (
    <button
      type="button"
      onClick={handleCopy}
      data-testid={testid}
      title={title || `Copy ID ${id} to clipboard (for CSV imports)`}
      style={{
        all: 'unset',
        cursor: 'pointer',
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        fontSize: 11,
        color: copied ? '#15803d' : '#475569',
        background: copied ? '#dcfce7' : '#f1f5f9',
        border: `1px solid ${copied ? '#86efac' : '#cbd5e1'}`,
        borderRadius: 4,
        padding: '1px 6px',
        display: 'inline-flex', alignItems: 'center', gap: 4,
        transition: 'background-color 120ms ease, color 120ms ease',
      }}
    >
      {copied ? '✓ copied' : display}
    </button>
  );
}
