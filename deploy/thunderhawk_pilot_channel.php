<?php
/** Render an inert channel when deployment and FPM use different native accounts. */
declare(strict_types=1);
require_once __DIR__.'/full_core_maintenance_channel.php';

function thunderhawkPilotChannelRender(array $spec,array $candidate,array $recovery): string
{
    fullCoreMaintenanceChannelValidate($spec);
    if(!is_int($spec['installer_uid']??null)||$spec['installer_uid']<0)throw new RuntimeException('Installer owner required.');
    $plans=[];
    foreach(['candidate'=>$candidate,'recovery'=>$recovery]as$phase=>$files){
        if(!$files)throw new RuntimeException('Both cache phases require pinned files.');
        $plan=['version'=>1,'operation'=>$spec['operation'],'candidate_commit'=>$spec['candidate_commit'],
            'root'=>$spec['root'],'held_web_sha256'=>$spec['held_web_sha256'],'phase'=>$phase,'files'=>$files];
        // File bytes are checked at execution; rendering checks the complete relative-path contract.
        $seen=[];foreach($files as$file){$path=$file['path']??null;$hash=$file['sha256']??null;
            if(!is_string($path)||!preg_match('~^(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.php$~D',$path)
                ||$path===$spec['path']||isset($seen[$path])||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('Invalid fixed cache inventory.');
            $seen[$path]=true;
        }
        $plans[hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR))]=$plan;
    }
    $library=file_get_contents(__DIR__.'/full_core_maintenance_channel.php');
    if(!is_string($library)||hash('sha256',$library)!==$spec['library_sha256'])throw new RuntimeException('Pinned channel library differs.');
    $library=preg_replace('/^<\?php\s*/','',$library,1);
    $library=str_replace('declare(strict_types=1);','',$library);
    $template=file_get_contents(__DIR__.'/thunderhawk_pilot_channel_web.php');
    foreach(['__PILOT_SPEC__','__PILOT_PLANS__','__PILOT_LIBRARY__']as$key)if(substr_count($template,$key)!==1)throw new RuntimeException('Pilot channel template differs.');
    return str_replace(['__PILOT_SPEC__','__PILOT_PLANS__','/* __PILOT_LIBRARY__ */'],[
        base64_encode(json_encode($spec,JSON_THROW_ON_ERROR)),base64_encode(json_encode($plans,JSON_THROW_ON_ERROR)),$library],$template);
}
