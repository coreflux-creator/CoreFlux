<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/placements/lib/commission_input.php';
require_once __DIR__ . '/../core/integrations/staffing_field_contract.php';
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException($label);
    $passed++;
};
$valid = ['role'=>'recruiter', 'user_id'=>7, 'plan_id'=>null, 'split_pct'=>null,
    'basis'=>'flat', 'flat_amount'=>'125.50', 'effective_from'=>'2026-08-01',
    'effective_to'=>null, 'notes'=>'Reviewed legacy rule'];
$check(placementCommissionInput($valid) === $valid, 'supported fields retain exact values');

// Exercise both real API branches in isolated child processes. Authentication,
// reads and writes are fixture adapters; no application DB or HTTP server runs.
$root = sys_get_temp_dir() . '/core-commission-input-' . bin2hex(random_bytes(8));
$dirs = [$root, $root.'/core', $root.'/modules', $root.'/modules/placements',
    $root.'/modules/placements/api', $root.'/modules/placements/lib'];
$files = [];
$put = static function (string $path, string $content) use (&$files): void {
    if (file_put_contents($path, $content) === false) throw new RuntimeException('Fixture write failed');
    $files[$path] = true;
};
try {
    foreach ($dirs as $dir) if (!mkdir($dir, 0700)) throw new RuntimeException('Fixture directory failed');
    $put($root.'/core/api_bootstrap.php', <<<'PHP'
<?php
$GLOBALS['writes']=[];
function api_require_auth(): array { return ['user'=>['id'=>7], 'tenant_id'=>2]; }
function api_method(): string { return $GLOBALS['argv'][2]; }
function api_query(string $key, mixed $default=null): mixed { return 17; }
function api_json_body(): array { return json_decode(file_get_contents($GLOBALS['argv'][1]),true,512,JSON_THROW_ON_ERROR); }
function api_require_fields(array $body,array $fields): void {
    foreach($fields as $field) if(!isset($body[$field])) api_error('Missing field',422);
}
function api_error(string $message,int $status=400): never {
    echo json_encode(['status'=>$status,'error'=>$message,'writes'=>$GLOBALS['writes']],JSON_THROW_ON_ERROR);exit;
}
function api_ok(array $result,int $status=200): never {
    echo json_encode(['status'=>$status,'result'=>$result,'writes'=>$GLOBALS['writes']],JSON_THROW_ON_ERROR);exit;
}
function rbac_legacy_require(array $user,string $permission): void {}
function scopedFind(string $sql,array $values): array { return ['placement_id'=>17]; }
function membershipReadSourceSql(): string { return 'fixture_membership'; }
function getDB(): object {
    return new class {
        public function prepare(string $sql): object {
            return new class { public function execute(array $args): void {} public function fetchColumn(): bool { return true; } };
        }
    };
}
function scopedInsert(string $table,array $values): int { $GLOBALS['writes'][]=$values;return 19; }
function scopedUpdate(string $table,int $id,array $values): int { $GLOBALS['writes'][]=$values;return 1; }
function placementsAudit(string $event,array $data,int $placement): void {}
function placementEconomicsReconcile(int $tenant,int $placement): void {}
PHP
    );
    foreach (['core/RBAC.php','modules/placements/lib/placements.php','modules/placements/lib/economics.php'] as $path) {
        $put($root.'/'.$path, "<?php\n");
    }
    foreach (['modules/placements/api/commissions.php','modules/placements/lib/commission_input.php'] as $path) {
        $put($root.'/'.$path, file_get_contents(__DIR__.'/../'.$path));
    }
    $invoke = static function (string $method,array $body) use ($root,$put): array {
        $put($root.'/request.json',json_encode($body,JSON_THROW_ON_ERROR));
        $process=proc_open([PHP_BINARY,$root.'/modules/placements/api/commissions.php',$root.'/request.json',$method],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Fixture process failed');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0||$err!=='')throw new RuntimeException('Fixture failed: '.$err);
        return json_decode($out,true,512,JSON_THROW_ON_ERROR);
    };
    foreach (['POST','PATCH'] as $method) {
        foreach ([
            ['currency'=>'INR'], ['currency'=>'USD'], ['currency'=>null],
            ['payment_frequency'=>'one_time'], ['due_month'=>'2026-08'], ['due_date'=>'2026-08-01'],
            ['basis'=>'one_time'], ['basis'=>'per_hour'], ['notes'=>['currency'=>'INR']],
            ['tenant_id'=>99], ['placement_id'=>99], ['created_by_user_id'=>99],
            ['source_managed'=>1], ['approved_at'=>'2026-08-01'], ['unsupported_source_term'=>'private value'],
        ] as $extra) {
            $result=$invoke($method,array_replace($valid,$extra));
            $check($result['status']===422 && $result['writes']===[], $method.' rejects unrepresentable or protected terms before writing');
            $check(!str_contains($result['error'],'private value'), 'input values are not reflected in validation errors');
        }
        $result=$invoke($method,$valid);
        $check($result['status']===($method==='POST'?201:200) && count($result['writes'])===1,
            $method.' supported legacy mutation still reaches its writer');
        $check($result['writes'][0]['flat_amount']==='125.50' && $result['writes'][0]['effective_from']==='2026-08-01',
            $method.' retains amount precision and rule effective date');
    }
    $result=$invoke('PATCH',['user_id'=>7]);
    $check($result['status']===200 && $result['writes']===[['user_id'=>7]],'recipient-only edit remains supported');
} finally {
    foreach(array_keys($files) as $file) if(is_file($file))unlink($file);
    foreach(array_reverse($dirs) as $dir) if(is_dir($dir))rmdir($dir);
}
$rule=staffingFieldContractRules('placement_commissions','flat_amount');
$check($rule['unit']==='amount; currency-and-frequency-not-stored','dictionary discloses missing amount units');
$check(str_contains(staffingFieldContractRules('placement_commissions','effective_from')['definition'],'not the commission payment due'),
    'effective date does not claim due date precision');
foreach($rule['remote_contracts'] as $contract) {
    $check(empty($contract['mappings']) && !$contract['sync_enabled_by_dictionary'],'no unimplemented commission exchange inferred');
}
$check(isset(staffingExchangeWorkflowGaps()['commission_source_terms']),'unimplemented foreign-currency settlement is explicit');
echo "Commission input: {$passed} passed\n";
