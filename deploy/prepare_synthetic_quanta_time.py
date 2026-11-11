"""Ready only P-368 / PL-874 in the isolated Quanta tenant through native APIs."""
import fcntl, hashlib, json, os, subprocess, sys, urllib.error, urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
WEB = 'core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
ACTOR = '8041205e-b179-4991-8dbe-65d51a474e22'
APPROVER = 'ff6fdfe9-291c-4fac-91aa-51782ba5585f'
WORKER = 'cfw_14ff9fa0959916e822421f01ba8884b4fc667e774a918af3c5c74d814a422574'
SITE = '1bc9011d-12df-458e-b79d-c31f09b9ee09'
KEY = '4aae55c2-313b-4515-bc3c-12419811bdf2'
STATE = PRIVATE / 'synthetic-quanta-time-preparation-20261111.json'


def save(path, value):
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as stream:
        json.dump(value, stream, indent=2)


def request(path, token=None, body=None, method=None):
    assert path in ['/auth/login', '/auth/me', '/auth/logout', '/tenant/api-keys',
        '/tenant/api-keys/'+KEY+'/scopes', '/tenant/users/'+WORKER,
        '/tenant/users/'+WORKER+'/set-approver', '/tenant/worksites/'+SITE+'/assignments']
    headers = {'Content-Type': 'application/json'}
    if token: headers['Authorization'] = 'Bearer '+token
    req = urllib.request.Request('http://127.0.0.1:19181/api'+path,
        None if body is None else json.dumps(body).encode(), headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        value = json.loads(error.read())
        save(str(STATE)+'.last-response.private.json', value)
        return error.code, value


def snapshot():
    code = r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
from pathlib import Path
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MAIL_DRIVER']=='devoutbox' and os.environ['QUANTA_STARTUP_MODE']=='web'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
assert os.environ['COREFLUX_TIME_COMMITS']=='1' and not os.environ.get('COREFLUX_TIME_ACCESS_TENANTS')
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
t='4f93d4e7-af74-4765-b812-3464bb6d974b'
w='cfw_14ff9fa0959916e822421f01ba8884b4fc667e774a918af3c5c74d814a422574'
a='8041205e-b179-4991-8dbe-65d51a474e22'; k='4aae55c2-313b-4515-bc3c-12419811bdf2'
worker=d.users.find_one({'tenant_id':t,'id':w}); admin=d.users.find_one({'tenant_id':t,'id':a})
assert worker and worker['email']=='thunderhawk-lifecycle-20261111@example.com' and worker['roles']==['worker']
assert admin and admin['roles']==['tenant_admin']
assert d.tenants.find_one({'id':t,'name':'Thunderhawk — isolated pilot'})
result={}
for n in sorted(d.list_collection_names()):
 rows=[]
 for row in d[n].find({}):
  if n=='users' and row.get('id')==w and row.get('tenant_id')==t:
   for field in ['status','approver_user_id','updated_at']:row.pop(field,None)
  if n=='users' and row.get('id')==a and row.get('tenant_id')==t:row.pop('last_login_at',None)
  if n=='identities' and row.get('id')==admin['identity_id']:
   for field in ['last_tenant_id','updated_at']:row.pop(field,None)
  if n=='api_keys' and row.get('id')==k and row.get('tenant_id')==t:
   for field in ['scopes','scope_revision','scope_changes']:row.pop(field,None)
  if n=='coreflux_commit_fences':row.pop('revision',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 result[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
key=d.api_keys.find_one({'id':k,'tenant_id':t})
assert key and not key.get('revoked_at') and not key.get('expires_at')
print(json_util.dumps({'collections':result,'worker':worker,'key':key,
 'fence':d.coreflux_commit_fences.find_one({}),
 'memberships':list(d.worksite_assignments.find({'tenant_id':t,'user_id':w})),
 'runtime':{p:hashlib.sha256((Path('/app/backend')/p).read_bytes().replace(b'\r\n',b'\n')).hexdigest()
  for p in ['app/routers/tenant.py','app/routers/worksites.py','app/routers/api_keys.py','app/routers/auth.py','app/coreflux_commit.py']}}))'''
    response = subprocess.run(['docker','exec','-i',WEB,'python','-'], input=code,
        text=True, capture_output=True, timeout=25)
    if response.returncode: raise RuntimeError('Isolated snapshot failed')
    return json.loads(response.stdout)


def main():
    assert sys.argv[1:]==['--prepare-synthetic-worker'] and os.geteuid()==0
    os.umask(0o077)
    assert PRIVATE.resolve()==PRIVATE and not PRIVATE.stat().st_mode & 0o077 and not STATE.exists()
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a')
    fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    plan=json.loads((PRIVATE/'synthetic-quanta-time-plan-20261111.json').read_text())
    assert datetime.now(timezone.utc)<datetime.fromisoformat(plan['expires_at'])
    live=json.loads(subprocess.check_output(['docker','inspect',WEB]))[0]
    assert live['Id']==plan['container_id'] and live['State']['Running']
    before=snapshot()
    assert before['runtime']==plan['runtime']
    assert before['worker']['status']=='disabled' and not before['worker'].get('identity_id')
    assert not before['worker'].get('approver_user_id') and not before['memberships']
    assert before['key'].get('scope_revision',1)==1 and 'read:core_time_access' in before['key']['scopes']
    assert 'write:core_time_access' not in before['key']['scopes']
    save(str(STATE)+'.before.private.json',before)
    report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'login','passed':False,
        'person_number':'P-368','placement_number':'PL-874','production_changed':False,
        'real_payroll_payments_postings':False,'worker_login_granted':False}
    save(STATE,report)
    token=refresh=None
    try:
        credentials=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        status,login=request('/auth/login',body={'email':'thunderhawk-core-pilot-admin@example.invalid',
            'password':credentials['quanta_admin_password'],'tenant_slug':'thunderhawk'})
        assert status==200 and login['user']['id']==ACTOR
        token=login['access_token'];refresh=login['refresh_token'];del credentials,login
        status,me=request('/auth/me',token)
        assert status==200 and me['current_tenant_id']==TENANT and me['user']['roles']==['tenant_admin'] and not me['is_impersonating']
        report['phase']='worker-activation';save(STATE,report)
        status,worker=request('/tenant/users/'+WORKER,token,{'status':'active'},'PATCH')
        assert status==200 and worker['status']=='active' and worker['roles']==['worker']
        report['phase']='independent-approver';save(STATE,report)
        status,_=request('/tenant/users/'+WORKER+'/set-approver',token,{'approver_user_id':WORKER},'PATCH')
        assert status==400
        status,worker=request('/tenant/users/'+WORKER+'/set-approver',token,{'approver_user_id':APPROVER},'PATCH')
        assert status==200 and worker['approver_user_id']==APPROVER
        report['self_approval_assignment_refused']=True
        report['phase']='worksite-membership';save(STATE,report)
        status,member=request('/tenant/worksites/'+SITE+'/assignments',token,{'user_id':WORKER})
        assert status==201 and member['tenant_id']==TENANT and member['user_id']==WORKER and member['active']
        status,replay=request('/tenant/worksites/'+SITE+'/assignments',token,{'user_id':WORKER})
        assert status==201 and replay['id']==member['id']
        report['membership_id']=member['id'];report['membership_replay_verified']=True
        report['phase']='time-permission';save(STATE,report)
        scopes=before['key']['scopes']+['write:core_time_access']
        status,key=request('/tenant/api-keys/'+KEY+'/scopes',token,{'scopes':scopes,'expected_revision':1},'PATCH')
        assert status==200 and key['scopes']==scopes and key['scope_revision']==2
        status,_=request('/tenant/api-keys/'+KEY+'/scopes',token,{'scopes':scopes,'expected_revision':1},'PATCH')
        assert status==409
        status,replay=request('/tenant/api-keys/'+KEY+'/scopes',token,{'scopes':scopes,'expected_revision':2},'PATCH')
        assert status==200 and replay==key
        report.update(key_id=KEY,scope_revision=2,added_scope='write:core_time_access',stale_scope_revision_refused=True,scope_replay_verified=True)
        report['phase']='verification'
    except Exception as error:
        report.update(failure_class=type(error).__name__,failure_line=error.__traceback__.tb_lineno)
    finally:
        if token and refresh:
            try:
                status,result=request('/auth/logout',token,{'refresh_token':refresh})
                assert status==200 and result=={'ok':True,'revoked':1}
                report['refresh_session_revoked']=True
            except Exception as error:report['cleanup_failure']=type(error).__name__
        token=refresh=None
    try:
        after=snapshot();assert after['runtime']==before['runtime']
        assert after['worker']['status']=='active' and after['worker']['approver_user_id']==APPROVER and not after['worker'].get('identity_id')
        assert len(after['memberships'])==1 and after['memberships'][0]['id']==report['membership_id']
        assert after['key']['scopes']==scopes and after['key']['scope_revision']==2
        assert after['key']['scope_changes'][:-1]==before['key'].get('scope_changes',[])
        change=after['key']['scope_changes'][-1]
        assert change['before']==before['key']['scopes'] and change['after']==scopes and change['user_id']==ACTOR
        assert after['fence']['revision']>=before['fence']['revision']
        assert set(before['collections'])==set(after['collections'])
        changed={}
        for name,prior in before['collections'].items():
            fresh=after['collections'][name];assert prior['indexes']==fresh['indexes'],name
            if prior['rows']==fresh['rows']:continue
            assert name in ['auth_sessions','audit_events','worksite_assignments'],name
            assert not Counter(prior['rows'])-Counter(fresh['rows']),name
            changed[name]=len(fresh['rows'])-len(prior['rows'])
        assert changed.get('auth_sessions')==1 and changed.get('worksite_assignments')==1
        report.update(collections_verified=len(before['collections']),added_record_counts=changed,
            original_records_preserved=True,credential_and_existing_receipts_preserved=True,
            time_capture_still_disabled=True,worker_status='active',approver_id=APPROVER)
        report['passed']=report['phase']=='verification' and not report.get('cleanup_failure')
        if report['passed']:report['phase']='complete'
    except Exception as error:report.update(verification_failure=type(error).__name__,verification_line=error.__traceback__.tb_lineno)
    report['finished_at']=datetime.now(timezone.utc).isoformat();save(STATE,report)
    print(json.dumps(report));return 0 if report['passed'] else 1


if __name__=='__main__':sys.exit(main())
