"""Guard and record a rendered, synthetic-only Quanta portal walkthrough.

Run on the isolated provider host. Preparation is one-shot; inspect is read-only.
Finish retires only the new fixture login, leaving all time and provider IDs intact.
No application code or production configuration is changed.
"""
import fcntl
import hashlib
import json
import os
import subprocess
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
STATE = PRIVATE / 'correction-browser-acceptance-20261112.json'
BEFORE = PRIVATE / 'correction-browser-before-20261112.private.json'
WEB = 'core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER = 'cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6'
ADMIN = '8041205e-b179-4991-8dbe-65d51a474e22'
ASSIGNMENT = 'cfa_3fa8dbe1a23e858c87bab64b9555d2c147e78919eae39e5ba5eaa9f1994b16f2'


def docker(code):
    p = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend', WEB, 'python', '-'],
                       input=code, text=True, capture_output=True, timeout=30)
    if p.returncode:
        raise RuntimeError('Isolated guard failed: ' + p.stderr[-1400:])
    return json.loads(p.stdout)


def save(path, value, exclusive=False):
    flags = os.O_WRONLY | os.O_CREAT | (os.O_EXCL if exclusive else os.O_TRUNC)
    with os.fdopen(os.open(path, flags, 0o600), 'w') as f:
        json.dump(value, f, indent=2)


def snapshot():
    return docker(r'''
import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
t='4f93d4e7-af74-4765-b812-3464bb6d974b'
w='cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6'
a='8041205e-b179-4991-8dbe-65d51a474e22'
worker=d.users.find_one({'id':w,'tenant_id':t}); admin=d.users.find_one({'id':a,'tenant_id':t})
assert worker['roles']==['worker'] and worker['status']=='active'
assert worker['email']=='thunderhawk-correction-20261112@example.com'
assert admin['roles']==['tenant_admin'] and admin['status']=='active'
assert d.coreflux_person_links.find_one({'tenant_id':t,'worker_id':w,'person.number':'P-387'})
assert not (d.connecteam_configs.find_one({'tenant_id':t}) or {}).get('enabled')
collections={}
for n in sorted(d.list_collection_names()):
 rows=[]
 for row in d[n].find({}):
  if n=='users' and row.get('tenant_id')==t:
   if row.get('id')==a: row.pop('last_login_at',None)
   if row.get('id')==w:
    for f in ['identity_id','password_hash','updated_at','last_login_at']:row.pop(f,None)
  if n=='identities' and row.get('id')==admin['identity_id']:
   for f in ['updated_at','last_tenant_id']:row.pop(f,None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 collections[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
outbox=Path('/app/backend/dev_outbox.jsonl');b=outbox.read_bytes() if outbox.exists() else b''
runtime={str(p.relative_to('/app/backend')):hashlib.sha256(p.read_bytes()).hexdigest()
 for p in Path('/app/backend/app').rglob('*.py')}
fences=list(d.coreflux_commit_fences.find({}));fence_hashes=[];fence_revisions=[]
for row in fences:
 fence_revisions.append(row['revision'])
 assert 194 <= row['revision'] <= 394
 row['revision']=194
 fence_hashes.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
out={'collections':collections,'worker':worker,'identity':d.identities.find_one({'id':worker.get('identity_id')}),
 'original_fence_hashes':sorted(fence_hashes),'current_fence_revisions':fence_revisions,
 'identity_ids':[x['id'] for x in d.identities.find({}, {'id':1})],
 'sessions':list(d.auth_sessions.find({'user_id':{'$in':[a,w]}})),
 'entries':list(d.time_entries.find({'tenant_id':t,'user_id':w})),
 'sheets':list(d.timesheets.find({'tenant_id':t,'user_id':w})),
 'runtime':runtime,'outbox_lines':len(b.splitlines()),'outbox_sha256':hashlib.sha256(b).hexdigest(),
 'outbox_bytes':len(b),'environment_sha256':hashlib.sha256(json.dumps(dict(os.environ),sort_keys=True).encode()).hexdigest()}
print(json_util.dumps(out))
''')


def pure_snapshot():
    code = """import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];out={}
for n in sorted(d.list_collection_names()):
 rows=sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in d[n].find({}))
 out[n]={'rows':rows,'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
print(json.dumps(out))
"""
    p = subprocess.run(['docker','exec','-i','core-provider-pilot-20261029-pure-pilot-web-1','python','-'],
                       input=code,text=True,capture_output=True,timeout=30)
    assert p.returncode == 0, 'PurePay read-only snapshot failed'
    return json.loads(p.stdout)


def verify(before, current):
    assert before['runtime'] == current['runtime'], 'Quanta code changed'
    assert before['environment_sha256'] == current['environment_sha256'], 'Quanta holds/config changed'
    assert before['entries'] == current['entries'] and before['sheets'] == current['sheets'], 'Approved time changed'
    assert len(current['entries']) == 1 and current['entries'][0]['duration_minutes'] == 90
    assert current['entries'][0]['core_assignment']['assignment_id'] == ASSIGNMENT
    assert len(current['sheets']) == 1 and current['sheets'][0]['status'] == 'approved'
    changes = {}
    assert set(before['collections']) == set(current['collections']), 'Collection set changed'
    for name, old in before['collections'].items():
        new = current['collections'][name]
        assert old['indexes'] == new['indexes'], name
        if old['rows'] == new['rows']: continue
        if name == 'coreflux_commit_fences':
            # Read-only recovery proved that the original complete row hashes
            # match exactly at revision 194. Native portal reads/invitation
            # advance this coordination counter; no other field may change.
            assert old['rows'] == current['original_fence_hashes'], name
            continue
        assert name in ['identities','auth_sessions','audit_events'], name
        assert not Counter(old['rows']) - Counter(new['rows']), name
        changes[name] = len(new['rows']) - len(old['rows'])
    return changes


def main():
    assert os.geteuid() == 0 and sys.argv[1:] in [['prepare'],['inspect'],['finish']]
    assert PRIVATE.resolve() == PRIVATE and not PRIVATE.stat().st_mode & 0o077
    os.umask(0o077)
    lock = (PRIVATE/'assignment-lifecycle-install.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    action=sys.argv[1]
    if action == 'prepare':
        assert not STATE.exists() and not BEFORE.exists(), 'Existing fixture must be inspected, not restarted'
        before=snapshot(); assert not before['worker'].get('identity_id')
        before['pure']=pure_snapshot(); save(BEFORE,before,True)
        state={'checked_at':datetime.now(timezone.utc).isoformat(),'phase':'prepared','worker':WORKER,
               'person_number':'P-387','placement_number':'PL-902','production_changed':False,
               'email_mode':'devoutbox','real_payroll_payments_postings':False,
               'quanta_collections':len(before['collections']),'pure_collections':len(before['pure'])}
        save(STATE,state,True);print(json.dumps(state));return
    state=json.loads(STATE.read_text());before=json.loads(BEFORE.read_text());current=snapshot()
    changes=verify(before,current)
    assert before['pure']==pure_snapshot(), 'PurePay changed'
    new_ids=set(current['identity_ids'])-set(before['identity_ids'])
    if action=='inspect':
        print(json.dumps({'checked_at':datetime.now(timezone.utc).isoformat(),'phase':state['phase'],
            'business_records_preserved':True,'purepay_unchanged':True,'added':changes,
            'new_worker_identity':len(new_ids)==1 and current['worker'].get('identity_id') in new_ids,
            'worker_password_set':bool((current['identity'] or {}).get('password_hash')),
            'outbox_added_lines':current['outbox_lines']-before['outbox_lines']}));return
    assert state['phase']=='prepared' and len(new_ids)==1, 'Finish is one-shot and requires the new worker login'
    iid=current['worker'].get('identity_id');assert iid in new_ids
    assert current['identity']['email']=='thunderhawk-correction-20261112@example.com'
    assert current['outbox_lines']==before['outbox_lines']+1, 'Expected one captured invitation'
    assert changes.get('identities')==1
    original_sessions={x['id'] for x in before['sessions']}
    fresh_sessions=[s for s in current['sessions'] if s['id'] not in original_sessions]
    assert len(fresh_sessions)==2 and {s['user_id'] for s in fresh_sessions}=={ADMIN,WORKER}
    assert all(s.get('revoked_at') for s in fresh_sessions), 'Sign out both browser sessions before finish'
    state['phase']='retiring-created-worker-login';save(STATE,state)
    retired=docker('''import json,os
from datetime import datetime,timezone
from pymongo import MongoClient
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
assert os.environ['DB_NAME']=='corepilot_quanta_20261029' and os.environ['MAIL_DRIVER']=='devoutbox'
iid=IDENTITY;w=WORKER;t=TENANT
assert d.identities.count_documents({'id':iid,'email':'thunderhawk-correction-20261112@example.com'})==1
assert d.users.count_documents({'identity_id':iid})==1
assert d.users.count_documents({'id':w,'tenant_id':t,'identity_id':iid,'roles':['worker']})==1
now=datetime.now(timezone.utc).isoformat()
r=d.identities.update_one({'id':iid,'disabled_at':None},{'$set':{'disabled_at':now,'password_hash':None,'updated_at':now}})
assert r.matched_count==1
r=d.users.update_one({'id':w,'tenant_id':t,'identity_id':iid},{'$set':{'password_hash':None}})
assert r.matched_count==1
print(json.dumps({'created_identity_disabled':True,'password_removed':True}))
'''.replace('IDENTITY',repr(iid)).replace('WORKER',repr(WORKER)).replace('TENANT',repr(TENANT)))
    final=snapshot();final_changes=verify(before,final);assert before['pure']==pure_snapshot()
    assert final['identity']['disabled_at'] and not final['identity'].get('password_hash')
    assert not final['worker'].get('password_hash')
    state.update(checked_at=datetime.now(timezone.utc).isoformat(),phase='complete',passed=True,
        business_records_preserved=True,provider_ids_preserved=True,approved_time_unchanged=True,
        purepay_unchanged=True,added=final_changes,worker_login_retired=retired,
        native_browser_sessions_revoked=2,outbound_email_sent=False,invitation_captured_locally=True)
    save(STATE,state);print(json.dumps(state))


if __name__ == '__main__':
    main()
