<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!is_file('/run/pilot-channel-fixture')||posix_geteuid()!==0)exit(2);
require_once __DIR__.'/../deploy/thunderhawk_pilot_channel.php';
require_once __DIR__.'/../deploy/full_core_maintenance.php';
mkdir('/fixture/public_html',0755,true);mkdir('/fixture/private',0700);
$old='<?php echo json_encode(["version"=>"old","posix_available"=>function_exists("posix_geteuid"),"private_readable"=>is_readable("/fixture/private")]);';
$new='<?php echo json_encode(["version"=>"new"]);';
file_put_contents('/fixture/public_html/index.php',$old);file_put_contents('/fixture/public_html/other.php','<?php echo "other-old";');
file_put_contents('/fixture/public_html/.htaccess',"Options -Indexes\n");
$operation=str_repeat('a',32);$path='core-maintenance-'.$operation.'-'.str_repeat('b',32).'.php';
$held=fullCoreMaintenanceWebRule($operation,$path);$token=str_repeat('c',64);
$spec=['operation'=>$operation,'candidate_commit'=>str_repeat('d',40),'token_sha256'=>hash('sha256',$token),
 'held_web_sha256'=>hash('sha256',$held),'library_sha256'=>hash_file('sha256',__DIR__.'/../deploy/full_core_maintenance_channel.php'),
 'path'=>$path,'root'=>'/fixture/public_html','private_directory'=>'/fixture/private','host'=>'fixture.local','pool'=>'www',
 'installer_uid'=>0,'issued_at'=>time(),'expires'=>time()+600];
$candidate=[['path'=>'index.php','sha256'=>hash('sha256',$new)]];$recovery=[['path'=>'index.php','sha256'=>hash('sha256',$old)]];
file_put_contents('/fixture/public_html/'.$path,thunderhawkPilotChannelRender($spec,$candidate,$recovery));
$expired=$spec;$expired['path']='core-maintenance-'.$operation.'-'.str_repeat('e',32).'.php';$expired['issued_at']=time()-600;$expired['expires']=time()-1;
file_put_contents('/fixture/public_html/'.$expired['path'],thunderhawkPilotChannelRender($expired,$candidate,$recovery));
$plans=[];foreach(['candidate'=>$candidate,'recovery'=>$recovery]as$phase=>$files){$p=['version'=>1,'operation'=>$operation,'candidate_commit'=>$spec['candidate_commit'],
 'root'=>$spec['root'],'held_web_sha256'=>$spec['held_web_sha256'],'phase'=>$phase,'files'=>$files];$plans[$phase]=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));}
file_put_contents('/fixture/private/control.json',json_encode(['spec'=>$spec,'token'=>$token,'held'=>$held,'old'=>$old,'new'=>$new,'plans'=>$plans,'expired_path'=>$expired['path']],JSON_THROW_ON_ERROR));
foreach(glob('/fixture/public_html/*')as$file)chmod($file,0644);chmod('/fixture/public_html/.htaccess',0644);chmod('/fixture/private/control.json',0600);
echo "Prepared distinct root installer and www-data FPM fixture\n";
