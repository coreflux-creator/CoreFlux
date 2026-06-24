import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import List from './List';
import ListGraphql from './ListGraphql';
import Expiring from './Expiring';
import PlacementCreate from './PlacementCreate';
import PlacementDetail from './PlacementDetail';
import PlacementDetailGraphql from './PlacementDetailGraphql';
import Reports from './Reports';
import CsvImport from './CsvImport';
import DraftRatesQueue from './DraftRatesQueue';
import CustomFields from './CustomFields';

/**
 * Placements module entry — SPEC §7 routes.
 * 'commissions' and 'referrals' actions in manifest are tenant-wide overviews,
 * mapped here to filtered list views. Per-placement editing happens inside
 * PlacementDetail tabs.
 */
export default function PlacementsModule({ session }) {
  return (
    <div data-testid="placements-module">
      <Routes>
        <Route index             element={<Navigate to="list" replace />} />
        <Route path="overview"   element={<Navigate to="../list" replace />} />
        <Route path="list"       element={<List session={session} />} />
        <Route path="list-graphql" element={<ListGraphql />} />
        <Route path="expiring"   element={<Expiring />} />
        <Route path="new"        element={<PlacementCreate />} />
        <Route path="csv_import" element={<CsvImport />} />
        <Route path="draft-rates" element={<DraftRatesQueue />} />
        <Route path="custom_fields" element={<CustomFields />} />
        <Route path="reports"    element={<Reports />} />
        <Route path="commissions"element={<List session={session} commissionsView />} />
        <Route path="referrals"  element={<List session={session} referralsView />} />
        <Route path=":pid/graphql" element={<PlacementDetailGraphql />} />
        <Route path=":pid/*"     element={<PlacementDetail session={session} />} />
      </Routes>
    </div>
  );
}
