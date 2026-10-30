"""Native HTTP time acceptance on the isolated Thunderhawk copy only.

Run on the provider host. Credentials and login responses never leave its
private directory. Real worker access, invitations, and payroll are untouched.
"""
import datetime
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
CONTAINER = 'core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER = 'cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa'
ADMIN = '8041205e-b179-4991-8dbe-65d51a474e22'
APPROVER = 'ff6fdfe9-291c-4fac-91aa-51782ba5585f'
DATE = '2026-10-19'
PERIOD_START = '2026-10-17'  # Quanta's native Saturday weekly calendar.
NOTE = 'ISOLATED INTEGRATION TEST: 8 synthetic hours, not real payroll payable.'
STATE = PRIVATE / 'thunderhawk-quanta-time-acceptance.json'


def request(path, token=None, body=None, method=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request('http://127.0.0.1:19181/api' + path,
        None if body is None else json.dumps(body).encode(), headers,
        method=method or ('GET' if body is None else 'POST'))
    try:
        with urllib.request.urlopen(req, timeout=40) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        # Never include arbitrary response bodies or login data in diagnostics.
        raise RuntimeError(f'HTTP {error.code} at {path.split("?")[0]}') from None


def checkpoint(report):
    tmp = STATE.with_suffix('.tmp')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as out:
        json.dump(report, out, sort_keys=True, indent=2)
    os.replace(tmp, STATE)


def inspect_records():
    code = '''import os,json
from pymongo import MongoClient
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1'
assert os.environ['DISABLE_SCHEDULER']=='1'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
t=d.tenants.find_one({'id':__TENANT__})
assert t and t['name']=='Thunderhawk \u2014 isolated pilot'
w=d.users.find_one({'id':__WORKER__,'tenant_id':__TENANT__})
assert w and w['roles']==['worker'] and not w.get('identity_id')
entries=list(d.time_entries.find({'tenant_id':__TENANT__,'user_id':__WORKER__},{'_id':0,'id':1,'work_date':1,'duration_minutes':1,'notes':1,'timesheet_id':1}))
sheets=list(d.timesheets.find({'tenant_id':__TENANT__,'user_id':__WORKER__},{'_id':0,'id':1,'status':1,'period_start_local':1,'total_minutes':1,'submitted_by_user_id':1,'approved_by_user_id':1}))
print(json.dumps({'worker_status':w['status'],'worker_has_login':bool(w.get('identity_id')),'entries':entries,'sheets':sheets}))
'''.replace('__TENANT__', repr(TENANT)).replace('__WORKER__', repr(WORKER))
    result = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'python', '-'],
                            input=code, text=True, capture_output=True, check=False)
    if result.returncode:
        raise RuntimeError('Isolated database and mail guards failed')
    return json.loads(result.stdout)


def main():
    if sys.argv[1:] != ['--accept-isolated-thunderhawk-time'] or not PRIVATE.is_dir():
        raise RuntimeError('Explicit isolated pilot argument and host directory required')
    report = json.loads(STATE.read_text()) if STATE.exists() else {
        'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'workspace_id': TENANT, 'worker_id': WORKER, 'mode': 'isolated_test_hours',
        'production_changed': False, 'worker_login_created': False,
        'real_payroll_executed': False, 'outbound_mail': 'devoutbox only'}
    phase = 'preflight'
    try:
        records = inspect_records()
        assert len(records['entries']) <= 1 and len(records['sheets']) <= 1
        if records['entries']:
            e = records['entries'][0]
            assert e['work_date'] == DATE and e['duration_minutes'] == 480 and e['notes'] == NOTE
            report['entry_id'] = e['id']
        plan = json.loads((PRIVATE / 'thunderhawk-bootstrap-plan.private.json').read_text())
        tokens = {}
        phase = 'native_login'
        for role, uid in [('admin', ADMIN), ('approver', APPROVER)]:
            login = request('/auth/login', body={
                'email': f'thunderhawk-core-pilot-{role}@example.invalid',
                'password': plan[f'quanta_{role}_password'], 'tenant_slug': 'thunderhawk'})
            token = login['access_token']
            me = request('/auth/me', token)
            assert me['current_tenant_id'] == TENANT and me['user']['id'] == uid
            assert not me['is_impersonating']
            tokens[role] = token
        admin, approver = tokens['admin'], tokens['approver']
        phase = 'activate_directory_without_login'
        if records['worker_status'] != 'active':
            worker = request(f'/tenant/users/{WORKER}', admin, {'status': 'active'}, 'PATCH')
            assert worker['status'] == 'active'
        request(f'/tenant/users/{WORKER}/set-approver', admin, {'approver_user_id': APPROVER}, 'PATCH')
        phase = 'create_test_entry'
        if not records['entries']:
            entry = request('/admin/time-entries', admin, {
                'user_id': WORKER, 'entry_mode': 'duration', 'work_date': DATE,
                'duration_minutes': 480, 'notes': NOTE})
            assert entry['duration_minutes'] == 480
            report['entry_id'] = entry['id']
            checkpoint(report)
        phase = 'submit_as_admin'
        records = inspect_records()
        if records['sheets']:
            sheet = records['sheets'][0]
            assert sheet['period_start_local'] == PERIOD_START
            report['timesheet_id'] = sheet['id']
        else:
            query = urllib.parse.urlencode({'worker_id': WORKER, 'period_start_local': PERIOD_START})
            week = request('/admin/timesheets/worker-week?' + query, admin)
            assert week['can_submit'] is True
            sheet = request('/admin/timesheets/worker-week/submit', admin, {
                'worker_id': WORKER, 'period_start_local': PERIOD_START,
                'expected_revision': week['revision'], 'note': NOTE})
            assert sheet['status'] == 'submitted' and sheet['total_minutes'] == 480
            assert sheet['submitted_by_user_id'] == ADMIN and sheet['approver_user_id'] == APPROVER
            report['timesheet_id'] = sheet['id']
            checkpoint(report)
        phase = 'approve_with_separate_test_account'
        if sheet['status'] == 'submitted':
            sheet = request(f'/approvals/timesheets/{sheet["id"]}/approve', approver, {'note': NOTE})
        assert sheet['status'] == 'approved' and sheet['total_minutes'] == 480
        phase = 'readback'
        after = inspect_records()
        assert len(after['entries']) == 1 and len(after['sheets']) == 1
        verified = after['sheets'][0]
        assert verified['status'] == 'approved' and verified['approved_by_user_id'] == APPROVER
        assert verified['submitted_by_user_id'] == ADMIN
        report.update({'passed': True, 'minutes': 480, 'date': DATE,
            'submitted_by': ADMIN, 'approved_by': APPROVER, 'separate_approver_verified': True,
            'worker_has_login_after': after['worker_has_login'], 'source_status': 'approved'})
        report.pop('failed_phase', None)
        report.pop('error', None)
    except Exception as error:
        report.update({'passed': False, 'failed_phase': phase, 'error': str(error) if isinstance(error, RuntimeError) else type(error).__name__})
        checkpoint(report)
        print(json.dumps(report))
        return 1
    checkpoint(report)
    print(json.dumps(report))
    return 0


if __name__ == '__main__':
    sys.exit(main())
