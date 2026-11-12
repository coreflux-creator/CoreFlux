<?php
/** Native corporate details. API callers authorize corporate view/manage. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics_edit.php';
require_once __DIR__ . '/../../../core/encryption.php';

function placementCorpMetadataFields(): array
{
    return ['corp_address_line1'=>255,'corp_address_line2'=>255,'corp_city'=>120,'corp_state'=>60,
        'corp_postal_code'=>20,'corp_country'=>2,'corp_contact_name'=>200,'corp_contact_email'=>255,
        'corp_contact_phone'=>40,'coi_expiry'=>10];
}
function placementCorpDocumentFields(): array
{
    return ['msa_storage_object_id','coi_storage_object_id','w9_storage_object_id'];
}
function placementCorpPublic(?array $row): ?array
{
    if (!$row) return null;
    return array_intersect_key($row,array_flip(array_merge(
        ['placement_id','tenant_id','company_id','ap_vendor_id','corp_legal_name','corp_ein_last4',
            'payment_terms_override','pwp_enabled','updated_at'],
        array_keys(placementCorpMetadataFields()),placementCorpDocumentFields()
    )));
}
/** Caller locks the parent before these children, matching all source writers. */
function placementCorpState(PDO $pdo,array $placement): array
{
    $args=[(int)$placement['tenant_id'],(int)$placement['id']];
    $q=$pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=? FOR UPDATE');
    $q->execute($args);$corp=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=? FOR UPDATE');
    $q->execute([...$args,'corp:'.$placement['id']]);$party=$q->fetch(PDO::FETCH_ASSOC)?:null;
    // Hash binary ciphertext without disclosing it or decrypting it for display.
    return ['corp'=>$corp,'party'=>$party,'review_token'=>hash('sha256',serialize([$placement,$corp,$party]))];
}
function placementCorpReview(int $tenantId,int $placementId): array
{
    return placementWithLockedRecord($tenantId,$placementId,static function(array $placement,PDO $pdo):array{
        $state=placementCorpState($pdo,$placement);
        return ['corp'=>placementCorpPublic($state['corp']),'review_token'=>$state['review_token']];
    });
}
function placementCorpSave(int $tenantId,int $placementId,array $body,array $user=[]): array
{
    $allowed=array_merge(['corp_legal_name','company_id','corp_ein','payment_terms_override','pwp_enabled','review_token'],
        array_keys(placementCorpMetadataFields()),placementCorpDocumentFields());
    foreach($body as $field=>$value) {
        if(!in_array($field,$allowed,true)||(!is_scalar($value)&&$value!==null))throw new InvalidArgumentException('Invalid corporate field: '.$field,422);
    }
    $legal=trim((string)($body['corp_legal_name']??''));
    if($legal===''||mb_strlen($legal)>255)throw new InvalidArgumentException('Enter a corporate legal name of up to 255 characters.',422);
    return placementWithLockedRecord($tenantId,$placementId,static function(array $placement,PDO $pdo)use($tenantId,$placementId,$body,$user,$legal):array{
        if($placement['engagement_type']!=='c2c')throw new InvalidArgumentException('Corporate payee details require a C2C placement.',422);
        $owned=placementOwnershipFields($placement['coreflux_overridden_fields']??null);
        $state=placementCorpState($pdo,$placement);$before=$state['corp'];$priorParty=$state['party'];
        $token=(string)($body['review_token']??'');
        if(($before||$priorParty||$token!=='')&&(!preg_match('/^[a-f0-9]{64}$/D',$token)||!hash_equals($state['review_token'],$token))) {
            throw new RuntimeException('Corporate details changed or were not reviewed. Reload them before saving.',409);
        }
        $companyOwner=placementsCompanyDirectoryTenantId();
        $companyId=null;
        if(array_key_exists('company_id',$body)) {
            if(!preg_match('/^[1-9][0-9]*$/D',(string)$body['company_id']))throw new InvalidArgumentException('Choose a valid corporate company.',422);
            $companyId=(int)$body['company_id'];
        } elseif($before&&$before['corp_legal_name']===$legal&&!empty($before['company_id']))$companyId=(int)$before['company_id'];
        if($companyId) {
            $company=companiesGet($companyId,$companyOwner);
            if(!$company)throw new InvalidArgumentException('The corporate company is unavailable in this workspace.',422);
            if(array_key_exists('company_id',$body)&&$legal!==$company['name'])throw new InvalidArgumentException('The selected company and legal name must match.',422);
        } else {
            $companyId=companiesUpsertByName($companyOwner,$legal,['created_by_user_id'=>$user['id']??null],['vendor']);
        }
        $identityChanged=$before&&((int)$before['company_id']!==$companyId||$before['corp_legal_name']!==$legal);
        if($identityChanged)foreach(array_merge(['corp_ein_ct'],placementCorpDocumentFields()) as $privateField) {
            if(!empty($before[$privateField]))throw new RuntimeException('Review the linked corporate tax record and documents before changing this payee.',409);
        }
        $changes=['company_id'=>$companyId,'corp_legal_name'=>$legal];
        if($identityChanged)foreach(array_keys(placementCorpMetadataFields()) as $field)$changes[$field]=null;
        foreach(placementCorpMetadataFields() as $field=>$limit) {
            if(!array_key_exists($field,$body))continue;
            $value=$body[$field]===null?null:trim((string)$body[$field]);if($value==='')$value=null;
            if($value!==null&&mb_strlen($value)>$limit)throw new InvalidArgumentException('Corporate field is too long: '.$field,422);
            if($field==='corp_country'&&$value!==null) {
                if(!preg_match('/^[A-Za-z]{2}$/D',$value))throw new InvalidArgumentException('Use a two-letter corporate country code.',422);
                $value=strtoupper($value);
            }
            if($field==='corp_contact_email'&&$value!==null&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid corporate contact email.',422);
            if($field==='coi_expiry'&&$value!==null) {
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Enter a valid insurance expiry date.',422);
            }
            $changes[$field]=$value;
        }
        foreach(placementCorpDocumentFields() as $field) {
            if(!array_key_exists($field,$body))continue;
            $value=$body[$field];
            if($value===null||$value===''){$changes[$field]=null;continue;}
            if(!preg_match('/^[1-9][0-9]*$/D',(string)$value))throw new InvalidArgumentException('Choose a valid corporate document.',422);
            $q=$pdo->prepare('SELECT s.id FROM storage_objects s JOIN placement_documents d ON d.storage_object_id=s.id AND d.tenant_id=s.tenant_id
                WHERE s.id=? AND s.tenant_id=? AND s.soft_deleted_at IS NULL AND d.placement_id=? AND d.deleted_at IS NULL FOR UPDATE');
            $q->execute([(int)$value,$tenantId,$placementId]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('The corporate document must belong to this placement and workspace.',422);
            $changes[$field]=(int)$value;
        }
        $terms=placementEconomicsNormaliseTerms((string)($body['payment_terms_override']??$before['payment_terms_override']??$placement['vendor_payment_terms_override']??'NET30'),'');
        if($terms==='')throw new InvalidArgumentException('Choose valid corporate payment terms.',422);
        $pwp=placementEconomicsTermsArePwp($terms)?1:(int)($before['pwp_enabled']??$placement['vendor_pwp_enabled']??0);
        if(array_key_exists('pwp_enabled',$body)) {
            if(!in_array($body['pwp_enabled'],[true,false,0,1,'0','1'],true))throw new InvalidArgumentException('Choose yes or no for paid when paid.',422);
            $pwp=(int)(bool)$body['pwp_enabled'];
        } elseif(array_key_exists('payment_terms_override',$body))$pwp=(int)placementEconomicsTermsArePwp($terms);
        if(!$pwp&&placementEconomicsTermsArePwp($terms))throw new InvalidArgumentException('Payment terms and paid when paid must agree.',422);
        $changes['payment_terms_override']=$terms;$changes['pwp_enabled']=$pwp;
        $ein=trim((string)($body['corp_ein']??''));$einChanged=false;
        if($ein!=='') {
            if(!preg_match('/^[0-9]{2}-?[0-9]{7}$/D',$ein))throw new InvalidArgumentException('Enter a nine-digit corporate EIN.',422);
            $digits=str_replace('-','',$ein);
            try {
                $current=!empty($before['corp_ein_ct'])?decryptField($before['corp_ein_ct']):null;
                if($current===null||preg_replace('/[^0-9]/','',$current)!==$digits) {
                    $changes['corp_ein_ct']=encryptField($digits);$changes['corp_ein_last4']=last4($digits);$einChanged=true;
                }
            }catch(Throwable $e){throw new RuntimeException('Corporate tax data could not be saved securely. Contact your administrator.',503);}
        }
        $cycleId=(int)($priorParty['operating_cycle_id']??$placement['ap_operating_cycle_id']??0);
        if(!$cycleId)$cycleId=(int)placementEconomicsEnsureStandardCycle($tenantId,'ap',$placement['vendor_pay_cycle']?:'biweekly');
        $q=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND id=? AND purpose="ap" AND active=1 FOR UPDATE');
        $q->execute([$tenantId,$cycleId]);$cycle=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cycle)throw new InvalidArgumentException('Review the corporate payment schedule before saving these details.',422);
        $owned=array_values(array_unique(array_merge($owned,['corp_identity','vendor_payment_terms_override','vendor_pwp_enabled','vendor_pay_cycle','vendor_pay_cycle_anchor','ap_operating_cycle_id'])));
        $summary=['coreflux_overridden_fields'=>json_encode($owned,JSON_THROW_ON_ERROR),'vendor_payment_terms_override'=>$terms,
            'vendor_pwp_enabled'=>$pwp,'vendor_pay_cycle'=>$cycle['cadence'],'vendor_pay_cycle_anchor'=>$cycle['anchor_date'],'ap_operating_cycle_id'=>$cycleId];
        $q=$pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id=? AND company_id=? ORDER BY id LIMIT 2 FOR UPDATE');
        $q->execute([$tenantId,$companyId]);$vendors=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($vendors)>1)throw new RuntimeException('Review the duplicate corporate vendor records before saving.',409);
        $vendorId=$vendors?(int)$vendors[0]:0;
        $desiredParty=['company_id'=>$companyId,'display_name'=>$legal,'ap_vendor_id'=>$vendorId,'payment_terms'=>$terms,
            'pwp_enabled'=>$pwp,'source_managed'=>0,'active'=>1,'operating_cycle_id'=>$cycleId,
            'payment_terms_overridden'=>1,'pwp_overridden'=>1,'cycle_overridden'=>1];
        if($vendorId&&$before&&$priorParty&&!placementEconomicsNativeDiffers($before,$changes+['ap_vendor_id'=>$vendorId])
            &&!placementEconomicsNativeDiffers($priorParty,$desiredParty)&&!placementEconomicsNativeDiffers($placement,$summary)) {
            return ['ok'=>true,'changed'=>false,'corp'=>placementCorpPublic($before),'review_token'=>$state['review_token']];
        }
        $vendor=placementEconomicsEnsureVendor($tenantId,$legal,$companyId,'c2c_corp',$terms,(bool)$pwp,$placementId,currentTenantId());
        if(!$vendor)throw new RuntimeException('The corporate payment destination could not be resolved.',503);
        $changes['ap_vendor_id']=(int)$vendor['id'];
        if(!$before) {
            $values=['tenant_id'=>$tenantId,'placement_id'=>$placementId]+$changes;
            $pdo->prepare('INSERT INTO placement_corp_details ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_map(static fn($k)=>':'.$k,array_keys($values))).')')->execute($values);
        } elseif(placementEconomicsNativeDiffers($before,$changes)) {
            $pdo->prepare('UPDATE placement_corp_details SET '.implode(',',array_map(static fn($k)=>$k.'=:'.$k,array_keys($changes))).',updated_at=NOW() WHERE tenant_id=:tenant AND placement_id=:placement')->execute($changes+['tenant'=>$tenantId,'placement'=>$placementId]);
        }
        $pdo->prepare('UPDATE placements SET '.implode(',',array_map(static fn($k)=>$k.'=:'.$k,array_keys($summary))).' WHERE tenant_id=:tenant AND id=:placement')->execute($summary+['tenant'=>$tenantId,'placement'=>$placementId]);
        $partyId=placementEconomicsUpsertParty($tenantId,$placementId,[
            'source_ref'=>'corp:'.$placementId,'source_type'=>'corp','source_id'=>$placementId,'role'=>'c2c_vendor',
            'display_name'=>$legal,'company_id'=>$companyId,'ap_vendor_id'=>(int)$vendor['id'],
            'money_flow'=>'payable','settlement_channel'=>'ap','fee_basis'=>$priorParty['fee_basis']??'pay_rate',
            'fee_pct'=>$priorParty['fee_pct']??null,'fee_flat'=>$priorParty['fee_flat']??null,'payment_terms'=>$terms,'pwp_enabled'=>$pwp,
            'operating_cycle_id'=>$cycleId,'effective_from'=>$priorParty['effective_from']??$placement['start_date'],
            'effective_to'=>$priorParty['effective_to']??$placement['actual_end_date']??$placement['end_date'],
            'source_managed'=>0,'created_by_user_id'=>$user['id']??null,
        ]);
        if(!$partyId)throw new RuntimeException('The corporate participant could not be saved.',503);
        $pdo->prepare('UPDATE placement_economic_parties SET source_managed=0,payment_terms=?,pwp_enabled=?,
            payment_terms_overridden=1,pwp_overridden=1,cycle_overridden=1 WHERE tenant_id=? AND placement_id=? AND id=?')
            ->execute([$terms,$pwp,$tenantId,$placementId,$partyId]);
        $graph=placementEconomicsReconcile($tenantId,$placementId);
        if(empty($graph['available'])||!empty($graph['errors']))throw new RuntimeException('The corporate contract could not be reconciled. Review its links and try again.',422);
        $read=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=?');$read->execute([$tenantId,$placementId]);
        $after=placementCorpState($pdo,$read->fetch(PDO::FETCH_ASSOC));
        if((int)($after['corp']['company_id']??0)!==$companyId||(int)($after['party']['company_id']??0)!==$companyId
            ||$after['party']['display_name']!==$legal||$after['party']['payment_terms']!==$terms
            ||(int)$after['party']['ap_vendor_id']!==(int)$after['corp']['ap_vendor_id'])throw new RuntimeException('The corporate payee links did not retain the reviewed values.',409);
        placementRequiredAudit('placement.corp.updated',[
            'placement_id'=>$placementId,'economic_party_id'=>$partyId,'fields'=>array_values(array_diff(array_keys($body),['review_token'])),
            'before'=>placementCorpPublic($before),'after'=>placementCorpPublic($after['corp']),
            'ein_changed'=>$einChanged,'core_owned_fields'=>$owned,
        ],$placementId);
        return ['ok'=>true,'changed'=>true,'corp'=>placementCorpPublic($after['corp']),'review_token'=>$after['review_token']];
    });
}
