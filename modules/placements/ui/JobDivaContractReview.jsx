import React from 'react';

const handlingLabels = {
  retain_core: 'Retain Core value',
  core_unchanged: 'Core-managed · unchanged',
  source_proposal: 'Source proposal',
  unchanged: 'No difference',
  review_required: 'Ownership needs review',
};
const valueText = value => value === null || value === undefined || value === ''
  ? '—' : typeof value === 'boolean' ? (value ? 'Yes' : 'No') : String(value);

export default function JobDivaContractReview({ fields = [], testId }) {
  return (
    <table style={{ width: '100%', marginTop: 6, fontSize: 12, borderCollapse: 'collapse' }} data-testid={testId}>
      <thead><tr>
        <th style={{ textAlign: 'left' }}>Core field</th>
        <th style={{ textAlign: 'left' }}>Current Core value</th>
        <th style={{ textAlign: 'left' }}>JobDiva value</th>
        <th style={{ textAlign: 'left' }}>Handling</th>
      </tr></thead>
      <tbody>{fields.map(field => (
        <tr key={`${field.group}-${field.field}`} style={{ background: field.handling === 'retain_core' ? '#eff6ff' : field.changes ? '#fff7ed' : 'transparent' }}>
          <td style={{ padding: 5 }}><span style={{ color: '#64748b' }}>{field.group}</span><br /><strong>{field.label}</strong></td>
          <td style={{ padding: 5 }}>{valueText(field.current)}</td>
          <td style={{ padding: 5 }}>{valueText(field.proposed)}</td>
          <td style={{ padding: 5 }}>
            <strong>{handlingLabels[field.handling] || 'Refresh preview to review ownership'}</strong>
            {field.handling === 'retain_core' && <div>Reviewed Core ownership is preserved.</div>}
          </td>
        </tr>
      ))}</tbody>
    </table>
  );
}
