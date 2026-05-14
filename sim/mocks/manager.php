<?php
/**
 * Sim Mock Manager — deterministic external-integration mocks.
 *
 * Per harness spec §9: external providers (Plaid, OpenAI, Resend, Gusto)
 * must be fully mocked when running inside the simulation environment.
 * The mocks must:
 *   • Be deterministic — same seed → same response sequence.
 *   • Simulate realistic failure modes (timeouts, duplicates, partial
 *     responses, rate limits).
 *   • Never make real HTTP calls.
 *
 * Activation strategy (opt-in, non-invasive):
 *
 *   1. The sim runner calls simMockEnable($services) before walking a
 *      scenario's steps. This sets a process-global flag.
 *
 *   2. Production service files (core/plaid_service.php, ai_service.php,
 *      mailer.php) can OPT IN to mock-aware behaviour by checking
 *      simShouldMock('<service>') before making the real HTTP call.
 *      Modules that haven't opted in keep working unchanged.
 *
 *   3. Scenarios can request specific failure conditions via the
 *      step payload (e.g. {"plaid_fault": "rate_limit"}); the mock
 *      reads simMockFault('plaid') at call time.
 *
 * The mocks are intentionally standalone so this file can be loaded
 * by tests (no DB dependency).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/seed.php';

$GLOBALS['__sim_mock_enabled'] = [];
$GLOBALS['__sim_mock_faults']  = [];
$GLOBALS['__sim_mock_calls']   = [];   // service => [...] for assertions

const SIM_KNOWN_SERVICES = ['plaid', 'openai', 'resend', 'gusto'];

function simMockEnable(array $services = SIM_KNOWN_SERVICES): void {
    foreach ($services as $s) $GLOBALS['__sim_mock_enabled'][$s] = true;
}

function simMockDisable(?string $service = null): void {
    if ($service === null) {
        $GLOBALS['__sim_mock_enabled'] = [];
        $GLOBALS['__sim_mock_faults']  = [];
        $GLOBALS['__sim_mock_calls']   = [];
        return;
    }
    unset($GLOBALS['__sim_mock_enabled'][$service]);
}

function simShouldMock(string $service): bool {
    // Explicit enable flag.
    if (!empty($GLOBALS['__sim_mock_enabled'][$service])) return true;
    // Env override (CLI use, CI, etc.)
    if (getenv('SIM_MOCK_' . strtoupper($service)) === '1') return true;
    if (getenv('SIM_MODE') === '1') return true;
    return false;
}

/** Inject a fault for the NEXT call to a service. One-shot — consumed
 *  on read so the next call resets to normal. Faults supported:
 *    'rate_limit' (429), 'timeout' (network), 'server_error' (500),
 *    'malformed' (invalid JSON), 'partial' (truncated payload). */
function simMockSetFault(string $service, string $fault): void {
    $GLOBALS['__sim_mock_faults'][$service] = $fault;
}
function simMockConsumeFault(string $service): ?string {
    $f = $GLOBALS['__sim_mock_faults'][$service] ?? null;
    unset($GLOBALS['__sim_mock_faults'][$service]);
    return $f;
}

/** Telemetry — every mock invocation appends a row so assertions can
 *  check "exactly N calls to service X" or "no calls to service Y". */
function simMockRecordCall(string $service, string $op, array $payload, $response): void {
    $GLOBALS['__sim_mock_calls'][] = [
        'service'      => $service,
        'op'           => $op,
        'payload_hash' => simHash($payload),
        'response_hash'=> simHash($response),
        'recorded_at'  => simNow('Y-m-d H:i:s.u'),
    ];
}

function simMockCalls(?string $service = null): array {
    if ($service === null) return $GLOBALS['__sim_mock_calls'];
    return array_values(array_filter($GLOBALS['__sim_mock_calls'],
        fn ($c) => $c['service'] === $service));
}

function simMockReset(): void {
    $GLOBALS['__sim_mock_calls']  = [];
    $GLOBALS['__sim_mock_faults'] = [];
}

/** Raise the appropriate exception/response shape for a fault. */
function simMockApplyFault(string $service, ?string $fault) {
    if ($fault === null) return null;
    switch ($fault) {
        case 'rate_limit':
            throw new \RuntimeException("[sim/{$service}] Mocked 429 rate_limit_exceeded");
        case 'timeout':
            throw new \RuntimeException("[sim/{$service}] Mocked network timeout");
        case 'server_error':
            throw new \RuntimeException("[sim/{$service}] Mocked 500 internal_server_error");
        case 'malformed':
            return ['__malformed' => true, 'raw' => '<<not json>>'];
        case 'partial':
            return ['__partial' => true];
        default:
            throw new \RuntimeException("[sim/{$service}] Unknown fault: {$fault}");
    }
}
