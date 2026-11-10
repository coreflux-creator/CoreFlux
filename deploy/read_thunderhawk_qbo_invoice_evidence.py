"""GET-only QBO evidence for five already-confirmed pilot invoice lines.

Runs on the provider host through the installed PurePay connection wrapper.
Leaves invoices and pilot captures unchanged; normal auth/usage metadata may move.
"""
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
if sys.argv[1:] != ['--read-confirmed-invoices'] or PRIVATE.resolve() != PRIVATE:
    raise SystemExit('Explicit source evidence read required')
if PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private evidence directory permissions differ')
target = PRIVATE / 'thunderhawk-qbo-invoice-evidence-20261030.private.json'
if target.exists() or target.is_symlink():
    raise SystemExit('This evidence packet already exists; review it before making another source read')

READ = r'''
import asyncio, hashlib, json, logging, os, sys, time
from datetime import datetime, timezone
from decimal import Decimal
from bson import json_util
logging.disable(logging.CRITICAL)
import server as S
import runtime_env

ORG='org_0369a63ff2cb'
REALM='9341453235663641'
IDS={'inv_82de265509':('6019','21'), 'inv_ea597af5df':('4155','21'),
     'inv_dcb25061c4':('3474','24'), 'inv_191eb3675c':('6472','18'),
     'inv_0f1382bcb5':('7101','16')}
assert os.environ['DB_NAME']=='purepay'
assert os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_web'

async def main():
    org=await S.db.organizations.find_one({'id':ORG})
    assert org and not org.get('sandbox')
    runtime_env.enter_org(ORG)
    assert not runtime_env.is_sandbox() and S.qbo_client.QBO_ENV=='production'
    conn=await S._qbo_conn(ORG)
    assert str(conn['realm_id'])==REALM and conn['environment']=='production'
    async def local_rows():
        return await S.db.invoices.find({'organization_id':ORG,'id':{'$in':list(IDS)}}).sort('id',1).to_list(6)
    def digest(rows):
        return hashlib.sha256(json_util.dumps(rows,sort_keys=True).encode()).hexdigest()
    before=await local_rows()
    assert {r['id'] for r in before}==set(IDS)
    byid={r['id']:r for r in before}
    result=[]
    allowed={'invoice/'+pair[0] for pair in IDS.values()}
    original=S._qbo_call
    original_conn=S._qbo_conn
    original_transport=S.qbo_client.api_request
    async def checked_connection(org_id):
        assert org_id==ORG
        current=await original_conn(org_id)
        assert str(current['realm_id'])==REALM and current['environment']=='production'
        assert current.get('access_expires_at',0)>time.time()+300
        return current
    async def checked_transport(token, realm_id, method, path, **kwargs):
        assert str(realm_id)==REALM and method=='GET' and path in allowed and not kwargs
        assert S.qbo_client.QBO_ENV=='production'
        current=await checked_connection(ORG)
        assert current['access_token']==token
        return await original_transport(token,realm_id,method,path)
    def refuse_refresh(*args,**kwargs):
        raise RuntimeError('Fresh token required; this evidence reader never refreshes credentials')
    S._qbo_conn=checked_connection
    S.qbo_client.api_request=checked_transport
    S.qbo_client.refresh_tokens=refuse_refresh
    async def read_only(org_id, method, path, **kwargs):
        assert org_id==ORG and method=='GET' and path in allowed
        assert set(kwargs)=={'expect_realm','expect_env'}
        assert kwargs['expect_realm']==REALM and kwargs['expect_env']=='production'
        # Production's older wrapper has no expect_* arguments. Pin at its
        # connection read and final transport, retaining its usage/backoff path.
        return await original(org_id,method,path)
    for identity,(qid,lineid) in IDS.items():
        local=byid[identity]
        assert str(local['qbo_invoice_id'])==qid and local['customer_id']=='cus_e25f880f86'
        lines=[r for r in local['line_items'] if r.get('item_id')=='itm_eb535696e3']
        assert len(lines)==1 and lines[0]['line_id']=='il_qbo_'+qid+'_'+lineid
        oldline=lines[0]
        with S.qbo_usage.feature('Core pilot confirmed invoice evidence GET only'):
            data=await read_only(ORG,'GET','invoice/'+qid,expect_realm=REALM,expect_env='production')
        inv=data['Invoice']
        assert str(inv['Id'])==qid
        qlines=[r for r in inv.get('Line',[]) if str(r.get('Id'))==lineid]
        assert len(qlines)==1 and qlines[0]['DetailType']=='SalesItemLineDetail'
        line=qlines[0]; detail=line['SalesItemLineDetail']
        checks={
          'customer_ref_matches':str((inv.get('CustomerRef') or {}).get('value'))==str(local.get('customer_ref')),
          'item_ref_matches':str((detail.get('ItemRef') or {}).get('value'))==str(oldline.get('qbo_item_id')),
          'quantity_matches':Decimal(str(detail.get('Qty')))==Decimal(str(oldline.get('hours'))),
          'unit_price_matches':Decimal(str(detail.get('UnitPrice')))==Decimal(str(oldline.get('bill_rate'))),
          'amount_matches':Decimal(str(line.get('Amount')))==Decimal(str(oldline.get('amount'))),
          'description_matches':line.get('Description')==oldline.get('description')}
        result.append({'invoice_id':identity,'qbo_invoice_id':qid,'source_line_id':oldline['line_id'],
          'customer_id':local['customer_id'],'qbo_customer_ref':inv.get('CustomerRef'),
          'currency_ref':inv.get('CurrencyRef'),'exchange_rate':inv.get('ExchangeRate'),
          'txn_date':inv.get('TxnDate'),'due_date':inv.get('DueDate'),
          'sync_token':inv.get('SyncToken'),'metadata':inv.get('MetaData'),
          'stored_sync_token':(local.get('qbo_invoice_facts') or {}).get('sync_token'),
          'selected_line':line,'comparison':checks,
          'provider_invoice_sha256':hashlib.sha256(json.dumps(inv,sort_keys=True).encode()).hexdigest()})
    after=await local_rows()
    packet={'checked_at':datetime.now(timezone.utc).isoformat(),'organization_id':ORG,
      'qbo_realm_id':REALM,'environment':'production','company_name':conn.get('company_name'),
      'provider_method':'GET','source_wrapper':'server._qbo_call','invoices':result,
      'local_invoice_hash_before':digest(before),'local_invoice_hash_after':digest(after),
      'local_invoices_unchanged':before==after,'invoice_or_payment_writes':False,
      'pilot_changes':False,'background_startup_invoked':False,'token_refresh_allowed':False}
    print(json.dumps(packet))
    S.client.close()
asyncio.run(main())
'''

result = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend',
    'payroll-web-pure-web-1', 'python', '-c', READ], text=True, capture_output=True)
if result.returncode:
    error = PRIVATE / 'thunderhawk-qbo-invoice-read-error.private.txt'
    with os.fdopen(os.open(error, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600), 'w') as f:
        f.write(result.stderr)
    raise SystemExit('Source read stopped; diagnostics retained privately')
packet = json.loads(result.stdout)
with os.fdopen(os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600), 'w') as f:
    json.dump(packet, f, sort_keys=True)
public = {k:v for k,v in packet.items() if k not in {'invoices'}}
public['invoices'] = [{'invoice_id':r['invoice_id'],'qbo_invoice_id':r['qbo_invoice_id'],
    'source_line_id':r['source_line_id'],'currency':(r['currency_ref'] or {}).get('value'),
    'service_date':r['selected_line']['SalesItemLineDetail'].get('ServiceDate'),
    'comparison':r['comparison'],'sync_token':r['sync_token'],
    'stored_sync_token':r['stored_sync_token'],'provider_invoice_sha256':r['provider_invoice_sha256']}
    for r in packet['invoices']]
print(json.dumps(public, indent=2))
