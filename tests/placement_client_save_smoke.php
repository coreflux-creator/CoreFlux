<?php
/** Placement client creation preserves country defaults and catalog ownership. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/placements/lib/placements.php';
require_once __DIR__ . '/../modules/staffing/lib/clients.php';

class PlacementClientSavePDO extends PDO
{
    public array $companies = [];
    public array $clients = [];
    public int $lastId = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PlacementClientSaveStatement($this, $query);
    }
    public function lastInsertId(?string $name = null): string|false { return (string) $this->lastId; }
}

class PlacementClientSaveStatement extends PDOStatement
{
    private array|false $row = false;
    public function __construct(private PlacementClientSavePDO $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $p = $params ?? [];
        $this->row = false;
        if (str_contains($this->sql, 'FROM tenants')) {
            $id = (int) $p['id'];
            $this->row = ['id' => $id, 'parent_id' => $id === 1 ? null : 1,
                'tenant_type' => $id === 1 ? 'master' : 'sub'];
        } elseif (str_contains($this->sql, 'FROM tenant_module_scope')) {
            $this->row = $p['t'] === 3 ? ['scope_mode' => 'isolated'] : false;
        } elseif (str_contains($this->sql, 'INSERT INTO `companies`')) {
            if (($p['country'] ?? null) === null || trim($p['country']) === '') {
                throw new RuntimeException('Company country must not be null or blank');
            }
            $this->db->lastId = count($this->db->companies) + 1;
            $this->db->companies[$this->db->lastId] = ['id' => $this->db->lastId] + $p;
        } elseif (str_contains($this->sql, 'UPDATE companies SET')) {
            $id = $p['id'];
            if ($this->db->companies[$id]['tenant_id'] !== $p['tenant_id']) {
                throw new RuntimeException('Cross-tenant company update');
            }
            unset($p['id'], $p['tenant_id']);
            $this->db->companies[$id] = array_replace($this->db->companies[$id], $p);
        } elseif (str_contains($this->sql, 'FROM companies')) {
            foreach ($this->db->companies as $row) {
                if ($row['tenant_id'] === $p['t']
                    && (!isset($p['n']) || $row['name'] === $p['n'])
                    && (!isset($p['id']) || $row['id'] === $p['id'])) {
                    $this->row = $row;
                    break;
                }
            }
        } elseif (str_contains($this->sql, 'INSERT IGNORE INTO company_roles')) {
            // Roles are not material to the country/default and identity cases.
        } elseif (str_contains($this->sql, 'INSERT INTO staffing_clients')) {
            $this->db->lastId = count($this->db->clients) + 1;
            $this->db->clients[$this->db->lastId] = ['id' => $this->db->lastId] + $p;
        } elseif (str_contains($this->sql, 'FROM staffing_clients')) {
            foreach ($this->db->clients as $row) {
                if ($row['tenant_id'] === ($p['tenant_id'] ?? $p['t'])
                    && (!isset($p['company_id']) || $row['company_id'] === $p['company_id'])
                    && (!isset($p['n']) || $row['name'] === $p['n'])) {
                    $this->row = $row;
                    break;
                }
            }
        } else {
            throw new RuntimeException('Unexpected query in client save regression: ' . $this->sql);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
    public function fetchColumn(int $column = 0): mixed { return $this->row['id'] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->row ? [$this->row] : []; }
}

$pdo = new PlacementClientSavePDO();
$failures = 0;
$assert = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . " - $name\n";
    if (!$ok) $failures++;
};

foreach ([null, '', '   '] as $index => $country) {
    $id = companiesUpsertByName(1, "Blank country $index", ['country' => $country]);
    $assert("new company retains default for blank country $index", $pdo->companies[$id]['country'] === 'US');
}
$id = companiesUpsertByName(1, 'Canadian company', ['country' => 'CA']);
$assert('explicit company country is retained', $pdo->companies[$id]['country'] === 'CA');
foreach ([null, '', '   '] as $index => $country) {
    companiesUpsertByName(1, 'Canadian company', ['country' => $country, 'city' => 'Toronto']);
    $assert("blank country $index preserves existing non-US country", $pdo->companies[$id]['country'] === 'CA');
}
$assert('other supplied fields still update', $pdo->companies[$id]['city'] === 'Toronto');

$_SERVER['REQUEST_URI'] = '/modules/placements/api/placements.php';
foreach ([1 => 1, 2 => 1, 3 => 3] as $activeTenant => $catalogTenant) {
    setRequestTenantId($activeTenant);
    $scope = (int) placementsGraphTenantId($activeTenant);
    $ref = staffingClientEnsureForCompany($scope, null, "Placement client $activeTenant");
    $company = $pdo->companies[$ref['company_id']];
    $client = $pdo->clients[$ref['client_id']];
    $assert("tenant $activeTenant creates company and client in catalog $catalogTenant",
        $company['tenant_id'] === $catalogTenant && $client['tenant_id'] === $catalogTenant);
    $assert("tenant $activeTenant bridge handles missing billing country",
        $company['country'] === 'US' && $client['billing_country'] === 'US');
    $before = [count($pdo->companies), count($pdo->clients)];
    $again = staffingClientEnsureForCompany($scope, null, "Placement client $activeTenant");
    $assert("tenant $activeTenant repeated name lookup reuses both records",
        $again === $ref && $before === [count($pdo->companies), count($pdo->clients)]);
    $assert("tenant $activeTenant request context is unchanged", currentTenantId() === $activeTenant);
}
$api = file_get_contents(__DIR__ . '/../modules/placements/api/placements.php');
$assert('placement create and edit use the resolved catalog for company identity',
    str_contains($api, '$placementTenantId = (int) placementsGraphTenantId((int) $ctx[\'tenant_id\']);')
    && str_contains($api, 'placementsResolveCompanyRoles($placementTenantId,')
    && substr_count($api, 'placementsResolveCompanyRoles($placementTenantId,') === 2);
exit($failures ? 1 : 0);
