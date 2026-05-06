import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import MyTime from './MyTime';
import ReviewQueue from './ReviewQueue';
import Periods from './Periods';
import Reports from './Reports';
import Categories from './Categories';
import CsvImport from './CsvImport';
import TimeSettlement from './TimeSettlement';
import TimesheetUpload from './TimesheetUpload';
import IntakeQueue from './IntakeQueue';

/**
 * Time Module — Phase A routes.
 * Inbox (AI) and Missing Timesheets routes scaffold stubs — full Phase C.
 */
export default function TimeModule({ session }) {
  return (
    <div data-testid="time-module">
      <Routes>
        <Route index            element={<Navigate to="entries" replace />} />
        <Route path="overview"  element={<Navigate to="../entries" replace />} />
        <Route path="entries"   element={<MyTime session={session} />} />
        <Route path="upload"    element={<TimesheetUpload />} />
        <Route path="intake"    element={<IntakeQueue />} />
        <Route path="review"    element={<ReviewQueue session={session} />} />
        <Route path="settlement" element={<TimeSettlement />} />
        <Route path="periods"   element={<Periods session={session} />} />
        <Route path="reports"   element={<Reports />} />
        <Route path="bulk"      element={<CsvImport />} />
        <Route path="categories"element={<Categories />} />
        <Route path="inbox"     element={<ComingSoon title="Inbox (AI)" notes="Phase C: AI email parsing depends on MailService M365/Gmail drivers." />} />
        <Route path="missing"   element={<ComingSoon title="Missing Timesheets" notes="Phase C: combined dashboard of AI-unreadable + expected-not-received placements." />} />
      </Routes>
    </div>
  );
}

function ComingSoon({ title, notes }) {
  return (
    <section className="people-directory" data-testid="time-coming-soon">
      <h2>{title}</h2>
      <p className="empty">{notes}</p>
    </section>
  );
}
