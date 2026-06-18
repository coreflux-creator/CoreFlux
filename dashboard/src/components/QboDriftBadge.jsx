import React, { useEffect, useState, useMemo } from 'react';
import { api } from '../lib/api';

/**
 * QboDriftBadge — small chip rendered next to a CoreFlux bill/invoice
 * row to surface QBO-side state and open drift.
 *
 * Usage:
 *   const driftMap = useQboDriftBadges('bill', rowIds);   // batch fetch
 *   <QboDriftBadge entry={driftMap[row.id]} />
 *
 * Renders:
 *   • Nothing if entry is undefined or null (not seen in QBO).
 *   • Solid colored pill if a drift_kind exists ('Paid in QBO',
 *     'QBO partial', 'Voided in QBO').
 *   • Subtle gray "QBO synced" pill if shadow exists but no drift.
 */
export function QboDriftBadge({ entry }) {
  if (!entry) return null;

  const { kind, severity, summary, qbo_status, qbo_balance_cents, qbo_total_amount_cents } = entry;

  // No drift: a tiny "synced" indicator, useful so operators know we
  // CAN see the QBO state (vs the "no badge at all" case which means
  // we've never seen this row in QBO).
  if (!kind) {
    return (
      <span
        data-testid="qbo-drift-badge-synced"
        title={`Last seen in QBO as ${qbo_status || 'open'} • balance ${(qbo_balance_cents ?? 0) / 100}`}
        style={badgeStyle('#f3f4f6', '#6b7280')}
      >
        QBO synced
      </span>
    );
  }

  const palette = {
    critical: { bg: '#fee2e2', fg: '#991b1b' },
    warn:     { bg: '#fef3c7', fg: '#92400e' },
    info:     { bg: '#dbeafe', fg: '#1e40af' },
  }[severity] || { bg: '#f3f4f6', fg: '#374151' };

  const label = {
    paid_out_of_band: 'Paid in QBO',
    balance_changed:  'QBO partial',
    voided_in_qbo:    'Voided in QBO',
    amount_changed:   'QBO amount differs',
    qbo_only_orphan:  'QBO-only',
  }[kind] || `QBO ${kind}`;

  return (
    <span
      data-testid={`qbo-drift-badge-${kind}`}
      title={summary || `${label} — see Integration triage`}
      style={badgeStyle(palette.bg, palette.fg)}
    >
      {label}
    </span>
  );
}

/**
 * useQboDriftBadges — batch-fetch drift snapshots for an array of ids.
 *
 * Returns a stable map (string keys) so consumers can `driftMap[row.id]`.
 * Refetches whenever `ids` changes shape.
 */
export function useQboDriftBadges(type /* 'bill' | 'invoice' */, ids) {
  const [map, setMap] = useState({});
  const idsKey = useMemo(() => (ids || []).filter(Boolean).join(','), [ids]);

  useEffect(() => {
    if (!idsKey) {
      setMap({});
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const r = await api.get(
          `/api/admin/qbo/drift_badges.php?type=${type}&ids=${encodeURIComponent(idsKey)}`,
        );
        if (!cancelled) setMap(r?.items || {});
      } catch (_e) {
        // Drift is a non-critical decoration — failing silently
        // is correct UX so the host list still renders.
        if (!cancelled) setMap({});
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [type, idsKey]);

  return map;
}

function badgeStyle(bg, fg) {
  return {
    display: 'inline-block',
    padding: '1px 6px',
    borderRadius: 4,
    background: bg,
    color: fg,
    fontSize: 10,
    fontWeight: 600,
    marginLeft: 6,
    verticalAlign: 'middle',
  };
}
