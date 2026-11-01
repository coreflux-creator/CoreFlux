<?php
/** Exercise staffing payee identity against the disposable fully migrated CI database. */
declare(strict_types=1);
if (PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true)) exit(2);
require_once __DIR__.'/../modules/placements/lib/economics.php';
$pdo=getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci') throw new RuntimeException('Disposable database required');
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if ($tenant<=0) throw new RuntimeException('CI tenant missing');
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void {
    if (!$ok) throw new RuntimeException($label);
    $passed++; echo "PASS $label\n";
};
$fails=static function(callable $fn):bool {try {$fn();return false;}catch(RuntimeException){return true;}};
$company=static function(string $name,int $owner)use($pdo):int {
    $pdo->prepare('INSERT INTO companies(tenant_id,name) VALUES(:t,:n)')->execute(['t'=>$owner,'n'=>$name]);
    return (int)$pdo->lastInsertId();
};
$vendor=static function(string $name,?int $company,int $owner)use($pdo):int {
    $pdo->prepare('INSERT INTO ap_vendors_index(tenant_id,vendor_name,company_id,vendor_type,default_terms,default_pwp,requires_1099)
        VALUES(:t,:n,:c,"w9_business","NET45",1,0)')->execute(['t'=>$owner,'n'=>$name,'c'=>$company]);
    return (int)$pdo->lastInsertId();
};
$snapshot=static function()use($pdo):array {
    $result=[];
    foreach (['ap_vendors_index','companies','company_roles','core_record_numbers','external_entity_mappings'] as $table) {
        $order=$table==='core_record_numbers'?'tenant_id,entity_type,record_id':'id';
        $result[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
};
$pdo->beginTransaction();
try {
    $a=$company('CI staffing company A',$tenant);$b=$company('CI staffing company B',$tenant);
    $c=$company('CI staffing foreign company',999);
    $d=$company('CI staffing new company',$tenant);
    $v=$vendor('CI staffing shared label',$a,$tenant);
    $unlinked=$vendor('CI staffing unreviewed vendor',null,$tenant);
    $foreign=$vendor('CI staffing other tenant vendor',$c,999);
    $before=$snapshot();
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'CI staffing shared label',$b,'c2c_corp')),
        'a matching vendor name cannot replace the selected company identity');
    $check($snapshot()===$before&&$pdo->inTransaction(),'rejected collision preserves all identities, company usage and the caller transaction');
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'CI staffing shared label',null,'1099_individual')),
        'person-only payee cannot adopt a company-bound vendor by name');
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'CI staffing unreviewed vendor',$b,'c2c_corp')),
        'a name alone cannot attach an existing unlinked vendor to a company');
    $check($snapshot()===$before,'both name-only holds leave vendor and canonical-number rows unchanged');
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'CI staffing other tenant vendor',$c,'c2c_corp')),
        'an explicit foreign company is rejected before vendor or role writes');
    $check($snapshot()===$before,'foreign company, vendor and provider mapping rows remain untouched');
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=:id')->execute(['id'=>$b]);
    $beforeDeleted=$snapshot();
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'CI staffing deleted company vendor',$b,'c2c_corp'))&&$snapshot()===$beforeDeleted,
        'deleted company cannot gain a new vendor identity');
    $pdo->prepare('UPDATE companies SET deleted_at=NULL WHERE id=:id')->execute(['id'=>$b]);
    $numbers=$pdo->query('SELECT * FROM core_record_numbers ORDER BY tenant_id,entity_type,record_id')->fetchAll(PDO::FETCH_ASSOC);
    $resolved=placementEconomicsEnsureVendor($tenant,'Changed upstream display name',$a,'c2c_corp','NET10',false,712345);
    $check($resolved['id']===$v&&(int)$resolved['company_id']===$a&&$resolved['vendor_name']==='CI staffing shared label',
        'exact company identity retains the existing vendor despite an upstream name change');
    $check($resolved['vendor_type']==='w9_business'&&$resolved['default_terms']==='NET45'&&$resolved['default_pwp']===1&&$resolved['requires_1099']===0,
        'source reconciliation preserves reviewed tax classification, payment terms and PWP');
    $check($pdo->query('SELECT * FROM core_record_numbers ORDER BY tenant_id,entity_type,record_id')->fetchAll(PDO::FETCH_ASSOC)===$numbers,
        'reconciliation preserves every existing canonical number');
    $duplicate=$vendor('CI staffing duplicate vendor',$a,$tenant);
    $beforeDuplicate=$snapshot();
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'CI staffing shared label',$a,'c2c_corp'))&&$snapshot()===$beforeDuplicate,
        'multiple vendors for one company require review instead of choosing the first row');
    $pdo->prepare('DELETE FROM ap_vendors_index WHERE id=:i')->execute(['i'=>$duplicate]);
    $new=placementEconomicsEnsureVendor($tenant,'CI staffing new vendor',$d,'c2c_corp','NET15',false,712345);
    $check((int)$new['company_id']===$d&&$new['default_terms']==='NET15'&&$new['default_pwp']===0,
        'a new vendor receives the exact active company and explicit initial terms');
    $repeat=placementEconomicsEnsureVendor($tenant,'New source label',$d,'other','NET5',true,712345);
    $check($repeat===$new,'replay preserves vendor identity and reviewed defaults');
    $s=$pdo->prepare('SELECT COUNT(*) FROM ap_vendors_index WHERE tenant_id=:t AND company_id=:c');
    $s->execute(['t'=>$tenant,'c'=>$d]);
    $check((int)$s->fetchColumn()===1,'replay creates no duplicate company vendor');
    $s=$pdo->prepare('SELECT COUNT(*) FROM core_record_numbers WHERE tenant_id=:t AND entity_type="vendor" AND record_id=:v AND canonical_record_id=:v2');
    $s->execute(['t'=>$tenant,'v'=>$new['id'],'v2'=>$new['id']]);
    $check((int)$s->fetchColumn()===1,'the new vendor receives one canonical vendor number');
    $plain=placementEconomicsEnsureVendor($tenant,'CI staffing unreviewed vendor',null,'other','NET10',false);
    $check($plain['id']===$unlinked&&$plain['company_id']===null&&$plain['default_terms']==='NET45',
        'existing unlinked name-only vendor keeps its identity and reviewed defaults');
    $beforeInvalid=$snapshot();
    $check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'Invalid company',0,'c2c_corp'))&&$snapshot()===$beforeInvalid,
        'zero is not a real company identity');
    $check($pdo->inTransaction(),'nested resolution never commits the caller transaction');
} finally {if($pdo->inTransaction())$pdo->rollBack();}

// Failure outside a caller transaction must not leave an open transaction behind.
$check($fails(fn()=>placementEconomicsEnsureVendor($tenant,'No such company',PHP_INT_MAX,'c2c_corp'))&&!$pdo->inTransaction(),
    'standalone resolution failure rolls back its own transaction');
echo "Staffing vendor identity: $passed passed\n";
