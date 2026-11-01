"""Prepare a native worksite and worker time-entry access in the isolated copy."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER='cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa'
SITE={'name':'TEST Rockefeller - Hamilton, NJ','code':'CORE-PL-864-PILOT',
      'address':'Hamilton, NJ','timezone':'America/New_York'}


def request(path,token=None,body=None,method=None):
    headers={'Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    req=urllib.request.Request('http://127.0.0.1:19181/api'+path,
        None if body is None else json.dumps(body).encode(),headers,
        method=method or ('GET' if body is None else 'POST'))
    try:
        with urllib.request.urlopen(req,timeout=30) as response:return json.load(response)
    except urllib.error.HTTPError as error:
        raise RuntimeError('Native API rejected request: '+str(error.code)) from None


def protected():
    code='''import os,json,hashlib
from pymongo import MongoClient
from bson.json_util import dumps
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
t=__TENANT__
assert d.tenants.find_one({'id':t,'name':'Thunderhawk — isolated pilot'})
out={}
for name in ['time_entries','timesheets','coreflux_assignments','coreflux_onboarding','coreflux_person_links']:
    rows=sorted(dumps(r,sort_keys=True) for r in d[name].find({}))
    out[name]=hashlib.sha256(json.dumps(rows).encode()).hexdigest()
out['worker_roles']=d.users.find_one({'id':__WORKER__,'tenant_id':t})['roles']
print(json.dumps(out))
'''.replace('__TENANT__',repr(TENANT)).replace('__WORKER__',repr(WORKER))
    r=subprocess.run(['docker','exec','-i','core-provider-pilot-20261029-quanta-pilot-web-1','python','-'],
        input=code,text=True,capture_output=True)
    if r.returncode:raise RuntimeError('Isolated environment guard failed')
    return json.loads(r.stdout)


def main():
    if sys.argv[1:]!=['--prepare-isolated-worker-time']:return 2
    report={'workspace_id':TENANT,'worker_id':WORKER,'production_changed':False,
        'real_worker_email_sent':False,'core_assignment_changed':False}
    phase='guard'
    try:
        before=protected()
        plan=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        token=request('/auth/login',body={'email':'thunderhawk-core-pilot-admin@example.invalid',
            'password':plan['quanta_admin_password'],'tenant_slug':'thunderhawk'})['access_token']
        me=request('/auth/me',token)
        assert me['current_tenant_id']==TENANT and me['user']['id']=='8041205e-b179-4991-8dbe-65d51a474e22'
        assert me['user']['roles']==['tenant_admin']
        phase='worksite'
        sites=request('/tenant/worksites?page_size=200',token)
        assert sites['total']<=200
        matched=[s for s in sites['items'] if s['code']==SITE['code']]
        assert len(matched)<=1
        site=matched[0] if matched else request('/tenant/worksites',token,SITE)
        assert site['tenant_id']==TENANT and site['active'] is True
        assert all(site[k]==v for k,v in SITE.items())
        phase='native_worker_access'
        member=request('/tenant/worksites/'+site['id']+'/assignments',token,{'user_id':WORKER})
        assert member['tenant_id']==TENANT and member['user_id']==WORKER and member['active'] is True
        repeat=request('/tenant/worksites/'+site['id']+'/assignments',token,{'user_id':WORKER})
        assert repeat['id']==member['id']
        phase='pilot_manual_time_policy'
        policy=request('/tenant/config/time-entry',token)
        revised=request('/tenant/config/time-entry',token,{'allow_worker_duration_entry':True},'PATCH')
        assert revised=={**policy,'allow_worker_duration_entry':True}
        assert protected()==before
        report.update({'passed':True,'worksite_id':site['id'],'worksite_code':site['code'],
            'worksite_name':site['name'],'worksite_membership_id':member['id'],
            'worksite_membership_replay_verified':True,'allow_worker_duration_entry':True,
            'prior_time_policy':policy,'max_daily_minutes':revised['max_daily_minutes'],
            'existing_time_assignments_and_onboarding_unchanged':True,'worker_roles_unchanged':True,
            'location_source':'User-provided PL-864 staffing row: Hamilton, NJ'})
    except Exception as error:
        report.update({'passed':False,'failed_phase':phase,'failure_class':type(error).__name__})
        if isinstance(error,RuntimeError):report['reason']=str(error)
    print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__=='__main__':sys.exit(main())
