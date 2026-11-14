"""Run the prepared native-session pilot test once; observe never restarts it."""
import argparse, hashlib, json, secrets, shlex, subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030'
WEB='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
SSH=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
sha=lambda b:hashlib.sha256(b).hexdigest()

def observe(handle):
    directory=handle['directory']
    assert directory.startswith(PRIVATE+'/time-payroll-http-')
    code=r'''<?php
const DIR=DIRECTORY;
if(PHP_SAPI!=='cli'||posix_geteuid()!==1003||realpath(DIR)!==DIR)exit(2);
$file=DIR.'/result.json';if(!is_file($file)){echo json_encode(['receipt_exists'=>false]);exit;}
if(is_link($file)||fileowner($file)!==1003||(fileperms($file)&0777)!==0600)exit(2);
$report=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$pid=(int)$report['pid'];
$cmd=$pid>0?@file_get_contents('/proc/'.$pid.'/cmdline'):false;
echo json_encode(['receipt_exists'=>true,'running'=>is_string($cmd)&&str_contains($cmd,DIR.'/verify.php'),'report'=>$report],JSON_THROW_ON_ERROR);
'''.replace('DIRECTORY',repr(directory))
    r=subprocess.run(SSH+['/usr/bin/php8.2'],input=code.encode(),capture_output=True,timeout=30)
    if r.returncode:raise RuntimeError('Observe the existing handle again; observation failed')
    return json.loads(r.stdout)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['run','observe'])
    parser.add_argument('--handle',required=True,type=Path)
    parser.add_argument('--installation-handle',type=Path)
    args=parser.parse_args()
    if args.action=='observe':
        result=observe(json.loads(args.handle.read_text()))
        print(json.dumps(result));return
    assert not args.handle.exists(),'Use observe with the existing handle; do not replay'
    assert args.installation_handle is not None
    install=json.loads(args.installation_handle.read_text())
    verified=json.loads((ROOT/'output/time-payroll-installed-verification.json').read_text())
    assert verified['passed'] and verified['fresh_runtime_loaded'] and verified['maintenance_restored']
    assert install['directory'].startswith(PRIVATE+'/time-payroll-')
    verifier=(ROOT/'deploy/verify_thunderhawk_native_time_payroll_http.php').read_bytes()
    lint=subprocess.run(SSH+['/usr/bin/php8.2 -l'],input=verifier,capture_output=True,timeout=30)
    assert lint.returncode==0,'Remote PHP lint failed'
    manifest=json.dumps({'installation_directory':install['directory'],'package_sha256':install['package_sha256'],
                         'source_commit':verified['candidate'],'verifier_sha256':sha(verifier)},sort_keys=True).encode()
    bundle={'verify.php':verifier,'manifest.json':manifest}
    directory=PRIVATE+'/time-payroll-http-'+secrets.token_hex(12)
    handle={'directory':directory,'manifest_sha256':sha(manifest),'verifier_sha256':sha(verifier),
            'installation_operation':verified['operation'],'created_for':'native time and independent payroll review'}
    args.handle.write_text(json.dumps(handle,indent=2)+'\n')
    for name,body in bundle.items():
        code=r'''$dir=DIRECTORY;$parent=dirname($dir);$path=$dir.'/FILENAME';
if(posix_geteuid()!==1003||realpath($parent)!==$parent||fileowner($parent)!==1003||(fileperms($parent)&0777)!==0700||is_link($dir)||is_link($path))exit(2);
if(!is_dir($dir)&&!mkdir($dir,0700))exit(3);
if(realpath($dir)!==$dir||fileowner($dir)!==1003||(fileperms($dir)&0777)!==0700||file_exists($dir.'/result.json'))exit(4);
$body=file_get_contents('php://stdin');if(hash('sha256',$body)!=='DIGEST')exit(5);
if(file_exists($path)){if(hash_file('sha256',$path)!=='DIGEST')exit(6);exit;}
$mask=umask(0077);$f=fopen($path,'x+b');umask($mask);if(!$f)exit(7);
if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))exit(8);fclose($f);'''
        code=code.replace('DIRECTORY',repr(directory)).replace('FILENAME',name).replace('DIGEST',sha(body))
        r=subprocess.run(SSH+['/usr/bin/php8.2 -r '+shlex.quote(code)],input=body,capture_output=True,timeout=40)
        assert r.returncode==0,'Private staging failed; inspect the existing handle'
    command='cd '+shlex.quote(WEB)+' && COREFLUX_ENV=staging /usr/bin/php8.2 '+shlex.quote(directory+'/verify.php')+' --synthetic-native-review '+sha(manifest)
    try:r=subprocess.run(SSH+[command],capture_output=True,text=True,timeout=600)
    except subprocess.TimeoutExpired:
        print(json.dumps({'transport_timeout':True,'observation':observe(handle),'repeat':False}));raise SystemExit(1)
    if r.stderr:print(r.stderr[:1400])
    result=observe(handle)
    if result.get('receipt_exists') and result['report'].get('status')=='finished':
        report_path=args.handle.with_name(args.handle.stem+'-result.json')
        assert not report_path.exists()
        report_path.write_text(json.dumps(result['report'],indent=2)+'\n')
    print(json.dumps({'exit_code':r.returncode,'observation':result}))
    raise SystemExit(r.returncode)

if __name__=='__main__':main()
