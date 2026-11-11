"""Accept only P-368 and PL-874 as inactive/draft metadata in the isolated provider."""
import datetime,hashlib,json,os,subprocess,sys,urllib.request,urllib.error
from pathlib import Path
PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
CONTAINER='core-provider-pilot-20261029-pure-pilot-web-1'
ORG='org_0369a63ff2cb'
PERSON='cpp_35e915068e58c292fe92f97afcefdecd5edc1f698e8160111ffdfcafb533caa4'
ASSIGNMENT='cpa_a09e554d86e41986180b5187311fff6074c3b30f7af4e92a9e1ebb3996485676'
STATE=PRIVATE/'synthetic-lifecycle-pure-native-20261111.json'
def request(path,token=None,body=None):
    assert path=='/auth/login' or path.startswith('/payroll/core-people/'+PERSON) or path.startswith('/assignments/core/'+ASSIGNMENT)
    headers={'Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    req=urllib.request.Request('http://127.0.0.1:19180/api'+path,None if body is None else json.dumps(body).encode(),headers)
    try:
        with urllib.request.urlopen(req,timeout=35) as response:return response.status,json.load(response)
    except urllib.error.HTTPError as e:
        value=json.load(e)
        with os.fdopen(os.open(str(STATE)+'.last-error.private.json',os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(value,f)
        return e.code,value
def snapshot():
    code=r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
print(json.dumps({name:sorted(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest() for row in db[name].find({})) for name in sorted(db.list_collection_names())}))'''
    r=subprocess.run(['docker','exec','-i',CONTAINER,'python','-'],input=code,text=True,capture_output=True,timeout=30)
    if r.returncode:raise RuntimeError('Isolated provider snapshot failed')
    return json.loads(r.stdout)
def checkpoint(report):
    with STATE.open('w') as f:json.dump(report,f,indent=2)
def main():
    assert sys.argv[1:]==['--accept-synthetic-metadata'] and os.geteuid()==0 and PRIVATE.is_dir() and not PRIVATE.is_symlink()
    if STATE.exists():raise RuntimeError('Inspect the existing native review outcome; do not repeat an uncertain write')
    before=snapshot()
    with os.fdopen(os.open(str(STATE)+'.before.private.json',os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:json.dump(before,f)
    with os.fdopen(os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:f.write('{}')
    report={'checked_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'passed':False,'phase':'login','production_changed':False,'real_payroll_payments_postings':False}
    try:
        plan=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        status,login=request('/auth/login',body={'email':'thunderhawk-core-pilot@example.invalid','password':plan['pure_password']})
        assert status==200 and login['organization']['id']==ORG and login['user']['id']=='usr_2d19df3dfa23'
        token=login['token'];del plan,login
        base='/payroll/core-people/'+PERSON
        report['phase']='person_review';checkpoint(report)
        status,review=request(base,token)
        if status==200 and review.get('creation_enabled') is False:
            report['setup_required']='Enable isolated COREFLUX_PEOPLE_PROVISIONING before accepting this profile'
            raise RuntimeError('Person profile creation is disabled in the isolated provider')
        assert status==200 and review['state']=='ready' and review['creation_enabled'] is True and review['packet']['person']['number']=='P-368' and review['email_candidates']==[]
        report['phase']='person_reviewed';report['person_review_hash']=review['review_hash'];checkpoint(report)
        status,_=request(base+'/create-profile',token,{'review_hash':'0'*64,'confirm_new_person':True});assert status==409
        body={'review_hash':review['review_hash'],'confirm_new_person':True}
        report['phase']='person_creating';checkpoint(report)
        status,created=request(base+'/create-profile',token,body)
        assert status==200 and created['state']=='created' and created['employee_id']
        status,replay=request(base+'/create-profile',token,body);assert status==200 and replay==created
        report['employee_id']=created['employee_id'];report['phase']='person_created';checkpoint(report)
        base='/assignments/core/'+ASSIGNMENT
        status,review=request(base,token)
        assert status==200 and review['packet']['placement']['number']=='PL-874'
        report['assignment_review_state']=review['state'];report['missing_links']=review['missing_links'];checkpoint(report)
        assert review['state']=='ready' and review['missing_links']==[]
        body={'review_hash':review['review_hash'],'confirmed':True}
        status,_=request(base+'/create',token,{**body,'review_hash':'0'*64});assert status==409
        report['phase']='assignment_creating';checkpoint(report)
        status,created=request(base+'/create',token,body)
        assert status==200 and created['state']=='created' and created['assignment_status']=='draft'
        status,replay=request(base+'/create',token,body);assert status==200 and replay==created
        report.update(assignment_id=created['assignment_id'],assignment_status=created['assignment_status'],accepted_revision=created['accepted_revision'],duplicate_requests_same_records=True,altered_reviews_rejected=True)
        report['phase']='verify';checkpoint(report)
        after=snapshot();from collections import Counter
        additions={};preserved=0
        for name in set(before)|set(after):
            old,new=Counter(before.get(name,[])),Counter(after.get(name,[]));assert not old-new,('Original record changed',name)
            preserved+=sum(old.values())
            if new-old:additions[name]=sum((new-old).values())
        assert additions=={'employees':1,'coreflux_record_links':2,'assignments':1},additions
        report.update(passed=True,phase='complete',original_documents_preserved=preserved,added_collections=additions,employee_status='inactive')
    except Exception as e:report.update(failure=type(e).__name__,failure_message=str(e)[:600])
    checkpoint(report);print(json.dumps(report));return 0 if report['passed'] else 1
if __name__=='__main__':raise SystemExit(main())
