<?php
/** Regression: shared placements and their contract graph use the same tenant. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/placements/lib/economics.php';

class StaffingScopePDO extends PDO
{
    public array $placementLookups = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new StaffingScopeStatement($this, $query);
    }
}

class StaffingScopeStatement extends PDOStatement
{
    private array|false $row = false;
    public function __construct(private StaffingScopePDO $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $params ??= [];
        if (str_contains($this->sql, 'FROM tenants')) {
            $id = (int) $params['id'];
            $this->row = ['id' => $id, 'parent_id' => $id === 1 ? null : 1,
                'tenant_type' => $id === 1 ? 'master' : 'sub'];
        } elseif (str_contains($this->sql, 'FROM tenant_module_scope')) {
            $this->row = $params['t'] === 3 ? ['scope_mode' => 'isolated'] : false;
        } elseif (str_contains($this->sql, 'FROM placements p')) {
            $this->db->placementLookups[] = $params;
            // No matching placement: the graph must stop, never broaden scope.
            $this->row = false;
        } else {
            throw new RuntimeException('Unexpected query in scope regression');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row;
    }
}

$pdo = new StaffingScopePDO();
$failures = 0;
$assert = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . " - $name\n";
    if (!$ok) $failures++;
};

foreach ([1 => 1, 2 => 1, 3 => 3] as $activeTenant => $graphTenant) {
    setRequestTenantId($activeTenant);
    $_SERVER['REQUEST_URI'] = '/modules/placements/api/economics.php';
    $assert("tenant $activeTenant agrees with placement overview scope",
        placementsGraphTenantId($activeTenant) === effectiveTenantIdForRequest());
    $before = count($pdo->placementLookups);
    $result = placementEconomicsContext($activeTenant, 810);
    $expectedLookup = ['t' => $graphTenant, 'p' => 810,
        'end_company_owner' => $graphTenant, 'bill_company_owner' => $graphTenant];
    $assert("tenant $activeTenant queries only graph tenant $graphTenant",
        count($pdo->placementLookups) === $before + 1
        && end($pdo->placementLookups) === $expectedLookup);
    $assert("tenant $activeTenant preserves not-found and authenticated context",
        !$result['available'] && $result['reconcile']['errors'] === ['placement not found']
        && currentTenantId() === $activeTenant);
    placementEconomicsReconcile($activeTenant, 810);
    $assert("direct reconciliation for tenant $activeTenant uses graph scope",
        end($pdo->placementLookups) === $expectedLookup);
}

foreach (['economics', 'cycles'] as $endpoint) {
    $source = file_get_contents(__DIR__ . "/../modules/placements/api/$endpoint.php");
    $assert("$endpoint reads and writes resolve the placement graph tenant",
        str_contains($source, '$tenantId = (int) placementsGraphTenantId((int) $ctx[\'tenant_id\']);'));
}
$api = file_get_contents(__DIR__ . '/../modules/placements/api/economics.php');
$assert('missing placement returns 404 instead of a migration instruction',
    str_contains($api, "api_error('Placement not found', 404)")
    && !str_contains($api, 'Apply migration 126'));

exit($failures ? 1 : 0);
