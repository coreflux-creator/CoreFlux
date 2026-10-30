"""Read-only native HTTP acceptance using the isolated pilot's existing test actors."""
import csv
import datetime
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
Q = '4f93d4e7-af74-4765-b812-3464bb6d974b'
CONTAINER = 'core-provider-pilot-20261029-quanta-pilot-web-1'
if sys.argv[1:] != ['--verify-isolated-team-portal'] or ROOT.resolve() != ROOT or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Exact isolated pilot and explicit verification required')

PREAMBLE = '''import hashlib,json,os,socket
from bson import json_util
from pymongo import MongoClient
assert socket.gethostname()=='quanta-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['QUANTA_STARTUP_MODE']=='web'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
assert os.environ['MAIL_DRIVER']=='devoutbox'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
tenant='4f93d4e7-af74-4765-b812-3464bb6d974b'
'''


def db(code):
    result = subprocess.run(['docker', 'exec', '-w', '/app/backend', CONTAINER, 'python', '-c', PREAMBLE + code],
                            text=True, capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Isolated database verification failed')
    return json.loads(result.stdout)


def snapshot():
    return db('''out={}
for name in sorted(d.list_collection_names()):
    values=sorted(json_util.dumps(r,sort_keys=True) for r in d[name].find({}))
    out[name]={'count':len(values),'sha256':hashlib.sha256(json.dumps(values).encode()).hexdigest()}
print(json.dumps(out))
''')


checks = []


def expect(value, label):
    if not value:
        raise RuntimeError(label)
    checks.append(label)


def get(path, token=None):
    headers = {'Authorization': 'Bearer ' + token} if token else {}
    request = urllib.request.Request('http://127.0.0.1:19181' + path, headers=headers)
    try:
        response = urllib.request.urlopen(request, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    raw = response.read().decode('utf-8')
    if 'application/json' in response.headers.get('Content-Type', ''):
        raw = json.loads(raw)
    return response.status, raw, response.headers


report = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'production_changed': False,
          'staff_browser_acceptance': False, 'financial_execution': False, 'test_sessions': 'existing isolated actors; server-issued short-lived JWTs held in memory only'}
try:
    before = snapshot()
    context = db('''from app.security import create_access_token
ids={'worker':'cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa',
     'approver':'ff6fdfe9-291c-4fac-91aa-51782ba5585f','admin':'8041205e-b179-4991-8dbe-65d51a474e22'}
tokens={}
for name,uid in ids.items():
    user=d.users.find_one({'id':uid,'tenant_id':tenant,'status':'active'})
    assert user and d.identities.find_one({'id':user['identity_id'],'disabled_at':None})
    assert user['roles']=={'worker':['worker'],'approver':['approver'],'admin':['tenant_admin']}[name]
    tokens[name]=create_access_token(user_id=uid,identity_id=user['identity_id'],tenant_id=tenant,roles=user['roles'],mfa=False)[0]
assignment=d.coreflux_assignments.find_one({'tenant_id':tenant,'placement.number':'PL-864'})
assert assignment and assignment['worker_id']==ids['worker'] and assignment['person']['number']=='P-358'
case=d.coreflux_onboarding.find_one({'tenant_id':tenant,'assignment_id':assignment['id'],'publication.state':'published'})
assert case
foreign=d.coreflux_assignments.find_one({'tenant_id':{'$ne':tenant}},{'_id':0,'id':1})
print(json.dumps({'tokens':tokens,'assignment_id':assignment['id'],'case_id':case['id'],'foreign_assignment_id':foreign['id'] if foreign else None}))
''')
    path = '/api/core/assignments'
    expect(get(path + '?view=team')[0] == 401, 'anonymous team access denied')
    loaded = {}
    for name, view in [('worker', 'mine'), ('approver', 'team'), ('admin', 'workspace')]:
        status, body, headers = get(path + '?view=' + view, context['tokens'][name])
        expect(status == 200 and body['workspace']['id'] == Q and body['view'] == view, name + ' current workspace accepted')
        expect(headers['Cache-Control'] == 'no-store' and body['checked_at'], name + ' report is uncached and dated')
        expect(len(body['items']) == 1 and body['items'][0]['placement_number'] == 'PL-864'
               and body['items'][0]['person_number'] == 'P-358', name + ' sees exactly the reviewed placement and person')
        row = body['items'][0]
        expect(row['core_onboarding']['state'] == 'requirements_satisfied'
               and row['core_onboarding']['required_count'] == row['core_onboarding']['satisfied_count'] == 3,
               name + ' sees three Core-reviewed onboarding requirements')
        expect(row['client_number'] == 'CL-1280' and row['company_number'] == 'CO-1902', name + ' retains client and company references')
        loaded[name] = body
    approver = context['tokens']['approver']
    expect(loaded['approver']['can_view_team'] and not loaded['approver']['can_view_workspace'], 'approver has team scope only')
    expect('onboarding' not in loaded['approver']['items'][0] and 'submissions' not in json.dumps(loaded['approver']), 'team data omits admin controls and private submissions')
    detail = path + '/' + context['assignment_id']
    expect(get(detail + '?view=team', approver)[0] == 200, 'team detail opens reviewed assignment')
    status, time_rows, _ = get(detail + '/time-reviews?view=team', approver)
    expect(status == 200 and len(time_rows['items']) == 2
           and sorted(r['minutes'] for r in time_rows['items']) == [60, 480]
           and all(r['state'] == 'accepted' and r['current'] for r in time_rows['items']), 'team time drill-through retains both accepted pilot entries')
    status, raw, headers = get(path + '/export?view=team', approver)
    rows = list(csv.DictReader(io.StringIO(raw))) if status == 200 else []
    expect(status == 200 and len(rows) == 1 and rows[0]['placement_number'] == 'PL-864'
           and rows[0]['person_number'] == 'P-358' and rows[0]['required_tasks'] == rows[0]['satisfied_tasks'] == '3'
           and rows[0]['report_view'] == 'team' and rows[0]['report_generated_at'] == headers['X-Report-Generated-At'],
           'complete team CSV retains identity readiness and report timestamp')
    report['csv_sha256'] = hashlib.sha256(raw.encode()).hexdigest()
    for suffix in ['?view=workspace', '/export?view=workspace']:
        expect(get(path + suffix, approver)[0] == 403, 'approver denied workspace ' + suffix)
    expect(get(path + '?view=team', context['tokens']['worker'])[0] == 403, 'worker denied team view')
    expect(get('/api/core/onboarding?view=workspace', approver)[0] == 403, 'approver denied private workspace onboarding')
    expect(get('/api/core/onboarding/' + context['case_id'] + '/document-transfers', approver)[0] in (403, 404), 'approver denied worker document history')
    if context['foreign_assignment_id']:
        expect(get(path + '/' + context['foreign_assignment_id'] + '?view=workspace', context['tokens']['admin'])[0] == 404, 'foreign company assignment denied')
    expect(snapshot() == before, 'all provider records unchanged by portal reads')
    report.update(passed=True, checks=checks, core_person='P-358', core_placement='PL-864', required_tasks=3,
                  satisfied_tasks=3, source_time_minutes=[60, 480], report_rows=1)
except Exception as error:
    report.update(passed=False, checks=checks, failure_class=type(error).__name__, failed_check=str(error))
finally:
    (PRIVATE / 'quanta-team-acceptance-result.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report))
raise SystemExit(0 if report['passed'] else 1)
