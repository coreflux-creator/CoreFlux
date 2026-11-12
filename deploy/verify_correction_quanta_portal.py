"""Read the authenticated portal projection after the synthetic correction return."""
import json,os,subprocess,sys,urllib.request,urllib.error,fcntl
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path
from correct_correction_quanta_hour import snapshot,save,PRIVATE,WEB,TENANT,ADMIN,WORKER,ASSIGNMENT
STATE=PRIVATE/'synthetic-correction-portal-verification-20261112.json'
PATH='/core/assignments/'+ASSIGNMENT+'/time-reviews?view=workspace'
def request(path,token=None,body=None):
    assert path in [PATH,'/auth/login','/auth/logout']
    headers={'Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    req=urllib.request.Request('http://127.0.0.1:19181/api'+path,None if body is None else json.dumps(body).encode(),headers)
    try:
        with urllib.request.urlopen(req,timeout=20) as response:return response.status,json.load(response)
    except urllib.error.HTTPError as error:return error.code,json.loads(error.read())
def main():
    assert os.geteuid()==0 and sys.argv[1:]==['--verify-native-portal'] and not STATE.exists()
    os.umask(0o077);lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    before=snapshot();save(str(STATE)+'.before.private.json',before)
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':False,'native_http':True,'rendered_browser_acceptance':False,'person_number':'P-387','placement_number':'PL-902'}
    save(STATE,report);token=refresh=None
    try:
        status,_=request(PATH);assert status==401
        credentials=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        status,login=request('/auth/login',body={'email':'thunderhawk-core-pilot-admin@example.invalid','password':credentials['quanta_admin_password'],'tenant_slug':'thunderhawk'})
        assert status==200 and login['user']['id']==ADMIN
        token=login['access_token'];refresh=login['refresh_token'];del credentials,login
        status,portal=request(PATH,token)
        assert status==200 and portal['workspace_id']==TENANT and portal['assignment_id']==ASSIGNMENT and len(portal['items'])==1
        item=portal['items'][0];assert item['state']=='accepted' and item['current'] and item['minutes']==90 and item['work_date']=='2026-11-09'
        assert set(item)=={'id','state','work_date','minutes','shared_at','current'}
        report['portal']=portal;report['anonymous_denied']=True
        report['verified']=True
    except Exception as error:report.update(failure_class=type(error).__name__,failure_line=error.__traceback__.tb_lineno)
    finally:
        if token and refresh:
            try:
                status,result=request('/auth/logout',token,{'refresh_token':refresh});assert status==200 and result=={'ok':True,'revoked':1}
                report['session_revoked']=True
            except Exception as error:report['cleanup_failure']=type(error).__name__
        token=refresh=None
    try:
        after=snapshot();assert before['runtime']==after['runtime'] and before['entries']==after['entries'] and before['sheets']==after['sheets']
        changed={}
        assert set(before['collections'])==set(after['collections'])
        for name,prior in before['collections'].items():
            fresh=after['collections'][name];assert prior['indexes']==fresh['indexes']
            if prior['rows']==fresh['rows']:continue
            assert name in ['auth_sessions','audit_events'] and not Counter(prior['rows'])-Counter(fresh['rows']),name
            changed[name]=len(fresh['rows'])-len(prior['rows'])
        assert changed['auth_sessions']==1
        report.update(collections_verified=len(before['collections']),added_record_counts=changed,business_records_unchanged=True,source_approval_unchanged=True)
        report['passed']=report.get('verified',False) and report.get('session_revoked',False) and not report.get('cleanup_failure')
    except Exception as error:report.update(verification_failure=type(error).__name__,verification_line=error.__traceback__.tb_lineno)
    save(STATE,report);print(json.dumps(report));return 0 if report['passed'] else 1
if __name__=='__main__':sys.exit(main())
