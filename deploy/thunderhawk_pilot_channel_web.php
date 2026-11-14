<?php
/** Inert template: fixed public file hashes only, no private runtime or application bootstrap. */
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
header('Cache-Control: no-store, private, max-age=0');header('Content-Type: application/json');header('X-Robots-Tag: noindex, nofollow');
$spec=json_decode((string)base64_decode('__PILOT_SPEC__',true),true);
$plans=json_decode((string)base64_decode('__PILOT_PLANS__',true),true);
if(!is_array($spec)||!is_array($plans)||PHP_SAPI!=='fpm-fcgi'||!function_exists('fpm_get_status')
 ||($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!==($spec['host']??null)
 ||($_SERVER['REQUEST_URI']??'')!=='/'.($spec['path']??'')||($_SERVER['QUERY_STRING']??'')!==''||($_SERVER['PATH_INFO']??'')!==''
 ||($_SERVER['SCRIPT_FILENAME']??'')!==($spec['root']??'').'/'.($spec['path']??'')||realpath(__DIR__)!==($spec['root']??null)
 ||time()<($spec['issued_at']??PHP_INT_MAX)||time()>=($spec['expires']??0)||!is_string($spec['token_sha256']??null)
 ||!hash_equals($spec['token_sha256'],hash('sha256',(string)($_SERVER['HTTP_X_COREFLUX_MAINTENANCE']??'')))){http_response_code(404);exit;}
/* __PILOT_LIBRARY__ */
try{
 fullCoreMaintenanceChannelValidate($spec);
 if(!is_int($spec['installer_uid']??null)||$spec['installer_uid']<0)throw new RuntimeException('Installer owner required.');
 $held=static function()use($spec):void{
  foreach([$spec['root'].'/.htaccess',$spec['root'].'/'.$spec['path']]as$path){clearstatcache(true,$path);$s=@lstat($path);
   if(!$s||is_link($path)||realpath($path)!==$path||!is_file($path)||$s['nlink']!==1||$s['uid']!==$spec['installer_uid']
      ||!in_array($s['mode']&07777,[0644,0664],true))throw new RuntimeException('Installer-owned web files required.');}
  if(hash_file('sha256',$spec['root'].'/.htaccess')!==$spec['held_web_sha256'])throw new RuntimeException('Exact maintenance hold required.');
 };
 $held();$raw=file_get_contents('php://input',false,null,0,4097);
 if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('Bounded request required.');
 $request=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($request))throw new RuntimeException('Request required.');
 $status=fpm_get_status();if(!is_array($status))throw new RuntimeException('Native pool status unavailable.');
 $pool=fullCoreMaintenanceChannelPool($status,$spec['pool'],getmypid(),$spec['root'].'/'.$spec['path']);unset($status);
 $result=['version'=>1,'operation'=>$spec['operation'],'candidate_commit'=>$spec['candidate_commit'],
  'held_web_sha256'=>$spec['held_web_sha256'],'observed_at'=>gmdate('c'),'self_pid'=>getmypid(),'pool'=>$pool,
  'private_files_read'=>false,'application_bootstrap'=>false,'global_cache_reset'=>false];
 if($request!==['action'=>'status']){
  $keys=array_keys($request);sort($keys);
  if($keys!==['action','plan_sha256']||$request['action']!=='invalidate'||!is_string($request['plan_sha256'])
    ||!isset($plans[$request['plan_sha256']])||!$pool['request_pool_quiet'])throw new RuntimeException('Quiet pool and a fixed cache plan required.');
  $plan=$plans[$request['plan_sha256']];
  if(hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR))!==$request['plan_sha256'])throw new RuntimeException('Fixed cache plan differs.');
  $files=fullCoreMaintenanceChannelCacheFiles($plan,$spec);
  $cache=function_exists('opcache_get_status')?opcache_get_status(false):false;
  if(!is_array($cache)||($cache['opcache_enabled']??false)!==true||($cache['restart_pending']??true)
    ||($cache['restart_in_progress']??true)||!function_exists('opcache_invalidate'))throw new RuntimeException('Stable cache controls required.');
  foreach($files as$file){$held();if(!opcache_invalidate($file,true))throw new RuntimeException('Targeted cache invalidation failed.');}
  $result+=['cache_plan_sha256'=>$request['plan_sha256'],'cache_phase'=>$plan['phase'],'invalidated_files'=>count($files)];
 }
 $held();echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable$error){http_response_code(503);echo json_encode(['error'=>'Pilot maintenance diagnostic refused.','reason'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
