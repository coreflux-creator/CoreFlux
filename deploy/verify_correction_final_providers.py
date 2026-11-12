import json,shlex,subprocess,hashlib
from collections import Counter
from pathlib import Path
out=Path(__file__).resolve().parent
ssh=['ssh','-T','-i','C:/Users/kunal/.ssh/purepay_quanta_migration_20261028','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','root@142.93.20.44']
code=r'''import hashlib,json,os
from datetime import datetime,timezone
from pymongo import MongoClient
from bson import json_util
name=os.environ['DB_NAME'];assert name in ['corepilot_quanta_20261029','corepilot_pure_20261029']
if name=='corepilot_quanta_20261029':assert os.environ['MAIL_DRIVER']=='devoutbox' and os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
else:assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[name];collections={};metadata={};evidence={}
actors=['8041205e-b179-4991-8dbe-65d51a474e22','ff6fdfe9-291c-4fac-91aa-51782ba5585f']
identities=[u.get('identity_id') for u in d.users.find({'id':{'$in':actors}})] if name=='corepilot_quanta_20261029' else []
for n in sorted(d.list_collection_names()):
 rows=[];mutable=[]
 for value in d[n].find({}):
  if n=='api_keys':value.pop('last_used_at',None)
  if n=='coreflux_commit_guards':value.pop('revision',None)
  h=hashlib.sha256(json_util.dumps(value,sort_keys=True).encode()).hexdigest();rows.append(h)
  if (n=='users' and value.get('id') in actors) or (n=='identities' and value.get('id') in identities) or n=='coreflux_commit_fences':mutable.append(h)
 collections[n]=sorted(rows)
 if mutable:metadata[n]=mutable
if name=='corepilot_quanta_20261029':
 t='4f93d4e7-af74-4765-b812-3464bb6d974b';source='3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2'
 e=d.time_entries.find_one({'tenant_id':t,'id':source});s=d.timesheets.find_one({'tenant_id':t,'id':e['timesheet_id']})
 review=d.coreflux_time_reviews.find_one({'tenant_id':t,'source_entry_id':source})
 assert e['duration_minutes']==90 and s['status']=='approved' and s['approved_by_user_id']==actors[1]
 assert review['version']==4 and review['state']=='accepted'
 assert [h['state'] for h in review['history']]==['pending_review','accepted','pending_review']
 assert review['publication']['components'][0]['entry_id']==16
 w=d.users.find_one({'tenant_id':t,'id':e['user_id']});assert not w.get('identity_id') and w['roles']==['worker']
 old=d.coreflux_assignments.find_one({'tenant_id':t,'placement.number':'PL-874'});assert old and old['status']=='ended'
 evidence={'source_entry_id':source,'minutes':90,'source_approved':True,'review_version':4,'review_state':'accepted','review_history_states':[h['state'] for h in review['history']],'worker_login_granted':False,'old_assignment_closed':True}
print(json.dumps({'checked_at':datetime.now(timezone.utc).isoformat(),'database':name,'collections':collections,'known_mutable_metadata':metadata,'evidence':evidence,'rows':sum(map(len,collections.values()))}))'''
summary={}
for name in ['quanta','pure']:
 p=subprocess.run(ssh+['docker exec -i -w /app/backend core-provider-pilot-20261029-'+name+'-pilot-web-1 python -c '+shlex.quote(code)],capture_output=True,text=True,timeout=45)
 if p.returncode:raise RuntimeError('Final read failed: '+p.stderr[-1500:])
 after=json.loads(p.stdout);before=json.loads((out/('synthetic-correction-'+name+'-before.json')).read_text(encoding='utf-8'))
 assert set(before['collections'])==set(after['collections'])
 changed={}
 for collection,hashes in before['collections'].items():
  current=after['collections'][collection]
  if hashes==current:continue
  assert name=='quanta','PurePay changed'
  missing=Counter(hashes)-Counter(current);new=Counter(current)-Counter(hashes)
  if missing:
   known=after['known_mutable_metadata'].get(collection,[])
   assert collection in ['users','identities','coreflux_commit_fences'],collection
   replaced=sum(v for h,v in Counter(known).items() if h not in hashes)
   assert sum(missing.values())==replaced and all(new[h]>=n for h,n in Counter(known).items() if h not in hashes),collection
  changed[collection]={'before':len(hashes),'after':len(current),'old_metadata_rows_replaced':sum(missing.values())}
 (out/('synthetic-correction-'+name+'-after.json')).write_text(json.dumps(after,indent=2)+'\n',encoding='utf-8')
 summary[name]={'checked_at':after['checked_at'],'passed':True,'collections_verified':len(after['collections']),'before_rows':before['total'],'after_rows':after['rows'],'changes':changed,'evidence':after['evidence'],'no_original_business_record_removed':True}
report={'passed':True,'providers':summary,'purepay_unchanged':True,'production_changed':False,'financial_execution':False}
(out/'synthetic-correction-final-providers.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8');print(json.dumps(report))
