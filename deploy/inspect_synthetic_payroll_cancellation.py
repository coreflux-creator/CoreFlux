"""Read-only provider before/after proof for the one existing synthetic draft.

This script sends no provider API requests and changes no remote files. Its
before capture must be retained; after verification never refreshes that baseline.
"""
import copy
import hashlib
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ORG = 'org_0369a63ff2cb'
AUTHORITY = 'cpa_92285a297913dd5feab0e0a3ed148ba14866619c2a01e790e0394a5c57931954'
RUN = 'run_core_6ee5f0d01ee1a7ffa5316b44e2ef3f2927e1b01e43971ced17bb2a5024df7a06'
INPUT = 'cpi_255117c910a9437a565ed773d63cc32857b901b132926b88790b458040d09bd9'
WEB = 'core-provider-pilot-20261029-pure-pilot-web-1'
CONTAINER = '643c9c0d99dc30cc6cd06a5baf627902571df683b650ef2b32220f1c274cc9e2'
RUN_FIELDS = ['status','core_cancelled','core_cancel_fence','core_allocation_ready','voided_at','voided_by']
AUTHORITY_FIELDS = ['state','cancellation_started_at','cancelled_by','cancel_fence','released_at']

REMOTE = r'''
import hashlib,json,os
from pathlib import Path
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot'
assert os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
assert os.environ.get('COREFLUX_PAYROLL_DRAFTS')=='1'
assert not os.environ.get('COREFLUX_PAYROLL_DRAFT_PILOT')
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
org=ORG; authority=AUTHORITY; run_id=RUN
company=db.organizations.find_one({'id':org})
assert company['payroll']['processing']['calculation']=='MANUAL'
assert company['payroll']['processing']['payments']=='RECORD_ONLY'
grants=list(db.coreflux_payroll_authorities.find({'organization_id':org,'id':authority}))
runs=list(db.pay_runs.find({'organization_id':org,'id':run_id}))
assert len(grants)==len(runs)==1
grant=grants[0]; run=runs[0]
assert grant['authority']['input_id']==INPUT
assert run['core_allocation']['authority_id']==authority and run['core_allocation']['input_id']==INPUT
assert run['core_execution_hold'] is True
assert len(run['lines'])==1 and float(run['totals']['gross'])==50.0
assert run['lines'][0]['employee_id']=='emp_core_6db3732563a74d57f1e5cc00e6b257008e47eee22fd0b83502b04e24eab51717'
key_id=grant['created_by_key_id']
keys=list(db.api_keys.find({'organization_id':org,'id':key_id}))
assert len(keys)==1 and not keys[0].get('revoked')
claims=list(db.payroll_time_claims.find({'organization_id':org,'run_id':run_id}))
collections={}
for name in sorted(db.list_collection_names()):
 rows=[]
 for row in db[name].find({}):
  if row.get('organization_id')==org:
   if name=='pay_runs' and row.get('id')==run_id:
    for field in RUN_FIELDS:row.pop(field,None)
   if name=='coreflux_payroll_authorities' and row.get('id')==authority:
    for field in AUTHORITY_FIELDS:row.pop(field,None)
   if name=='payroll_time_claims' and row.get('run_id')==run_id:continue
   if name=='api_keys' and row.get('id')==key_id:row.pop('last_used_at',None)
  rows.append(hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest())
 collections[name]={'rows':sorted(rows),'indexes':json_util.dumps(db[name].index_information(),sort_keys=True)}
runtime={p:hashlib.sha256((Path('/app/backend')/p).read_bytes().replace(b'\r\n',b'\n')).hexdigest()
 for p in ['server.py','coreflux_payroll_drafts.py','coreflux_payroll_authority.py','coreflux_payroll_lifecycle.py',
 'coreflux_payroll_results.py','coreflux_payroll_earnings.py','coreflux_pilot_scope.py','payroll_time_claims.py']}
print(json_util.dumps({'collections':collections,'runtime':runtime,'run':run,'authority':grant,'claims':claims,'key_id':key_id}))
'''


def capture():
    ssh = ['ssh','-T','-i','C:/Users/kunal/.ssh/purepay_quanta_migration_20261028',
           '-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','root@142.93.20.44']
    inspected = subprocess.run(ssh + ['docker inspect ' + WEB], capture_output=True, text=True, timeout=30)
    if inspected.returncode:
        raise RuntimeError('Provider container inspection failed')
    info = json.loads(inspected.stdout)[0]
    assert info['Id'] == CONTAINER and info['State']['Running']
    config = {k:info[k] for k in ['Config','HostConfig','Mounts','NetworkSettings']}
    config['Mounts'] = sorted(config['Mounts'],key=lambda m:m['Destination'])
    constants = '\n'.join(k+'='+repr(v) for k,v in {
        'ORG':ORG,'AUTHORITY':AUTHORITY,'RUN':RUN,'INPUT':INPUT,
        'RUN_FIELDS':RUN_FIELDS,'AUTHORITY_FIELDS':AUTHORITY_FIELDS}.items())
    result = subprocess.run(ssh + ['docker exec -i '+WEB+' python -'], input=constants+'\n'+REMOTE,
                            capture_output=True, text=True, timeout=45)
    if result.returncode:
        raise RuntimeError('Read-only provider scope validation failed')
    data = json.loads(result.stdout)
    data['container'] = {'id':info['Id'],'image':info['Image'],'started_at':info['State']['StartedAt'],
                         'config_hash':hashlib.sha256(json.dumps(config,sort_keys=True).encode()).hexdigest()}
    data['checked_at'] = datetime.now(timezone.utc).isoformat()
    return data


def verify(before, after):
    assert before['container'] == after['container'], 'Provider container changed'
    assert before['runtime'] == after['runtime'], 'Provider runtime changed'
    assert before['key_id'] == after['key_id'], 'Original integration key changed'
    assert before['collections'] == after['collections'], 'Unexpected provider record or index changes'
    assert before['authority']['state'] == 'allocated' and before['run']['status'] == 'draft'
    assert len(before['claims']) == 2 and after['claims'] == []
    grant, run = after['authority'], after['run']
    assert grant['state'] == 'released' and run['status'] == 'void'
    assert run['core_cancelled'] is True and run['core_allocation_ready'] is False
    assert run['core_execution_hold'] is True and grant['released_at']
    assert run['voided_at'] == grant['cancellation_started_at'] and run['voided_by'] == grant['cancelled_by']
    assert grant['cancelled_by'] == 'Core key ' + before['key_id']
    expected = 'cpf_' + hashlib.sha256(json.dumps(
        [ORG,AUTHORITY,RUN,run['core_allocation']['request_hash'],'cancelled'],
        sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
    assert run['core_cancel_fence'] == grant['cancel_fence'] == expected
    for key, fields in [('run',RUN_FIELDS),('authority',AUTHORITY_FIELDS)]:
        left, right = copy.deepcopy(before[key]), copy.deepcopy(after[key])
        for field in fields:
            left.pop(field,None); right.pop(field,None)
        assert left == right, 'Original '+key+' contents changed'
    return {'checked_at':after['checked_at'],'passed':True,'authority_id':AUTHORITY,'run_id':RUN,
            'collections_verified':len(after['collections']),'original_claims_released':2,
            'permanent_fence':expected,'original_source_and_financial_values_preserved':True,
            'container_runtime_and_other_records_preserved':True,'real_payroll_payments_postings':False}


def main():
    assert len(sys.argv)==2 and sys.argv[1] in ('before','check','after')
    output=Path(__file__).resolve().parents[1]/'output'
    baseline=output/'synthetic-payroll-cancel-provider-before.private.json'
    current=capture()
    if sys.argv[1]=='before':
        assert current['authority']['state']=='allocated' and current['run']['status']=='draft'
        assert len(current['claims'])==2
        with baseline.open('x',encoding='utf-8') as f: json.dump(current,f,indent=2)
        print(json.dumps({'checked_at':current['checked_at'],'status':'preflight_verified',
                          'run_state':'draft','authority_state':'allocated','claims':2,
                          'collections':len(current['collections']),'provider_changed':False}))
    elif sys.argv[1]=='check':
        before=json.loads(baseline.read_text(encoding='utf-8'))
        assert {k:v for k,v in current.items() if k!='checked_at'} == {k:v for k,v in before.items() if k!='checked_at'}, 'Provider state changed since the retained baseline'
        proof={'checked_at':current['checked_at'],'passed':True,'baseline_sha256':hashlib.sha256(baseline.read_bytes()).hexdigest(),
               'run_state':'draft','source_claims':2,'provider_changed':False}
        (output/'synthetic-payroll-cancel-provider-preflight.json').write_text(json.dumps(proof,indent=2)+'\n',encoding='utf-8')
        print(json.dumps(proof))
    else:
        before=json.loads(baseline.read_text(encoding='utf-8'))
        # Retain the actual observation even if preservation validation fails.
        (output/'synthetic-payroll-cancel-provider-after.private.json').write_text(json.dumps(current,indent=2),encoding='utf-8')
        proof=verify(before,current)
        (output/'synthetic-payroll-cancel-provider-verification.json').write_text(json.dumps(proof,indent=2)+'\n',encoding='utf-8')
        print(json.dumps(proof))


if __name__=='__main__':main()
