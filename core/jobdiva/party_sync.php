<?php
/** Exact JobDiva identities, source proposals and mapped fields share one transaction. */
declare(strict_types=1);
require_once __DIR__.'/../../modules/people/lib/companies.php';
require_once __DIR__.'/../../modules/people/lib/company_source_ownership.php';

function jobdivaPartyAtomic(callable $write): mixed
{
    $pdo=getDB();$own=!$pdo->inTransaction();$save='jobdiva_party_'.bin2hex(random_bytes(8));
    if($own)$pdo->beginTransaction();else$pdo->exec('SAVEPOINT '.$save);
    try{$result=$write($pdo);if($own)$pdo->commit();else$pdo->exec('RELEASE SAVEPOINT '.$save);return$result;}
    catch(Throwable$e){if($pdo->inTransaction()){if($own)$pdo->rollBack();else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}}throw$e;}
}

function jobdivaPartyMappedFields(int $workspace,string $type,int $id,array $payload): void
{
    require_once __DIR__.'/../integrations/field_map_apply.php';
    $result=integrationFieldMapApplyAll($workspace,'jobdiva',$type,$payload,['self'=>$id]);
    if($result['errors'])throw new RuntimeException('Company source mappings need review: '.implode('; ',$result['errors']));
}

/** Assignment evidence can propose roles only through the exact company binding. */
function jobdivaCompanyObserveMappedRoles(int $workspace,string $external,int $companyId,array $roles): array
{
    if($workspace<=0||$external===''||$companyId<=0||!array_is_list($roles)
        ||array_filter($roles,static fn($role)=>!is_string($role))||array_diff($roles,COMPANY_ROLES)){
        throw new InvalidArgumentException('An exact company source identity and valid roles are required.');
    }
    return jobdivaPartyAtomic(static function(PDO $pdo)use($workspace,$external,$companyId,$roles):array{
        $owner=companiesDirectoryTenantId($workspace);
        return companySourceWithRecord($pdo,$owner,'companies',$companyId,static function(array $current)use($pdo,$workspace,$owner,$external,$companyId,$roles):array{
            $q=$pdo->prepare('SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="company" AND external_id=? FOR UPDATE');
            $q->execute([$workspace,$external]);
            if((int)$q->fetchColumn()!==$companyId)throw new RuntimeException('Review the exact JobDiva company link before applying source roles.',409);
            $state=companySourceState($current,'companies');
            $proposed=array_values(array_unique(array_merge($current['roles'],$state['proposed']['roles']??[],$roles)));sort($proposed);
            return companySourceObserve($pdo,$owner,'companies',$companyId,['roles'=>$proposed],
                ['system'=>'jobdiva','workspace_id'=>$workspace,'external_id'=>$external],false,false);
        });
    });
}

function jobdivaCompanyUpsertStable(int $workspace,string $external,string $name,array $patch,array $payload,?int $actor,array $roles): int
{
    $name=trim($name);if($external===''||$name==='')throw new InvalidArgumentException('Company source identity and name are required.');
    return jobdivaPartyAtomic(static function(PDO $pdo)use($workspace,$external,$name,$patch,$payload,$actor,$roles):int{
        $owner=companiesDirectoryTenantId($workspace);
        $link=mappingFindInternal($workspace,'jobdiva','company',$external);
        $new=$link===null;$id=$link?(int)$link['internal_entity_id']:0;
        $values=array_intersect_key($patch,array_flip(companySourceFields('companies')));
        $values=array_filter($values,static fn($v)=>$v!==null&&$v!=='');$values['name']=$name;
        if($new){
            $q=$pdo->prepare('SELECT id FROM companies WHERE tenant_id=? AND name=? FOR UPDATE');$q->execute([$owner,$name]);
            if($q->fetchColumn())throw new RuntimeException('A Core company already uses this name. Review and link the source identity before importing.',409);
            $q=$pdo->prepare('INSERT INTO companies(tenant_id,name,country,created_by_user_id) VALUES(?,?,?,?)');
            $q->execute([$owner,$name,$values['country']??'US',$actor]);$id=(int)$pdo->lastInsertId();
        }
        return companySourceWithRecord($pdo,$owner,'companies',$id,static function(array $current)use($pdo,$workspace,$owner,$external,$name,$values,$payload,$actor,$roles,$new,$id):int{
            $proposal=$values;
            if($roles){$proposal['roles']=array_values(array_unique(array_merge($current['roles'],$roles)));sort($proposal['roles']);}
            mappingUpsert($workspace,'jobdiva','company',$external,$id,$payload,'pull',$actor);
            companySourceObserve($pdo,$owner,'companies',$id,$proposal,['system'=>'jobdiva','workspace_id'=>$workspace,'external_id'=>$external],$new);
            jobdivaPartyMappedFields($workspace,'company',$id,$payload);
            companySourceFinishObservation($pdo,$owner,'companies',$id,$current);
            if(in_array('client',$roles,true)&&in_array('client',companyRoles($id),true)){
                $q=$pdo->prepare('SELECT name FROM companies WHERE tenant_id=? AND id=?');$q->execute([$owner,$id]);
                jobdivaEnsureStaffingClientForCompany($workspace,$id,(string)$q->fetchColumn(),$actor);
            }
            return$id;
        });
    });
}

function jobdivaContactUpsertStable(int $workspace,int $companyId,string $external,array $payload,string $name,array $values,?int $actor): int
{
    if($external===''||trim($name)==='')throw new InvalidArgumentException('Contact source identity and name are required.');
    return jobdivaPartyAtomic(static function(PDO $pdo)use($workspace,$companyId,$external,$payload,$name,$values,$actor):int{
        $owner=companiesDirectoryTenantId($workspace);
        return companySourceWithRecord($pdo,$owner,'companies',$companyId,static function(array $parent)use($pdo,$workspace,$owner,$companyId,$external,$payload,$name,$values,$actor):int{
            $link=mappingFindInternal($workspace,'jobdiva','contact',$external);
            $new=$link===null;$id=$link?(int)$link['internal_entity_id']:0;
            if($new){
                if(!empty($values['email'])){
                    $q=$pdo->prepare('SELECT id FROM company_contacts WHERE tenant_id=? AND company_id=? AND email=? FOR UPDATE');$q->execute([$owner,$companyId,$values['email']]);
                    if($q->fetchColumn())throw new RuntimeException('A contact already uses this email. Review the source identity before linking it.',409);
                }
                $q=$pdo->prepare('INSERT INTO company_contacts(tenant_id,company_id,name) VALUES(?,?,?)');$q->execute([$owner,$companyId,$name]);$id=(int)$pdo->lastInsertId();
            }
            return companySourceWithRecord($pdo,$owner,'company_contacts',$id,static function(array $current)use($pdo,$workspace,$owner,$companyId,$external,$payload,$name,$values,$actor,$new,$id):int{
                if((int)$current['company_id']!==$companyId)throw new RuntimeException('This source contact is already linked to a different company. Review the identity change.',409);
                mappingUpsert($workspace,'jobdiva','contact',$external,$id,$payload,'pull',$actor);
                companySourceObserve($pdo,$owner,'company_contacts',$id,$values+['name'=>$name],['system'=>'jobdiva','workspace_id'=>$workspace,'external_id'=>$external],$new);
                jobdivaPartyMappedFields($workspace,'contact',$id,$payload);
                companySourceFinishObservation($pdo,$owner,'company_contacts',$id,$current);
                return$id;
            });
        });
    });
}
