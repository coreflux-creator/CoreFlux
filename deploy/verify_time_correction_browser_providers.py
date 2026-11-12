"""One-shot provider preservation checkpoint for the Core browser return journey."""
import fcntl, hashlib, json, os, subprocess, sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
BEFORE = PRIVATE / 'core-time-browser-providers-20261112.private.json'
RESULT = PRIVATE / 'core-time-browser-providers-20261112.json'
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
SOURCE = '3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2'

def save(path, value):
    assert path.parent.resolve() == PRIVATE and not path.is_symlink()
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as f:
        json.dump(value, f, indent=2); f.flush(); os.fsync(f.fileno())

def snapshot(name):
    code = r'''import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
name=__NAME__;tenant=__TENANT__;source=__SOURCE__
assert os.environ['DB_NAME']=='corepilot_'+('quanta' if name=='quanta' else 'pure')+'_20261029'
if name=='quanta': assert os.environ['MAIL_DRIVER']=='devoutbox' and os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
else: assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];out={}
for n in sorted(d.list_collection_names()):
 rows=[]
 for r in d[n].find({}):
  if name=='quanta':
   if n=='coreflux_time_reviews' and r.get('tenant_id')==tenant and r.get('source_entry_id')==source: continue
   if n=='api_keys' and r.get('tenant_id')==tenant: r.pop('last_used_at',None)
   if n in ['coreflux_commit_guards','coreflux_commit_fences']: r.pop('revision',None)
  rows.append(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest())
 out[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
review=d.coreflux_time_reviews.find_one({'tenant_id':tenant,'source_entry_id':source}) if name=='quanta' else None
e=d.time_entries.find_one({'tenant_id':tenant,'id':source}) if name=='quanta' else None
s=d.timesheets.find_one({'tenant_id':tenant,'id':e['timesheet_id']}) if e else None
if e: assert e['duration_minutes']==120 and s['status']=='approved' and s['approved_by_user_id']=='ff6fdfe9-291c-4fac-91aa-51782ba5585f'
result={'collections':out,'review':review,'entry':e,'sheet':s,'environment_sha256':hashlib.sha256(json.dumps(dict(os.environ),sort_keys=True).encode()).hexdigest()}
if name=='quanta': result['delivery_records']=[{'sha256':hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest(),'record':r} for r in d.coreflux_deliveries.find({})]
print(json_util.dumps(result))'''
    for k, v in [('NAME', name), ('TENANT', TENANT), ('SOURCE', SOURCE)]: code = code.replace('__'+k+'__', repr(v))
    r = subprocess.run(['docker', 'exec', '-i', 'core-provider-pilot-20261029-'+name+'-pilot-web-1', 'python', '-'], input=code, text=True, capture_output=True, timeout=30)
    assert r.returncode == 0, r.stderr[-1200:]
    return json.loads(r.stdout)

def main():
    assert os.geteuid()==0 and PRIVATE.resolve()==PRIVATE and not PRIVATE.stat().st_mode&0o077
    assert sys.argv[1:] in [['prepare'], ['finish']]
    lock=(PRIVATE/'assignment-lifecycle-install.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    current={name:snapshot(name) for name in ['quanta','pure']}
    if sys.argv[1]=='prepare':
        assert not BEFORE.exists() and not RESULT.exists()
        assert current['quanta']['review']['version']==4 and current['quanta']['review']['state']=='accepted'
        save(BEFORE,current);print(json.dumps({'prepared':True,'source_minutes':120,'current_return_version':4}));return
    before=json.loads(BEFORE.read_text());assert current['pure']==before['pure'],'PurePay changed'
    b=before['quanta'];a=current['quanta'];assert a['entry']==b['entry'] and a['sheet']==b['sheet'] and a['environment_sha256']==b['environment_sha256']
    assert set(a['collections'])==set(b['collections']);changes={}
    for n, prior in b['collections'].items():
        fresh=a['collections'][n];assert prior['indexes']==fresh['indexes'],n
        if prior['rows']==fresh['rows']:continue
        assert n in ['audit_events','coreflux_deliveries','api_usage_events'],n
        assert not Counter(prior['rows'])-Counter(fresh['rows']),n
        changes[n]=len(fresh['rows'])-len(prior['rows'])
    old=b['review'];new=a['review'];assert new['version']==6 and new['state']=='accepted'
    assert new['history'][:len(old['history'])]==old['history'] and len(new['history'])==len(old['history'])+2
    history_fields=('id','tenant_id','assignment_id','source_entry_id','version','publication','state','updated_at')
    assert new['history'][-2]=={k:old[k] for k in history_fields} and new['history'][-1]['state']=='pending_review'
    assert new['id']==old['id'] and new['assignment_id']==old['assignment_id'] and new['source_entry_id']==SOURCE
    original_hashes=set(b['collections']['coreflux_deliveries']['rows'])
    deliveries=[r['record'] for r in a['delivery_records'] if r['sha256'] not in original_hashes]
    assert len(deliveries)==2 and changes['coreflux_deliveries']==2
    assert all(r['tenant_id']==TENANT and r['request_path']=='/api/v1/integrations/coreflux/time-reviews' and r['state']=='complete' and r['status_code']==200 for r in deliveries)
    assert new['last_delivery_id'] in {r['id'] for r in deliveries}
    assert sorted((r['response']['version'],r['response']['state']) for r in deliveries)==[(5,'pending_review'),(6,'accepted')]
    assert all(r['response']['source_entry_id']==SOURCE and r['response']['assignment_id']==new['assignment_id'] for r in deliveries)
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':True,'source_unchanged':True,'source_minutes':120,'review_version':6,
        'review_state':'accepted','core_components':new['publication']['components'],'prior_return_history_preserved':True,
        'completed_return_receipts':[r['id'] for r in deliveries],
        'quanta_changes':changes,'quanta_collections_verified':len(a['collections']),'purepay_unchanged':True,
        'purepay_collections_verified':len(current['pure']['collections']),'production_changed':False,'financial_execution':False}
    save(RESULT,report);print(json.dumps(report))

if __name__=='__main__':main()
