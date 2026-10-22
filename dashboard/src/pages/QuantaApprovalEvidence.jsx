import React from 'react';

export default function QuantaApprovalEvidence({ value }) {
  let proof = value;
  try { if (typeof proof === 'string') proof = JSON.parse(proof); } catch { proof = null; }
  if (!proof || proof.version !== 1) {
    return <div style={{ fontSize: 12, marginTop: 5 }}>Source approval details were not captured.</div>;
  }
  const label = value => typeof value === 'string' && value ? value : 'Not reported';
  return <details style={{ fontSize: 12, marginTop: 5 }}>
    <summary>Quanta approval details</summary>
    <dl style={{ margin: '8px 0', display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '4px 12px' }}>
      <dt>Source status</dt><dd style={{ margin: 0 }}>{label(proof.timesheet_status)}</dd>
      <dt>Approved by</dt><dd style={{ margin: 0 }}>{label(proof.approved_by_email)}</dd>
      <dt>Approved at</dt><dd style={{ margin: 0 }}>{label(proof.approved_at)}</dd>
      <dt>Source timesheet</dt><dd style={{ margin: 0 }}>{label(proof.timesheet_id)}</dd>
      <dt>Entry updated</dt><dd style={{ margin: 0 }}>{label(proof.entry_updated_at)}</dd>
    </dl>
    <p style={{ margin: '6px 0' }}>Reported by Quanta. Core approval is a separate review.</p>
  </details>;
}
