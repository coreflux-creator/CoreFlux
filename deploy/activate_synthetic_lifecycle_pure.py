"""Use native authenticated APIs to ready only synthetic P-368 / PL-874 for held time tests."""
import fcntl,json,os,subprocess,sys,urllib.request,urllib.error
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path
PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
WEB='core-provider-pilot-20261029-pure-pilot-web-1'
ORG='org_0369a63ff2cb'; ACTOR='usr_2d19df3dfa23'
EMPLOYEE='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'
ASSIGNMENT='asg_core_aff68d7460216d12beedbe1e0e6a3ddee7921840a562577d275d992b1d131008'
INPUT='cpa_a09e554d86e41986180b5187311fff6074c3b30f7af4e92a9e1ebb3996485676'
REVISION='6a0c515b5e086905ab7909c27288afa7f581422b103184b322be394fd811eda2'
STATE=PRIVATE/'synthetic-lifecycle-pure-activation-20261111.json'

def request(path,token=None,body=None,method=None):
    assert path in ['/auth/login','/security/overview','/security/sessions/revoke'] or path.startswith('/payroll/employees/'+EMPLOYEE) or path.startswith('/assignments/core/'+INPUT)
    headers={'Content-Type':'application/json'}
    if token: headers['Authorization']='Bearer '+token
    req=urllib.request.Request('http://127.0.0.1:19180/api'+path,None if body is None else json.dumps(body).encode(),headers,method=method)
    try:
        with urllib.request.urlopen(req,timeout=20) as r: return r.status,json.load(r)
    except urllib.error.HTTPError as e:
        raw=e.read()
        try:value=json.loads(raw)
        except ValueError:value={'response_format':'non-json'}
        with os.fdopen(os.open(str(STATE)+'.last-error.private.json',os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(value,f)
        return e.code,value

def snapshot():
    code=r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
target={'employees':'emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717','assignments':'asg_core_aff68d7460216d12beedbe1e0e6a3ddee7921840a562577d275d992b1d131008'}
result={}
for n in sorted(db.list_collection_names()):
 rows=[]
 for row in db[n].find({}):
  if n in target and row.get('id')==target[n] and row.get('organization_id')=='org_0369a63ff2cb':continue
  if n=='users' and row.get('id')=='usr_2d19df3dfa23':row.pop('last_login',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 result[n]={'rows':sorted(rows),'indexes':json_util.dumps(db[n].index_information(),sort_keys=True)}
employee=db.employees.find_one({'id':target['employees'],'organization_id':'org_0369a63ff2cb'})
assignment=db.assignments.find_one({'id':target['assignments'],'organization_id':'org_0369a63ff2cb'})
assert employee and assignment
print(json_util.dumps({'collections':result,'employee':employee,'assignment':assignment}))'''
    r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=25)
    if r.returncode:raise RuntimeError('Isolated snapshot unavailable')
    return json.loads(r.stdout)

def save(report):
    with STATE.open('w') as f:json.dump(report,f,indent=2)

def main():
    assert sys.argv[1:]==['--activate-synthetic-metadata'] and os.geteuid()==0 and not STATE.exists()
    lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    install=json.loads((PRIVATE/'pure-lifecycle-current-source-install-resume-20261111.json').read_text())
    live=json.loads(subprocess.check_output(['docker','inspect',WEB]))[0]
    assert install['passed'] is True and live['Id']==install['container_id'] and live['State']['Running']
    before=snapshot(); assert before['employee']['status']=='inactive' and before['assignment']['status']=='draft'
    assert before['assignment']['source_revision']==REVISION
    with os.fdopen(os.open(str(STATE)+'.before.private.json',os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:json.dump(before,f)
    with os.fdopen(os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:f.write('{}')
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':False,'phase':'login','production_changed':False,'real_payroll_payments_postings':False}
    token=None; sid=None
    try:
        plan=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        code,login=request('/auth/login',body={'email':'thunderhawk-core-pilot@example.invalid','password':plan['pure_password']})
        assert code==200 and login['organization']['id']==ORG and login['user']['id']==ACTOR
        token=login['token'];del login,plan
        code,security=request('/security/overview',token);assert code==200 and security['current_session_id'];sid=security['current_session_id']
        base='/assignments/core/'+INPUT
        code,review=request(base,token)
        assert code==200 and review['state']=='created' and review['assignment_status']=='draft' and review['assignment_id']==ASSIGNMENT and review['accepted_revision']==REVISION and review['missing_links']==[]
        packet=review['packet'];term=packet['terms'][0]
        assert packet['person']['number']=='P-368' and packet['placement']['number']=='PL-874' and packet['source_status']=='active'
        assert packet['start_date']=='2026-11-09' and len(packet['terms'])==1 and term['pay_rate_unit']=='hour' and float(term['pay_rates']['regular'])==50
        code,_=request(base+'/activate',token,{'review_hash':review['review_hash'],'confirmed':True});assert code==409
        report['inactive_employee_blocks_assignment']=True
        employee='/payroll/employees/'+EMPLOYEE
        code,_=request(employee+'/status',token,{'status':'active'});assert code==409
        report['incomplete_setup_blocks_employee']=True
        code,detail=request(employee+'/detail',token);assert code==200 and detail['employee']['status']=='inactive'
        original=detail['employee']
        body={'name':original['name'],'email':original.get('email'),'employment_type':'w2','start_date':packet['start_date'],
            'pay_type':'hourly','hourly_rate':float(term['pay_rates']['regular']),'pay_method':'record_only',
            'ot_multiplier':float(term['pay_rates']['overtime'])/50 if term['pay_rates'].get('overtime') else 1.5}
        report['phase']='employee-setup';save(report)
        code,updated=request(employee,token,body,method='PUT');assert code==200 and updated['hourly_rate']==50 and updated['status']=='inactive'
        report['phase']='employee-activation';save(report)
        code,updated=request(employee+'/status',token,{'status':'active'});assert code==200 and updated['status']=='active'
        code,review=request(base,token);assert code==200 and review['assignment_status']=='draft'
        report['phase']='assignment-activation';save(report)
        old_review=review['review_hash']
        code,_=request(base+'/activate',token,{'review_hash':'0'*64,'confirmed':True});assert code==409
        code,active=request(base+'/activate',token,{'review_hash':old_review,'confirmed':True})
        assert code==200 and active['assignment_status']=='active' and active['assignment_id']==ASSIGNMENT and active['accepted_revision']==REVISION
        code,_=request(base+'/activate',token,{'review_hash':old_review,'confirmed':True});assert code==409
        code,replayed=request(base+'/activate',token,{'review_hash':active['review_hash'],'confirmed':True});assert code==200 and replayed==active
        report.update(employee_id=EMPLOYEE,assignment_id=ASSIGNMENT,employee_status='active',assignment_status='active',accepted_revision=REVISION,
            hourly_rate=50,pay_method='record_only',altered_and_stale_reviews_rejected=True,current_review_replay_unchanged=True)
        code,revoke=request('/security/sessions/revoke',token,{'session_id':sid});assert code==200 and revoke['status']=='revoked';sid=None
        report['native_session_retired']=True
        after=snapshot(); additions={};preserved=0
        for name in set(before['collections'])|set(after['collections']):
            b=before['collections'].get(name,{'rows':[]});a=after['collections'].get(name,{'rows':[]})
            old,new=Counter(b['rows']),Counter(a['rows']);assert not old-new,('Existing record changed',name)
            if 'indexes' in b:assert a.get('indexes')==b['indexes'],('Indexes changed',name)
            preserved+=sum(old.values())
            if new-old:additions[name]=sum((new-old).values())
        assert additions=={'sessions':1,'login_events':1},additions
        emp=after['employee'];ass=after['assignment']
        assert emp['status']=='active' and emp['hourly_rate']==50 and emp['pay_method']=='record_only'
        assert emp['coreflux_origin']==before['employee']['coreflux_origin'] and emp['_id']==before['employee']['_id']
        assert not any(emp.get(k) for k in ['routing_enc','account_enc','ssn_enc','ssn_last4','w4','state_form'])
        clean=dict(ass);prior=dict(before['assignment']);clean['status']=prior['status'];clean['core_assignment']=dict(clean['core_assignment']);clean['core_assignment'].pop('activation')
        assert clean==prior,'Assignment changed beyond native activation metadata'
        report.update(passed=True,phase='complete',other_documents_preserved=preserved,new_records=additions,no_bank_or_tax_identity_added=True)
    except Exception as e:report.update(failure=type(e).__name__,failure_message=str(e)[:350])
    finally:
        if sid and token:
            code,_=request('/security/sessions/revoke',token,{'session_id':sid});report['native_session_retired']=code==200
    save(report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':raise SystemExit(main())
