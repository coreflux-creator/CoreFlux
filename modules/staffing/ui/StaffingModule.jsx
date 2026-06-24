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

// Workbench shortcut into the source Placements module. Staffing composes this
// surface for operators; it does not own placement records.
import PlacementsModule from '../../placements/ui/PlacementsModule';

/**
 * CoreStaffing workbench module.
 *
 * Staffing owns operating views, queues, and KPIs. Source objects stay with
 * People, Placements, Time, Payroll, Billing, Accounting, Treasury, and Reports.
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
        <Route index element={<Navigate to="overview" replace />} />
        <Route path="overview" element={<StaffingOverview session={session} />} />
        <Route path="timesheets" element={<TimesheetsList session={session} />} />
        <Route path="timesheets/week" element={<TimesheetWeek session={session} />} />
        <Route path="timesheets/:id" element={<TimesheetDetail session={session} />} />
        <Route path="approvals/*" element={<StaffingApprovals session={session} />} />
        <Route path="placements/*" element={<PlacementsModule session={session} />} />
        <Route path="settings" element={<StaffingSettings session={session} />} />
        <Route path="clients" element={<Clients />} />
        <Route path="clients-graphql" element={<ClientsGraphql />} />
        <Route path="clients/csv_import" element={<ClientsCsvImport />} />
        <Route path="jobs" element={<ComingSoon title="Jobs / Roles" phase="2" />} />
        <Route path="profitability/*" element={<StaffingProfitability session={session} />} />
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
      <p className="empty">Phase {phase} feature. See <code>/app/memory/PRD.md</code> CoreStaffing MVP roadmap.</p>
    </section>
  );
}
