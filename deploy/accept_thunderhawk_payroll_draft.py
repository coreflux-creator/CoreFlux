"""Create and verify a held payroll draft from the isolated eight-hour test."""
import datetime
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
CONTAINER = 'core-provider-pilot-20261029-pure-pilot-web-1'
ORG = 'org_0369a63ff2cb'
INPUT = 'cpi_2c219ec937ea221bead21b8b4cb3391b099bd7bdbe19deb570d42882d726bcd9'
AUTHORITY = 'cpa_687c5888d2cc9f28aeda46fb8a1251b33562e33075f3563abc09df3073563961'
STATE = PRIVATE / 'thunderhawk-pure-payroll-draft-acceptance.json'


def request(path, token=None, body=None, request_key=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    if request_key:
        headers['Idempotency-Key'] = request_key
    req = urllib.request.Request('http://127.0.0.1:19180/api' + path,
        None if body is None else json.dumps(body).encode(), headers)
    try:
        with urllib.request.urlopen(req, timeout=45) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        payload = json.load(error)
        # Diagnostic errors are retained only in the private host file.
        return error.code, payload


def check_records():
    code = '''import os,json
from pymongo import MongoClient
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
runs=list(d.pay_runs.find({'organization_id':__ORG__},{'_id':0,'id':1,'status':1,'core_execution_hold':1,'core_allocation_ready':1,'totals':1,'core_allocation':1}))
print(json.dumps({'runs':runs,'counts':{c:d[c].count_documents({'organization_id':__ORG__}) for c in ['payments','bills','invoices']}}))
'''.replace('__ORG__', repr(ORG))
    result = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'python', '-'],
        input=code, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('Isolated payroll database guard failed')
    return json.loads(result.stdout)


def checkpoint(state):
    tmp = STATE.with_suffix('.tmp')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as out:
        json.dump(state, out, sort_keys=True, indent=2)
    os.replace(tmp, STATE)


def main():
    global INPUT, AUTHORITY, STATE
    browser_hour = sys.argv[1:] == ['--create-browser-hour-held-draft']
    if sys.argv[1:] not in (['--create-isolated-held-draft'], ['--create-browser-hour-held-draft']) or not PRIVATE.is_dir():
        raise RuntimeError('Explicit isolated draft argument and private host directory required')
    expected_gross, expected_hours, pay_date = 520.0, '8.00', '2026-10-30'
    if browser_hour:
        INPUT = 'cpi_390401c8dcc2bc7b7e979232c4c294ad6347d42e4a920bd0777dd693572a399b'
        AUTHORITY = 'cpa_0d44c7f660e4181fd94ee6d418082e0576c0687fdbec0e0afaf6e41ab1212357'
        STATE = PRIVATE / 'thunderhawk-browser-hour-payroll-draft-acceptance.json'
        expected_gross, expected_hours, pay_date = 65.0, '1.00', '2026-11-06'
    state = json.loads(STATE.read_text()) if STATE.exists() else {}
    report = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'organization_id': ORG, 'input_id': INPUT, 'authority_id': AUTHORITY,
              'production_changed': False, 'real_payroll_executed': False}
    phase = 'guard'
    try:
        before = check_records()
        assert len(before['runs']) <= 2
        allowed_authorities = {
            'cpa_687c5888d2cc9f28aeda46fb8a1251b33562e33075f3563abc09df3073563961',
            'cpa_0d44c7f660e4181fd94ee6d418082e0576c0687fdbec0e0afaf6e41ab1212357',
        }
        assert all(run['status'] == 'draft' and run['core_execution_hold'] is True
            and run['core_allocation']['authority_id'] in allowed_authorities for run in before['runs'])
        assert all(v == 0 for v in before['counts'].values())
        plan = json.loads((PRIVATE / 'thunderhawk-bootstrap-plan.private.json').read_text())
        phase = 'native_login'
        status, login = request('/auth/login', body={
            'email': 'thunderhawk-core-pilot@example.invalid', 'password': plan['pure_password']})
        assert status == 200 and login['organization']['id'] == ORG and login['user']['id'] == 'usr_2d19df3dfa23'
        token = login['token']
        base = f'/payroll/core-inputs/{INPUT}'
        phase = 'draft_review'
        if 'request' not in state:
            options = {'authority_id': AUTHORITY, 'pay_date': pay_date, 'pay_group_id': None}
            status, review = request(base + '/draft-review', token, options)
            if status != 200:
                state['private_error'] = review
                raise RuntimeError(f'Draft review HTTP {status}; details retained privately')
            assert review['execution_enabled'] is False and review['payroll_created'] is False
            assert float(review['totals']['gross']) == expected_gross
            state['request'] = {**options, 'review_hash': review['review_hash']}
            state['request_key'] = review['request_key']
            state['expected_run_id'] = review['run_id']
            checkpoint(state)
        phase = 'create_held_draft'
        status, draft = request(base + '/draft', token, state['request'], state['request_key'])
        if status != 200:
            state['private_error'] = draft
            raise RuntimeError(f'Draft creation HTTP {status}; details retained privately')
        assert draft['run_id'] == state['expected_run_id'] and draft['status'] == 'draft'
        assert draft['ready'] is True and draft['execution_enabled'] is False
        status, replay = request(base + '/draft', token, state['request'], state['request_key'])
        assert status == 200 and replay == draft
        phase = 'verify_execution_hold'
        status, denied = request(f'/payroll/runs/{draft["run_id"]}/approve', token, {})
        assert status == 409 and denied.get('code') == 'isolated_pilot_operation_held'
        phase = 'readback'
        after = check_records()
        assert after['counts'] == before['counts']
        other_before = {run['id']: run for run in before['runs'] if run['id'] != draft['run_id']}
        other_after = {run['id']: run for run in after['runs'] if run['id'] != draft['run_id']}
        assert other_after == other_before
        selected = [run for run in after['runs'] if run['id'] == draft['run_id']]
        assert len(selected) == 1
        run = selected[0]
        assert run['id'] == draft['run_id'] and run['status'] == 'draft'
        assert run['core_execution_hold'] is True and run['core_allocation_ready'] is True
        assert float(run['totals']['gross']) == expected_gross and run['core_allocation']['authority_id'] == AUTHORITY
        report.update({'passed': True, 'run_id': run['id'], 'status': 'draft', 'gross': f'{expected_gross:.2f}',
            'hours': expected_hours, 'pay_rate': '65.0000', 'currency': 'USD', 'execution_hold': True,
            'pay_date': pay_date, 'prior_drafts_unchanged': True,
            'duplicate_request_same_draft': True, 'execution_rejected': True,
            'payments_bills_invoices_unchanged': True})
        state.pop('private_error', None)
    except Exception as error:
        report.update({'passed': False, 'failed_phase': phase,
            'error': str(error) if isinstance(error, RuntimeError) else type(error).__name__})
    state['receipt'] = report
    checkpoint(state)
    print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
