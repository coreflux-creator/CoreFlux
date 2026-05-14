<?php
/**
 * Sim Mock Bridge (Phase H2.5 — 2026-02-XX).
 *
 * Lets production service files (core/plaid_service.php,
 * core/ai_service.php, core/mailer.php) check for sim mocking WITHOUT
 * hard-linking to the sim/ tree. If the sim manager hasn't been loaded
 * (production), we return false — the call falls through to the real
 * provider as normal.
 *
 * The contract:
 *   • If a sim-flagged tenant is active OR env SIM_MODE=1 is set, the
 *     bridge returns true and the caller routes to the mock.
 *   • Otherwise returns false and the real HTTP call runs.
 *
 * Auto-loads the sim/mocks/manager.php lazily (only when the bridge
 * has reason to think sim mocking COULD apply — env var or sim tenant).
 */
declare(strict_types=1);

function simShouldMockIfLoaded(string $service): bool {
    // Fast path: manager already loaded in this process.
    if (function_exists('simShouldMock')) {
        return simShouldMock($service);
    }
    // Env-flag escape hatch — works in CLI runner + CI without sim tenant.
    if (getenv('SIM_MODE') === '1' || getenv('SIM_MOCK_' . strtoupper($service)) === '1') {
        require_once __DIR__ . '/../sim/mocks/manager.php';
        return simShouldMock($service);
    }
    // Per-tenant flag: only worth checking when a tenant is in scope.
    if (function_exists('currentTenantId') && function_exists('getDB')) {
        $tid = (int) currentTenantId();
        if ($tid > 0) {
            static $cache = [];
            if (!isset($cache[$tid])) {
                try {
                    $pdo = getDB();
                    if ($pdo) {
                        $stmt = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
                        $stmt->execute(['id' => $tid]);
                        $cache[$tid] = (int) $stmt->fetchColumn() === 1;
                    } else { $cache[$tid] = false; }
                } catch (\Throwable $_) { $cache[$tid] = false; }
            }
            if ($cache[$tid]) {
                require_once __DIR__ . '/../sim/mocks/manager.php';
                simMockEnable([$service]);
                return true;
            }
        }
    }
    return false;
}
