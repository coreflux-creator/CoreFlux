<?php
/** Native client profile edits preserve the CL identity and commit with their company and audit. */
declare(strict_types=1);
require_once __DIR__.'/clients.php';
require_once __DIR__.'/client_audit.php';
require_once __DIR__.'/../../people/lib/company_relationships.php';
require_once __DIR__.'/../../people/lib/company_source_ownership.php';

function staffingClientNativeFields(): array
{
    return ['name','legal_name','industry','primary_contact_name','primary_contact_email','primary_contact_phone',
        'billing_address_line1','billing_address_line2','billing_city','billing_state','billing_postal_code','billing_country',
        'payment_terms_days','status','notes','msa_status','msa_executed_at','msa_expires_at'];
}

function staffingClientCompanyFields(): array
{
    // Billing terms and client MSA status belong to the client profile; they
    // do not attest to a company-wide agreement or alter vendor terms.
    return ['name'=>'name','legal_name'=>'legal_name','industry'=>'industry','primary_contact_name'=>'primary_contact_name',
        'primary_contact_email'=>'primary_contact_email','primary_contact_phone'=>'primary_contact_phone',
        'billing_address_line1'=>'address_line1','billing_address_line2'=>'address_line2','billing_city'=>'city',
        'billing_state'=>'state','billing_postal_code'=>'postal_code','billing_country'=>'country'];
}

function staffingClientRevision(PDO $pdo,array $scope,array $client,?array $company=null): string
{
    if((int)$client['tenant_id']!==$scope['owner'])throw new RuntimeException('Client directory changed. Refresh and try again.',409);
    if($company===null&&!empty($client['company_id'])){
        $q=$pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND id=?');$q->execute([$scope['company_owner'],$client['company_id']]);$company=$q->fetch(PDO::FETCH_ASSOC)?:[];
    }
    return mappingHash(['scope'=>$scope,'client'=>array_intersect_key($client,array_flip([...staffingClientNativeFields(),'id','tenant_id','company_id','external_id','source_system'])),
        'company'=>array_intersect_key($company??[],array_flip([...array_values(staffingClientCompanyFields()),'id','tenant_id','deleted_at','coreflux_source_state']))]);
}

function staffingClientValidateNative(array $patch): array
{
    if(!$patch||array_diff(array_keys($patch),staffingClientNativeFields()))throw new InvalidArgumentException('Choose the client fields to save.');
    foreach($patch as$field=>$value)if($value!==null&&!is_scalar($value))throw new InvalidArgumentException('Invalid client field: '.$field);
    if(array_key_exists('name',$patch)){$patch['name']=trim((string)$patch['name']);if($patch['name']==='')throw new InvalidArgumentException('A client name is required.');}
    foreach(['status'=>['active','prospect','on_hold','inactive','closed'],'msa_status'=>['none','draft','executed','expired']]as$field=>$allowed){
        if(array_key_exists($field,$patch)&&!in_array($patch[$field],$allowed,true))throw new InvalidArgumentException('Choose a valid '.$field.'.');
    }
    if(array_key_exists('payment_terms_days',$patch)){
        $v=$patch['payment_terms_days'];if((!is_int($v)&&!is_string($v))||!preg_match('/^(0|[1-9][0-9]{0,2})$/D',(string)$v)||(int)$v>365)throw new InvalidArgumentException('Payment terms must be a whole number from 0 to 365.');$patch['payment_terms_days']=(int)$v;
    }
    if(array_key_exists('billing_country',$patch)){
        $country=strtoupper(trim((string)$patch['billing_country']));if($country==='')unset($patch['billing_country']);
        elseif(!preg_match('/^[A-Z]{2}$/D',$country))throw new InvalidArgumentException('Use a two-letter billing country.');else$patch['billing_country']=$country;
    }
    foreach(['msa_executed_at','msa_expires_at']as$field)if(array_key_exists($field,$patch)){
        $value=$patch[$field];if($value===''||$value===null)$patch[$field]=null;
        elseif(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!($d=DateTimeImmutable::createFromFormat('!Y-m-d',$value))||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Use a valid agreement date.');
    }
    foreach(['name'=>255,'legal_name'=>255,'industry'=>80,'primary_contact_name'=>120,'primary_contact_email'=>255,'primary_contact_phone'=>40,
        'billing_address_line1'=>255,'billing_address_line2'=>255,'billing_city'=>80,'billing_state'=>40,'billing_postal_code'=>20]as$field=>$max){
        if(isset($patch[$field])&&mb_strlen((string)$patch[$field])>$max)throw new InvalidArgumentException('Client field is too long: '.$field);
    }
    if(!empty($patch['primary_contact_email'])&&!filter_var($patch['primary_contact_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Use a valid contact email.');
    return$patch;
}

function staffingClientSaveNative(int $workspace,?int $id,array $patch,int $actor,?string $revision=null,string $event=''): array
{
    if($actor<=0||($id!==null&&$id<=0))throw new InvalidArgumentException('A current client editor is required.');
    $patch=staffingClientValidateNative($patch);$pdo=getDB();
    return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$id,$patch,$actor,$revision,$event):array{
        $scope=companyRelationshipScope($pdo,$workspace,'placements');$owner=$scope['owner'];$companyOwner=$scope['company_owner'];
        if(jobdivaPartyDirectoryScope($pdo,$owner,true)['owner']!==$companyOwner)throw new RuntimeException('This shared client cannot use a company private to this workspace. Review its sharing setup.',409);
        $before=[];$company=null;
        if($id!==null){
            $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$before=$q->fetch(PDO::FETCH_ASSOC);
            if(!$before)throw new RuntimeException('Client not found in this directory.',404);
            if(!empty($before['company_id']))$company=companyRelationshipCurrent($pdo,$scope,(int)$before['company_id']);
            if(!$revision||!hash_equals(staffingClientRevision($pdo,$scope,$before,$company),$revision))throw new RuntimeException('The client, company or sharing policy changed. Refresh before saving.',409);
        }elseif(empty($patch['name']))throw new InvalidArgumentException('A client name is required.');
        $after=array_replace($before,$patch);$name=$after['name'];
        $q=$pdo->prepare('SELECT id FROM staffing_clients WHERE tenant_id=? AND name=? AND id<>? FOR UPDATE');$q->execute([$owner,$name,$id??0]);
        if($q->fetchColumn())throw new RuntimeException('A client with this name already exists. Open that client instead.',409);
        if(!$company){
            $q=$pdo->prepare('SELECT id FROM companies WHERE tenant_id=? AND name=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$companyOwner,$name]);$companyId=(int)$q->fetchColumn();
            if(!$companyId){$q=$pdo->prepare('INSERT INTO companies(tenant_id,name,country,created_by_user_id) VALUES(?,?,?,?)');$q->execute([$companyOwner,$name,$patch['billing_country']??'US',$actor]);$companyId=(int)$pdo->lastInsertId();}
            $company=companyRelationshipCurrent($pdo,$scope,$companyId);
        }
        $companyId=(int)$company['id'];
        if(isset($patch['status'])&&in_array($patch['status'],['inactive','closed'],true)&&$id!==null){
            $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND client_id=? AND status="active" AND deleted_at IS NULL LIMIT 1 FOR UPDATE');$q->execute([$owner,$id]);
            if($q->fetchColumn())throw new RuntimeException('This billing client has active placements. Resolve those assignments before closing it.',409);
        }
        $companyPatch=[];foreach(staffingClientCompanyFields()as$field=>$target)if(array_key_exists($field,$patch))$companyPatch[$target]=$patch[$field];
        if($id===null){$q=$pdo->prepare('SELECT role FROM company_roles WHERE company_id=? ORDER BY role FOR UPDATE');$q->execute([$companyId]);$companyPatch['roles']=array_values(array_unique([...$q->fetchAll(PDO::FETCH_COLUMN),'client']));}
        if($companyPatch)companyNativeFields($pdo,$workspace,$companyOwner,'companies',$companyId,$companyPatch,$actor);
        $patch['company_id']=$companyId;
        if($id===null){
            $patch+=['tenant_id'=>$owner,'status'=>'active','payment_terms_days'=>30,'source_system'=>'manual','billing_country'=>$company['country']??'US'];
            $q=$pdo->prepare('INSERT INTO staffing_clients (`'.implode('`,`',array_keys($patch)).'`) VALUES('.implode(',',array_fill(0,count($patch),'?')).')');$q->execute(array_values($patch));$id=(int)$pdo->lastInsertId();
        }else{
            $q=$pdo->prepare('UPDATE staffing_clients SET '.implode(',',array_map(static fn($key)=>'`'.$key.'`=?',array_keys($patch))).' WHERE tenant_id=? AND id=?');$q->execute([...array_values($patch),$owner,$id]);
        }
        $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=?');$q->execute([$owner,$id]);$saved=$q->fetch(PDO::FETCH_ASSOC);
        // Provenance scans follow the client owner; retain the acting workspace separately.
        companySourceAudit($pdo,$owner,$owner,'staffing_clients',$id,$event?:($before?'staffing.client.updated':'staffing.client.created'),array_keys($patch),$actor,
            ['acting_workspace_id'=>$workspace,'before'=>staffingClientAuditSnapshot($before),'after'=>staffingClientAuditSnapshot($saved)]);
        $saved['edit_revision']=staffingClientRevision($pdo,$scope,$saved);return$saved;
    });
}
