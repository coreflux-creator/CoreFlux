import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import TimesheetWeek from './TimesheetWeek';
import StaffingOverview from './StaffingOverview';
import StaffingApprovals from './StaffingApprovals';
import StaffingSettings from './StaffingSettings';
import StaffingProfitability from './StaffingProfitability';
import Clients from './Clients';
import ClientsCsvImport from './ClientsCsvImport';
import StaffingReadiness from './StaffingReadiness';

// Reuse existing module pages — they already exist and work; we're just
// re-homing them under the Staffing umbrella per the CoreStaffing spec.
import PlacementsModule from '../../placements/ui/PlacementsModule';

/**
 * CoreStaffing umbrella module.
 *
 * Spec: /app/memory/PRD.md → CoreStaffing MVP (Feb 2026).
 *
 * Phase 1 (this sprint): module shell + weekly timesheet grid.
 * Reuses existing Placements UI verbatim. Clients/Jobs/Readiness pages
 * are "coming soon" stubs that ship in Phase 2.
 */
export default function StaffingModule({ session }) {
  return (
    <div data-testid="staffing-module">
      <Routes>
        <Route index               element={<Navigate to="overview" replace />} />
        <Route path="overview"     element={<StaffingOverview session={session} />} />
        <Route path="timesheets/*" element={<TimesheetWeek session={session} />} />
        <Route path="approvals/*"  element={<StaffingApprovals session={session} />} />
        <Route path="placements/*" element={<PlacementsModule session={session} />} />
        <Route path="settings"     element={<StaffingSettings session={session} />} />
        <Route path="clients"      element={<Clients />} />
        <Route path="clients/csv_import" element={<ClientsCsvImport />} />
        <Route path="jobs"         element={<ComingSoon title="Jobs / Roles"      phase="2" />} />
        <Route path="profitability/*"  element={<StaffingProfitability session={session} />} />
        <Route path="payroll-readiness" element={<StaffingReadiness mode="payroll" />} />
        <Route path="billing-readiness" element={<StaffingReadiness mode="billing" />} />
      </Routes>
    </div>
  );
}

function ComingSoon({ title, phase }) {
  return (
    <section className="people-directory" data-testid={`staffing-coming-soon-${title.toLowerCase().replace(/\s+/g, '-')}`}>
      <h2>{title}</h2>
      <p className="empty">Phase {phase} feature — not yet shipped. See <code>/app/memory/PRD.md</code> CoreStaffing MVP roadmap.</p>
    </section>
  );
}
