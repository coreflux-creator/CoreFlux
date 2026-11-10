"""Review one browser-created synthetic hour through the native pilot approval API."""
import datetime
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
CONTAINER = 'core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER = 'cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa'
APPROVER = 'ff6fdfe9-291c-4fac-91aa-51782ba5585f'
ENTRY = '69f7fc04-ce35-497b-ad2d-589eabca1455'
SHEET = '0c5accda-db6f-4d24-b5ba-960b9be0018d'
SITE = '1bc9011d-12df-458e-b79d-c31f09b9ee09'
NOTE = 'ISOLATED INTEGRATION TEST: 1 synthetic hour entered through the worker portal. Not real payroll payable.'


def request(path, token=None, body=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request('http://127.0.0.1:19181/api' + path,
        None if body is None else json.dumps(body).encode(), headers)
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, None


def inspect():
    code = '''import os,json,hashlib
from pymongo import MongoClient
from bson.json_util import dumps
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
t=__TENANT__; w=__WORKER__
assert d.tenants.find_one({'id':t,'name':'Thunderhawk — isolated pilot'})
assert d.users.find_one({'id':w,'tenant_id':t,'status':'active','roles':['worker']})
e=d.time_entries.find_one({'id':__ENTRY__,'tenant_id':t,'user_id':w},{'_id':0})
s=d.timesheets.find_one({'id':__SHEET__,'tenant_id':t,'user_id':w},{'_id':0})
assert e and s
protected={}
for name in ['time_entries','timesheets','coreflux_assignments','coreflux_onboarding','coreflux_time_reviews','coreflux_person_links','users','worksite_assignments']:
    query={'id':{'$ne':__ENTRY__}} if name=='time_entries' else {'id':{'$ne':__SHEET__}} if name=='timesheets' else {}
    projection={'last_login_at':0} if name=='users' else None
    rows=sorted(dumps(r,sort_keys=True) for r in d[name].find(query,projection))
    protected[name]=hashlib.sha256(json.dumps(rows).encode()).hexdigest()
print(json.dumps({'entry':{k:e.get(k) for k in ['id','work_date','duration_minutes','notes','source','worksite_id','timesheet_id','entry_mode']},
 'sheet':{k:s.get(k) for k in ['id','status','total_minutes','submitted_by_user_id','approved_by_user_id','approver_user_id','period_start_local']},'protected':protected}))
'''
    for name, value in [('TENANT', TENANT), ('WORKER', WORKER), ('ENTRY', ENTRY), ('SHEET', SHEET)]:
        code = code.replace('__' + name + '__', repr(value))
    result = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'python', '-'],
        input=code, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('Pilot isolation guard failed')
    return json.loads(result.stdout)


def main():
    if sys.argv[1:] != ['--approve-browser-test-hour'] or not PRIVATE.is_dir():
        return 2
    report = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'workspace_id': TENANT, 'entry_id': ENTRY, 'timesheet_id': SHEET,
        'production_changed': False, 'payroll_executed': False, 'independent_human_review': False}
    phase = 'guard'
    try:
        before = inspect()
        assert before['entry'] == {'id': ENTRY, 'work_date': '2026-10-26', 'duration_minutes': 60,
            'notes': NOTE, 'source': 'manual_worker', 'worksite_id': SITE, 'timesheet_id': SHEET, 'entry_mode': 'duration'}
        sheet = before['sheet']
        assert sheet['status'] in {'submitted', 'approved'} and sheet['total_minutes'] == 60
        assert sheet['submitted_by_user_id'] == WORKER and sheet['approver_user_id'] == APPROVER
        assert sheet['period_start_local'] == '2026-10-24'
        report['recovered_existing_approval'] = sheet['status'] == 'approved'
        plan = json.loads((PRIVATE / 'thunderhawk-bootstrap-plan.private.json').read_text())
        phase = 'approver_login'
        status, login = request('/auth/login', body={'email': 'thunderhawk-core-pilot-approver@example.invalid',
            'password': plan['quanta_approver_password'], 'tenant_slug': 'thunderhawk'})
        assert status == 200
        token = login['access_token']
        status, me = request('/auth/me', token)
        assert status == 200 and me['current_tenant_id'] == TENANT and me['user']['id'] == APPROVER
        assert not me['is_impersonating']
        phase = 'native_approval'
        if sheet['status'] == 'submitted':
            status, approved = request('/approvals/timesheets/' + SHEET + '/approve', token, {'note': NOTE})
            assert status == 200 and approved['status'] == 'approved'
        phase = 'readback'
        after = inspect()
        assert after['sheet']['status'] == 'approved' and after['sheet']['approved_by_user_id'] == APPROVER
        assert after['entry'] == before['entry'] and after['protected'] == before['protected']
        phase = 'duplicate_approval'
        status, _ = request('/approvals/timesheets/' + SHEET + '/approve', token, {'note': NOTE})
        assert status == 409
        repeat = inspect()
        assert repeat == after
        report.update({'passed': True, 'date': '2026-10-26', 'minutes': 60,
            'browser_created_and_worker_submitted': True, 'source': 'manual_worker',
            'submitted_by': WORKER, 'approved_by': APPROVER, 'separate_test_approver_verified': True,
            'duplicate_approval_rejected': True, 'source_status': 'approved',
            'other_time_identity_onboarding_and_access_unchanged': True})
    except Exception as error:
        report.update({'passed': False, 'failed_phase': phase, 'failure_class': type(error).__name__})
    print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
