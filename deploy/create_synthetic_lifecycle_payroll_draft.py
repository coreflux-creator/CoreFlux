"""Create one held demo draft from the original P-368 source reservation."""
import fcntl,hashlib,json,os,subprocess,sys,urllib.error,urllib.request
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path
PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
WEB='core-provider-pilot-20261029-pure-pilot-web-1';ORG='org_0369a63ff2cb'
INPUT='cpi_255117c910a9437a565ed773d63cc32857b901b132926b88790b458040d09bd9'
AUTHORITY='cpa_92285a297913dd5feab0e0a3ed148ba14866619c2a01e790e0394a5c57931954'
STATE=PRIVATE/'synthetic-lifecycle-payroll-draft-20261111.json'
BASE='/payroll/core-inputs/'+INPUT

def save(path,value):
    with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(value,f,indent=2)

def request(path,token=None,body=None,key=None):
    assert path in ['/auth/login','/security/overview','/security/sessions/revoke',BASE+'/draft-review',BASE+'/draft',BASE+'/draft-status'] or path.startswith('/payroll/runs/run_core_') and path.endswith('/approve')
    headers={'Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    if key:headers['Idempotency-Key']=key
    req=urllib.request.Request('http://127.0.0.1:19180/api'+path,None if body is None else json.dumps(body).encode(),headers)
    try:
        with urllib.request.urlopen(req,timeout=30) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:
        value=json.loads(e.read());save(str(STATE)+'.last-response.private.json',value);return e.code,value

def snapshot():
    code=r'''import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
assert os.environ.get('COREFLUX_PAYROLL_DRAFTS')=='1' and not os.environ.get('COREFLUX_PAYROLL_DRAFT_PILOT')
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];org='org_0369a63ff2cb';authority='cpa_92285a297913dd5feab0e0a3ed148ba14866619c2a01e790e0394a5c57931954'
o=d.organizations.find_one({'id':org});assert o['payroll']['processing']['calculation']=='MANUAL' and o['payroll']['processing']['payments']=='RECORD_ONLY'
e=d.employees.find_one({'organization_id':org,'id':'emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'})
assert e['status']=='active' and e['email']=='thunderhawk-lifecycle-20261111@example.com' and e['pay_method']=='record_only'
out={}
for name in sorted(d.list_collection_names()):
 rows=[]
 for row in d[name].find({}):
  if name=='users' and row.get('id')=='usr_2d19df3dfa23':row.pop('last_login',None)
  if name=='coreflux_payroll_authorities' and row.get('id')==authority and row.get('organization_id')==org:
   for field in ['state','allocation','allocated_at']:row.pop(field,None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 out[name]={'rows':sorted(rows),'indexes':json_util.dumps(d[name].index_information(),sort_keys=True)}
files=['server.py','coreflux_payroll_drafts.py','coreflux_payroll_authority.py','coreflux_payroll_results.py','coreflux_payroll_earnings.py','coreflux_pilot_scope.py','payroll_time_claims.py']
runtime={p:hashlib.sha256((Path('/app/backend')/p).read_bytes().replace(b'\r\n',b'\n')).hexdigest() for p in files}
print(json_util.dumps({'collections':out,'runtime':runtime,'runs':list(d.pay_runs.find({'organization_id':org,'core_allocation.authority_id':authority})),
 'authority':d.coreflux_payroll_authorities.find_one({'organization_id':org,'id':authority})}))'''
    r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=30)
    if r.returncode:raise RuntimeError('Isolated draft guard failed: '+r.stderr)
    return json.loads(r.stdout)

def main():
    assert sys.argv[1:]==['--create-original-synthetic-held-draft'] and os.geteuid()==0 and not STATE.exists()
    os.umask(0o077)
    lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    plan=json.loads((PRIVATE/'synthetic-lifecycle-payroll-draft-plan-20261111.json').read_text())
    assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
    info=json.loads(subprocess.check_output(['docker','inspect',WEB]))[0];assert info['State']['Running'] and info['Id']==plan['container_id']
    before=snapshot();assert before['runtime']==plan['runtime'] and not before['runs'] and before['authority']['state']=='available'
    save(str(STATE)+'.before.private.json',before)
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'phase':'login','passed':False,'input_id':INPUT,'authority_id':AUTHORITY,
        'production_changed':False,'real_payroll_payments_postings':False,'synthetic_draft_only':True}
    save(STATE,report);token=sid=None
    try:
        credentials=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        status,login=request('/auth/login',body={'email':'thunderhawk-core-pilot@example.invalid','password':credentials['pure_password']})
        assert status==200 and login['organization']['id']==ORG and login['user']['id']=='usr_2d19df3dfa23'
        token=login['token'];del login,credentials
        status,security=request('/security/overview',token);assert status==200;sid=security['current_session_id'];assert sid
        options={'authority_id':AUTHORITY,'pay_date':'2026-11-20','pay_group_id':None}
        status,review=request(BASE+'/draft-review',token,options)
        assert status==200 and review['execution_enabled'] is False and review['payroll_created'] is False and float(review['totals']['gross'])==50.0
        report['review']=review;body={**options,'review_hash':review['review_hash']};key=review['request_key']
        report['request']=body;report['request_key']=key;report['phase']='reviewed';save(STATE,report)
        wrong={**body,'review_hash':'0'*64};wrong_key=hashlib.sha256(json.dumps(wrong,sort_keys=True,separators=(',',':')).encode()).hexdigest()
        status,_=request(BASE+'/draft',token,wrong,wrong_key);assert status==409
        report['stale_review_refused']=True;report['phase']='draft_sending';save(STATE,report)
        status,draft=request(BASE+'/draft',token,body,key)
        assert status==200 and draft['run_id']==review['run_id'] and draft['status']=='draft' and draft['ready'] and draft['execution_enabled'] is False
        report['draft']=draft;report['phase']='draft_created';save(STATE,report)
        status,replay=request(BASE+'/draft',token,body,key);assert status==200 and replay==draft
        report['replay_same_draft']=True
        changed={**body,'pay_date':'2026-11-21'}
        status,_=request(BASE+'/draft',token,changed,key);assert status==409;report['changed_request_refused']=True
        status,denied=request('/payroll/runs/'+draft['run_id']+'/approve',token,{})
        assert status==409 and denied.get('code')=='isolated_pilot_operation_held';report['execution_refused']=True
        status,recovered=request(BASE+'/draft-status',token)
        items=[r for r in recovered.get('items',[]) if r['authority_id']==AUTHORITY]
        assert status==200 and recovered['execution_enabled'] is False and len(items)==1 and items[0]['run_id']==draft['run_id'] and items[0]['ready']
        report['reload_recovers_original_draft']=True;report['phase']='verification'
    except Exception as e:report.update(failure_class=type(e).__name__,failure_line=e.__traceback__.tb_lineno)
    finally:
        if token and sid:
            try:
                status,result=request('/security/sessions/revoke',token,{'session_id':sid});assert status==200 and result['status']=='revoked'
                report['native_session_retired']=True
            except Exception as e:report['session_cleanup_failure']=type(e).__name__
        token=None
    try:
        after=snapshot();assert after['runtime']==before['runtime'] and len(after['runs'])==1
        run=after['runs'][0];assert run['id']==report['draft']['run_id'] and run['status']=='draft' and run['core_execution_hold'] is True and run['core_allocation_ready'] is True
        assert run['core_allocation']['authority_id']==AUTHORITY and run['core_allocation']['input_id']==INPUT and float(run['totals']['gross'])==50
        assert len(run['lines'])==1 and run['lines'][0]['employee_id']=='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'
        assert after['authority']['state']=='allocated' and after['authority']['authority']==before['authority']['authority']
        added={};a=before['collections'];b=after['collections'];assert a.keys()==b.keys()
        for name in a:
            assert a[name]['indexes']==b[name]['indexes'],name
            if a[name]['rows']==b[name]['rows']:continue
            assert name in ['pay_runs','payroll_time_claims','sessions','login_events'],name
            assert not Counter(a[name]['rows'])-Counter(b[name]['rows']),name
            added[name]=len(b[name]['rows'])-len(a[name]['rows'])
        # The same hour is reserved by both its Core identity and Quanta origin.
        assert added=={'pay_runs':1,'payroll_time_claims':2,'sessions':1,'login_events':1},added
        report.update(passed=report['phase']=='verification' and report.get('native_session_retired') is True,run_id=run['id'],
            gross='50.00',hours='1.00',pay_rate='50.0000',currency='USD',status='draft',execution_hold=True,
            original_records_preserved=True,collections_verified=len(a),added_record_counts=added,provider_authority_state='allocated')
    except Exception as e:report.update(verification_failure=type(e).__name__,verification_line=e.__traceback__.tb_lineno)
    report['finished_at']=datetime.now(timezone.utc).isoformat();save(STATE,report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':sys.exit(main())
