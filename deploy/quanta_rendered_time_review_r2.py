"""One-shot evidence for the already authorized synthetic browser correction."""
import fcntl,hashlib,json,os,subprocess,sys
from collections import Counter
from datetime import datetime,timezone
from pathlib import Path
PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
STATE=PRIVATE/'rendered-time-review-20261112-r2.json'
BEFORE=PRIVATE/'rendered-time-review-before-20261112-r2.private.json'
WEB='core-provider-pilot-20261029-quanta-pilot-web-1'
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER='cfw_84c68922408ef4edb348e7d1a00c5254e9c019936e192ce5fd9f517199ab52d6'
ASSIGNMENT='cfa_3fa8dbe1a23e858c87bab64b9555d2c147e78919eae39e5ba5eaa9f1994b16f2'
ADMIN='8041205e-b179-4991-8dbe-65d51a474e22'
APPROVER='ff6fdfe9-291c-4fac-91aa-51782ba5585f'
ENTRY='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2'
SHEET='c1ebacce-1523-4f1f-b991-6c3b90bed1a5'

def save(path,data,new=False):
    assert path.parent.resolve()==PRIVATE and not path.is_symlink()
    with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|(os.O_EXCL if new else os.O_TRUNC),0o600),'w') as f:
        json.dump(data,f,indent=2);f.flush();os.fsync(f.fileno())

def snapshot():
    code=r'''import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_quanta_20261029' and os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
t=__TENANT__;w=__WORKER__;a=[__ADMIN__,__APPROVER__]
worker=d.users.find_one({'id':w,'tenant_id':t});assert worker['roles']==['worker'] and worker['status']=='active' and worker['approver_user_id']==a[1]
assert d.coreflux_person_links.find_one({'tenant_id':t,'worker_id':w,'person.number':'P-387'})
assert not (d.connecteam_configs.find_one({'tenant_id':t}) or {}).get('enabled')
actors=list(d.users.find({'tenant_id':t,'id':{'$in':a}}));assert len(actors)==2 and all(x['status']=='active' for x in actors)
iids=[x['identity_id'] for x in actors];out={}
for n in sorted(d.list_collection_names()):
 rows=[]
 for row in d[n].find({}):
  if n in ['time_entries','timesheets'] and row.get('tenant_id')==t and row.get('user_id')==w:continue
  if n=='users' and row.get('tenant_id')==t and row.get('id') in a:row.pop('last_login_at',None)
  if n=='identities' and row.get('id') in iids:
   for k in ['last_tenant_id','updated_at']:row.pop(k,None)
  if n=='coreflux_commit_fences':row.pop('revision',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 out[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
runtime={str(p.relative_to('/app/backend')):hashlib.sha256(p.read_bytes()).hexdigest() for p in Path('/app/backend/app').rglob('*.py')}
print(json_util.dumps({'collections':out,'runtime':runtime,'environment_sha256':hashlib.sha256(json.dumps(dict(os.environ),sort_keys=True).encode()).hexdigest(),
 'entries':list(d.time_entries.find({'tenant_id':t,'user_id':w})), 'sheets':list(d.timesheets.find({'tenant_id':t,'user_id':w})),
 'sessions':list(d.auth_sessions.find({'user_id':{'$in':a}}))}))
'''
    for k,v in [('TENANT',TENANT),('WORKER',WORKER),('ADMIN',ADMIN),('APPROVER',APPROVER)]:code=code.replace('__'+k+'__',repr(v))
    r=subprocess.run(['docker','exec','-i','-w','/app/backend',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=30)
    if r.returncode:raise RuntimeError('Isolated source inspection failed: '+r.stderr[-1000:])
    result=json.loads(r.stdout)
    pure="""import os,json,hashlib
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
print(json.dumps({n:{'rows':sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in d[n].find({})),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)} for n in sorted(d.list_collection_names())}))
"""
    r=subprocess.run(['docker','exec','-i','core-provider-pilot-20261029-pure-pilot-web-1','python','-'],input=pure,text=True,capture_output=True,timeout=30)
    assert r.returncode==0,'PurePay read-only comparison failed'
    result['pure']=json.loads(r.stdout);return result

def main():
    assert os.geteuid()==0 and PRIVATE.resolve()==PRIVATE and not PRIVATE.stat().st_mode&0o077
    assert sys.argv[1:] in [['prepare'],['inspect'],['finish-source'],['close-unchanged']]
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    action=sys.argv[1];current=snapshot()
    assert len(current['entries'])==len(current['sheets'])==1
    entry=current['entries'][0];sheet=current['sheets'][0]
    assert entry['id']==ENTRY and sheet['id']==SHEET and entry['core_assignment']['assignment_id']==ASSIGNMENT
    if action=='prepare':
        assert not STATE.exists() and not BEFORE.exists(),'Inspect the existing browser checkpoint'
        assert entry['duration_minutes']==90 and sheet['status']=='approved' and sheet['approved_by_user_id']==APPROVER
        save(BEFORE,current,True)
        report={'checked_at':datetime.now(timezone.utc).isoformat(),'phase':'prepared','passed':False,'source_entry_id':ENTRY,'timesheet_id':SHEET,'person_number':'P-387','placement_number':'PL-902','old_minutes':90,'target_minutes':120,'production_changed':False,'real_payroll_payments_postings':False}
        save(STATE,report,True);print(json.dumps(report));return
    state=json.loads(STATE.read_text());before=json.loads(BEFORE.read_text())
    assert before['runtime']==current['runtime'] and before['environment_sha256']==current['environment_sha256'] and before['pure']==current['pure']
    if action=='inspect':
        print(json.dumps({'phase':state['phase'],'source_minutes':entry['duration_minutes'],'source_status':sheet['status'],'approved_by':sheet.get('approved_by_user_id'),'submitted_by':sheet.get('submitted_by_user_id'),'history_count':len(sheet.get('history',[])),'runtime_and_purepay_unchanged':True}));return
    if action=='close-unchanged':
        assert state['phase']=='prepared'
        assert before['entries']==current['entries'] and before['sheets']==current['sheets']
        assert set(before['collections'])==set(current['collections'])
        changes={}
        for name,old_rows in before['collections'].items():
            fresh=current['collections'][name]
            assert old_rows['indexes']==fresh['indexes'],name
            if old_rows['rows']==fresh['rows']:continue
            assert name in ['auth_sessions','audit_events'],name
            assert not Counter(old_rows['rows'])-Counter(fresh['rows']),name
            changes[name]=len(fresh['rows'])-len(old_rows['rows'])
        old_ids={s['id'] for s in before['sessions']}
        sessions=[s for s in current['sessions'] if s['id'] not in old_ids]
        assert len(sessions)==1 and sessions[0]['user_id']==ADMIN and sessions[0].get('revoked_at')
        state.update(checked_at=datetime.now(timezone.utc).isoformat(),phase='closed_unchanged',passed=False,
                     source_business_records_unchanged=True,purepay_unchanged=True,native_sessions_revoked=1,
                     changes=changes,remaining='Admin edit and worker-week submission UI is missing; implement before a new browser walkthrough.')
        save(STATE,state);print(json.dumps(state));return
    assert state['phase']=='prepared','Source finish is one-shot'
    old=before['entries'][0];old_sheet=before['sheets'][0]
    assert entry['duration_minutes']==entry['raw_minutes']==120 and entry['edited_by_user_id']==ADMIN
    assert entry['core_assignment']==old['core_assignment']
    changed={k for k in set(entry)|set(old) if entry.get(k)!=old.get(k)}
    assert changed<=set(['duration_minutes','raw_minutes','edited_by_user_id','updated_at','rates_frozen_at','pay_amount_cents','bill_amount_cents','rate_breakdown','resolved_bill_rate_card_id','resolved_pay_rate_card_id','rate_freeze_history']),changed
    old_freezes=old.get('rate_freeze_history') or []
    freezes=entry.get('rate_freeze_history') or []
    assert freezes[:len(old_freezes)]==old_freezes
    if len(freezes)>len(old_freezes):
        assert len(freezes)==len(old_freezes)+1
        assert freezes[-1]['changed_fields']==['duration_minutes','raw_minutes']
        assert freezes[-1]['prior']=={k:old.get(k) for k in ['resolved_bill_rate_card_id','resolved_pay_rate_card_id','bill_amount_cents','pay_amount_cents','rate_breakdown','rates_frozen_at']}
    assert sheet['status']=='approved' and sheet['total_minutes']==120 and sheet['approved_by_user_id']==APPROVER and sheet['submitted_by_user_id']==ADMIN
    assert sheet['approved_at']!=old_sheet['approved_at'] and sheet['history'][:len(old_sheet['history'])]==old_sheet['history']
    unlocks=[e for e in sheet['history'][len(old_sheet['history']):] if e['event']=='admin_unlocked'];assert len(unlocks)==1
    assert unlocks[0]['metadata']['prior_approval']['approved_at']==old_sheet['approved_at'] and unlocks[0]['metadata']['prior_approval']['approved_by_user_id']==APPROVER
    changes={};assert set(before['collections'])==set(current['collections'])
    for name,old_rows in before['collections'].items():
        fresh=current['collections'][name];assert old_rows['indexes']==fresh['indexes'],name
        if fresh['rows']==old_rows['rows']:continue
        assert name in ['auth_sessions','audit_events','webhook_deliveries','magic_link_tokens'],name
        assert not Counter(old_rows['rows'])-Counter(fresh['rows']),name
        changes[name]=len(fresh['rows'])-len(old_rows['rows'])
    old_ids={s['id'] for s in before['sessions']};sessions=[s for s in current['sessions'] if s['id'] not in old_ids]
    assert len(sessions)==2 and all(s.get('revoked_at') for s in sessions),'Sign out both browser operators first'
    state.update(checked_at=datetime.now(timezone.utc).isoformat(),phase='source_complete',passed=True,source_identity_preserved=True,original_approval_preserved=True,independent_test_approver=True,other_original_records_preserved=True,purepay_unchanged=True,native_sessions_revoked=2,changes=changes)
    save(STATE,state);print(json.dumps(state))

if __name__=='__main__':main()
