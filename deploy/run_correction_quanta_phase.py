"""Stage a bounded native Quanta test; never repeat a phase after uncertain outcome."""
import hashlib,json,shlex,subprocess,sys
from datetime import datetime,timezone,timedelta
from pathlib import Path
root=Path(__file__).resolve().parents[1];out=root/'output';source=root.parent/'Quanta-profile-return/backend'
private='/srv/core-integration-pilot/20261029/private'
phase=sys.argv[1];assert phase in ('prepare','capture','correct','portal')
helper={'portal':'verify_correction_quanta_portal.py','prepare':'prepare_correction_quanta_time.py','capture':'capture_correction_quanta_hour.py','correct':'correct_correction_quanta_hour.py'}[phase]
stem={'portal':'synthetic-correction-portal','prepare':'synthetic-quanta-time','capture':'synthetic-quanta-hour','correct':'synthetic-quanta-correction'}[phase]
state={'portal':'synthetic-correction-portal-verification','prepare':'synthetic-quanta-time-preparation','capture':'synthetic-quanta-hour','correct':'synthetic-quanta-correction'}[phase]
arg={'portal':'--verify-native-portal','prepare':'--prepare-synthetic-worker','capture':'--capture-and-approve-synthetic-hour','correct':'--correct-and-reapprove-synthetic-hour'}[phase]
files=['app/routers/admin_time_create.py','app/manual_time_capture.py','app/routers/admin_worker_week.py','app/services/timesheet_submission.py','app/routers/approvals.py','app/coreflux_time_access.py'] if phase!='prepare' else ['app/routers/tenant.py','app/routers/worksites.py','app/routers/api_keys.py','app/routers/auth.py','app/coreflux_commit.py']
if phase=='correct':files+=['app/routers/tenant.py','app/routers/time.py']
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/purepay_quanta_migration_20261028','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','root@142.93.20.44']
live=json.loads(subprocess.check_output(ssh+['docker inspect --format '+shlex.quote('{{json .Id}}')+' core-provider-pilot-20261029-quanta-pilot-web-1'],text=True,timeout=30))
plan={'expires_at':(datetime.now(timezone.utc)+timedelta(minutes=30)).isoformat(),'container_id':live,'runtime':{p:hashlib.sha256((source/p).read_bytes().replace(b'\r\n',b'\n')).hexdigest() for p in files}}
bundle={stem+'-plan-20261112-correction.json':json.dumps(plan,sort_keys=True).encode(),helper:(root/'deploy'/helper).read_bytes()}
for name,body in bundle.items():
 code="""import hashlib,os,sys
from pathlib import Path
p=Path(PATH);b=sys.stdin.buffer.read();assert hashlib.sha256(b).hexdigest()==DIGEST
assert p.parent.resolve()==p.parent and not p.parent.stat().st_mode&0o077 and not p.is_symlink()
if p.exists():assert p.read_bytes()==b
else:
 with os.fdopen(os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write(b)
""".replace('PATH',repr(private+'/'+name)).replace('DIGEST',repr(hashlib.sha256(body).hexdigest()))
 p=subprocess.run(ssh+['python3 -c '+shlex.quote(code)],input=body,capture_output=True,timeout=30)
 if p.returncode:raise RuntimeError('Staging refused; inspect existing private phase files, do not repeat')
handle={'state':private+'/'+state+('-20261112.json' if phase=='portal' else '-20261112-correction.json'),'plan':plan,'helper_sha256':hashlib.sha256(bundle[helper]).hexdigest()}
(out/('correction-quanta-'+phase+'-handle.json')).write_text(json.dumps(handle,indent=2)+'\n',encoding='utf-8')
try:p=subprocess.run(ssh+['python3 '+private+'/'+helper+' '+arg],capture_output=True,text=True,timeout=90)
except subprocess.TimeoutExpired:raise RuntimeError('Outcome uncertain: inspect this handle and native records before continuing')
try:report=json.loads(p.stdout)
except ValueError:
 (out/('correction-quanta-'+phase+'-observation.json')).write_text(json.dumps({'code':p.returncode,'stdout':p.stdout,'stderr':p.stderr}),encoding='utf-8')
 raise RuntimeError('Inspect saved phase before continuing; do not restart')
(out/('correction-quanta-'+phase+'-result.json')).write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
print(json.dumps(report));raise SystemExit(p.returncode)
