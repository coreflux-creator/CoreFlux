import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import EmployeeDirectory from './EmployeeDirectory';
import EmployeeDetail from './EmployeeDetail';
import EmployeeCreate from './EmployeeCreate';
import OrgChart from './OrgChart';

/**
 * People Module — React entry
 * Owns /modules/people/* routes.
 */
export default function PeopleModule({ session }) {
  return (
    <Routes>
      <Route index element={<Navigate to="directory" replace />} />
      <Route path="overview"   element={<Navigate to="../directory" replace />} />
      <Route path="directory"  element={<EmployeeDirectory session={session} />} />
      <Route path="new"        element={<EmployeeCreate session={session} />} />
      <Route path="org_chart"  element={<OrgChart session={session} />} />
      <Route path=":employeeId/*" element={<EmployeeDetail session={session} />} />
    </Routes>
  );
}
