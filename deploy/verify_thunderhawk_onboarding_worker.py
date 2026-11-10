"""Read the returned employer decisions as the isolated Thunderhawk worker.

Uses the existing private pilot login, never sends mail, and prints no credentials.
This checks the native worker API; it is not a rendered-browser acceptance.
"""
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

PRIVATE = Path('/srv/core-integration-pilot/20261029/private')
TENANT = '4f93d4e7-af74-4765-b812-3464bb6d974b'
WORKER = 'cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa'
CASE = 'cfo_b2fcc457b55a5365544597d55d8ead4bf9e7572cc89bd6cdd921ca7f45b5b9b6'


def request(path, token=None, body=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request('http://127.0.0.1:19181/api' + path,
        None if body is None else json.dumps(body).encode(), headers)
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, None


def checked(path, token=None, body=None):
    status, value = request(path, token, body)
    if status != 200:
        raise RuntimeError('Unexpected native API status ' + str(status))
    return value


def main():
    if sys.argv[1:] != ['--verify-isolated-review-return']:
        return 2
    report = {'workspace_id': TENANT, 'worker_id': WORKER, 'case_id': CASE,
        'production_changed': False, 'real_worker_email_sent': False,
        'rendered_browser_verified': False}
    phase = 'environment_guard'
    try:
        guard = "import os; assert os.environ['DB_NAME']=='corepilot_quanta_20261029'; assert os.environ['MAIL_DRIVER']=='devoutbox'; assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1'; assert os.environ['DISABLE_SCHEDULER']=='1'"
        subprocess.run(['docker', 'exec', 'core-provider-pilot-20261029-quanta-pilot-web-1',
            'python', '-c', guard], check=True, capture_output=True)
        private = json.loads((PRIVATE / 'thunderhawk-worker-onboarding.private.json').read_text())
        phase = 'worker_login'
        login = checked('/auth/login', body={'email': private['email'],
            'password': private['password'], 'tenant_slug': 'thunderhawk'})
        token = login['access_token']
        me = checked('/auth/me', token)
        assert me['current_tenant_id'] == TENANT and me['user']['id'] == WORKER
        assert me['user']['roles'] == ['worker'] and not me['is_impersonating']
        phase = 'returned_decisions'
        result = checked('/core/onboarding?view=mine', token)
        assert result['workspace_id'] == TENANT and result['view'] == 'mine'
        assert len(result['items']) == 1
        case = result['items'][0]
        assert case['id'] == CASE and case['person_number'] == 'P-358' and case['placement_number'] == 'PL-864'
        assert len(case['tasks']) == 3 and all(t['effective_state'] == 'approved' for t in case['tasks'])
        assert len(case['submissions']) == 2
        expected_receipts = {
            'cfs_d40479a98fa4c29be9e7f327caf47117c55e582f19b85c360fe602381dcd148e',
            'cfs_80b180e017f5901815b8cdf11b9c2b762123943eb224da68565658e3a2d323fa',
        }
        assert {r['id'] for r in case['submissions']} == expected_receipts
        assert all(r['projection_hash'] != case['projection_hash'] for r in case['submissions'])
        assert all(t['effective_state'] not in {'requested', 'rejected', 'needs_review', 'expired', 'evidence_unavailable'}
            for t in case['tasks'] if t['assignee'] == 'worker')
        phase = 'worker_access_boundary'
        assert request('/core/onboarding?view=workspace', token)[0] == 403
        assert request('/tenant/users', token)[0] == 403
        assert checked('/core/onboarding?view=mine', token) == result
        report.update({'passed': True, 'portal_case_version': case['version'],
            'approved_task_count': 3, 'original_submission_count': 2,
            'worker_api_decisions_verified': True, 'repeat_read_unchanged': True,
            'worker_tasks_need_no_resubmission': True, 'workspace_and_team_access_denied': True})
    except Exception as error:
        report.update({'passed': False, 'failed_phase': phase, 'failure_class': type(error).__name__})
    print(json.dumps(report))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
