import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import EngagementsList from './EngagementsList';

/**
 * EngagementsModule — top-level router for the engagements UI.
 *
 * Only one surface today (the list page); detail page is rolled into
 * the list via the inline expansion / create modal. A dedicated
 * /modules/engagements/:id detail page can be added later without
 * touching this routing shell.
 */
export default function EngagementsModule(/* { session } */) {
  return (
    <Routes>
      <Route index element={<EngagementsList />} />
      <Route path="list" element={<EngagementsList />} />
      <Route path="*" element={<Navigate to="." replace />} />
    </Routes>
  );
}
