"""Review only the three existing isolated pilot pairs; preserve every original row."""
import hashlib,json,shlex,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1];out=root/'output'
package=json.loads((out/'employment-identity-package.json').read_text(encoding='utf-8'))
installed=json.loads((out/'employment-identity-install-result.json').read_text(encoding='utf-8'))
assert installed['passed'] and installed['source_commit']==package['source_commit']
inspection=json.loads((out/'employment-identity-inspection.json').read_text(encoding='utf-8'))
assert inspection['tenant_id']==2002 and inspection['read_only']
evidence={'tenant_id':2002,'pairs':[[358,4],[368,5],[387,6]],
 'contact_hashes':{str(p['id']):p['email_hash'] for p in inspection['people']},
 'reasons':{
  '358':'Reviewed original Thunderhawk simulation person P-358 and employment E-2002-0001 against the native W-2 bridge creation evidence and unchanged source identity.',
  '368':'Reviewed native synthetic lifecycle P-368/PL-874 creation and original employment 5. This identity is separate from same-name person P-387.',
  '387':'Reviewed the separate native employee creation receipt for P-387/PL-902: employment 6. Preserve its distinction from same-name person P-368.'}}
pins={**package['dependencies'],**{f:v['sha256'] for f,v in package['files'].items()}}
name='accept_thunderhawk_employment_identity.php'
bundle={f:(root/'deploy'/f).read_bytes() for f in [name,'thunderhawk_employment_native.php']}
bundle['employment-runtime-pins.json']=json.dumps(pins,sort_keys=True).encode()
bundle['employment-reviewed-pairs.json']=json.dumps(evidence,sort_keys=True).encode()
hashes={f:hashlib.sha256(b).hexdigest() for f,b in bundle.items()}
private='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030'
directory=private+'/employment-review-'+hashlib.sha256(json.dumps(hashes,sort_keys=True).encode()).hexdigest()[:16]
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
for file,body in bundle.items():
 if file.endswith('.php'):
  r=subprocess.run(ssh+['/usr/bin/php8.2 -l'],input=body,capture_output=True,timeout=25)
  assert r.returncode==0,r.stdout.decode()+r.stderr.decode()
 code=r'''$dir=DIRECTORY;$parent=dirname($dir);$path=$dir.'/FILENAME';
if(posix_geteuid()!==1003||is_link($parent)||realpath($parent)!==$parent||fileowner($parent)!==1003||(fileperms($parent)&0777)!==0700||is_link($dir)||is_link($path))exit(2);
if(!is_dir($dir)&&!mkdir($dir,0700))exit(3);
if(realpath($dir)!==$dir||fileowner($dir)!==1003||(fileperms($dir)&0777)!==0700)exit(4);
$body=file_get_contents('php://stdin');if(hash('sha256',$body)!=='HASH')exit(5);
if(file_exists($path)){if(hash_file('sha256',$path)!=='HASH')exit(6);exit;}
$old=umask(0077);$f=fopen($path,'x');umask($old);if(!$f)exit(7);
if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))exit(8);fclose($f);'''.replace('DIRECTORY',repr(directory)).replace('FILENAME',file).replace('HASH',hashes[file])
 r=subprocess.run(ssh+['/usr/bin/php8.2 -r '+shlex.quote(code)],input=body,capture_output=True,timeout=30)
 if r.returncode:raise RuntimeError('Private acceptance staging failed')
handle={'directory':directory,'hashes':hashes,'source_commit':package['source_commit'],'result':private+'/synthetic-employment-identity-20261112.json'}
(out/'employment-identity-acceptance-handle.json').write_text(json.dumps(handle,indent=2)+'\n',encoding='utf-8')
command='cd /home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html && COREFLUX_ENV=staging /usr/bin/php8.2 '+shlex.quote(directory+'/'+name)+' --review-existing-isolated-employment'
try:r=subprocess.run(ssh+[command],capture_output=True,text=True,timeout=60)
except subprocess.TimeoutExpired:raise RuntimeError('Outcome uncertain. Inspect the original private receipt; do not repeat this operation.')
try:report=json.loads(r.stdout)
except ValueError:
 (out/'employment-identity-acceptance-observation.json').write_text(json.dumps({'returncode':r.returncode,'stdout':r.stdout,'stderr':r.stderr},indent=2),encoding='utf-8')
 raise RuntimeError('Inspect original checkpoint and observation before continuing')
report['package']=handle
(out/'employment-identity-acceptance-result.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
print(json.dumps({k:v for k,v in report.items() if k not in ('checks','requests','package')}))
raise SystemExit(r.returncode)
