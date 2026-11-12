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
STATE=PRIVATE/'synthetic-quanta-correction-20261112-correction.json'


def save(path,value):
    with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(value,f,indent=2)
def request(path,token=None,body=None,key=None,method=None):
    assert path in ['/auth/login','/auth/logout','/auth/me','/admin/time-entries','/admin/timesheets/worker-week/submit'] or path.startswith('/admin/timesheets/worker-week?') or path.startswith('/approvals/timesheets/') or path=='/admin/time-entries/3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2' or path=='/tenant/timesheets/c1ebacce-1523-4f1f-b991-6c3b90bed1a5/unlock'
    headers={'Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    if key:headers['Idempotency-Key']=key
    req=urllib.request.Request('http://127.0.0.1:19181/api'+path,None if body is None else json.dumps(body).encode(),headers,method=method)
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
  if n in ['time_entries','timesheets'] and row.get('tenant_id')==t and row.get('user_id')==w:continue
  if n=='users' and row.get('id') in a and row.get('tenant_id')==t:row.pop('last_login_at',None)
  if n=='identities' and row.get('id') in identities:
   for field in ['last_tenant_id','updated_at']:row.pop(field,None)
  if n=='coreflux_commit_fences':row.pop('revision',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 out[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
files=['app/routers/admin_time_create.py','app/manual_time_capture.py','app/routers/admin_worker_week.py','app/services/timesheet_submission.py','app/routers/approvals.py','app/coreflux_time_access.py','app/routers/tenant.py','app/routers/time.py']
print(json_util.dumps({'collections':out,'runtime':{p:hashlib.sha256((Path('/app/backend')/p).read_bytes().replace(b'\r\n',b'\n')).hexdigest() for p in files},
 'entries':list(d.time_entries.find({'tenant_id':t,'user_id':w})),
 'sheets':list(d.timesheets.find({'tenant_id':t,'user_id':w}))}))'''
    r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=25)
    if r.returncode:raise RuntimeError('Isolated time guard failed')
    return json.loads(r.stdout)



def main():
    assert sys.argv[1:]==['--correct-and-reapprove-synthetic-hour'] and os.geteuid()==0 and not STATE.exists()
    os.umask(0o077)
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    plan=json.loads((PRIVATE/'synthetic-quanta-correction-plan-20261112-correction.json').read_text())
    assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
    live=json.loads(subprocess.check_output(['docker','inspect',WEB]))[0]
    assert live['State']['Running'] and live['Id']==plan['container_id']
    before=snapshot();assert before['runtime']==plan['runtime']
    assert len(before['entries'])==len(before['sheets'])==1
    original=before['entries'][0];sheet=before['sheets'][0]
    source='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2';sheet_id='c1ebacce-1523-4f1f-b991-6c3b90bed1a5'
    assert original['id']==source and original['duration_minutes']==60 and original['core_assignment']['assignment_id']==ASSIGNMENT
    assert sheet['id']==sheet_id and sheet['status']=='approved' and sheet['approved_by_user_id']==APPROVER
    save(str(STATE)+'.before.private.json',before)
    report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'login','passed':False,'person_number':'P-387','placement_number':'PL-902','source_entry_id':source,'timesheet_id':sheet_id,'old_minutes':60,'new_minutes':90,'synthetic':True,'production_changed':False,'real_payroll_payments_postings':False,'outbound_mail':'devoutbox only'}
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
        edit='/admin/time-entries/'+source
        status,_=request(edit,admin,{'duration_minutes':90},method='PATCH');assert status==409
        assert snapshot()['entries']==before['entries'];report['approved_source_direct_edit_refused']=True
        report['phase']='unlock';save(STATE,report)
        status,unlocked=request('/tenant/timesheets/'+sheet_id+'/unlock',admin,{'target_state':'draft','reason':'Isolated correction test: change invented hour from 60 to 90 minutes.'})
        assert status==200 and unlocked['status']=='draft' and unlocked['approved_at'] is None
        report['phase']='edit';save(STATE,report)
        status,edited=request(edit,admin,{'duration_minutes':90},method='PATCH')
        assert status==200 and edited['duration_minutes']==90
        fresh=snapshot()['entries'][0]
        assert fresh['id']==source and fresh['core_assignment']==original['core_assignment']
        status,week=request('/admin/timesheets/worker-week?'+urllib.parse.urlencode({'worker_id':WORKER,'period_start_local':WEEK}),admin)
        assert status==200 and week['can_submit']
        report['phase']='resubmit';save(STATE,report)
        status,submitted=request('/admin/timesheets/worker-week/submit',admin,{'worker_id':WORKER,'period_start_local':WEEK,'expected_revision':week['revision'],'note':'Synthetic correction: 90 invented minutes; no payroll submission.'})
        assert status==200 and submitted['id']==sheet_id and submitted['status']=='submitted' and submitted['total_minutes']==90
        report['phase']='reapprove';save(STATE,report)
        status,approved=request('/approvals/timesheets/'+sheet_id+'/approve',approver,{'note':'Independent synthetic source correction review.'})
        assert status==200 and approved['status']=='approved'
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
        entry=after['entries'][0];current=after['sheets'][0]
        assert entry['id']==source and entry['duration_minutes']==90 and entry['raw_minutes']==90 and entry['edited_by_user_id']==ADMIN
        assert entry['core_assignment']==original['core_assignment']
        changes={k for k in set(entry)|set(original) if entry.get(k)!=original.get(k)}
        assert changes<=set(['duration_minutes','raw_minutes','edited_by_user_id','updated_at','rates_frozen_at','pay_amount_cents','bill_amount_cents','rate_breakdown','resolved_bill_rate_card_id','resolved_pay_rate_card_id']),changes
        assert current['id']==sheet_id and current['status']=='approved' and current['total_minutes']==90
        assert current['approved_by_user_id']==APPROVER and current['submitted_by_user_id']==ADMIN and current['approved_at']!=sheet['approved_at']
        history=current['history'];assert history[:len(sheet['history'])]==sheet['history']
        unlocks=[event for event in history[len(sheet['history']):] if event['event']=='admin_unlocked'];assert len(unlocks)==1
        assert unlocks[0]['metadata']['prior_approval']['approved_at']==sheet['approved_at'] and unlocks[0]['metadata']['prior_approval']['approved_by_user_id']==APPROVER
        allowed=['auth_sessions','audit_events','webhook_deliveries','magic_link_tokens'];added={}
        assert set(after['collections'])==set(before['collections'])
        for name,prior in before['collections'].items():
            fresh=after['collections'][name];assert fresh['indexes']==prior['indexes'],name
            if fresh['rows']==prior['rows']:continue
            assert name in allowed and not Counter(prior['rows'])-Counter(fresh['rows']),name
            added[name]=len(fresh['rows'])-len(prior['rows'])
        assert added['auth_sessions']==2
        report.update(collections_verified=len(before['collections']),added_record_counts=added,other_original_records_preserved=True,original_source_identity_preserved=True,original_approval_history_preserved=True,separate_approver_verified=True,entry_changed_fields=sorted(changes),source_status='approved')
        report['passed']=report['phase']=='verification' and report['revoked_sessions']==2 and not report.get('cleanup_failure')
        if report['passed']:report['phase']='complete'
    except Exception as error:report.update(verification_failure=type(error).__name__,verification_line=error.__traceback__.tb_lineno)
    report['finished_at']=datetime.now(timezone.utc).isoformat();save(STATE,report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':sys.exit(main())
