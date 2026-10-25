<?php
/** Canonical AP bill identity. This review never grants payment authority. */
declare(strict_types=1);
require_once __DIR__.'/purepay_service.php';
require_once __DIR__.'/purepay_payment_evidence.php';

function purepayBillId(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value)) {
        throw new PurePayApiException('Enter the exact PurePay bill ID.');
    }
    return $value;
}

function purepayBillText(mixed $value,string $label,int $max=255): string
{
    if (!is_string($value) || trim($value)==='' || mb_strlen($value)>$max || preg_match('/[\x00-\x1f\x7f]/',$value)) {
        throw new PurePayApiException($label.' is missing or invalid. Review the source record.');
    }
    return trim($value);
}

function purepayBillDate(mixed $value): string
{
    if (!is_string($value)) throw new PurePayApiException('A bill date is missing.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value) throw new PurePayApiException('A bill date is invalid.');
    return $value;
}

/** Whitelist commercial identity; never read bank or tax values into a review. */
function purepayBillLocal(PDO $pdo,int $tenant,int $billId,bool $lock=false): array
{
    if ($tenant<=0 || $billId<=0) throw new PurePayApiException('A Core bill is required.');
    $suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT id,tenant_id,entity_id,internal_ref,bill_number,vendor_name,bill_date,due_date,currency,total,status
        FROM ap_bills WHERE tenant_id=? AND id=?'.$suffix);
    $q->execute([$tenant,$billId]);$bill=$q->fetch(PDO::FETCH_ASSOC);
    if (!$bill) throw new PurePayApiException('The Core bill was not found in this company.');
    if (!in_array($bill['status'],['approved','partially_paid','paid'],true)) throw new PurePayApiException('Approve or resolve the Core bill before linking it.');
    $q=$pdo->prepare('SELECT id,vendor_name,remit_to_email,contact_email FROM ap_vendors_index WHERE tenant_id=? AND vendor_name=?'.$suffix);
    $q->execute([$tenant,$bill['vendor_name']]);$vendors=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($vendors)!==1 || $vendors[0]['vendor_name']!==$bill['vendor_name']) throw new PurePayApiException('Review the Core bill vendor before linking it.');
    $vendor=$vendors[0];$email=strtolower(trim((string)($vendor['remit_to_email']?:$vendor['contact_email'])));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new PurePayApiException('The Core vendor needs a valid remit email.');
    $q=$pdo->prepare('SELECT id,legal_name FROM accounting_entities WHERE tenant_id=? AND id=?'.$suffix);
    $q->execute([$tenant,(int)$bill['entity_id']]);$entity=$q->fetch(PDO::FETCH_ASSOC);
    if (!$entity) throw new PurePayApiException('The bill needs a legal entity in this Core company.');
    $total=purepayMoneyCents($bill['total']);
    if ($total===null || $total<=0 || $bill['currency']!=='USD') throw new PurePayApiException('This bill link requires an explicit positive USD total.');
    return ['bill_id'=>$billId,'reference'=>purepayBillText($bill['internal_ref'],'Core reference',40),
        'invoice_number'=>purepayBillText($bill['bill_number'],'Vendor invoice number',80),
        'vendor_id'=>(int)$vendor['id'],'vendor_name'=>purepayBillText($vendor['vendor_name'],'Core vendor'),
        'vendor_email'=>$email,'entity_id'=>(int)$entity['id'],'entity_name'=>purepayBillText($entity['legal_name'],'Legal entity'),
        'bill_date'=>purepayBillDate($bill['bill_date']),'due_date'=>purepayBillDate($bill['due_date']),
        'total_cents'=>$total,'currency'=>'USD','status'=>$bill['status']];
}

function purepayBillMapping(PDO $pdo,int $tenant,int $billId,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM purepay_bill_mappings WHERE tenant_id=? AND core_bill_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$billId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function purepayBillRemote(array $bill,array $vendor,string $billId,string $organization): array
{
    $vendorId=purepayBillId($bill['vendor_id']??null);
    if (($bill['id']??null)!==$billId || ($bill['organization_id']??null)!==$organization
        || ($vendor['id']??null)!==$vendorId || ($vendor['organization_id']??null)!==$organization
        || (isset($bill['bill_id']) && $bill['bill_id']!==$billId)) {
        throw new PurePayApiException('PurePay did not confirm the bill, vendor and company identities.');
    }
    $total=purepayMoneyCents($bill['amount']??null);
    if ($total===null || $total<=0 || ($bill['currency']??null)!=='USD') throw new PurePayApiException('PurePay must explicitly identify this bill’s USD currency and total. Historical missing currency needs review in PurePay.');
    $name=purepayBillText($vendor['display_name']??$vendor['legal_name']??$vendor['name']??null,'PurePay vendor');
    if (isset($bill['vendor_name']) && $bill['vendor_name']!==$name) throw new PurePayApiException('PurePay bill and vendor names disagree.');
    $email=strtolower(trim((string)($vendor['email']??'')));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new PurePayApiException('The PurePay vendor remit email is missing or invalid.');
    $status=purepayBillText($bill['status']??null,'PurePay bill status',40);
    if (in_array($status,['void','canceled','cancelled','rejected'],true) || !empty($bill['qbo_deleted_at']) || !empty($bill['merge_lock'])) throw new PurePayApiException('The PurePay bill is closed or being consolidated. Resolve it before linking.');
    return ['bill_id'=>$billId,'vendor_id'=>$vendorId,'vendor_name'=>$name,'vendor_email'=>$email,
        'invoice_number'=>purepayBillText($bill['invoice_number']??null,'PurePay invoice number',80),
        'bill_date'=>purepayBillDate($bill['bill_date']??null),'due_date'=>purepayBillDate($bill['due_date']??null),
        'total_cents'=>$total,'currency'=>'USD','status'=>$status];
}

function purepayBillHash(array $snapshot): string
{
    return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

/** Immutable identity: a fresh review may refresh evidence but never retarget a bill. */
function purepayBillAssertExisting(?array $mapped,array $snapshot): void
{
    if (!$mapped) return;
    foreach (['core_vendor_id'=>$snapshot['core']['vendor_id'],'entity_id'=>$snapshot['core']['entity_id'],
        'origin_hash'=>hash('sha256',$snapshot['origin']),'organization_id'=>$snapshot['organization']['id'],
        'purepay_bill_id'=>$snapshot['provider']['bill_id'],'purepay_vendor_id'=>$snapshot['provider']['vendor_id']] as $field=>$value) {
        if ((string)$mapped[$field] !== (string)$value) throw new PurePayApiException('This Core bill already has a different saved identity. Reconcile the original link; it was not reassigned.');
    }
}

function purepayBillReview(PDO $pdo,int $tenant,int $billId,string $remoteId): array
{
    if ($pdo->inTransaction()) throw new LogicException('Bill review requires provider reads outside a transaction.');
    purepayBillId($remoteId);
    $local=purepayBillLocal($pdo,$tenant,$billId);
    $connection=purepayGetConnection($tenant);
    $binding=purepayFinancialRequire($pdo,$tenant,$connection,true);
    try {
        $bill=purepayResource(purepayGetBill(purepayFinancialKey($connection),$remoteId),['bill']);
        $vendorId=purepayBillId($bill['vendor_id']??null);
        $vendor=purepayResource(purepayGetVendor(purepayFinancialKey($connection),$vendorId),['vendor']);
    } catch (Throwable $e) { throw new PurePayApiException('PurePay bill or vendor could not be read. No link was changed.'); }
    $remote=purepayBillRemote($bill,$vendor,$remoteId,$binding['remote_organization_id']);
    foreach (['invoice_number','bill_date','due_date','total_cents','currency','vendor_name','vendor_email'] as $field) {
        if ($local[$field]!==$remote[$field]) throw new PurePayApiException('Core and PurePay disagree on '.str_replace('_',' ',$field).'. Review both records before linking.');
    }
    $current=purepayFinancialRequire($pdo,$tenant);
    if ($current['binding_hash']!==$binding['binding_hash'] || purepayBillLocal($pdo,$tenant,$billId)!==$local) throw new PurePayApiException('The Core bill or connection changed during review. Review it again.');
    $snapshot=['version'=>1,'tenant_id'=>$tenant,'origin'=>purepayApiBase(),
        'organization'=>['id'=>$binding['remote_organization_id'],'name'=>$binding['remote_organization_name']],
        'connection_review_hash'=>$binding['binding_hash'],'core'=>$local,'provider'=>$remote];
    purepayBillAssertExisting(purepayBillMapping($pdo,$tenant,$billId),$snapshot);
    return ['snapshot'=>$snapshot,'review_hash'=>purepayBillHash($snapshot),'payment_authority'=>false,'reservation_created'=>false];
}

function purepayBillConfirm(PDO $pdo,int $tenant,int $actor,int $billId,string $remoteId,string $hash,callable $audit): array
{
    if ($actor<=0 || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new PurePayApiException('A reviewing administrator and current review are required.');
    $review=purepayBillReview($pdo,$tenant,$billId,$remoteId);
    if (!hash_equals($review['review_hash'],$hash)) throw new PurePayApiException('The bill or connection changed. Review both records again.');
    $s=$review['snapshot'];
    $pdo->beginTransaction();
    try {
        $local=purepayBillLocal($pdo,$tenant,$billId,true);
        $binding=purepayFinancialRequire($pdo,$tenant);
        if ($local!==$s['core'] || $binding['binding_hash']!==$s['connection_review_hash']) throw new PurePayApiException('The Core bill or connection changed before confirmation.');
        $existing=purepayBillMapping($pdo,$tenant,$billId,true);purepayBillAssertExisting($existing,$s);
        $json=json_encode($s,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $changed=!$existing || !hash_equals($existing['review_hash'],$hash);
        if (!$existing) {
            $pdo->prepare('INSERT INTO purepay_bill_mappings
                (tenant_id,core_bill_id,core_vendor_id,entity_id,origin_hash,organization_id,purepay_bill_id,purepay_vendor_id,review_hash,review_json,reviewed_by,reviewed_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$tenant,$billId,$local['vendor_id'],$local['entity_id'],hash('sha256',$s['origin']),
                    $s['organization']['id'],$remoteId,$s['provider']['vendor_id'],$hash,$json,$actor]);
        } elseif (!hash_equals($existing['review_hash'],$hash)) {
            $pdo->prepare('UPDATE purepay_bill_mappings SET review_hash=?,review_json=?,reviewed_by=?,reviewed_at=NOW()
                WHERE tenant_id=? AND core_bill_id=?')->execute([$hash,$json,$actor,$tenant,$billId]);
        }
        $q=$pdo->prepare('SELECT id FROM purepay_bill_mapping_reviews WHERE tenant_id=? AND core_bill_id=? AND review_hash=?');
        $q->execute([$tenant,$billId,$hash]);
        if ($q->fetchColumn()===false) {
            $pdo->prepare('INSERT INTO purepay_bill_mapping_reviews(tenant_id,core_bill_id,review_hash,review_json,reviewed_by,reviewed_at)
                VALUES(?,?,?,?,?,NOW())')->execute([$tenant,$billId,$hash,$json,$actor]);
        }
        // Reconfirming a previously seen snapshot is still an audited change
        // when it replaces a different current review. Only exact replay is silent.
        if ($changed) $audit(['core_bill_id'=>$billId,'provider_bill_id'=>$remoteId,'review_hash'=>$hash]);
        $pdo->commit();return ['linked'=>true,'review_hash'=>$hash,'payment_authority'=>false,'reservation_created'=>false];
    } catch (PDOException $e) {
        if ($pdo->inTransaction())$pdo->rollBack();
        if ($e->getCode()==='23000') throw new PurePayApiException('This PurePay bill is already linked. Reconcile the existing Core obligation; no link was reassigned.');
        throw $e;
    } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function purepayBillSummary(PDO $pdo,int $tenant,int $billId): array
{
    $q=$pdo->prepare('SELECT id FROM ap_bills WHERE tenant_id=? AND id=?');$q->execute([$tenant,$billId]);
    if (!$q->fetchColumn()) throw new PurePayApiException('The Core bill was not found in this company.');
    $row=purepayBillMapping($pdo,$tenant,$billId);
    $result=['linked'=>(bool)$row,'payment_authority'=>false,'reservation_created'=>false,'provider_rechecked'=>false];
    if (!$row) return $result;
    $snapshot=json_decode($row['review_json'],true,512,JSON_THROW_ON_ERROR);
    $current=false;
    try {
        $binding=purepayFinancialRequire($pdo,$tenant);
        $current=$snapshot['core']===purepayBillLocal($pdo,$tenant,$billId) && $snapshot['connection_review_hash']===$binding['binding_hash'];
    } catch (Throwable $e) { /* Preserve the saved identity; it is not current authority. */ }
    return $result+['provider_bill_id'=>$row['purepay_bill_id'],'organization'=>$snapshot['organization'],
        'reviewed_at'=>$row['reviewed_at'],'local_review_current'=>$current,'snapshot'=>$snapshot];
}
