"""Read-only comparison of the isolated provider with the pre-exchange baseline."""
import json, os, subprocess
from pathlib import Path
from datetime import datetime, timezone

private = Path('/srv/core-integration-pilot/20261029/private')
web = 'core-provider-pilot-20261029-pure-pilot-web-1'
assert os.geteuid() == 0 and private.resolve() == private and (private.stat().st_mode & 0o777) == 0o700
baseline = private / 'operational-exchange-provider-before-20261112.private.json'
assert baseline.is_file() and not baseline.is_symlink() and (baseline.stat().st_mode & 0o777) == 0o600
code = r'''
import sys, os, json
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
before=json_util.loads(sys.stdin.read())
current={n:list(d[n].find({})) for n in sorted(d.list_collection_names())}
canon=lambda row:json_util.dumps(row,sort_keys=True)
differences=[]; additions={}; preserved=0; normalized=[]; index_changes=[]; invoice_labels=[]
for n,rows in before['rows'].items():
 if canon(d[n].index_information())!=canon(before['indexes'][n]):index_changes.append(n)
 prior={canon(r['_id']):r for r in rows}; now={canon(r['_id']):r for r in current.get(n,[])}
 additions[n]=len(set(now)-set(prior))
 if n in ['sessions','login_events']:continue
 for key,r in prior.items():
  other=now.get(key)
  if other is None:differences.append({'collection':n,'issue':'original_row_missing'});continue
  r=dict(r);other=dict(other)
  if n=='users' and r.get('id')=='usr_2d19df3dfa23':
   if r.get('last_login')!=other.get('last_login'):normalized.append('pilot_reviewer_last_login')
   r.pop('last_login',None);other.pop('last_login',None)
  if n=='api_keys' and r.get('id')=='key_3afdf4dbff6c':
   if r.get('last_used_at')!=other.get('last_used_at'):normalized.append('dedicated_pilot_key_last_used_at')
   r.pop('last_used_at',None);other.pop('last_used_at',None)
  if n=='payments' and r.get('invoice_number')!=other.get('invoice_number'):
   pb=d.payables.find_one({'id':r.get('payable_id'),'organization_id':r.get('organization_id')})
   old_pb=next((v for v in before['rows']['payables'] if v.get('id')==r.get('payable_id') and v.get('organization_id')==r.get('organization_id')),None)
   invoice_labels.append({'payment_id':r.get('id'),'before_label':r.get('invoice_number'),'after_label':other.get('invoice_number'),
    'matches_preexisting_payable':bool(pb and old_pb and pb==old_pb and pb.get('invoice_number')==other.get('invoice_number'))})
  if r!=other:differences.append({'collection':n,'id':r.get('id'),'changed_fields':[k for k in set(r)|set(other) if r.get(k)!=other.get(k)]})
  else:preserved+=1
for n in set(current)-set(before['rows']):additions[n]=len(current[n])
org='org_0369a63ff2cb'
employee='emp_core_ddf206bef1e2b7a511b6b445ca9b49bbc65d56c9dc79e0617bf125c155c1606f'
assignment='asg_core_aff5699724d86834b6f810edaa9a763e3e35821fc42699223ac2492075258bab'
run='run_core_54cb6e8156e6bfd5386a133ed5ea4d41c015aab8f45d25aeb0813cb090d35938'
authority='cpa_4a0bfee5f022202d92f0b09a87d9423e859c337d65c2e514cc9a3fcf09fc27dc'
r=d.pay_runs.find_one({'id':run,'organization_id':org})
e=d.employees.find_one({'id':employee,'organization_id':org})
a=d.assignments.find_one({'id':assignment,'organization_id':org})
assert r and r['status']=='draft' and r['core_execution_hold'] and r['core_allocation_ready'] and r['totals']['gross']==100
assert d.pay_runs.count_documents({'organization_id':org,'core_allocation.authority_id':authority})==1
assert e and a
print(json.dumps({'passed':not differences and not index_changes,'original_business_rows_preserved':preserved,
 'original_collections':len(before['rows']),'current_collections':len(current),'differences':differences,'index_changes':index_changes,
 'normalized_auth_fields':normalized,'invoice_label_observations':invoice_labels,'added_rows':{n:c for n,c in additions.items() if c},
 'employee_id':employee,'employee_status':e.get('status'),'assignment_id':assignment,'assignment_status':a.get('status'),
 'run_id':run,'run_status':r['status'],'gross':str(r['totals']['gross']),'execution_hold':r['core_execution_hold'],
 'provider_egress':'disabled','real_financial_execution':False}))
'''
r = subprocess.run(['docker','exec','-i',web,'python','-c',code], input=baseline.read_bytes(), capture_output=True, timeout=30)
assert r.returncode == 0, r.stderr.decode()[:800]
report = json.loads(r.stdout)
report['checked_at'] = datetime.now(timezone.utc).isoformat()
print(json.dumps(report))
