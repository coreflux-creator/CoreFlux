"""Release boundary and partial-swap recovery checks using temporary files only."""
import importlib.util,json,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from uuid import uuid4

path=Path(__file__).resolve().parents[1]/'deploy/install_pure_commission_exchange.py'
spec=importlib.util.spec_from_file_location('commission_release',path)
# The real installer is Linux-only; supply its lock constant without altering live files.
import sys
if sys.platform=='win32':sys.modules.setdefault('fcntl',SimpleNamespace(LOCK_EX=2,LOCK_NB=4,flock=lambda *a:None))
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)

class ReleaseTest(unittest.TestCase):
    def test_relative_paths_reject_escape(self):
        self.assertEqual(str(release.validate_relative('static/js/main.js')),'static/js/main.js')
        for name in ('../escape','/absolute','x/../../escape','C:/outside','a\\b','a//b','a/./b',''):
            with self.subTest(name=name),self.assertRaises(ValueError):release.validate_relative(name)

    def setup_plan(self,stage):
        backend=stage/'provider/backend';backend.mkdir(parents=True);front=stage/'provider/frontend';front.mkdir()
        (front/'index.html').write_text('synthetic UI')
        (stage/'install_pure_payment_display.py').write_text('# verified helper fixture\n')
        changed={}
        for name in release.FILES:
            body=b'VALUE = "candidate"\n';(backend/name).write_bytes(body);changed[name]=release.sha(body)
        runtime={'server.py':release.sha(b'unchanged server')};runtime.update(changed)
        stamp={'release_id':'synthetic','deployment_id':str(uuid4()),'source_digest':release.sha(json.dumps(runtime,sort_keys=True).encode())}
        raw=json.dumps(stamp).encode();(stage/'release_handover.json.next').write_bytes(raw)
        return {'provider_commit':'579245c03bfa254dc2d01cee446f165af762b3bb','core_commit':'32f313a3da6ca556b5a072c51fa79b35412034ff',
            'ci_successful_jobs':11,'assignment_version':'2026-11-12.1','changed_backend':changed,
            'local_checks':{'provider_api':102,'provider_protected_records':True,'react':5},
            'helper_sha256':release.sha((stage/'install_pure_payment_display.py').read_bytes()),
            'new_frontend':release.hashes(front),'new_stamp':stamp,'new_stamp_sha256':release.sha(raw),
            'before':{'release_runtime':runtime}}

    def test_three_file_digest_and_staged_preimages(self):
        with tempfile.TemporaryDirectory()as name:
            stage=Path(name);plan=self.setup_plan(stage)
            self.assertEqual(json.loads(release.validate_plan(plan,stage)),plan['new_stamp'])
            (stage/'provider/backend/coreflux_commercial.py').write_text('altered')
            with self.assertRaises(AssertionError):release.validate_plan(plan,stage)

    def test_unrelated_source_cannot_disappear_from_release_stamp(self):
        with tempfile.TemporaryDirectory()as name:
            stage=Path(name);plan=self.setup_plan(stage);plan['before']['release_runtime']['unexpected.py']='a'*64
            with self.assertRaises(AssertionError):release.validate_plan(plan,stage)

    def test_unexpected_runtime_target_is_rejected(self):
        with tempfile.TemporaryDirectory()as name:
            stage=Path(name);plan=self.setup_plan(stage);plan['changed_backend']['server.py']='a'*64
            with self.assertRaises(AssertionError):release.validate_plan(plan,stage)

    def recovery(self,missing_frontend=False,foreign_source=False):
        with tempfile.TemporaryDirectory()as name:
            root=Path(name);private=root/'private';private.mkdir();backend=root/'backend';backend.mkdir();front=root/'frontend'
            backup=private/'before';backup.mkdir();old_front=backup/'frontend';old_front.mkdir();(old_front/'index.html').write_text('original')
            old={};changed={}
            for file in (*release.FILES,'release_handover.json'):
                body=b'original';(backup/file).write_bytes(body);(backend/file).write_bytes(b'candidate');old[file]=release.sha(body);changed[file]=release.sha(b'candidate')
            if not missing_frontend:front.mkdir();(front/'index.html').write_text('candidate')
            if foreign_source:(backend/release.FILES[0]).write_bytes(b'unrelated edit')
            plan={'new_stamp_sha256':changed['release_handover.json'],'changed_backend':{f:changed[f] for f in release.FILES},
                'new_frontend':{'index.html':release.sha(b'candidate')},'before':{'provider_pins':old,'frontend_files':release.hashes(old_front)}}
            commands=[];saved={'backup':str(backup),'database':{'unchanged':True},'others':{}}
            base=SimpleNamespace(command=lambda *a,**kw:commands.append(a),snapshot=lambda:saved['database'],services=lambda:{},
                source_hashes=lambda:{f:release.sha((backend/f).read_bytes())for f in old})
            with patch.multiple(release,ROOT=root,PRIVATE=private,BACKEND=backend,FRONTEND=front),\
                    patch.object(release,'write_backend',lambda f,b:(backend/f).write_bytes(b)),\
                    patch.object(release,'ready',lambda b:{'state':'active'}),patch.object(release,'current_scope',lambda *a:None):
                report={}
                if foreign_source:
                    with self.assertRaises(AssertionError):release.rollback(base,plan,report,saved)
                    self.assertEqual(commands,[])
                else:
                    release.rollback(base,plan,report,saved)
                    self.assertEqual(report['phase'],'rolled_back');self.assertEqual((front/'index.html').read_text(),'original')
                    self.assertTrue(all((backend/f).read_bytes()==b'original' for f in old))
                    self.assertEqual(len(commands),2)

    def test_complete_swap_recovery_preserves_original_files(self):self.recovery()
    def test_interrupted_directory_swap_recovers_missing_frontend(self):self.recovery(missing_frontend=True)
    def test_recovery_refuses_unrelated_source_edit(self):self.recovery(foreign_source=True)

if __name__=='__main__':unittest.main()
