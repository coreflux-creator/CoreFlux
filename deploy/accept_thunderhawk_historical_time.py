"""Replay user-supplied historical hours through isolated Quanta's native review flow.

Only the existing isolated pilot API and database are reachable by this helper.
The original workbook, production records, real payroll and payments are untouched.
"""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
STATE = PRIVATE / 'thunderhawk-historical-time-20261110.json'
CONTAINER = 'core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER = 'cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa'
SITE = '1bc9011d-12df-458e-b79d-c31f09b9ee09'
ADMIN = '8041205e-b179-4991-8dbe-65d51a474e22'
APPROVER = 'ff6fdfe9-291c-4fac-91aa-51782ba5585f'
SOURCE_HASH = '38a576b558d80bc63a65ec5472680c45ae5ba65744fda98109e4092881ee5ac8'
DATES = ['2026-10-' + str(day).zfill(2) for day in [5, 6, 7, 8, 9, 12, 13, 14, 15, 16]]
PERIODS = ['2026-10-03', '2026-10-10']  # Native Quanta Saturday weeks.


def request(path, token=None, body=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request('http://127.0.0.1:19181/api' + path,
        None if body is None else json.dumps(body).encode(), headers)
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        raise RuntimeError('Isolated native API rejected ' + path.split('?')[0] + ': ' + str(error.code)) from None


def checkpoint(report):
    tmp = STATE.with_suffix('.tmp')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as out:
        json.dump(report, out, sort_keys=True, indent=2)
        out.flush(); os.fsync(out.fileno())
    os.replace(tmp, STATE)


def inspect():
    payload = {'tenant': TENANT, 'worker': WORKER, 'site': SITE, 'dates': DATES, 'periods': PERIODS}
    code = '''import hashlib,json,os,sys
from pymongo import MongoClient
from bson.json_util import dumps
x=json.load(sys.stdin)
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
d=MongoClient(os.environ['MONGO_URL'],serverSelectionTimeoutMS=5000)[os.environ['DB_NAME']]
t=x['tenant'];w=x['worker']
assert d.tenants.find_one({'id':t,'name':'Thunderhawk — isolated pilot'})
assert d.users.find_one({'tenant_id':t,'id':w,'roles':['worker'],'status':'active'})
assert d.worksites.find_one({'tenant_id':t,'id':x['site'],'code':'CORE-PL-864-PILOT','active':True})
entries=list(d.time_entries.find({'tenant_id':t,'user_id':w,'work_date':{'$in':x['dates']}},
    {'_id':0,'id':1,'work_date':1,'duration_minutes':1,'notes':1,'timesheet_id':1,'worksite_id':1,'entry_mode':1}))
sheets=list(d.timesheets.find({'tenant_id':t,'user_id':w,'period_start_local':{'$in':x['periods']}},
    {'_id':0,'id':1,'period_start_local':1,'status':1,'total_minutes':1,'submitted_by_user_id':1,'approved_by_user_id':1,'approver_user_id':1}))
protected={}
for name in ['time_entries','timesheets','coreflux_assignments','coreflux_onboarding','coreflux_person_links','users','worksites','worksite_assignments']:
    q={}
    if name=='time_entries':q={'$nor':[{'tenant_id':t,'user_id':w,'work_date':{'$in':x['dates']}}]}
    if name=='timesheets':q={'$nor':[{'tenant_id':t,'user_id':w,'period_start_local':{'$in':x['periods']}}]}
    projection={'last_login_at':0} if name=='users' else None
    rows=sorted(dumps(r,sort_keys=True) for r in d[name].find(q,projection))
    protected[name]={'count':len(rows),'sha256':hashlib.sha256(json.dumps(rows).encode()).hexdigest()}
print(json.dumps({'entries':entries,'sheets':sheets,'protected':protected}))
'''
    result = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'python', '-c', code],
        input=json.dumps(payload), text=True, capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Isolated environment or native data guard failed')
    return json.loads(result.stdout)


def note(entry):
    return ('HISTORICAL WORKBOOK REPLAY — isolated pilot only. ' + entry['work_date']
        + ', Rockefeller Oct 26 Hrs!' + entry['source_cell'] + ', source ' + SOURCE_HASH[:12]
        + '. Previously paid period; do not pay again.')


def verify_entries(records, source, complete=False):
    by_date = {}
    for entry in records['entries']:
        day = entry['work_date']
        if day in by_date or day not in source:
            raise RuntimeError('Duplicate or unexpected historical date')
        if (entry['duration_minutes'] != source[day]['minutes'] or entry['notes'] != note(source[day])
                or entry['worksite_id'] != SITE or entry['entry_mode'] != 'duration'):
            raise RuntimeError('Existing entry differs from the exact workbook source')
        by_date[day] = entry
    if complete and sorted(by_date) != DATES:
        raise RuntimeError('Historical entry set is incomplete')
    return by_date


def main():
    if (sys.argv[1:] != ['--stage-historical-source'] or not PRIVATE.is_dir()
            or PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077):
        return 2
    source = json.load(sys.stdin)
    if (source.get('source_sha256') != SOURCE_HASH or source.get('sheet') != 'Oct 26 Hrs'
            or source.get('source_range') != 'M19:Z19' or source.get('total_hours') != 80
            or source.get('person_number') != 'P-358' or source.get('placement_number') != 'PL-864'
            or source.get('period_start') != '2026-10-05' or source.get('period_end') != '2026-10-18'
            or [e['work_date'] for e in source['entries']] != DATES
            or any(e['hours'] != 8 or e['minutes'] != 480 for e in source['entries'])):
        raise RuntimeError('Reviewed workbook period or identities differ')
    entries = {e['work_date']: e for e in source['entries']}
    lock = os.open(str(STATE) + '.lock', os.O_WRONLY | os.O_CREAT, 0o600)
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    report = json.loads(STATE.read_text()) if STATE.exists() else {
        'source_sha256': SOURCE_HASH, 'source_sheet': source['sheet'], 'source_range': source['source_range'],
        'mode': 'historical_source_replay_in_isolated_pilot', 'workspace_id': TENANT,
        'worker_id': WORKER, 'person_number': 'P-358', 'placement_number': 'PL-864',
        'production_changed': False, 'real_payroll_executed': False, 'financial_postings': False,
        'independent_human_review': False, 'original_client_approval_stamp_present': False,
        'source_designated_by_user': True, 'outbound_mail': 'devoutbox only'}
    phase = 'preflight'
    try:
        assert report['source_sha256'] == SOURCE_HASH
        before = inspect(); verify_entries(before, entries)
        protected = report.get('protected', before['protected'])
        if before['protected'] != protected:
            raise RuntimeError('Protected pilot records changed since the recorded start')
        report['protected'] = protected
        report['checked_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
        report['entries_created_this_attempt'] = 0
        checkpoint(report)
        plan = json.loads((PRIVATE / 'thunderhawk-bootstrap-plan.private.json').read_text())
        tokens = {}
        phase = 'native_login'
        for role, uid in [('admin', ADMIN), ('approver', APPROVER)]:
            login = request('/auth/login', body={'email': f'thunderhawk-core-pilot-{role}@example.invalid',
                'password': plan[f'quanta_{role}_password'], 'tenant_slug': 'thunderhawk'})
            token = login['access_token']; me = request('/auth/me', token)
            assert me['current_tenant_id'] == TENANT and me['user']['id'] == uid and not me['is_impersonating']
            tokens[role] = token
        current = verify_entries(inspect(), entries)
        phase = 'native_entry_creation'
        for day in DATES:
            if day in current:
                continue
            report['pending_date'] = day; checkpoint(report)
            created = request('/admin/time-entries', tokens['admin'], {'user_id': WORKER,
                'entry_mode': 'duration', 'work_date': day, 'duration_minutes': entries[day]['minutes'],
                'worksite_id': SITE, 'notes': note(entries[day])})
            assert created['work_date'] == day and created['duration_minutes'] == 480
            report['entries_created_this_attempt'] += 1
            report.pop('pending_date'); checkpoint(report)
        current = verify_entries(inspect(), entries, complete=True)
        phase = 'native_submit_and_separate_test_review'
        for period in PERIODS:
            records = inspect()
            matches = [s for s in records['sheets'] if s['period_start_local'] == period]
            if len(matches) > 1:
                raise RuntimeError('Duplicate native weekly sheet')
            sheet = matches[0] if matches else None
            if sheet is None or sheet['status'] == 'draft':
                query = urllib.parse.urlencode({'worker_id': WORKER, 'period_start_local': period})
                week = request('/admin/timesheets/worker-week?' + query, tokens['admin'])
                assert week['can_submit'] is True
                sheet = request('/admin/timesheets/worker-week/submit', tokens['admin'], {
                    'worker_id': WORKER, 'period_start_local': period, 'expected_revision': week['revision'],
                    'note': 'Historical workbook replay in the isolated pilot; previously paid period.'})
            assert sheet['submitted_by_user_id'] == ADMIN and sheet['approver_user_id'] == APPROVER
            assert sheet['total_minutes'] == 2400 and sheet['status'] in ('submitted', 'approved')
            if sheet['status'] == 'submitted':
                sheet = request('/approvals/timesheets/' + sheet['id'] + '/approve', tokens['approver'], {
                    'note': 'Separate test-account review of copied historical workbook hours. No live payroll authorization.'})
            assert sheet['status'] == 'approved'
            # The native approval response need not expose the reviewer; verify
            # the persisted separate reviewer during the database readback below.
        phase = 'readback'
        after = inspect(); current = verify_entries(after, entries, complete=True)
        assert after['protected'] == protected and len(after['sheets']) == 2
        for s in after['sheets']:
            assert s['status'] == 'approved' and s['total_minutes'] == 2400
            assert s['submitted_by_user_id'] == ADMIN and s['approved_by_user_id'] == APPROVER
        report.update(passed=True, minutes=4800, hours=80, separate_test_approver_verified=True,
            entries=[dict(current[d], source_cell=entries[d]['source_cell']) for d in DATES],
            sheets=after['sheets'], protected_records_unchanged=True)
        for key in ['failed_phase', 'failure_class', 'pending_date']:
            report.pop(key, None)
    except Exception as error:
        report.update(passed=False, failed_phase=phase, failure_class=type(error).__name__)
    checkpoint(report)
    print(json.dumps(report))
    return 0 if report.get('passed') else 1


if __name__ == '__main__':
    sys.exit(main())
