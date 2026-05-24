<?php
/**
 * Mock CoreFlux PHP backend for the GraphQL Federation smoke test.
 *
 * Spawned by tests/graphql_router_e2e_smoke.php under the PHP built-in
 * webserver. Serves the exact URLs the two Apollo subgraphs (coreflux,
 * jobdiva) call, with deterministic fixture data — enough for an end-
 * to-end federated query to come back with real-shaped JSON.
 *
 * What it answers
 * ---------------
 *   GET  /api/placements/placements?id=17           → fixture placement
 *   GET  /api/placements/placements?per_page=N      → list (one item)
 *   GET  /api/placements/placements?person_id=42    → list (one item)
 *   GET  /api/people/people?id=42                   → fixture person
 *   GET  /api/people/companies?id=300               → fixture company
 *   GET  /api/integrations/mappings.php             → external mappings list
 *   POST /api/internal/jobdiva_proxy.php            → fixture JobDiva row
 *   POST /api/internal/mappings_lookup.php          → placement_id → start_id
 *
 * Everything else returns 404 with a marker so smoke failures point
 * straight at a missing fixture.
 */
declare(strict_types=1);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// Single canonical fixture set — every endpoint pulls from these so
// the federation joins make sense (person_id 42 ⇔ placement.person_id,
// end_client_company_id 300 ⇔ company id 300, etc.).
$PLACEMENT = [
    'id'                     => 17,
    'title'                  => 'Senior Service Desk Analyst',
    'status'                 => 'active',
    'start_date'             => '2025-04-01',
    'end_date'               => '2025-12-31',
    'actual_end_date'        => null,
    'due_date'               => null,
    'engagement_type'        => 'w2',
    'remote_policy'          => 'hybrid',
    'worksite_state'         => 'NY',
    'worksite_country'       => 'US',
    'notes'                  => 'Mock smoke fixture.',
    'end_client_name'        => 'Acme Health Systems',
    'client_approver_name'   => 'Jane Approver',
    'client_approver_email'  => 'jane@acme.example',
    'person_id'              => 42,
    'end_client_company_id'  => 300,
    'created_at'             => '2025-03-15T10:00:00Z',
    'updated_at'             => '2025-04-01T08:30:00Z',
];

$PERSON = [
    'id'              => 42,
    'first_name'      => 'Alex',
    'last_name'       => 'Doe',
    'email_primary'   => 'alex@example.com',
    'classification'  => 'contractor',
    'employment_type' => 'w2',
    'status'          => 'active',
    'hire_date'       => '2024-01-15',
    'home_city'       => 'Brooklyn',
    'home_state'      => 'NY',
    'home_country'    => 'US',
    'created_at'      => '2024-01-15T09:00:00Z',
    'updated_at'      => '2025-04-01T08:30:00Z',
];

$COMPANY = [
    'id'              => 300,
    'name'            => 'Acme Health Systems',
    'industry'        => 'Healthcare',
    'website'         => 'https://acme.example',
    'phone'           => '+1-555-0100',
    'billing_email'   => 'ap@acme.example',
    'billing_terms'   => 'Net 30',
    'city'            => 'New York',
    'state'           => 'NY',
    'country'         => 'US',
];

$CURRENT_RATE = [
    'bill_rate'      => '110.00',
    'bill_rate_unit' => 'hour',
    'pay_rate'       => '75.00',
    'pay_rate_unit'  => 'hour',
    'currency'       => 'USD',
    'ot_multiplier'  => '1.5',
    'dt_multiplier'  => '2.0',
    'effective_from' => '2025-04-01',
    'effective_to'   => null,
];

$JOBDIVA_START = [
    'id'                => 5581186,
    'startId'           => 5581186,
    'refNumber'         => '26-03327',
    'status'            => 'Active',
    'startStatus'       => 'Offer Accepted',
    'startDate'         => '2025-04-01',
    'endDate'           => '2025-12-31',
    'submittalDate'     => '2025-03-10',
    'interviewDate'     => '2025-03-20',
    'positionType'      => 'contract',
    'job id'            => 991001,
    'candidate id'      => 992001,
    'customer id'       => 993001,
    'job contact id'    => 994001,
    'rate'              => '110.00',
    'rateUnit'          => 'hour',
    'payRate'           => '75.00',
    'payRateUnit'       => 'hour',
    'currency'          => 'USD',
];

$JOBDIVA_JOB = [
    'id'         => 991001,
    'title'      => 'Service Desk Analyst',
    'refNumber'  => 'JOB-991001',
    'description' => 'Tier 1/2 service desk support.',
    'department' => 'IT Operations',
    'status'     => 'Open',
    'hiringManager' => 'Pat Manager',
];

$JOBDIVA_CANDIDATE = [
    'id'         => 992001,
    'firstName'  => 'Alex',
    'lastName'   => 'Doe',
    'displayName' => 'Alex Doe',
    'email'      => 'alex@example.com',
    'phone'      => '+1-555-0123',
    'city'       => 'Brooklyn',
    'state'      => 'NY',
    'country'    => 'US',
];

$JOBDIVA_CUSTOMER = [
    'id'             => 993001,
    'name'           => 'Acme Health Systems',
    'industry'       => 'Healthcare',
    'city'           => 'New York',
    'state'          => 'NY',
    'country'        => 'US',
    'billingTerms'   => 'Net 30',
];

$JOBDIVA_CONTACT = [
    'id'          => 994001,
    'firstName'   => 'Jane',
    'lastName'    => 'Approver',
    'displayName' => 'Jane Approver',
    'title'       => 'IT Director',
    'email'       => 'jane@acme.example',
    'phone'       => '+1-555-0124',
];

function send(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------------
// PLACEMENTS
// ---------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/placements/placements') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        if ($id !== 17) send(['error' => 'mock: only placement id=17 is fixtured'], 404);
        send([
            'placement'    => $PLACEMENT,
            'current_rate' => $CURRENT_RATE,
        ]);
    }
    // List
    send(['placements' => [$PLACEMENT]]);
}

// ---------------------------------------------------------------------
// PEOPLE
// ---------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/people/people') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        if ($id !== 42) send(['error' => 'mock: only person id=42 is fixtured'], 404);
        send(['person' => $PERSON]);
    }
    send(['people' => [$PERSON]]);
}

// ---------------------------------------------------------------------
// COMPANIES
// ---------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/people/companies') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        if ($id !== 300) send(['error' => 'mock: only company id=300 is fixtured'], 404);
        send(['company' => $COMPANY]);
    }
    send(['companies' => [$COMPANY]]);
}

// ---------------------------------------------------------------------
// EXTERNAL MAPPINGS (CoreFlux subgraph reads via /api/integrations/mappings.php)
// ---------------------------------------------------------------------
if ($method === 'GET' && $path === '/api/integrations/mappings.php') {
    $action     = (string) ($_GET['action'] ?? '');
    $entityType = (string) ($_GET['entity_type'] ?? '');
    $internalId = (int)    ($_GET['internal_id'] ?? 0);
    if ($action === 'list_for_internal' && $entityType === 'placement' && $internalId === 17) {
        send(['mappings' => [[
            'source_system'         => 'jobdiva',
            'internal_entity_type'  => 'placement',
            'external_id'           => '5581186',
            'direction'             => 'pull',
            'last_synced_at'        => '2025-04-01T08:30:00Z',
            'payload_snapshot'      => null,
        ]]]);
    }
    send(['mappings' => []]);
}

// ---------------------------------------------------------------------
// INTERNAL HMAC BRIDGES — they re-verify signature, then return fixture data.
// We do NOT replicate the HMAC check here; the subgraph signs every call
// and the bridge logic is exercised by /tests/internal_hmac_bridge_smoke.php.
// For the e2e smoke we only need to prove the wire shape.
// ---------------------------------------------------------------------
if ($method === 'POST' && $path === '/api/internal/jobdiva_proxy.php') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $jdPath = (string) ($body['path'] ?? '');
    $jdBody = is_array($body['body'] ?? null) ? $body['body'] : [];
    if ($jdPath === '/apiv2/jobdiva/searchStart') {
        send(['ok' => true, 'data' => ['body' => ['data' => [$JOBDIVA_START]]]]);
    }
    if ($jdPath === '/apiv2/jobdiva/searchJob') {
        send(['ok' => true, 'data' => ['body' => ['data' => [$JOBDIVA_JOB]]]]);
    }
    if ($jdPath === '/apiv2/jobdiva/searchCandidate') {
        send(['ok' => true, 'data' => ['body' => ['data' => [$JOBDIVA_CANDIDATE]]]]);
    }
    if ($jdPath === '/apiv2/jobdiva/searchCustomer') {
        send(['ok' => true, 'data' => ['body' => ['data' => [$JOBDIVA_CUSTOMER]]]]);
    }
    if ($jdPath === '/apiv2/jobdiva/searchContact') {
        send(['ok' => true, 'data' => ['body' => ['data' => [$JOBDIVA_CONTACT]]]]);
    }
    send(['ok' => false, 'error' => 'mock: unhandled jobdiva path ' . $jdPath], 404);
}

if ($method === 'POST' && $path === '/api/internal/mappings_lookup.php') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $op   = (string) ($body['op'] ?? '');
    if ($op === 'find_external_by_internal' &&
        (string) ($body['source_system'] ?? '') === 'jobdiva' &&
        (string) ($body['internal_entity_type'] ?? '') === 'placement' &&
        (int)    ($body['internal_entity_id'] ?? 0) === 17) {
        send(['ok' => true, 'external_id' => '5581186']);
    }
    send(['ok' => true, 'external_id' => null]);
}

http_response_code(404);
echo json_encode(['mock_unhandled' => $method . ' ' . $path]);
