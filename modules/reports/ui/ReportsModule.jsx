import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import StaffingOverview from './StaffingOverview';
import ExecutiveSnapshot from './ExecutiveSnapshot';
import ClientProfitability from './ClientProfitability';
import RateSpreadMonitor from './RateSpreadMonitor';
import OvertimeWatch from './OvertimeWatch';

/**
 * Reports Module — Phase 1.
 * Industry-aware analytics layered on the v_timesheet_day_fin view.
 *
 * Routes:
 *   /modules/reports/overview             — Staffing Overview dashboard
 *   /modules/reports/executive_snapshot   — leadership-ready summary
 *   /modules/reports/client_profitability — per-client margin table
 *   /modules/reports/rate_spread          — per-placement rate + spread
 *   /modules/reports/overtime_watch       — OT exposure + trend
 *   (custom + other directories ship in Phase 2)
 *
 * Sprint 6f — dropped the inner ReportsSidebar wrapper. The global
 * left rail (`Sidebar.jsx`) already lists every Reports route via
 * `core/modules.php`; the inner duplicate sidebar made the page look
 * like it had two stacked Reports menus. Per-item icons are now driven
 * by the Sidebar.jsx iconMap, so the variety we liked from the inner
 * version stays — it just lives in the global rail now.
 */
export default function ReportsModule({ session }) {
  return (
    <div data-testid="reports-module" style={{ padding: 'var(--cf-space-6)' }}>
      <Routes>
        <Route index element={<Navigate to="overview" replace />} />
        <Route path="overview"             element={<StaffingOverview    session={session} />} />
        <Route path="executive_snapshot"   element={<ExecutiveSnapshot   session={session} />} />
        <Route path="client_profitability" element={<ClientProfitability session={session} />} />
        <Route path="rate_spread"          element={<RateSpreadMonitor   session={session} />} />
        <Route path="overtime_watch"       element={<OvertimeWatch       session={session} />} />
        {/* legacy redirects from prior /exec /finance /staffing routes */}
        <Route path="exec"     element={<Navigate to="../executive_snapshot" replace />} />
        <Route path="finance"  element={<Navigate to="../overview" replace />} />
        <Route path="staffing" element={<Navigate to="../overview" replace />} />
        <Route path="custom"   element={<ComingSoon title="Custom Report Builder" notes="Phase 2: drag-drop field catalog, group/filter/sort, calculated fields, save + export." />} />
        <Route path="other"    element={<ComingSoon title="Other Reports" notes="Phase 2: full report directory across industries with category chips and search." />} />
      </Routes>
    </div>
  );
}

function ComingSoon({ title, notes }) {
  return (
    <section data-testid="reports-coming-soon" className="people-directory">
      <h2>{title}</h2>
      <p className="empty">{notes}</p>
    </section>
  );
}
