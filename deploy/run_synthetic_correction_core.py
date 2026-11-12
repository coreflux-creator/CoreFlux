"""Stage and run the one-time native fixture; uncertain outcomes require inspection."""
import hashlib,json,shlex,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1];out=root/'output'
inspection=json.loads((out/'synthetic-lifecycle-core-inspection.json').read_text(encoding='utf-8'))
assert inspection['local_runtime_matches'] and inspection['existing_case']==[{'id':874,'person_id':368,'status':'ended'}] and inspection['employee_backfill_candidates']==0 and inspection['time_control_enabled']
body=(root/'deploy/provision_thunderhawk_correction_case.php').read_bytes()
pins=json.dumps(inspection['runtime_files'],sort_keys=True).encode()
digest=hashlib.sha256(body).hexdigest()
directory='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/lifecycle-case-'+digest[:16]
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
for name,data in [('correction-runtime-pins.json',pins),('provision_thunderhawk_correction_case.php',body)]:
    code=r'''$dir=DIRECTORY;$parent=dirname($dir);$path=$dir.'/FILENAME';
if(posix_geteuid()!==1003||is_link($parent)||realpath($parent)!==$parent||fileowner($parent)!==1003||(fileperms($parent)&0777)!==0700||is_link($dir)||is_link($path))exit(2);
if(!is_dir($dir)){if(!mkdir($dir,0700))exit(3);}
if(realpath($dir)!==$dir||fileowner($dir)!==1003||(fileperms($dir)&0777)!==0700)exit(4);
$body=file_get_contents('php://stdin');if(hash('sha256',$body)!=='HASH')exit(5);
if(file_exists($path)){if(hash_file('sha256',$path)!=='HASH')exit(6);exit;}
$old=umask(0077);$f=fopen($path,'x');umask($old);if(!$f)exit(7);
if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))exit(8);fclose($f);'''.replace('DIRECTORY',repr(directory)).replace('FILENAME',name).replace('HASH',hashlib.sha256(data).hexdigest())
    r=subprocess.run(ssh+['/usr/bin/php8.2 -r '+shlex.quote(code)],input=data,capture_output=True,timeout=45)
    if r.returncode:raise RuntimeError('Private fixture staging failed')
command='cd /home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html && COREFLUX_ENV=staging /usr/bin/php8.2 '+shlex.quote(directory+'/provision_thunderhawk_correction_case.php')+' --create-isolated-lifecycle-draft'
handle={'directory':directory,'verifier_sha256':digest,'result':'/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/synthetic-lifecycle-20261112-correction.json','started':True}
(out/'synthetic-correction-core-handle.json').write_text(json.dumps(handle,indent=2)+'\n')
try:r=subprocess.run(ssh+[command],capture_output=True,text=True,timeout=60)
except subprocess.TimeoutExpired:raise RuntimeError('Fixture outcome unconfirmed; inspect saved case, never repeat the create request')
try:report=json.loads(r.stdout)
except ValueError:
    (out/'synthetic-correction-core-observation.json').write_text(json.dumps({'returncode':r.returncode,'stdout':r.stdout,'stderr':r.stderr},indent=2))
    raise RuntimeError('Fixture outcome unconfirmed; inspect saved case and private observation before any continuation')
report['verifier_sha256']=digest;report['private_directory']=directory
(out/'synthetic-correction-core-result.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({k:v for k,v in report.items() if k not in ('checks','requests')}))
raise SystemExit(r.returncode)
