import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import PayrollOverview from './PayrollOverview';
import PaySchedules from './PaySchedules';
import PayCyclesPanel from './PayCyclesPanel';
import PayPeriods from './PayPeriods';
import PayrollProfiles from './PayrollProfiles';
import PayrollProfileEdit from './PayrollProfileEdit';
import PayrollRuns from './PayrollRuns';
import PayrollRunDetail from './PayrollRunDetail';
import PayrollSettings from './PayrollSettings';
import PayrollAnomalies from './PayrollAnomalies';
import PayStub from './PayStub';

/**
 * Payroll Module — React entry. Owns /modules/payroll/* routes.
 *
 * Strict scope: deterministic pay computation, schedules, profiles. AI is
 * narrative-only and rendered through <AISuggestion />.
 */
export default function PayrollModule({ session }) {
  return (
    <Routes>
      <Route index element={<Navigate to="overview" replace />} />
      <Route path="overview"        element={<PayrollOverview session={session} />} />
      <Route path="pay_schedules"   element={<PaySchedules session={session} />} />
      <Route path="pay-schedules"   element={<Navigate to="../pay_schedules" replace />} />
      <Route path="cycles"          element={<PayCyclesPanel session={session} />} />
      <Route path="pay_cycles"      element={<Navigate to="../cycles" replace />} />
      <Route path="anomalies"       element={<PayrollAnomalies session={session} />} />
      <Route path="pay_periods"     element={<PayPeriods session={session} />} />
      <Route path="pay-periods"     element={<Navigate to="../pay_periods" replace />} />
      <Route path="profiles"        element={<PayrollProfiles session={session} />} />
      <Route path="profiles/:employeeId" element={<PayrollProfileEdit session={session} />} />
      <Route path="runs"            element={<PayrollRuns session={session} />} />
      <Route path="runs/:runId"     element={<PayrollRunDetail session={session} />} />
      <Route path="stub/:lineId"    element={<PayStub session={session} />} />
      <Route path="settings"        element={<PayrollSettings session={session} />} />
      <Route path="*"               element={<Navigate to="overview" replace />} />
    </Routes>
  );
}
