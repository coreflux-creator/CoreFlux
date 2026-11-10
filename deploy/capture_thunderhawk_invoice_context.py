"""Copy five existing source invoices to the held pilot; no staffing identity is inferred."""
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
MODULE_HASH = '22c10b7f0a68b62f81fa2528c9a080a187224f41b38b936e797d6325d99b1009'
ORG = 'org_0369a63ff2cb'
ITEM = 'itm_eb535696e3'
INVOICES = ['inv_82de265509', 'inv_ea597af5df', 'inv_dcb25061c4', 'inv_191eb3675c', 'inv_0f1382bcb5']
if sys.argv[1:] != ['--capture-existing-invoice-context'] or ROOT.resolve() != ROOT:
    raise SystemExit('Explicit isolated capture required')
if PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private evidence directory differs')
module = (ROOT / 'pure/backend/integration_financial_observations.py').read_bytes()
assert hashlib.sha256(module).hexdigest() == MODULE_HASH


def execute(container, code, payload):
    result = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend', container, 'python', '-c', code],
                            input=json.dumps(payload), text=True, capture_output=True)
    if result.returncode:
        path = PRIVATE / 'invoice-context-error.private.txt'
        if path.is_symlink():
            raise RuntimeError('Private diagnostic path differs')
        with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as stream:
            stream.write(result.stderr)
        raise RuntimeError('Invoice capture stopped; inspect the private diagnostic')
    return json.loads(result.stdout)


SOURCE = r'''
import asyncio,hashlib,json,os,sys
from bson import json_util
from motor.motor_asyncio import AsyncIOMotorClient
x=json.load(sys.stdin)
assert os.environ['DB_NAME']=='purepay' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
assert hashlib.sha256(x['module'].encode()).hexdigest()==x['module_hash']
contract={'__name__':'reviewed_invoice_context'}
exec(compile(x['module'],'reviewed_invoice_context.py','exec'),contract)
async def main():
    client=AsyncIOMotorClient(os.environ['MONGO_URL']);db=client['purepay']
    async def records():
        result={}
        for name,query,projection in [
            ('invoices',{'id':{'$in':x['invoices']}},contract['DOCUMENT_PROJECTION']),
            ('ar_collections',{'invoice_id':{'$in':x['invoices']}},contract['PAYMENT_PROJECTION'])]:
            result[name]=await db[name].find({'organization_id':x['org'],**query},projection).sort('_id',1).to_list(101)
            assert len(result[name])<=100
        return result
    before=await records()
    assert {r['id'] for r in before['invoices']}==set(x['invoices'])
    for invoice in before['invoices']:
        assert invoice['customer_id']=='cus_e25f880f86'
        lines=[line for line in invoice['line_items'] if line.get('item_id')==x['item']]
        assert len(lines)==1 and lines[0].get('line_id') and lines[0].get('item_name') and lines[0].get('description')
        assert not lines[0].get('assignment_id') and not lines[0].get('employee_id')
    observations=[await contract['observe'](db,x['org'],'invoice',identity) for identity in x['invoices']]
    assert before==await records()
    print(json_util.dumps({'records':before,'observations':observations,'source_records_unchanged':True}))
    client.close()
asyncio.run(main())
'''
packet = execute('payroll-web-pure-web-1', SOURCE, {'module': module.decode(), 'module_hash': MODULE_HASH,
    'org': ORG, 'invoices': INVOICES, 'item': ITEM})
record_hash = hashlib.sha256(json.dumps(packet['records'], sort_keys=True).encode()).hexdigest()
source_path = PRIVATE / 'thunderhawk-invoice-context-source.private.json'
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
x=json.load(sys.stdin);records=json_util.loads(json.dumps(x['records']));org=x['org']
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
assert set(records)=={'invoices','ar_collections'}
allowed={name:[r['id'] for r in rows] for name,rows in records.items()}
assert set(allowed['invoices'])==set(x['invoices'])
def snapshot():
    result={}
    for name in sorted(db.list_collection_names()):
        query={'$nor':[{'organization_id':org,'id':{'$in':allowed[name]}}]} if name in allowed else {}
        rows=list(db[name].find(query))
        if rows:
            result[name]=hashlib.sha256(json.dumps(sorted(json_util.dumps(r,sort_keys=True) for r in rows)).encode()).hexdigest()
    return result
before=snapshot();planned=[]
for name,rows in records.items():
    for row in rows:
        assert row['organization_id']==org
        if name=='ar_collections':assert row['invoice_id'] in x['invoices']
        value={**row,'pilot_source':{'purpose':'read_only_existing_invoice_context','source_sha256':hashlib.sha256(json_util.dumps(row,sort_keys=True).encode()).hexdigest()}}
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
copied = execute(PILOT, COPY, {'records': packet['records'], 'org': ORG, 'invoices': INVOICES})
reader = json.loads((PRIVATE / 'thunderhawk-financial-reader.private.json').read_text())
assert reader['scope'] == 'read' and reader['organization_id'] == ORG
results = []
for expected in packet['observations']:
    identity = expected['document_id']
    request = urllib.request.Request('http://127.0.0.1:19180/api/v1/financial-observations/invoices/' + identity,
                                     headers={'Authorization': 'Bearer ' + reader['api_key']})
    with urllib.request.urlopen(request, timeout=25) as response:
        actual = json.load(response)
        assert response.headers['cache-control'] == 'no-store, private'
    assert actual['observation_hash'] == expected['observation_hash'] and actual['contract_version'] == '2026-10-30.2'
    assert actual['organization_id'] == ORG and actual['document']['currency'] is None
    assert actual['cash_delivery_proven'] is False and actual['execution_authority'] is False
    selected = [r for r in actual['document']['line_items'] if r['item_id'] == ITEM]
    assert len(selected) == 1 and selected[0]['assignment_id'] is None and selected[0]['employee_id'] is None
    assert selected[0]['service_start'] is None and selected[0]['service_end'] is None
    results.append({'kind': 'invoice', 'document_id': identity, 'observation_hash': actual['observation_hash'],
        'line_count': len(actual['document']['line_items']), 'selected_line_id': selected[0]['line_id'],
        'selected_item_id': ITEM, 'source_labels_retained': True, 'currency_recorded': False,
        'collection_count': len(actual['collections']), 'recorded_receipt_count': len(actual['document']['recorded_receipts']),
        'native_http_matches_source': True, 'staffing_identity_verified': False, 'service_dates_recorded': False})
print(json.dumps({'passed': True, 'source_record_projection_sha256': record_hash, 'copy': copied,
    'source_records_unchanged': True, 'observations': results, 'production_changed': False,
    'payments_or_payroll_executed': False, 'financial_postings': False, 'core_capture_saved': False}))
