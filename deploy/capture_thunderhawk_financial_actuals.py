"""Read existing AP/AR evidence; copy only its projection into the isolated pilot."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.request

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
PILOT = 'core-provider-pilot-20261029-pure-pilot-web-1'
ORG = 'org_0369a63ff2cb'
BILL = 'pay_041311ec10'
INVOICES = ['inv_05611deda7', 'inv_13c40a9026']
MODULE_HASH = 'df9be2a27d73365aa814c0eb73d4670754de1977523a01a084c9bbee2fee92a2'
if sys.argv[1:] != ['--capture-existing-financial-observations'] or ROOT.resolve() != ROOT or not ROOT.is_dir():
    raise SystemExit('Exact isolated host and explicit capture required')
if PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private evidence directory differs')
module = (ROOT / 'pure/backend/integration_financial_observations.py').read_bytes()
assert hashlib.sha256(module).hexdigest() == MODULE_HASH


def execute(container, code, payload):
    result = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend', container, 'python', '-c', code],
                            input=json.dumps(payload), text=True, capture_output=True)
    if result.returncode:
        # Error text remains private; never copy source records or credentials to logs.
        error = PRIVATE / 'financial-actual-capture-error.private.txt'
        fd = os.open(error, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write(result.stderr)
        raise RuntimeError('Financial source capture stopped; inspect the private diagnostic')
    return json.loads(result.stdout)


SOURCE = r'''
import asyncio,hashlib,json,os,sys
from bson import json_util
from motor.motor_asyncio import AsyncIOMotorClient
x=json.load(sys.stdin)
assert os.environ['DB_NAME']=='purepay'
assert os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
assert hashlib.sha256(x['module'].encode()).hexdigest()==x['module_hash']
contract={'__name__':'reviewed_financial_source_projection'}
exec(compile(x['module'],'reviewed_financial_source_projection.py','exec'),contract)
org='org_0369a63ff2cb';bill='pay_041311ec10';invoices=['inv_05611deda7','inv_13c40a9026']
async def main():
    client=AsyncIOMotorClient(os.environ['MONGO_URL']);db=client['purepay']
    async def records():
        result={}
        for name,query,projection in [
            ('payables',{'id':bill},contract['DOCUMENT_PROJECTION']),
            ('invoices',{'id':{'$in':invoices}},contract['DOCUMENT_PROJECTION']),
            ('payments',{'$or':[{'payable_id':bill},{'bill_id':bill}]},contract['PAYMENT_PROJECTION']),
            ('ar_collections',{'invoice_id':{'$in':invoices}},contract['PAYMENT_PROJECTION'])]:
            result[name]=await db[name].find({'organization_id':org,**query},projection).sort('_id',1).to_list(101)
            assert len(result[name])<=100
        return result
    before=await records()
    assert len(before['payables'])==1 and len(before['invoices'])==2
    assert set(before['payables'][0]['linked_invoice_ids'])==set(invoices)
    assert before['payables'][0]['assignment_ids']==['asg_520a00454f']
    assert {p['id'] for p in before['payments']}=={'pmt_87cf14ddb4','pmt_274cf7d401'}
    observations=[await contract['observe'](db,org,'bill',bill)]
    observations += [await contract['observe'](db,org,'invoice',identity) for identity in invoices]
    assert before==await records()
    print(json_util.dumps({'organization_id':org,'records':before,'observations':observations,'source_records_unchanged':True}))
    client.close()
asyncio.run(main())
'''
packet = execute('payroll-web-pure-web-1', SOURCE, {'module': module.decode(), 'module_hash': MODULE_HASH})
record_hash = hashlib.sha256(json.dumps(packet['records'], sort_keys=True).encode()).hexdigest()
source_path = PRIVATE / 'thunderhawk-financial-actual-source.private.json'
if source_path.is_symlink():
    raise SystemExit('Linked source packet')
if source_path.exists():
    prior = json.loads(source_path.read_text())
    assert hashlib.sha256(json.dumps(prior['records'], sort_keys=True).encode()).hexdigest() == record_hash
else:
    with os.fdopen(os.open(source_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as stream:
        json.dump(packet, stream)

COPY = r'''
import hashlib,json,os,socket,sys
from bson import json_util
from pymongo import MongoClient
x=json.load(sys.stdin);records=json_util.loads(json.dumps(x['records']))
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
org='org_0369a63ff2cb';db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
assert set(records)=={'payables','invoices','payments','ar_collections'}
allowed={name:[row['id'] for row in rows] for name,rows in records.items()}
assert allowed['payables']==['pay_041311ec10']
assert set(allowed['invoices'])=={'inv_05611deda7','inv_13c40a9026'}
assert set(allowed['payments'])=={'pmt_87cf14ddb4','pmt_274cf7d401'}
assert allowed['ar_collections']==[]
def snapshot():
    result={}
    for name in sorted(db.list_collection_names()):
        query={'$nor':[{'organization_id':org,'id':{'$in':allowed[name]}}]} if name in allowed else {}
        rows=list(db[name].find(query))
        if rows:
            values=sorted(json_util.dumps(row,sort_keys=True) for row in rows)
            result[name]=hashlib.sha256(json.dumps(values).encode()).hexdigest()
    return result
before=snapshot();planned=[]
for name,rows in records.items():
    for row in rows:
        assert row['organization_id']==org
        row_hash=hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest()
        value={**row,'pilot_source':{'purpose':'read_only_existing_financial_observation','source_sha256':row_hash}}
        existing=db[name].find_one({'organization_id':org,'id':row['id']})
        if existing is not None:assert existing==value
        else:
            assert db[name].find_one({'_id':row['_id']}) is None
            planned.append((name,value))
for name,value in planned:db[name].insert_one(value)
assert snapshot()==before
print(json.dumps({'copied_records':len(planned),'replay':not planned,'other_pilot_records_unchanged':True,
                  'record_counts':{name:len(rows) for name,rows in records.items()}}))
'''
copied = execute(PILOT, COPY, {'records': packet['records']})
reader = json.loads((PRIVATE / 'thunderhawk-financial-reader.private.json').read_text())
assert reader['scope'] == 'read' and reader['organization_id'] == ORG
results = []
for expected in packet['observations']:
    kind, identity = expected['kind'], expected['document_id']
    url = f'http://127.0.0.1:19180/api/v1/financial-observations/{"bills" if kind == "bill" else "invoices"}/{identity}'
    request = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + reader['api_key']})
    with urllib.request.urlopen(request, timeout=25) as response:
        actual = json.load(response)
        assert response.headers['cache-control'] == 'no-store, private'
    assert actual['observation_hash'] == expected['observation_hash']
    assert actual['organization_id'] == ORG and actual['document']['currency'] is None
    assert actual['cash_delivery_proven'] is False and actual['execution_authority'] is False
    results.append({'kind': kind, 'document_id': identity, 'observation_hash': actual['observation_hash'],
                    'currency_recorded': False, 'line_count': len(actual['document']['line_items']),
                    'outgoing_payment_count': len(actual['outgoing_payments']),
                    'recorded_receipt_count': len(actual['document']['recorded_receipts']),
                    'collection_count': len(actual['collections']),
                    'native_http_matches_source': True})
report = {'passed': True, 'organization_id': ORG, 'source_record_projection_sha256': record_hash,
          'source_records_unchanged': packet['source_records_unchanged'], 'copy': copied, 'observations': results,
          'production_changed': False, 'payments_or_payroll_executed': False, 'financial_postings': False,
          'cash_delivery_proven': False, 'currency_reconciliation': 'missing_source_currency',
          'core_financial_observation_saved': False}
print(json.dumps(report))
