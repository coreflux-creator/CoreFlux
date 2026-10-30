<?php
/** Real installed SDK with synthetic credentials; signing and intercepted writes, never network IO. */
declare(strict_types=1);
require_once __DIR__.'/../core/StorageService.php';
if (class_exists('Aws\\S3\\S3Client',false)) throw new RuntimeException('SDK must not already be loaded by the test.');
$driver=new \Core\Storage\S3Driver(['bucket'=>'coreflux-synthetic-vault','region'=>'us-east-1',
    'kms_key_id'=>'arn:aws:kms:us-east-1:000000000000:key/00000000-0000-4000-8000-000000000001',
    'access_key_id'=>'SYNTHETICNOTVALID','secret_access_key'=>'synthetic-signing-only-no-cloud-access']);
$rotated=['bucket'=>'coreflux-synthetic-vault','region'=>'us-east-1',
    'kms_key_id'=>'arn:aws:kms:us-east-1:000000000000:key/00000000-0000-4000-8000-000000000001',
    'access_key_id'=>'ROTATEDSYNTHETIC','secret_access_key'=>'synthetic-rotated-signing-only'];
if((new \Core\Storage\S3Driver($rotated))->location_fingerprint()!==$driver->location_fingerprint()
    ||(new \Core\Storage\S3Driver(array_replace($rotated,['bucket'=>'other-synthetic-vault'])))->location_fingerprint()===$driver->location_fingerprint()
    ||(new \Core\Storage\S3Driver(array_replace($rotated,['kms_key_id'=>'synthetic-other-key'])))->location_fingerprint()===$driver->location_fingerprint())throw new RuntimeException('Storage identity must track bucket/encryption configuration, not rotating credentials.');
$key='people/10/document/100/synthetic.pdf';
$post=$driver->get_presigned_post($key,['policy'=>[['content-length-range',1,25*1024*1024]]]);
if (!str_starts_with($post['form_action']??'','https://') || ($post['fields']['key']??null)!==$key
    || isset($post['fields']['acl'])
    || ($post['fields']['x-amz-server-side-encryption']??null)!=='aws:kms') throw new RuntimeException('Private encrypted upload form is invalid.');
$policy=json_decode(base64_decode($post['fields']['Policy']??$post['fields']['policy']??'',true),true,32,JSON_THROW_ON_ERROR);
if (!in_array(['content-length-range',1,25*1024*1024],$policy['conditions']??[],true)) throw new RuntimeException('Upload length restriction missing.');
foreach(['key'=>$key,'x-amz-server-side-encryption'=>'aws:kms',
    'x-amz-server-side-encryption-aws-kms-key-id'=>$post['fields']['x-amz-server-side-encryption-aws-kms-key-id'],
    'bucket'=>'coreflux-synthetic-vault']as$field=>$value){
    if(!in_array([$field=>$value],$policy['conditions'],true))throw new RuntimeException('Unsigned upload restriction: '.$field);
}
if(isset($post['fields']['ServerSideEncryption'])||isset($post['fields']['SSEKMSKeyId']))throw new RuntimeException('PUT-only encryption fields must not appear in POST forms.');
$url=$driver->get_signed_url($key,60,['filename_for_download'=>'synthetic.pdf']);
if (!str_starts_with($url,'https://') || !str_contains($url,'X-Amz-Signature=')) throw new RuntimeException('Download signing failed.');
$clientProperty=new ReflectionProperty($driver,'client');$client=$clientProperty->getValue($driver);$requests=[];
$client->getHandlerList()->setHandler(static function($command,$request)use(&$requests){
    $requests[]=$request;return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result(['ETag'=>'synthetic','VersionId'=>'synthetic-version']));
});
$driver->put($key,__FILE__,['source_is_bytes'=>true,'if_not_exists'=>true,'mime'=>'application/pdf']);
if(count($requests)!==1||(string)$requests[0]->getBody()!==__FILE__||$requests[0]->getHeaderLine('If-None-Match')!=='*'
    ||$requests[0]->getHeaderLine('x-amz-server-side-encryption')!=='aws:kms'
    ||$requests[0]->hasHeader('x-amz-acl'))throw new RuntimeException('Private conditional byte upload request is invalid.');
echo "PASS packaged S3 SDK lazy loading, scoped encrypted upload policy, signing and conditional byte upload (no network IO)\n";
