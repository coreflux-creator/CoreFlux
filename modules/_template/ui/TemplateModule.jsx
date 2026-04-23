import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';

/**
 * Template Module — React entry
 * Copy to: /dashboard/src/modules/<ModuleName>Module.jsx
 *
 * The module owns its own routes under `/modules/<id>/*`. All API calls go
 * through the shared `api` client so the same auth + error handling applies.
 */

const Overview = () => (
  <div className="module-view">
    <h2>Template Overview</h2>
    <p>Replace this with the module's landing content.</p>
  </div>
);

export default function TemplateModule() {
  return (
    <Routes>
      <Route index element={<Navigate to="overview" replace />} />
      <Route path="overview" element={<Overview />} />
      {/* <Route path="records" element={<RecordsPage />} /> */}
    </Routes>
  );
}
