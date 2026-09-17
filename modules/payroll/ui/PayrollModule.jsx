import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import ModuleTabs from '../../../dashboard/src/components/ModuleTabs';
import PayrollOverview from './PayrollOverview';
import PaySchedules from './PaySchedules';
import PayCyclesPanel from './PayCyclesPanel';
import PayPeriods from './PayPeriods';
import PayrollProfiles from './PayrollProfiles';
import PayrollProfileEdit from './PayrollProfileEdit';
import PayrollProfilesCsvImport from './PayrollProfilesCsvImport';
import PayrollRuns from './PayrollRuns';
import PayrollRunDetail from './PayrollRunDetail';
import PayrollSettings from './PayrollSettings';
import PayrollAnomalies from './PayrollAnomalies';
import PayStub from './PayStub';

const navItems = [
  { to: '/modules/payroll/overview', label: 'Overview' },
  { to: '/modules/payroll/pay_periods', label: 'Pay periods' },
  { to: '/modules/payroll/runs', label: 'Pay runs' },
  { to: '/modules/payroll/profiles', label: 'Employee setup' },
  { to: '/modules/payroll/pay_schedules', label: 'Schedules' },
  { to: '/modules/payroll/cycles', label: 'Pay groups' },
  { to: '/modules/payroll/anomalies', label: 'Anomalies' },
  { to: '/modules/payroll/settings', label: 'Settings' },
];

/**
 * Payroll Module — React entry. Owns /modules/payroll/* routes.
 *
 * Strict scope: deterministic pay computation, schedules, profiles. AI is
 * narrative-only and rendered through <AISuggestion />.
 */
export default function PayrollModule({ session }) {
  return (
    <div className="people-directory" data-testid="payroll-module">
      <header className="module-workspace-header">
        <span className="workspace-eyebrow">People cost</span>
        <h1>Payroll</h1>
        <p>Prepare employee setup, approved hours, pay runs, and ledger posting.</p>
        <ModuleTabs
          items={navItems.map((item) => ({ ...item, testId: `payroll-nav-${item.label.toLowerCase().replace(/\s+/g, '-')}` }))}
          primaryCount={5}
          label="Payroll sections"
          testId="payroll-section-nav"
        />
      </header>

      <Routes>
        <Route index element={<Navigate to="overview" replace />} />
        <Route path="overview"        element={<PayrollOverview session={session} />} />
        <Route path="dashboard"       element={<Navigate to="../overview" replace />} />
        <Route path="pay_schedules"   element={<PaySchedules session={session} />} />
        <Route path="pay-schedules"   element={<Navigate to="../pay_schedules" replace />} />
        <Route path="schedules"       element={<Navigate to="../pay_schedules" replace />} />
        <Route path="cycles"          element={<PayCyclesPanel session={session} />} />
        <Route path="pay_cycles"      element={<Navigate to="../cycles" replace />} />
        <Route path="anomalies"       element={<PayrollAnomalies session={session} />} />
        <Route path="pay_periods"     element={<PayPeriods session={session} />} />
        <Route path="pay-periods"     element={<Navigate to="../pay_periods" replace />} />
        <Route path="periods"         element={<Navigate to="../pay_periods" replace />} />
        <Route path="profiles"        element={<PayrollProfiles session={session} />} />
        <Route path="profiles/csv_import" element={<PayrollProfilesCsvImport session={session} />} />
        <Route path="profiles/:employeeId" element={<PayrollProfileEdit session={session} />} />
        <Route path="runs"            element={<PayrollRuns session={session} />} />
        <Route path="runs/:runId"     element={<PayrollRunDetail session={session} />} />
        <Route path="stub/:lineId"    element={<PayStub session={session} />} />
        <Route path="settings"        element={<PayrollSettings session={session} />} />
        <Route path="*"               element={<Navigate to="overview" replace />} />
      </Routes>
    </div>
  );
}
