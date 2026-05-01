import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import Directory from './Directory';
import PersonCreate from './PersonCreate';
import PersonDetail from './PersonDetail';
import Pipeline from './Pipeline';
import DocumentVault from './DocumentVault';
import CustomFields from './CustomFields';
import PIIAuditLog from './PIIAuditLog';
import CsvImport from './CsvImport';
import ClientsModule from './ClientsModule';
import VendorsModule from './VendorsModule';
import CompaniesMerge from './CompaniesMerge';

/**
 * People Module — React entry (SPEC-aligned)
 *
 * Routes match manifest.php sidebar actions and SPEC §6.
 * Legacy components (EmployeeDirectory, EmployeeDetail, etc.) are preserved
 * under /app/legacy/people_pre_spec_(date)/ per HARD_RULES R1.
 */
export default function PeopleModule({ session }) {
  return (
    <div data-testid="people-module">
      <Routes>
        <Route index                element={<Navigate to="directory" replace />} />
        <Route path="overview"      element={<Navigate to="../directory" replace />} />
        <Route path="directory"     element={<Directory      session={session} />} />
        <Route path="new"           element={<PersonCreate   session={session} />} />
        <Route path="csv_import"    element={<CsvImport      session={session} />} />
        <Route path="pipeline"      element={<Pipeline       session={session} />} />
        <Route path="documents"     element={<DocumentVault  session={session} />} />
        <Route path="custom_fields" element={<CustomFields   session={session} />} />
        <Route path="clients/*"     element={<ClientsModule  session={session} />} />
        <Route path="vendors/*"     element={<VendorsModule  session={session} />} />
        <Route path="merge"         element={<CompaniesMerge session={session} />} />
        <Route path="audit_pii"     element={<PIIAuditLog    session={session} />} />
        <Route path=":personId/*"   element={<PersonDetail   session={session} />} />
      </Routes>
    </div>
  );
}
