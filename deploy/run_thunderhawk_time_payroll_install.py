"""Stage verified private tooling and run an explicit isolated-pilot phase once.

Observe reuses the existing handle. A transport timeout never starts another action.
State output deliberately excludes the diagnostic token and private database backup.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import json
from pathlib import Path
import secrets
import shlex
import subprocess

ROOT=Path(__file__).resolve().parents[1]
PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030'
WEB='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
SSH=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes',
     '-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
FILES=['install_thunderhawk_time_payroll.php','thunderhawk_time_payroll_schema.php','thunderhawk_pilot_backup.php',
       'thunderhawk_pilot_channel.php','thunderhawk_pilot_channel_web.php',
       'full_core_maintenance.php','full_core_maintenance_host.php','full_core_maintenance_channel.php',
       'full_core_maintenance_channel_web.php']
sha=lambda b:hashlib.sha256(b).hexdigest()


def git(*args: str)->bytes:
    return subprocess.check_output(['git',*args],cwd=ROOT)


def observe(handle: dict)->dict:
    directory=handle['directory']
    assert directory.startswith(PRIVATE+'/time-payroll-')
    code=r'''<?php
const DIR=DIRECTORY;
if(PHP_SAPI!=='cli'||posix_geteuid()!==1003||realpath(DIR)!==DIR)exit(2);
$p=DIR.'/installation-state.json';
if(!is_file($p)){echo json_encode(['state_exists'=>false,'installation_started'=>false]);exit;}
if(is_link($p)||fileowner($p)!==1003||(fileperms($p)&0777)!==0600)exit(2);
$s=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);$pid=(int)($s['pid']??0);$running=false;
if($pid>0&&is_dir('/proc/'.$pid)){
 $stat=@file_get_contents('/proc/'.$pid.'/stat');$cmd=@file_get_contents('/proc/'.$pid.'/cmdline');
 if(is_string($stat)&&is_string($cmd)){$fields=preg_split('/\s+/',trim(substr($stat,strrpos($stat,') ')+2)));
 $running=($fields[19]??null)===($s['pid_start_ticks']??null)&&str_contains($cmd,DIR.'/install_thunderhawk_time_payroll.php');}
}
echo json_encode(['state_exists'=>true,'phase'=>$s['phase'],'operation'=>$s['operation'],'pid'=>$pid,'running'=>$running,
 'candidate'=>$s['candidate'],'failure'=>$s['failure']??null,'schema'=>$s['schema']??null,
 'database_backup_retained'=>isset($s['database_backup']),'completed_at'=>$s['completed_at']??null,
 'recovered_at'=>$s['recovered_at']??null,'production_changed'=>false]);
'''.replace('DIRECTORY',repr(directory))
    result=subprocess.run(SSH+['/usr/bin/php8.2'],input=code.encode(),capture_output=True,timeout=30)
    if result.returncode:raise RuntimeError('Private receipt observation failed')
    return json.loads(result.stdout)


def main()->None:
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['inspect','install','recover','observe'])
    parser.add_argument('--ci-run')
    parser.add_argument('--handle',required=True,type=Path)
    args=parser.parse_args()
    if args.action in ['observe','install','recover']:
        handle=json.loads(args.handle.read_text())
        if args.action=='observe':print(json.dumps(observe(handle)));return
        current=observe(handle)
        if current.get('running'):raise SystemExit('The existing operation is still running; observe the same handle')
        if args.action=='install' and current.get('state_exists'):raise SystemExit('An installation receipt already exists; do not replay')
        if args.action=='recover' and (not current.get('state_exists') or current.get('phase') in ['completed','recovered','aborted_before_schema']):raise SystemExit('No unresolved installation is available for recovery')
    else:
        if args.handle.exists():raise SystemExit('Keep the existing handle; choose a new filename for a new preparation')
        if not args.ci_run or not args.ci_run.isdigit():raise SystemExit('Exact completed CI run required')
        ci=json.loads(subprocess.check_output(['gh','run','view',args.ci_run,'--json','headSha,status,conclusion,jobs'],cwd=ROOT))
        assert ci['status']=='completed' and ci['conclusion']=='success' and len(ci['jobs'])==11 and all(j['conclusion']=='success' for j in ci['jobs'])
        commit=ci['headSha'];packageBytes=(ROOT/'output/time-payroll-application-package.json').read_bytes()
        package=json.loads(packageBytes);receipt=json.loads((ROOT/'output/time-payroll-application-package-receipt.json').read_text())
        assert sha(packageBytes)==receipt['package_sha256'] and package['application_source_commit']=='846e86204d188c6ec10d8a5b4537c6a79150f1ce'
        changed=git('diff','--name-only',package['application_source_commit'],commit).decode().splitlines()
        assert all(p.startswith(('deploy/','docs/','tests/','.github/')) for p in changed),'Application changed since accepted package'
        bundle={name:git('show',commit+':deploy/'+name) for name in FILES}
        bundle['time-payroll-application-package.json']=packageBytes
        bundle['host-preparation.json']=(ROOT/'output/time-payroll-host-preparation.json').read_bytes()
        for p,v in package['files'].items():
            if p.endswith('.sql') and '/migrations/' in p:bundle['candidate/'+p]=base64.b64decode(v['body'],validate=True)
        hashes={f:sha(b) for f,b in bundle.items()}
        manifest=json.dumps({'tools_commit':commit,'ci_run':int(args.ci_run),'files':hashes},separators=(',',':')).encode()
        bundle['trusted-tools.json']=manifest
        directory=PRIVATE+'/time-payroll-'+sha(manifest)[:16]+'-'+secrets.token_hex(6)
        handle={'directory':directory,'tools_commit':commit,'ci_run':int(args.ci_run),
                'package_sha256':sha(packageBytes),'tools_sha256':sha(manifest)}
        for name,body in bundle.items():
            if name.endswith('.php'):
                result=subprocess.run(SSH+['/usr/bin/php8.2 -l'],input=body,capture_output=True,timeout=25)
                if result.returncode:raise RuntimeError('Remote PHP lint failed for '+name)
            code=r'''$directory=DIRECTORY;$name=FILENAME;$root=dirname($directory);
if(posix_geteuid()!==1003||realpath($root)!==$root||fileowner($root)!==1003||(fileperms($root)&0777)!==0700)exit(2);
if(!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$name)||str_contains($name,'..'))exit(2);
$path=$directory.'/'.$name;$parts=explode('/',substr(dirname($path),strlen($root)+1));$parent=$root;
foreach($parts as$part){$parent.='/'.$part;if(is_link($parent))exit(2);if(!is_dir($parent)&&!mkdir($parent,0700))exit(3);
if(realpath($parent)!==$parent||fileowner($parent)!==1003||(fileperms($parent)&0777)!==0700)exit(4);}
if(is_link($path))exit(2);$body=file_get_contents('php://stdin');if(hash('sha256',$body)!==DIGEST)exit(5);
if(file_exists($path)){if(!is_file($path)||hash_file('sha256',$path)!==DIGEST||fileowner($path)!==1003||(fileperms($path)&0777)!==0600)exit(6);exit;}
$old=umask(0077);$f=fopen($path,'x+b');umask($old);if(!$f)exit(7);
if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))exit(8);fclose($f);'''
            code=code.replace('DIRECTORY',repr(directory)).replace('FILENAME',repr(name)).replace('DIGEST',repr(sha(body)))
            result=subprocess.run(SSH+['/usr/bin/php8.2 -r '+shlex.quote(code)],input=body,capture_output=True,timeout=40)
            if result.returncode:raise RuntimeError('Private tooling stage failed for '+name)
        args.handle.parent.mkdir(parents=True,exist_ok=True)
        args.handle.write_text(json.dumps(handle,indent=2)+'\n')
    command='cd '+shlex.quote(WEB)+' && COREFLUX_ENV=staging /usr/bin/php8.2 '+shlex.quote(handle['directory']+'/install_thunderhawk_time_payroll.php')
    command+=' '+args.action+' '+handle['package_sha256']+' '+handle['tools_sha256']
    try:result=subprocess.run(SSH+[command],capture_output=True,text=True,timeout=600)
    except subprocess.TimeoutExpired:
        print(json.dumps({'transport_timeout':True,'observation':observe(handle),'repeat_action':False}));raise SystemExit(1)
    # Both entry-point outputs are intentionally sanitized. Never print the state file.
    if result.stdout:print(result.stdout.strip())
    if result.stderr:print(result.stderr.strip()[:1800])
    print(json.dumps({'exit_code':result.returncode,'observation':observe(handle)}))
    raise SystemExit(result.returncode)


if __name__=='__main__':main()
