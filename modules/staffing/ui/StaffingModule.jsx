import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import TimesheetWeek from './TimesheetWeek';
import TimesheetsList from './TimesheetsList';
import TimesheetDetail from './TimesheetDetail';
import TimesheetLifecycle from './TimesheetLifecycle';
import StaffingOverview from './StaffingOverview';
import StaffingApprovals from './StaffingApprovals';
import StaffingSettings from './StaffingSettings';
import StaffingProfitability from './StaffingProfitability';
import Clients from './Clients';
import ClientsGraphql from './ClientsGraphql';
import ClientsCsvImport from './ClientsCsvImport';
import StaffingReadiness from './StaffingReadiness';
import Jobs from './Jobs';

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
 *
 * Phase 2 — Batch 2 (2026-02): timesheets is now a proper list/detail
 *   sub-router so operators can drill into individual timesheets and
 *   land on a placement-scoped detail view from the placement page.
 */
export default function StaffingModule({ session }) {
  return (
    <div data-testid="staffing-module">
      <Routes>
        <Route index               element={<Navigate to="overview" replace />} />
        <Route path="overview"     element={<StaffingOverview session={session} />} />
        <Route path="timesheets"            element={<TimesheetsList session={session} />} />
        <Route path="timesheets/week"       element={<TimesheetWeek session={session} />} />
        <Route path="timesheets/:id"        element={<TimesheetDetail session={session} />} />
        <Route path="timesheets/:id/lifecycle" element={<TimesheetLifecycle session={session} />} />
        <Route path="approvals/*"  element={<StaffingApprovals session={session} />} />
        <Route path="placements/*" element={<PlacementsModule session={session} />} />
        <Route path="settings"     element={<StaffingSettings session={session} />} />
        <Route path="clients"      element={<Clients />} />
        <Route path="clients-graphql" element={<ClientsGraphql />} />
        <Route path="clients/csv_import" element={<ClientsCsvImport />} />
        <Route path="jobs"         element={<Jobs />} />
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
