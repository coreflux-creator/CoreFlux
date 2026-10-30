"""Native worker access and sample submissions in the private Thunderhawk copy.

No outbound email, real employment evidence, production login, or HR approval.
Credentials stay only in the exact private pilot directory.
"""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT=Path('/srv/core-integration-pilot/20261029')
PRIVATE=ROOT/'private'
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER='cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa'
CASE='cfo_b2fcc457b55a5365544597d55d8ead4bf9e7572cc89bd6cdd921ca7f45b5b9b6'
STATE=PRIVATE/'thunderhawk-worker-onboarding.private.json'
PDF=PRIVATE/'thunderhawk-onboarding-sample.pdf'
PDF_HASH='c73ab9af7fcd5b0ee83b187a41e5419f3e7a7ca864a7296508aee16754cc4417'


def request(path,token=None,body=None,file=None):
    headers={}
    if token: headers['Authorization']='Bearer '+token
    if file:
        boundary='corepilot'+secrets.token_hex(16)
        headers['Content-Type']='multipart/form-data; boundary='+boundary
        data=(f'--{boundary}\r\nContent-Disposition: form-data; name="payload"\r\n\r\n'+json.dumps(body)+
              f'\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="thunderhawk-onboarding-sample.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode()+file+f'\r\n--{boundary}--\r\n'.encode()
    else:
        headers['Content-Type']='application/json'
        data=None if body is None else json.dumps(body).encode()
    req=urllib.request.Request('http://127.0.0.1:19181/api'+path,data,headers)
    try:
        with urllib.request.urlopen(req,timeout=45) as response:return response.status,json.load(response)
    except urllib.error.HTTPError as error:
        return error.code,json.load(error)


def checked(path,token=None,body=None,file=None):
    status,result=request(path,token,body,file)
    if status not in (200,201):raise RuntimeError(f'HTTP {status} at {path.split("?")[0]}')
    return result


def save(state):
    tmp=STATE.with_suffix('.tmp')
    with os.fdopen(os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as out:json.dump(state,out)
    os.replace(tmp,STATE)


def identity_state():
    code='''import os,json
from pymongo import MongoClient
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MAIL_DRIVER']=='devoutbox'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
assert os.environ['CORE_ONBOARDING_DOCUMENT_WORKSPACES']==__TENANT__
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
w=d.users.find_one({'tenant_id':__TENANT__,'id':__WORKER__})
assert w and w['roles']==['worker'] and w['status']=='active'
i=d.identities.find_one({'id':w.get('identity_id')}) if w.get('identity_id') else None
print(json.dumps({'identity_id':w.get('identity_id'),'password_set':bool((i or {}).get('password_hash'))}))
'''.replace('__TENANT__',repr(TENANT)).replace('__WORKER__',repr(WORKER))
    r=subprocess.run(['docker','exec','-i','core-provider-pilot-20261029-quanta-pilot-web-1','python','-'],input=code,text=True,capture_output=True)
    if r.returncode:raise RuntimeError('Isolated worker and mail guard failed')
    return json.loads(r.stdout)


def main():
    if sys.argv[1:]!=['--submit-isolated-sample-evidence'] or not PRIVATE.is_dir():return 2
    state=json.loads(STATE.read_text()) if STATE.exists() else {'password':secrets.token_urlsafe(40),'requests':{}}
    save(state)
    report={'workspace_id':TENANT,'worker_id':WORKER,'case_id':CASE,'production_changed':False,
        'real_employment_evidence':False,'real_worker_email_sent':False,'employer_approval_granted':False}
    phase='guard'
    try:
        content=PDF.read_bytes();assert hashlib.sha256(content).hexdigest()==PDF_HASH
        current=identity_state()
        plan=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
        admin=checked('/auth/login',body={'email':'thunderhawk-core-pilot-admin@example.invalid','password':plan['quanta_admin_password'],'tenant_slug':'thunderhawk'})['access_token']
        me=checked('/auth/me',admin);assert me['current_tenant_id']==TENANT and me['user']['id']=='8041205e-b179-4991-8dbe-65d51a474e22'
        workers=checked('/tenant/users?role=worker',admin)['items']
        worker=next(w for w in workers if w['id']==WORKER)
        source=json.loads((PRIVATE/'core-source.private.json').read_text(encoding='utf-8-sig'))
        assert worker['email'].strip().lower()==source['person']['email_primary'].strip().lower()
        state['email']=worker['email']
        phase='enable_isolated_worker_login'
        if not current['identity_id']:
            access=checked('/tenant/users',admin,{'email':worker['email'],'existing_worker_id':WORKER})
            assert access['user_id']==WORKER and access['email_sent'] is False and access['email_delivery']=='captured'
            state['access_receipt']=access;save(state)
            current=identity_state()
        assert current['identity_id']==state.get('access_receipt',{}).get('identity_id')
        phase='initialize_private_test_password'
        if not current['password_set']:
            messages=[json.loads(line) for line in (ROOT/'data/quanta/dev_outbox.jsonl').read_text().splitlines() if line.strip()]
            matches=[m for m in messages if m['to']==worker['email'] and '/set-password?token=' in m.get('text','')]
            assert len(matches)==1
            url=matches[0]['text'].split()[-1]
            token=urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['token'][0]
            result=checked('/auth/set-password',body={'token':token,'password':state['password'],'confirm_password':state['password']})
            assert result['ok'] is True and result['identity_id']==current['identity_id']
        phase='worker_session'
        login=checked('/auth/login',body={'email':state['email'],'password':state['password'],'tenant_slug':'thunderhawk'})
        token=login['access_token'];me=checked('/auth/me',token)
        assert me['current_tenant_id']==TENANT and me['user']['id']==WORKER and me['user']['roles']==['worker'] and not me['is_impersonating']
        state['access_token']=token;save(state)
        assert request('/core/assignments?view=workspace',token)[0]==403
        assert request('/tenant/users',token)[0]==403
        phase='submit_sample_tasks'
        for kind in ['attestation','document']:
            cases=checked('/core/onboarding?view=mine',token)
            assert cases['workspace_id']==TENANT and len(cases['items'])==1
            case=cases['items'][0]
            assert case['id']==CASE and case['person_number']=='P-358' and case['placement_number']=='PL-864'
            assert case['can_submit'] is True and case['document_upload']['enabled'] is True
            task=next(t for t in case['tasks'] if t['assignee']=='worker' and t['evidence_kind']==kind)
            if kind not in state['requests']:
                state['requests'][kind]={'request_id':str(uuid.uuid5(uuid.NAMESPACE_URL,'thunderhawk-pilot-onboarding-20261030:'+kind)),
                    'expected_version':case['version'],'task_id':task['id'],'task_version':task['version'],
                    'projection_hash':case['projection_hash'],'acknowledged':True}
                save(state)
            body=state['requests'][kind];path=f'/core/onboarding/{CASE}/'+('documents' if kind=='document' else 'submissions')
            first=checked(path,token,body,content if kind=='document' else None)
            again=checked(path,token,body,content if kind=='document' else None)
            assert again['replayed'] is True and first['receipt']==again['receipt']
            assert first['receipt']['status']=='awaiting_core_review'
            conflict=request(path,token,{**body,'task_version':body['task_version']+1},content if kind=='document' else None)
            assert conflict[0]==409
            report[kind]={'receipt_id':first['receipt']['id'],'task_id':task['id'],
                'status':first['receipt']['status'],'replay_same_receipt':True,'changed_request_rejected':True}
            if kind=='document':
                assert first['receipt']['document']['sha256']==PDF_HASH
                report[kind]['sha256']=PDF_HASH
            state['receipts']=report;save(state)
        case=checked('/core/onboarding?view=mine',token)['items'][0]
        staff=next(t for t in case['tasks'] if t['assignee']=='staff')
        forbidden={'request_id':str(uuid.uuid5(uuid.NAMESPACE_URL,'thunderhawk-pilot-forbidden-staff-task')),
            'expected_version':case['version'],'task_id':staff['id'],'task_version':staff['version'],
            'projection_hash':case['projection_hash'],'acknowledged':True}
        assert request(f'/core/onboarding/{CASE}/submissions',token,forbidden)[0]==409
        assert len(case['submissions'])==2
        report.update({'passed':True,'portal_case_version':case['version'],'submission_count':2,
            'worker_cannot_submit_staff_task':True,'worker_cannot_view_workspace_or_manage_team':True,
            'private_test_login_created':True,'notifications':'captured in isolated outbox'})
    except Exception as error:
        report.update({'passed':False,'failed_phase':phase,'error':str(error) if isinstance(error,RuntimeError) else type(error).__name__})
    state['receipts']=report;save(state)
    print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__=='__main__':sys.exit(main())
