"""Fresh, isolated P-387 / PL-902 time; keep prior source and held payroll intact."""
import fcntl, hashlib, json, os, subprocess, sys, urllib.error, urllib.parse, urllib.request, uuid
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
WEB='core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b'
ADMIN='8041205e-b179-4991-8dbe-65d51a474e22'
APPROVER='ff6fdfe9-291c-4fac-91aa-51782ba5585f'
WORKER='cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6'
ASSIGNMENT='cfa_3fa8dbe1a23e858c87bab64b9555d2c147e78919eae39e5ba5eaa9f1994b16f2'
SITE='1bc9011d-12df-458e-b79d-c31f09b9ee09'
DATE=WEEK='2026-11-14'
NOTE='ISOLATED SYNTHETIC ROUNDTRIP: 75 invented minutes; no real work or payroll payable.'
PLAN=PRIVATE/'updated-roundtrip-quanta-20261114-plan.private.json'
STATE=PRIVATE/'updated-roundtrip-quanta-20261114.json'

def save(path,value,new=False):
    assert path.parent.resolve()==PRIVATE and not path.is_symlink()
    with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|(os.O_EXCL if new else os.O_TRUNC),0o600),'w') as f:
        json.dump(value,f,indent=2);f.flush();os.fsync(f.fileno())

def snapshot():
    code=r'''import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_quanta_20261029' and os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
t=__TENANT__;w=__WORKER__;a=[__ADMIN__,__APPROVER__]
assert os.environ['COREFLUX_TIME_ACCESS_TENANTS']==t and os.environ['COREFLUX_TIME_COMMITS']=='1'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
assert not (d.connecteam_configs.find_one({'tenant_id':t}) or {}).get('enabled')
worker=d.users.find_one({'id':w,'tenant_id':t});assert worker['status']=='active' and worker['roles']==['worker'] and worker['approver_user_id']==a[1]
assert d.coreflux_person_links.find_one({'tenant_id':t,'worker_id':w,'person.number':'P-387'})
actors=list(d.users.find({'tenant_id':t,'id':{'$in':a}}));assert len(actors)==2 and all(x['status']=='active' for x in actors)
identities=[x['identity_id'] for x in actors];out={}
policies=list(d.coreflux_time_access.find({'tenant_id':t,'worker_id':w}));assert len(policies)==1
for name in sorted(d.list_collection_names()):
 rows=[]
 for row in d[name].find({}):
  if name=='users' and row.get('tenant_id')==t and row.get('id') in a:row.pop('last_login_at',None)
  if name=='identities' and row.get('id') in identities:
   for k in ['last_tenant_id','updated_at']:row.pop(k,None)
  if name=='coreflux_commit_fences':row.pop('revision',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 out[name]={'rows':sorted(rows),'indexes':json_util.dumps(d[name].index_information(),sort_keys=True)}
runtime={str(p.relative_to('/app/backend')):hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/app/backend/app').rglob('*.py')}
print(json_util.dumps({'collections':out,'runtime':runtime,'environment_sha256':hashlib.sha256(json.dumps(dict(os.environ),sort_keys=True).encode()).hexdigest(),
 'entries':list(d.time_entries.find({'tenant_id':t,'user_id':w})), 'sheets':list(d.timesheets.find({'tenant_id':t,'user_id':w})),
 'sessions':list(d.auth_sessions.find({'user_id':{'$in':a}})),'policy_version':policies[0]['version']}))
'''
    for k,v in [('TENANT',TENANT),('WORKER',WORKER),('ADMIN',ADMIN),('APPROVER',APPROVER)]:code=code.replace('__'+k+'__',repr(v))
    r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=30)
    assert r.returncode==0,'Isolated Quanta guard failed: '+r.stderr[-500:]
    result=json.loads(r.stdout)
    pure=r'''import os,json,hashlib
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
r=d.pay_runs.find_one({'organization_id':'org_0369a63ff2cb','id':'run_core_54cb6e8156e6bfd5386a133ed5ea4d41c015aab8f45d25aeb0813cb090d35938'})
assert r['status']=='draft' and r['core_execution_hold'] and r['totals']['gross']==100
print(json.dumps({n:{'rows':sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in d[n].find({})),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)} for n in sorted(d.list_collection_names())}))
'''
    r=subprocess.run(['docker','exec','-i','core-provider-pilot-20261029-pure-pilot-web-1','python','-'],input=pure,text=True,capture_output=True,timeout=30)
    assert r.returncode==0,'Held payroll inspection failed'
    result['pure']=json.loads(r.stdout)
    result['container_id']=json.loads(subprocess.check_output(['docker','inspect',WEB]))[0]['Id']
    return result

def request(path,token=None,body=None,key=None):
    assert path in ['/auth/login','/auth/logout','/auth/me','/admin/time-entries','/admin/timesheets/worker-week/submit'] or path.startswith('/admin/timesheets/worker-week?') or path.startswith('/approvals/timesheets/')
    h={'Content-Type':'application/json'}
    if token:h['Authorization']='Bearer '+token
    if key:h['Idempotency-Key']=key
    req=urllib.request.Request('http://127.0.0.1:19181/api'+path,None if body is None else json.dumps(body).encode(),h)
    try:
        with urllib.request.urlopen(req,timeout=25) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:
        value=json.loads(e.read());save(PRIVATE/'updated-roundtrip-quanta-last-response.private.json',value);return e.code,value

def check(report,ok,label):
    if not ok:report['failed_check']=label;raise AssertionError(label)
    report['checks'].append(label)

def main():
    assert os.geteuid()==0 and PRIVATE.resolve()==PRIVATE and not PRIVATE.stat().st_mode&0o077
    assert sys.argv[1:] in [['prepare'],['run'],['inspect']]
    os.umask(0o077)
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    action=sys.argv[1]
    if action=='inspect':
        report=json.loads(STATE.read_text()) if STATE.exists() else {'phase':'prepared' if PLAN.exists() else 'not_prepared'}
        print(json.dumps(report));return
    if action=='prepare':
        assert not PLAN.exists() and not STATE.exists(),'Inspect the existing operation; never replace its plan.'
        before=snapshot()
        assert not any(e.get('work_date')==DATE for e in before['entries'])
        assert not any(s.get('period_start_local')==WEEK for s in before['sheets'])
        save(PLAN,{'before':before,'expires_at':(datetime.now(timezone.utc)+timedelta(hours=2)).isoformat()},True)
        print(json.dumps({'prepared':True,'runtime_files':len(before['runtime']),'prior_entries':len(before['entries']),'prior_sheets':len(before['sheets']),'date':DATE,'minutes':75,'purepay_held':True}));return
    assert PLAN.exists() and not STATE.exists(),'Inspect the existing operation; never replay an uncertain capture.'
    plan=json.loads(PLAN.read_text());assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
    before=plan['before'];assert snapshot()==before,'Source changed after preparation; inspect before proceeding.'
    report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'login','passed':False,'pid':os.getpid(),
        'checks':[],'person_number':'P-387','placement_number':'PL-902','date':DATE,'minutes':75,'capture_key':str(uuid.uuid4()),
        'synthetic':True,'production_changed':False,'arabella_changed':False,'financial_execution':False,'browser_acceptance':False}
    save(STATE,report,True);sessions={}
    try:
        credentials=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        for role,actor in [('admin',ADMIN),('approver',APPROVER)]:
            status,v=request('/auth/login',body={'email':'thunderhawk-core-pilot-'+role+'@example.invalid','password':credentials['quanta_'+role+'_password'],'tenant_slug':'thunderhawk'})
            check(report,status==200 and v['user']['id']==actor,role+'_native_login')
            sessions[role]=(v['access_token'],v['refresh_token'])
            status,v=request('/auth/me',sessions[role][0]);check(report,status==200 and v['current_tenant_id']==TENANT and not v['is_impersonating'],role+'_company_identity')
        del credentials,v
        admin=sessions['admin'][0];approver=sessions['approver'][0]
        body={'user_id':WORKER,'worksite_id':SITE,'core_assignment_id':ASSIGNMENT,'entry_mode':'duration','work_date':DATE,'duration_minutes':75,'notes':NOTE}
        report['phase']='capture';save(STATE,report)
        status,_=request('/admin/time-entries',admin,{**body,'core_assignment_id':'cfa_'+'0'*64},str(uuid.uuid4()))
        check(report,status in [409,422],'unreviewed_assignment_refused')
        status,entry=request('/admin/time-entries',admin,body,report['capture_key'])
        check(report,status==201 and entry['duration_minutes']==75,'native_capture_75_minutes')
        report['entry_id']=entry['id'];save(STATE,report)
        status,v=request('/admin/time-entries',admin,body,report['capture_key']);check(report,status==201 and v==entry,'capture_exact_replay')
        status,_=request('/admin/time-entries',admin,{**body,'duration_minutes':76},report['capture_key']);check(report,status==409,'changed_capture_refused')
        status,week=request('/admin/timesheets/worker-week?'+urllib.parse.urlencode({'worker_id':WORKER,'period_start_local':WEEK}),admin)
        check(report,status==200 and week['can_submit'],'current_week_reviewable')
        submit={'worker_id':WORKER,'period_start_local':WEEK,'expected_revision':week['revision'],'note':NOTE}
        report['phase']='submit';save(STATE,report)
        status,_=request('/admin/timesheets/worker-week/submit',admin,{**submit,'expected_revision':'0'*64});check(report,status==409,'stale_week_refused')
        status,sheet=request('/admin/timesheets/worker-week/submit',admin,submit)
        check(report,status==200 and sheet['status']=='submitted' and sheet['total_minutes']==75 and sheet['submitted_by_user_id']==ADMIN and sheet['approver_user_id']==APPROVER,'native_submission_preserves_actors_and_minutes')
        report['timesheet_id']=sheet['id'];report['phase']='approve';save(STATE,report)
        status,v=request('/approvals/timesheets/'+sheet['id']+'/approve',approver,{'note':NOTE})
        check(report,status==200 and v['status']=='approved','independent_native_approval')
        report['phase']='verification'
    except Exception as e:
        report.update(failure_class=type(e).__name__,failure_line=e.__traceback__.tb_lineno)
    finally:
        report['revoked_sessions']=0
        for token,refresh in sessions.values():
            try:
                status,v=request('/auth/logout',token,{'refresh_token':refresh});assert status==200 and v=={'ok':True,'revoked':1}
                report['revoked_sessions']+=1
            except Exception as e:report['cleanup_failure']=type(e).__name__
        sessions.clear()
    try:
        after=snapshot()
        check(report,all(after[k]==before[k] for k in ['runtime','environment_sha256','container_id','pure','policy_version']),'provider_runtime_configuration_and_held_payroll_unchanged')
        old_ids={e['id'] for e in before['entries']};entries=[e for e in after['entries'] if e['id'] not in old_ids]
        old_sheets={s['id'] for s in before['sheets']};sheets=[s for s in after['sheets'] if s['id'] not in old_sheets]
        check(report,len(entries)==len(sheets)==1,'one_new_entry_and_sheet')
        e=entries[0];s=sheets[0]
        check(report,e['id']==report['entry_id'] and e['duration_minutes']==75 and e['work_date']==DATE and e['notes']==NOTE and e['core_assignment']['assignment_id']==ASSIGNMENT and e['core_assignment']['person']['number']=='P-387' and e['core_assignment']['placement']['number']=='PL-902','persisted_time_identity_and_assignment')
        check(report,s['id']==report['timesheet_id'] and e['timesheet_id']==s['id'] and s['status']=='approved' and s['total_minutes']==75 and s['submitted_by_user_id']==ADMIN and s['approved_by_user_id']==APPROVER,'persisted_independent_approval')
        changes={};assert set(after['collections'])==set(before['collections'])
        for name,prior in before['collections'].items():
            fresh=after['collections'][name];assert fresh['indexes']==prior['indexes'],name
            if prior['rows']==fresh['rows']:continue
            assert name in ['auth_sessions','audit_events','manual_time_receipts','time_entries','timesheets','webhook_deliveries','magic_link_tokens'],name
            assert not Counter(prior['rows'])-Counter(fresh['rows']),name
            changes[name]=len(fresh['rows'])-len(prior['rows'])
        check(report,changes.get('time_entries')==changes.get('timesheets')==1,'all_original_provider_records_preserved')
        old_sessions={s['id'] for s in before['sessions']};created=[s for s in after['sessions'] if s['id'] not in old_sessions]
        check(report,len(created)==2 and all(s.get('revoked_at') for s in created) and report['revoked_sessions']==2 and not report.get('cleanup_failure'),'temporary_native_sessions_revoked')
        report['changes']=changes;report['runtime_files_verified']=len(before['runtime'])
        report['passed']=report['phase']=='verification'
        if report['passed']:report['phase']='complete'
    except Exception as e:report.update(verification_failure=type(e).__name__,verification_line=e.__traceback__.tb_lineno)
    report['finished_at']=datetime.now(timezone.utc).isoformat();save(STATE,report);print(json.dumps(report))
    if not report['passed']:raise SystemExit(1)

if __name__=='__main__':main()
