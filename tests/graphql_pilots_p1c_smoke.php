<?php
/**
 * Smoke — GraphQL pilots: ClientsGraphql + PlacementDetailGraphql wiring
 * (P1.c — Migrate remaining Dashboard UI to GraphQL).
 *
 * Read-only side-by-side pilots that mirror the placements/ui/ListGraphql.jsx
 * pattern: same visual scaffolding (GraphQL badge, perf ping, error
 * panel, switch-to-REST link), but read from graphql.corefluxapp.com
 * instead of the REST endpoints. Mutations stay on the REST pages.
 *
 * Validates:
 *   1. Both new components exist + use useGql() instead of useApi().
 *   2. Query shapes reference fields the subgraph actually exposes.
 *   3. Routes wired in PlacementsModule + StaffingModule.
 *   4. CTAs (Switch to GraphQL) added to the REST pages for discoverability.
 *   5. data-testid scaffolding present on every interactive + state-revealing element.
 *   6. Components remain READ-ONLY (no api.post() / api.put() calls).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$clientsGql        = (string) file_get_contents('/app/modules/staffing/ui/ClientsGraphql.jsx');
$placementGql      = (string) file_get_contents('/app/modules/placements/ui/PlacementDetailGraphql.jsx');
$placementsModule  = (string) file_get_contents('/app/modules/placements/ui/PlacementsModule.jsx');
$staffingModule    = (string) file_get_contents('/app/modules/staffing/ui/StaffingModule.jsx');
$clientsRest       = (string) file_get_contents('/app/modules/staffing/ui/Clients.jsx');
$placementDetail   = (string) file_get_contents('/app/modules/placements/ui/PlacementDetail.jsx');
$subgraphSchema    = (string) file_get_contents('/app/graphql/subgraph-coreflux/schema.graphql');

echo "\n1. ClientsGraphql (read-only Companies list pilot)\n";
$a('uses useGql from graphqlClient',
   str_contains($clientsGql, "import { useGql } from '../../../dashboard/src/lib/graphqlClient';"));
$a('does NOT use REST useApi (transport must be GraphQL)',
   !str_contains($clientsGql, 'useApi('));
$a('READ-ONLY: no api.post / api.put / api.del',
   !str_contains($clientsGql, 'api.post(')
   && !str_contains($clientsGql, 'api.put(')
   && !str_contains($clientsGql, 'api.del('));
$a('query selects canonical Company fields',
   str_contains($clientsGql, 'companies(limit: $limit) {')
   && str_contains($clientsGql, 'industry')
   && str_contains($clientsGql, 'billingEmail')
   && str_contains($clientsGql, 'billingAddress { city state country }'));
foreach ([
    'clients-list-gql', 'clients-gql-badge', 'clients-gql-count',
    'clients-gql-search', 'clients-gql-status-filter',
    'clients-gql-refresh', 'clients-gql-switch-rest',
    'clients-gql-table', 'clients-gql-prev', 'clients-gql-next',
    'clients-gql-page-indicator',
] as $tid) {
    $a("testid present: {$tid}", str_contains($clientsGql, "data-testid=\"{$tid}\""));
}

echo "\n2. PlacementDetailGraphql (read-only detail pilot)\n";
$a('uses useGql + useParams',
   str_contains($placementGql, "import { useGql }")
   && str_contains($placementGql, 'useParams'));
$a('does NOT use REST useApi',
   !str_contains($placementGql, 'useApi('));
$a('READ-ONLY: no mutation calls',
   !str_contains($placementGql, 'api.post(')
   && !str_contains($placementGql, 'api.put(')
   && !str_contains($placementGql, 'api.del('));
$a('query selects person + endClient + rates + externalMappings',
   str_contains($placementGql, 'person {')
   && str_contains($placementGql, 'endClient {')
   && str_contains($placementGql, 'rates {')
   && str_contains($placementGql, 'externalMappings {'));
foreach ([
    'placement-detail-gql', 'placement-gql-detail-badge',
    'placement-gql-detail-status', 'placement-gql-detail-refresh',
    'placement-gql-detail-switch-rest',
] as $tid) {
    $a("testid present: {$tid}", str_contains($placementGql, "data-testid=\"{$tid}\""));
}
// Section + Field props get spread into inner data-testid attrs; assert
// the prop-level testid is wired (component does the data-testid emit).
foreach ([
    'placement-gql-detail-engagement', 'placement-gql-detail-rates',
] as $tid) {
    $a("Section testid prop wired: {$tid}",
       str_contains($placementGql, "testid=\"{$tid}\""));
}

echo "\n3. Routes wired\n";
$a('PlacementsModule imports PlacementDetailGraphql',
   str_contains($placementsModule, "import PlacementDetailGraphql from './PlacementDetailGraphql';"));
$a('PlacementsModule mounts :pid/graphql BEFORE :pid/*',
   strpos($placementsModule, 'path=":pid/graphql"') < strpos($placementsModule, 'path=":pid/*"'));
$a('StaffingModule imports ClientsGraphql',
   str_contains($staffingModule, "import ClientsGraphql from './ClientsGraphql';"));
$a('StaffingModule mounts clients-graphql route',
   str_contains($staffingModule, '<Route path="clients-graphql" element={<ClientsGraphql />} />'));

echo "\n4. REST pages expose Switch-to-GraphQL CTA (discoverability)\n";
$a('Clients.jsx has switch-gql link to ../clients-graphql',
   str_contains($clientsRest, 'data-testid="staffing-clients-switch-gql"')
   && str_contains($clientsRest, 'to="../clients-graphql"'));
$a('PlacementDetail.jsx imports Link from react-router-dom',
   str_contains($placementDetail, "useParams, useNavigate, NavLink, Routes, Route, Navigate, Link"));
$a('PlacementDetail.jsx has switch-gql link to graphql sub-route',
   str_contains($placementDetail, 'data-testid="placement-detail-switch-gql"')
   && str_contains($placementDetail, 'to="graphql"'));

echo "\n5. Subgraph schema supports the queries\n";
$a('schema.companies(limit: Int = 50)',
   (bool) preg_match('/companies\(limit: Int = 50\): \[Company!\]!/', $subgraphSchema));
$a('schema.placement(id: ID!)',
   (bool) preg_match('/placement\(id: ID!\): Placement/', $subgraphSchema));
$a('Company.billingAddress is an Address',
   (bool) preg_match('/billingAddress: Address/', $subgraphSchema));
$a('Placement.rates is PlacementRates',
   (bool) preg_match('/rates: PlacementRates/', $subgraphSchema));

echo "\n6. No regression — existing ListGraphql pilot still wired\n";
$a('PlacementsModule keeps list-graphql route',
   str_contains($placementsModule, '<Route path="list-graphql" element={<ListGraphql />} />'));

echo "\n=========================================\n";
echo "GraphQL pilots (Companies + Placement detail) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
