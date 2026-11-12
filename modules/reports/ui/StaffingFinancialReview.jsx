import React from 'react';
import { getPinnedTenantId, useApi } from '../../../dashboard/src/lib/api';
import StaffingReconciliation from '../../treasury/ui/StaffingReconciliation';

export function FinancialReviewContext({ tenantId }) {
  const { data, error, loading, reload } = useApi('/api/staffing_reconciliation.php?action=context', { enabled: !!tenantId });
  const pinned = getPinnedTenantId();
  const current = !!tenantId && String(pinned) === String(tenantId) && String(data?.tenant_id) === String(tenantId);
  if (!tenantId) return <p>Select a company to review its financial evidence.</p>;
  if (loading) return <p>Loading financial review access…</p>;
  if (error) return <p role="alert">This review requires access to accounting, receivables, payables, people, placements, time and payroll in this company. <button className="btn" onClick={reload}>Check access again</button></p>;
  if (!current) return <p role="alert">The company changed. Reopen this report in the intended company.</p>;
  return <StaffingReconciliation tenantId={tenantId} connectionVersion={`report:${tenantId}`} />;
}

export default function StaffingFinancialReview() {
  const tenantId = getPinnedTenantId();
  return <section data-testid="staffing-financial-review">
    <h1>Placement financial review</h1>
    <p>Compare approved Core time with saved invoice and payroll evidence. Missing source facts remain visible in the comparison.</p>
    <FinancialReviewContext key={tenantId || 'no-company'} tenantId={tenantId} />
  </section>;
}
