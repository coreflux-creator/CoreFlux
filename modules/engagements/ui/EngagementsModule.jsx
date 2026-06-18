import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import EngagementsList from './EngagementsList';
import EngagementDetail from './EngagementDetail';

/**
 * EngagementsModule — top-level router for the engagements UI.
 */
export default function EngagementsModule(/* { session } */) {
  return (
    <Routes>
      <Route index element={<EngagementsList />} />
      <Route path="list" element={<EngagementsList />} />
      <Route path=":id" element={<EngagementDetail />} />
      <Route path="*" element={<Navigate to="." replace />} />
    </Routes>
  );
}
