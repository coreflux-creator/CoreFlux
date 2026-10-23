<?php
/** Real installed SDK; signing only with synthetic credentials, never object IO. */
declare(strict_types=1);
require_once __DIR__.'/../core/StorageService.php';
if (class_exists('Aws\\S3\\S3Client',false)) throw new RuntimeException('SDK must not already be loaded by the test.');
$driver=new \Core\Storage\S3Driver(['bucket'=>'coreflux-synthetic-vault','region'=>'us-east-1',
    'kms_key_id'=>'arn:aws:kms:us-east-1:000000000000:key/00000000-0000-4000-8000-000000000001',
    'access_key_id'=>'SYNTHETICNOTVALID','secret_access_key'=>'synthetic-signing-only-no-cloud-access']);
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
echo "PASS packaged S3 SDK lazy loading, scoped encrypted upload policy and download signing (no object IO)\n";
