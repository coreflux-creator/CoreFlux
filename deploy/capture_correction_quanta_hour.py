"""One synthetic, assignment-bound hour using native capture/submit/approval APIs."""
import fcntl,hashlib,json,os,subprocess,sys,urllib.error,urllib.parse,urllib.request,uuid
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path
PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
WEB='core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b'
ADMIN='8041205e-b179-4991-8dbe-65d51a474e22';APPROVER='ff6fdfe9-291c-4fac-91aa-51782ba5585f'
WORKER='cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6'
SITE='1bc9011d-12df-458e-b79d-c31f09b9ee09'
ASSIGNMENT='cfa_3fa8dbe1a23e858c87bab64b9555d2c147e78919eae39e5ba5eaa9f1994b16f2'
DATE='2026-11-09';WEEK='2026-11-07'
NOTE='ISOLATED SYNTHETIC CORRECTION TEST: one invented hour; no real work or payroll payable.'
STATE=PRIVATE/'synthetic-quanta-hour-20261112-correction.json'


def save(path,value):
    with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(value,f,indent=2)
def request(path,token=None,body=None,key=None):
    assert path in ['/auth/login','/auth/logout','/auth/me','/admin/time-entries','/admin/timesheets/worker-week/submit'] or path.startswith('/admin/timesheets/worker-week?') or path.startswith('/approvals/timesheets/')
    headers={'Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    if key:headers['Idempotency-Key']=key
    req=urllib.request.Request('http://127.0.0.1:19181/api'+path,None if body is None else json.dumps(body).encode(),headers)
    try:
        with urllib.request.urlopen(req,timeout=25) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:
        value=json.loads(e.read());save(str(STATE)+'.last-response.private.json',value);return e.code,value
def snapshot():
    code=r'''import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_quanta_20261029' and os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
t='4f93d4e7-af74-4765-b812-3464bb6d974b';w='cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6'
a=['8041205e-b179-4991-8dbe-65d51a474e22','ff6fdfe9-291c-4fac-91aa-51782ba5585f']
assert os.environ['COREFLUX_TIME_ACCESS_TENANTS']==t
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
cfg=d.connecteam_configs.find_one({'tenant_id':t});assert not cfg or not cfg.get('enabled')
worker=d.users.find_one({'id':w,'tenant_id':t});assert worker['status']=='active' and worker['approver_user_id']==a[1] and not worker.get('identity_id')
actors=list(d.users.find({'id':{'$in':a},'tenant_id':t}));assert len(actors)==2
identities=[u['identity_id'] for u in actors]
policies=list(d.coreflux_time_access.find({'tenant_id':t,'worker_id':w}));assert len(policies)==1 and policies[0]['version']==1
out={}
for n in sorted(d.list_collection_names()):
 rows=[]
 for row in d[n].find({}):
  if n=='users' and row.get('id') in a and row.get('tenant_id')==t:row.pop('last_login_at',None)
  if n=='identities' and row.get('id') in identities:
   for field in ['last_tenant_id','updated_at']:row.pop(field,None)
  if n=='coreflux_commit_fences':row.pop('revision',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 out[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
files=['app/routers/admin_time_create.py','app/manual_time_capture.py','app/routers/admin_worker_week.py','app/services/timesheet_submission.py','app/routers/approvals.py','app/coreflux_time_access.py']
print(json_util.dumps({'collections':out,'runtime':{p:hashlib.sha256((Path('/app/backend')/p).read_bytes().replace(b'\r\n',b'\n')).hexdigest() for p in files},
 'entries':list(d.time_entries.find({'tenant_id':t,'user_id':w})),
 'sheets':list(d.timesheets.find({'tenant_id':t,'user_id':w}))}))'''
    r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=25)
    if r.returncode:raise RuntimeError('Isolated time guard failed')
    return json.loads(r.stdout)


def main():
    resume=sys.argv[1:]==['--resume-original-synthetic-capture']
    assert (resume or sys.argv[1:]==['--capture-and-approve-synthetic-hour']) and os.geteuid()==0
    assert STATE.exists() if resume else not STATE.exists()
    os.umask(0o077)
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    plan=json.loads((PRIVATE/'synthetic-quanta-hour-plan-20261112-correction.json').read_text())
    assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
    enabled=json.loads((PRIVATE/'synthetic-quanta-time-preparation-20261112-correction.json').read_text());assert enabled['passed']
    live=json.loads(subprocess.check_output(['docker','inspect',WEB]))[0]
    assert live['State']['Running'] and live['Id']==plan['container_id']
    current=snapshot();assert current['runtime']==plan['runtime'] and not current['sheets']
    if resume:
        report=json.loads(STATE.read_text())
        assert report['phase']=='capture' and not report['passed'] and report['revoked_sessions']==2 and report['failure_class']=='KeyError'
        assert len(current['entries'])==1 and current['entries'][0]['duration_minutes']==60 and current['entries'][0]['work_date']==DATE and current['entries'][0]['notes']==NOTE
        assert current['entries'][0]['core_assignment']['assignment_id']==ASSIGNMENT
        before=json.loads(Path(str(STATE)+'.before.private.json').read_text())
        for field in ['failure_class','failure_line','verification_failure','verification_line']:report.pop(field,None)
        report['resumed_original_capture']=True;report['phase']='login'
    else:
        assert not current['entries'];before=current
        save(str(STATE)+'.before.private.json',before)
        report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'login','passed':False,'capture_key':str(uuid.uuid4()),
            'person_number':'P-387','placement_number':'PL-902','date':DATE,'minutes':60,'synthetic':True,
            'production_changed':False,'real_payroll_payments_postings':False,'outbound_mail':'devoutbox only'}
    save(STATE,report);sessions={}
    try:
        credentials=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        for role,actor in [('admin',ADMIN),('approver',APPROVER)]:
            status,login=request('/auth/login',body={'email':'thunderhawk-core-pilot-'+role+'@example.invalid','password':credentials['quanta_'+role+'_password'],'tenant_slug':'thunderhawk'})
            assert status==200 and login['user']['id']==actor
            sessions[role]=(login['access_token'],login['refresh_token'])
            status,me=request('/auth/me',sessions[role][0]);assert status==200 and me['current_tenant_id']==TENANT and not me['is_impersonating']
        del credentials,login
        admin=sessions['admin'][0];approver=sessions['approver'][0]
        body={'user_id':WORKER,'worksite_id':SITE,'core_assignment_id':ASSIGNMENT,'entry_mode':'duration','work_date':DATE,'duration_minutes':60,'notes':NOTE}
        report['phase']='capture';save(STATE,report)
        if not resume:
            wrong={**body,'core_assignment_id':'cfa_'+'0'*64}
            status,_=request('/admin/time-entries',admin,wrong,str(uuid.uuid4()));assert status in [409,422]
            report['unreviewed_assignment_refused']=True
        status,entry=request('/admin/time-entries',admin,body,report['capture_key'])
        assert status==201 and entry['duration_minutes']==60
        report['entry_id']=entry['id'];save(STATE,report)
        captured=snapshot()['entries'];assert len(captured)==1 and captured[0]['id']==entry['id']
        assert captured[0]['core_assignment']['assignment_id']==ASSIGNMENT
        assert captured[0]['core_assignment']['person']['number']=='P-387' and captured[0]['core_assignment']['placement']['number']=='PL-902'
        status,replay=request('/admin/time-entries',admin,body,report['capture_key']);assert status==201 and replay==entry
        status,_=request('/admin/time-entries',admin,{**body,'duration_minutes':120},report['capture_key']);assert status==409
        report['capture_replay_verified']=True;report['changed_capture_refused']=True
        query=urllib.parse.urlencode({'worker_id':WORKER,'period_start_local':WEEK})
        status,week=request('/admin/timesheets/worker-week?'+query,admin);assert status==200 and week['can_submit']
        submit={'worker_id':WORKER,'period_start_local':WEEK,'expected_revision':week['revision'],'note':NOTE}
        report['phase']='submit';save(STATE,report)
        status,_=request('/admin/timesheets/worker-week/submit',admin,{**submit,'expected_revision':'0'*64});assert status==409
        status,sheet=request('/admin/timesheets/worker-week/submit',admin,submit)
        assert status==200 and sheet['status']=='submitted' and sheet['total_minutes']==60
        assert sheet['submitted_by_user_id']==ADMIN and sheet['approver_user_id']==APPROVER
        report['timesheet_id']=sheet['id'];report['stale_submission_refused']=True;report['phase']='approve';save(STATE,report)
        status,approved=request('/approvals/timesheets/'+sheet['id']+'/approve',approver,{'note':NOTE})
        assert status==200 and approved['status']=='approved'
        # Native approval responses omit actor provenance; verify the persisted
        # approver, submission actor and assignment below using read-only data.
        report['phase']='verification'
    except Exception as error:report.update(failure_class=type(error).__name__,failure_line=error.__traceback__.tb_lineno)
    finally:
        report['revoked_sessions']=0
        for token,refresh in sessions.values():
            try:
                status,result=request('/auth/logout',token,{'refresh_token':refresh});assert status==200 and result=={'ok':True,'revoked':1}
                report['revoked_sessions']+=1
            except Exception as error:report['cleanup_failure']=type(error).__name__
        sessions.clear()
    try:
        after=snapshot();assert after['runtime']==before['runtime']
        assert len(after['entries'])==len(after['sheets'])==1
        entry=after['entries'][0];sheet=after['sheets'][0]
        assert entry['id']==report['entry_id'] and entry['duration_minutes']==60 and entry['work_date']==DATE and entry['notes']==NOTE
        assert entry['core_assignment']['assignment_id']==ASSIGNMENT and entry['core_assignment']['policy_version']==1
        assert sheet['id']==report['timesheet_id'] and entry['timesheet_id']==sheet['id'] and sheet['status']=='approved'
        assert sheet['submitted_by_user_id']==ADMIN and sheet['approved_by_user_id']==APPROVER and sheet['total_minutes']==60
        added={};allowed=['auth_sessions','audit_events','manual_time_receipts','time_entries','timesheets','webhook_deliveries','magic_link_tokens']
        assert set(after['collections'])==set(before['collections'])
        for name,prior in before['collections'].items():
            fresh=after['collections'][name];assert fresh['indexes']==prior['indexes'],name
            if fresh['rows']==prior['rows']:continue
            assert name in allowed and not Counter(prior['rows'])-Counter(fresh['rows']),name
            added[name]=len(fresh['rows'])-len(prior['rows'])
        assert added['time_entries']==added['timesheets']==1 and added['auth_sessions']==(4 if resume else 2)
        report.update(collections_verified=len(before['collections']),added_record_counts=added,original_records_preserved=True,
            source_status='approved',submitted_by=ADMIN,approved_by=APPROVER,separate_approver_verified=True,assignment_id=ASSIGNMENT)
        report['passed']=report['phase']=='verification' and report['revoked_sessions']==2 and not report.get('cleanup_failure')
        if report['passed']:report['phase']='complete'
    except Exception as error:report.update(verification_failure=type(error).__name__,verification_line=error.__traceback__.tb_lineno)
    report['finished_at']=datetime.now(timezone.utc).isoformat();save(STATE,report);print(json.dumps(report));return 0 if report['passed'] else 1


if __name__=='__main__':sys.exit(main())
