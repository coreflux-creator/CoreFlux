"""Fresh peer-owner, web-pool and process observations for an already held release.

This module does not start maintenance or authorize a release. The caller must
provide the exact reviewed hold binding and the private expiring web-channel token.
"""
from __future__ import annotations

import hashlib
import http.client
import json
import re
import secrets
import ssl
import time
from datetime import datetime
from urllib.parse import urlsplit

from inspect_full_core_release import ROOT, encode, exact_hex


class MaintenanceStatus:
    """Single exact HTTPS endpoint; no redirects, cookies, environment proxies or token logs."""
    def __init__(self, url: str, token: str, operation: str):
        parsed = urlsplit(url)
        if (parsed.scheme != 'https' or parsed.hostname not in ('corefluxapp.com', 'www.corefluxapp.com')
                or parsed.port not in (None, 443) or parsed.username is not None or parsed.password is not None
                or parsed.query or parsed.fragment or not exact_hex(operation, 32)
                or not re.fullmatch('/core-maintenance-' + operation + '-[a-f0-9]{32}\\.php', parsed.path)
                or not isinstance(token, str) or not re.fullmatch('[A-Za-z0-9_-]{32,256}', token)):
            raise ValueError('Exact private Core maintenance endpoint required')
        self.host, self.path, self.token = parsed.hostname, parsed.path, token

    def __call__(self) -> dict:
        return self._request({'action': 'status'})

    def invalidate(self, plan_sha256: str) -> dict:
        if not exact_hex(plan_sha256, 64):
            raise ValueError('Exact reviewed cache-plan fingerprint required')
        return self._request({'action': 'invalidate', 'plan_sha256': plan_sha256})

    def _request(self, payload: dict) -> dict:
        connection = http.client.HTTPSConnection(self.host, timeout=8, context=ssl.create_default_context())
        try:
            connection.request('POST', self.path, body=encode(payload), headers={
                'Content-Type': 'application/json', 'X-CoreFlux-Maintenance': self.token})
            response = connection.getresponse()
            body = response.read(1048577)
            if response.status != 200 or len(body) > 1048576:
                raise ValueError('Authenticated maintenance status unavailable')
            result = json.loads(body)
            if not isinstance(result, dict):
                raise ValueError('Exact maintenance status required')
            return result
        finally:
            connection.close()


class NativeHoldObserver:
    def __init__(self, coordinator, binding: dict, status: MaintenanceStatus):
        self.coordinator = coordinator
        self.binding = json.loads(encode(binding))
        self.status = status
        spec = coordinator.config['control_spec']
        for key in ('operation', 'candidate_commit', 'migration_plan_sha256', 'manifest_sha256', 'root'):
            if binding.get(key) != spec.get(key):
                raise ValueError('Hold binding differs from reviewed release')
        for key in ('held_web_sha256', 'application_held_cron_sha256', 'master_held_cron_sha256',
                    'application_maintenance_receipt_sha256', 'master_maintenance_receipt_sha256', 'writer_inventory_sha256'):
            if not exact_hex(binding.get(key), 64):
                raise ValueError('Complete reviewed hold binding required')

    def _hold(self, uid: int, value: dict) -> dict:
        prefix = 'master' if uid == 1003 else 'application'
        scope = 'current-user-scheduler' if uid == 1003 else 'application-scheduler-and-web'
        if not isinstance(value, dict) or any(value.get(k) != v for k, v in {
            'scope': scope, 'cli_uid': uid, 'root': ROOT, 'operation': self.binding['operation'],
            'receipt_sha256': self.binding[prefix + '_maintenance_receipt_sha256'],
            'held_cron_sha256': self.binding[prefix + '_held_cron_sha256'],
        }.items()) or (uid == 1004 and value.get('held_web_sha256') != self.binding['held_web_sha256']):
            raise ValueError('Native owner no longer holds this release')
        return value

    def _web(self, web: dict, started: float) -> list[int]:
        if not isinstance(web, dict) or any(web.get(k) != v for k, v in {
            'scope': 'core-web-pool-only', 'operation': self.binding['operation'],
            'candidate_commit': self.binding['candidate_commit'], 'held_web_sha256': self.binding['held_web_sha256'],
        }.items()):
            raise ValueError('Authenticated Core web observation differs')
        stamp = datetime.fromisoformat(web['observed_at'].replace('Z', '+00:00'))
        # FPM reports whole UTC seconds, on a different host. Apply the same
        # bounded two-second clock skew as native SSH observations, with flooring
        # for that timestamp precision. The full witness still expires at 15s.
        if stamp.tzinfo is None or not (int(started - 2) <= stamp.timestamp() <= time.time() + 2):
            raise ValueError('Core web observation is stale')
        pool = web.get('pool', {})
        if any(pool.get(k) is not True for k in ('pool_matches', 'self_running', 'self_script_matches',
                                               'counts_consistent', 'request_pool_quiet')) or any(
                pool.get(k) != v for k, v in {'self_process_count': 1, 'listen_queue': 0,
                                             'other_busy_processes': 0, 'active_processes': 1}.items()):
            raise ValueError('Core request pool is not quiet')
        pids = pool.get('process_ids')
        if (not isinstance(pids, list) or not pids or any(type(pid) is not int or pid < 1 for pid in pids)
                or len(pids) != len(set(pids)) or len(pids) != pool.get('total_processes')
                or pool.get('other_idle_processes') != len(pids) - 1
                or type(pool.get('accepted_connections')) is not int or pool['accepted_connections'] < 1):
            raise ValueError('Complete authenticated Core worker PIDs required')
        return pids

    def __call__(self, query: dict) -> dict:
        started = time.time()
        for key in ('operation', 'candidate_commit', 'migration_plan_sha256'):
            if query.get(key) != self.binding[key]:
                raise ValueError('Hold observation belongs to another release')
        uid = query.get('owner_uid')
        if (type(uid) is not int or uid not in (1003, 1004) or query.get('kind') != 'hold-witness'
                or not exact_hex(query.get('challenge'), 64) or type(query.get('owner_pid')) is not int
                or query['owner_pid'] < 1 or not isinstance(query.get('owner_start_ticks'), str)
                or not re.fullmatch('[0-9]+', query['owner_start_ticks'])):
            raise ValueError('Authenticated active native owner required')
        own = self._hold(uid, query.get('own_hold'))
        other = 1004 if uid == 1003 else 1003
        web = self.status()
        pids = self._web(web, started)
        runtime = {k: query[k] for k in ('operation', 'candidate_commit', 'owner_uid', 'owner_pid', 'owner_start_ticks')}
        runtime.update(held_cron_sha256={str(u): self.binding[p + '_held_cron_sha256']
                                        for u, p in ((1003, 'master'), (1004, 'application'))},
                       pool_pids=pids, database_children=query.get('database_children', []),
                       worker_commands={str(u): self.coordinator.worker_command_hash(u) for u in (1003, 1004)})
        witnesses = []
        peer = None
        # The peer's one authenticated observation includes its independently
        # locked hold readback. Never re-enter the active owner's release lock.
        # Both accounts still inspect live processes for every witness; no cache.
        for observer in (other, uid):
            challenge = secrets.token_hex(32)
            data = {'challenge': challenge, 'runtime': runtime}
            requested = time.time()
            report = self.coordinator.call(observer, 'witness-writers', data)
            self.coordinator._verify_writer_response(observer, 'witness-writers', data, report, requested)
            if observer == other:
                peer = self._hold(other, report.get('hold'))
            writers = report.get('writers', {})
            if (writers.get('owner_uid') != observer or writers.get('inventory_sha256') != self.binding['writer_inventory_sha256']
                    or writers.get('registry_unchanged') is not True or writers.get('core_cli_processes') != 0
                    or writers.get('unmanaged_writers') != 0 or not exact_hex(writers.get('observation_sha256'), 64)):
                raise ValueError('Fresh native writer classification differs')
            witnesses.append(report)
        if time.time() - started > 15 or witnesses[0].get('boot_id') != witnesses[1].get('boot_id'):
            raise ValueError('Native writer observations are stale or from different hosts')
        result = {k: query[k] for k in ('challenge', 'operation', 'candidate_commit', 'migration_plan_sha256')}
        result.update(observed_at_unix=time.time(), master=own if uid == 1003 else peer,
                      application=own if uid == 1004 else peer, web=web,
                      writers={'inventory_sha256': self.binding['writer_inventory_sha256'], 'registry_unchanged': True,
                               'core_cli_processes': 0, 'unmanaged_writers': 0,
                               'observations_sha256': hashlib.sha256(encode(witnesses)).hexdigest()})
        if 'backup_pair_sha256' in self.binding:
            result['backup_pair_sha256'] = self.binding['backup_pair_sha256']
        return result
