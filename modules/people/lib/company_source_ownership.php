<?php
/** One reviewed source authority per company/contact, with explicit native field holds. */
declare(strict_types=1);
require_once __DIR__ . '/../../../core/integrations/entity_mappings.php';
require_once __DIR__ . '/company_identity.php';

function companySourceFields(string $table): array
{
    return match ($table) {
        'companies' => ['name','legal_name','duns','ein_last4','website','phone',
            'primary_contact_name','primary_contact_email','primary_contact_phone',
            'address_line1','address_line2','city','state','postal_code','country','notes','msa_signed_at',
            'account_manager_user_id','default_terms','currency','status','tax_classification','industry','employee_size_range','roles'],
        'company_contacts' => ['name','title','email','phone','contact_role','is_primary','notes',
            'mobile_phone','linkedin_url','department','decision_role','is_active'],
        default => throw new InvalidArgumentException('Unsupported company source record.'),
    };
}

function companySourceState(array $row, string $table): array
{
    $fields = companySourceFields($table);
    if (($row['coreflux_source_state'] ?? null) === null) {
        return ['version'=>1,'revision'=>0,'source'=>null,'owned'=>$fields,'proposed'=>[], 'legacy_unreviewed'=>true];
    }
    $state = json_decode($row['coreflux_source_state'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($state) || ($state['version'] ?? null)!==1 || !is_int($state['revision']??null) || $state['revision']<0 || !is_array($state['owned'] ?? null)
        || !array_is_list($state['owned']) || array_filter($state['owned'],static fn($f)=>!is_string($f)) || array_diff($state['owned'], $fields)
        || !is_array($state['proposed'] ?? null) || array_diff(array_keys($state['proposed']), $fields)
        || !array_key_exists('source',$state) || !is_bool($state['legacy_unreviewed'] ?? null)) {
        throw new RuntimeException('Review the stored company field ownership before making changes.',409);
    }
    if ($state['source'] !== null && (!is_array($state['source']) || ($state['source']['system'] ?? '')!=='jobdiva'
        || !is_int($state['source']['workspace_id'] ?? null) || $state['source']['workspace_id']<=0
        || !is_string($state['source']['external_id'] ?? null) || $state['source']['external_id']==='')) {
        throw new RuntimeException('Review the stored company source identity.',409);
    }
    foreach($state['proposed']as$field=>$value){
        if($field==='roles'){
            if(!is_array($value)||!array_is_list($value)||array_filter($value,static fn($role)=>!is_string($role))
                ||array_diff($value,['client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner']))throw new RuntimeException('Review the stored company role proposal.',409);
        }elseif($value!==null&&!is_scalar($value))throw new RuntimeException('Review the stored company source value.',409);
    }
    if(array_key_exists('merged_custom_proposals',$state)){
        if($table!=='companies'||!is_array($state['merged_custom_proposals']))throw new RuntimeException('Review the merged company custom proposals.',409);
        foreach($state['merged_custom_proposals']as$key=>$proposal){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$key)||!is_array($proposal)
                ||!is_int($proposal['mapping_id']??null)||$proposal['mapping_id']<=0
                ||!is_int($proposal['definition_id']??null)||$proposal['definition_id']<=0
                ||!array_key_exists('value',$proposal)||$proposal['value']===null
                ||(!is_scalar($proposal['value'])&&(!is_array($proposal['value'])||!array_is_list($proposal['value'])
                    ||array_filter($proposal['value'],static fn($v)=>!is_string($v))))){
                throw new RuntimeException('Review the merged company custom proposal value.',409);
            }
        }
    }
    return $state;
}

/** Nested callers retain their transaction; a failed operation rolls back its own writes. */
function companySourceWithRecord(PDO $pdo, int $owner, string $table, int $id, callable $write, bool $parentLocked=false, bool $allowMergedSource=false): mixed
{
    companySourceFields($table);
    if ($owner<=0 || $id<=0) throw new InvalidArgumentException('A scoped company record is required.');
    if($table==='company_contacts'&&!$parentLocked){
        $q=$pdo->prepare('SELECT company_id FROM company_contacts WHERE tenant_id=? AND id=?');$q->execute([$owner,$id]);$parent=(int)$q->fetchColumn();
        if($parent<=0)throw new RuntimeException('Company contact not found in this directory.',404);
        return companySourceWithRecord($pdo,$owner,'companies',$parent,static fn(array $company)=>companySourceWithRecord($pdo,$owner,$table,$id,$write,true));
    }
    $own = !$pdo->inTransaction(); $save = 'company_source_'.bin2hex(random_bytes(8));
    if ($own) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT '.$save);
    try {
        if($table==='companies'&&$allowMergedSource){
            $identity=companyIdentityResolve($pdo,$owner,$id,true);
            $row=$identity?$identity['source_company']+['_canonical_company_id'=>$identity['id'],'_merged_source'=>$identity['merged']]:null;
        }else{
            $sql='SELECT * FROM '.$table.' WHERE tenant_id=? AND id=?';
            if ($table==='companies') $sql.=' AND deleted_at IS NULL';
            $q=$pdo->prepare($sql.' FOR UPDATE');$q->execute([$owner,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row) throw new RuntimeException('Company record not found in this directory.',404);
        if($table==='companies'){$q=$pdo->prepare('SELECT role FROM company_roles WHERE company_id=? ORDER BY role');$q->execute([$id]);$row['roles']=$q->fetchAll(PDO::FETCH_COLUMN);}
        $result=$write($row);
        if ($own) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT '.$save);
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($own) $pdo->rollBack(); else {$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}
        }
        throw $e;
    }
}

function companySourcePersist(PDO $pdo, int $owner, string $table, int $id, array $values, array $state): void
{
    $allowed=companySourceFields($table);
    if (!$pdo->inTransaction() || array_diff(array_keys($values),$allowed)) throw new LogicException('Invalid company field write.');
    if(array_key_exists('roles',$values)){
        $roles=$values['roles'];unset($values['roles']);
        if(!is_array($roles)||!array_is_list($roles)||array_filter($roles,static fn($r)=>!is_string($r))
            ||array_diff($roles,['client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner']))throw new InvalidArgumentException('Invalid company roles.');
        $roles=array_values(array_unique($roles));
        $sql='DELETE FROM company_roles WHERE company_id=?';$params=[$id];
        if($roles){$sql.=' AND role NOT IN ('.implode(',',array_fill(0,count($roles),'?')).')';$params=array_merge($params,$roles);}
        $pdo->prepare($sql)->execute($params);
        $q=$pdo->prepare('INSERT IGNORE INTO company_roles(company_id,role) VALUES(?,?)');foreach($roles as$role)$q->execute([$id,$role]);
    }
    $set=['coreflux_source_state=:state'];$params=['state'=>json_encode($state,JSON_THROW_ON_ERROR),'owner'=>$owner,'id'=>$id];
    foreach($values as$field=>$value){$set[]='`'.$field.'`=:v_'.$field;$params['v_'.$field]=$value;}
    $pdo->prepare('UPDATE '.$table.' SET '.implode(',',$set).' WHERE tenant_id=:owner AND id=:id')->execute($params);
}

function companySourceAudit(PDO $pdo,int $workspace,int $owner,string $table,int $id,string $event,array $fields,?int $actor,array $receipt=[]): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Company audit requires the write transaction.');
    $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at)
        VALUES(?,?,?,?,?,?,NOW())')->execute([$workspace,$actor,$event,$id,
        json_encode(['directory_owner_id'=>$owner,'record_type'=>$table,'fields'=>array_values($fields)]+$receipt,JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null]);
}

function companySourceComparableValues(array $values): array
{
    foreach ($values as $field=>$value) {
        if ($value === null) continue;
        if ($field === 'roles') { sort($values[$field]); continue; }
        if (in_array($field, ['account_manager_user_id','is_primary','is_active'], true)) $values[$field]=(int)$value;
        else $values[$field]=(string)$value;
    }
    ksort($values);
    return $values;
}

/** Default and configured mappings form one atomic observation, not two revisions. */
function companySourceFinishObservation(PDO $pdo,int $owner,string $table,int $id,array $before): void
{
    companySourceWithRecord($pdo,$owner,$table,$id,static function(array $after)use($pdo,$owner,$table,$id,$before):void{
        $old=companySourceState($before,$table);$state=companySourceState($after,$table);
        $oldSemantic=$old;$newSemantic=$state;
        unset($oldSemantic['observed_at'],$oldSemantic['revision'],$newSemantic['observed_at'],$newSemantic['revision']);
        $fields=array_flip(companySourceFields($table));
        $changed=mappingHash($oldSemantic)!==mappingHash($newSemantic)
            ||companySourceComparableValues(array_intersect_key($before,$fields))!==companySourceComparableValues(array_intersect_key($after,$fields));
        $revision=$old['revision']+($changed?1:0);
        if($state['revision']!==$revision){$state['revision']=$revision;companySourcePersist($pdo,$owner,$table,$id,[],$state);}
    },false,$table==='companies');
}

/** Source proposals are retained even when a Core edit prevents their application. */
function companySourceObserve(PDO $pdo,int $owner,string $table,int $id,array $values,array $source,bool $newRecord=false,bool $replaceProposal=true): array
{
    if (array_diff(array_keys($values),companySourceFields($table))) throw new InvalidArgumentException('Unsupported source company field.');
    if (($source['system']??'')!=='jobdiva' || !is_int($source['workspace_id']??null) || $source['workspace_id']<=0
        || !is_string($source['external_id']??null) || $source['external_id']==='') throw new InvalidArgumentException('Exact company source identity is required.');
    $source=array_intersect_key($source,array_flip(['system','workspace_id','external_id']));
    return companySourceWithRecord($pdo,$owner,$table,$id,static function(array $row)use($pdo,$owner,$table,$id,$values,$source,$newRecord,$replaceProposal):array{
        $state=companySourceState($row,$table);$before=$state;unset($before['observed_at']);
        // A secondary identity keeps its own proposals. It has no authority to
        // change either the retired profile or the reviewed surviving company.
        if(!empty($row['_merged_source'])){
            if($newRecord)throw new LogicException('A merged source cannot initialize a company.');
            $state['owned']=companySourceFields($table);sort($state['owned']);
            $state['legacy_unreviewed']=false;
        }
        if ($newRecord) {
            if ($row['coreflux_source_state']!==null) throw new LogicException('Source initialization cannot replace existing ownership.');
            $state['owned']=[];$state['legacy_unreviewed']=false;
        }
        if ($state['source']!==null && mappingHash($state['source'])!==mappingHash($source)) {
            throw new RuntimeException('This company record has a different source authority. Review the existing link.',409);
        }
        $state['source']=$source;
        $state['proposed']=$replaceProposal?$values:array_replace($state['proposed'],$values);
        $state['observed_at']=gmdate('c');
        $apply=array_diff_key($values,array_flip($state['owned']));
        $semantic=$state;unset($semantic['observed_at']);
        $changed=mappingHash($before)!==mappingHash($semantic);
        if(companySourceComparableValues(array_intersect_key($row,$apply))!==companySourceComparableValues($apply))$changed=true;
        if($changed)$state['revision']++;
        companySourcePersist($pdo,$owner,$table,$id,$apply,$state);
        return ['written'=>count($apply),'skipped'=>array_values(array_intersect(array_keys($values),$state['owned']))];
    },false,$table==='companies');
}

function companyNativeFields(PDO $pdo,int $workspace,int $owner,string $table,int $id,array $values,?int $actor): array
{
    if (array_diff(array_keys($values),companySourceFields($table))) throw new InvalidArgumentException('Unsupported company field.');
    return companySourceWithRecord($pdo,$owner,$table,$id,static function(array $row)use($pdo,$workspace,$owner,$table,$id,$values,$actor):array{
        $state=companySourceState($row,$table);
        $state['owned']=array_values(array_unique(array_merge($state['owned'],array_keys($values))));sort($state['owned']);$state['revision']++;
        companySourcePersist($pdo,$owner,$table,$id,$values,$state);
        companySourceAudit($pdo,$workspace,$owner,$table,$id,'company.source.native_fields',array_keys($values),$actor);
        return ['owned_fields'=>$state['owned']];
    });
}

function companySourceReview(array $row,string $table): array
{
    $state=companySourceState($row,$table);$current=array_intersect_key($row,array_flip(companySourceFields($table)));
    $tokenState=$state;unset($tokenState['observed_at']);
    return ['record_id'=>(int)$row['id'],'directory_owner_id'=>(int)$row['tenant_id'],'record_type'=>$table,
        'source'=>$state['source'],'owned_fields'=>$state['owned'],'legacy_unreviewed'=>$state['legacy_unreviewed'],
        'current'=>$current,'proposed'=>$state['proposed'],'observed_at'=>$state['observed_at']??null,
        'review_token'=>mappingHash(['table'=>$table,'id'=>$row['id'],'owner'=>$row['tenant_id'],'current'=>$current,'state'=>$tokenState])];
}

/** Accept exactly the observed proposals reviewed by the operator, atomically with ownership. */
function companySourceAccept(PDO $pdo,int $workspace,int $owner,string $table,int $id,array $fields,string $token,?int $actor): array
{
    if (!$fields || !array_is_list($fields) || array_filter($fields,static fn($f)=>!is_string($f))
        || count(array_unique($fields))!==count($fields)) throw new InvalidArgumentException('Choose the source fields to accept.');
    sort($fields);
    return companySourceWithRecord($pdo,$owner,$table,$id,static function(array $row)use($pdo,$workspace,$owner,$table,$id,$fields,$token,$actor):array{
        $digest=mappingHash($fields);
        $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='company.source.accepted' AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.record_type'))=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.directory_owner_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.review_token'))=? ORDER BY id DESC LIMIT 1");
        $q->execute([$workspace,$id,$table,(string)$owner,$token]);$prior=$q->fetchColumn();
        if($prior!==false){$receipt=json_decode($prior,true,32,JSON_THROW_ON_ERROR);if(!hash_equals($receipt['request_digest'],$digest))throw new RuntimeException('This source review was already used for a different field selection.',409);return $receipt['result']+['replayed'=>true];}
        $review=companySourceReview($row,$table);$state=companySourceState($row,$table);
        if ($token==='' || !hash_equals($review['review_token'],$token)) throw new RuntimeException('Company or source values changed. Refresh and review again.',409);
        if ($state['source']===null || array_diff($fields,array_keys($state['proposed']))) throw new InvalidArgumentException('Every accepted field needs an observed source value.');
        $values=array_intersect_key($state['proposed'],array_flip($fields));
        $state['owned']=array_values(array_diff($state['owned'],$fields));$state['legacy_unreviewed']=false;$state['revision']++;
        companySourcePersist($pdo,$owner,$table,$id,$values,$state);
        $result=['accepted_fields'=>$fields,'owned_fields'=>$state['owned']];
        companySourceAudit($pdo,$workspace,$owner,$table,$id,'company.source.accepted',$fields,$actor,['review_token'=>$token,'request_digest'=>$digest,'result'=>$result]);
        return $result+['replayed'=>false];
    });
}
