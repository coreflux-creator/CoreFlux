"""One isolated correction phase; a saved result must be inspected before any retry."""
import hashlib,json,shlex,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parents[1];out=root/'output'
phase=sys.argv[1]
assert phase in ('revision_pending_return','revision','reapproval','revision_return','employee_setup','activation','publication','time_policy','time_import','time_approval','time_accept_return')
name='correction_'+phase+'.php';arg='--execute-isolated-correction-phase'
files=[name,'thunderhawk_correction_native.php']
pins_files=['modules/Placements/api/placements.php','modules/Placements/api/rates.php','modules/Placements/lib/placements.php',
 'modules/Placements/lib/rate_approve.php','modules/Placements/lib/economics.php','modules/payroll/api/profiles.php',
 'modules/payroll/api/pay_schedules.php','modules/payroll/api/cycles.php','modules/payroll/lib/profiles.php',
 'modules/payroll/lib/payroll.php','modules/payroll/lib/cycles.php','core/audit.php','api/quanta.php','api/purepay_core.php','core/quanta/worker_delivery.php',
 'core/quanta/metadata_delivery.php','core/integrations/purepay_people_inputs.php','core/integrations/purepay_assignment_inputs.php',
 'modules/people/api/people.php','modules/people/api/employees.php','modules/people/lib/employees.php',
 'core/quanta/routes.php','core/quanta/time_control_delivery.php','core/quanta/time_control_contract.php',
 'core/quanta/sync.php','core/quanta/time_approval.php','core/quanta/time_review_delivery.php','modules/staffing/lib/timesheets.php',
 'modules/time/api/entries.php','modules/time/lib/time.php','core/quanta/time_current.php','core/quanta/time_revisions.php',
 'core/integrations/purepay_links.php','core/integrations/purepay_payroll_inputs.php','core/integrations/purepay_payroll_authority.php',
 'core/integrations/purepay_payroll_reservation_review.php','core/integrations/purepay_payroll_results.php']
if phase in ('offboarding','offboarding_resume'):pins_files+=['modules/placements/lib/end.php','core/integrations/purepay_assignment_contract.php']
pins={f:hashlib.sha256((root/f).read_bytes().replace(b'\r\n',b'\n')).hexdigest() for f in pins_files}
bundle={name:(root/'deploy'/name).read_bytes() for name in files}
bundle['lifecycle-runtime-pins.json']=json.dumps(pins,sort_keys=True).encode()
hashes={name:hashlib.sha256(body).hexdigest() for name,body in bundle.items()}
digest=hashlib.sha256(json.dumps(hashes,sort_keys=True).encode()).hexdigest()
directory='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/lifecycle-'+phase+'-'+digest[:16]
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111']
for filename,body in bundle.items():
 code=r'''$dir=DIRECTORY;$parent=dirname($dir);$path=$dir.'/FILENAME';
if(posix_geteuid()!==1003||is_link($parent)||realpath($parent)!==$parent||fileowner($parent)!==1003||(fileperms($parent)&0777)!==0700||is_link($dir)||is_link($path))exit(2);
if(!is_dir($dir)&&!mkdir($dir,0700))exit(3);
if(realpath($dir)!==$dir||fileowner($dir)!==1003||(fileperms($dir)&0777)!==0700)exit(4);
$body=file_get_contents('php://stdin');if(hash('sha256',$body)!=='HASH')exit(5);
if(file_exists($path)){if(hash_file('sha256',$path)!=='HASH')exit(6);exit;}
$old=umask(0077);$f=fopen($path,'x');umask($old);if(!$f)exit(7);
if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))exit(8);fclose($f);'''.replace('DIRECTORY',repr(directory)).replace('FILENAME',filename).replace('HASH',hashes[filename])
 r=subprocess.run(ssh+['/usr/bin/php8.2 -r '+shlex.quote(code)],input=body,capture_output=True,timeout=45)
 if r.returncode:raise RuntimeError('Private phase staging failed')
command='cd /home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html && COREFLUX_ENV=staging /usr/bin/php8.2 '+shlex.quote(directory+'/'+name)+' '+arg
handle={'directory':directory,'hashes':hashes,'runtime_pins':pins,'result':'/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/synthetic-lifecycle-'+phase+'-20261112-correction.json'}
(out/('synthetic-correction-'+phase+'-handle.json')).write_text(json.dumps(handle,indent=2)+'\n')
try:r=subprocess.run(ssh+[command],capture_output=True,text=True,timeout=60)
except subprocess.TimeoutExpired:raise RuntimeError('Outcome unconfirmed. Inspect the saved phase and original deliveries; never repeat by creating new requests.')
try:report=json.loads(r.stdout)
except ValueError:
 (out/('synthetic-correction-'+phase+'-observation.json')).write_text(json.dumps({'returncode':r.returncode,'stdout':r.stdout,'stderr':r.stderr},indent=2))
 raise RuntimeError('Outcome unconfirmed; inspect saved checkpoint and observation before continuing')
report['package']=handle
(out/('synthetic-correction-'+phase+'-result.json')).write_text(json.dumps(report,indent=2)+'\n')
if phase in ('payroll_cancel','offboarding','offboarding_resume'):
 print(json.dumps({k:v for k,v in report.items() if k not in ('checks','requests','package','before','release','observation','reviews','deliveries')}))
else:print(json.dumps({k:v for k,v in report.items() if k not in ('checks','requests','package','reviews','review','deliveries','source_preview','return_review')}))
raise SystemExit(r.returncode)
