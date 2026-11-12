"""Reject the stale stamp that caused the pilot's failed startup, before restart."""
import ast
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from uuid import uuid4

ROOT = Path(__file__).resolve().parents[1]
ns = {'Path': Path, 'json': json, 'sha': lambda body: hashlib.sha256(body).hexdigest()}
tree = ast.parse((ROOT / 'deploy/install_pure_payment_display.py').read_text())
nodes = [node for node in tree.body if isinstance(node, ast.FunctionDef)
         and node.name in {'release_digest', 'verify_release_stamp'}]
assert len(nodes) == 2
exec(compile(ast.Module(body=nodes, type_ignores=[]), 'reviewed-release-contract', 'exec'), ns)


class ReviewedReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.old = b'def display():\n return "old"\n'
        self.new = b'def display():\n return "new"\n'
        (self.root / 'server.py').write_bytes(self.old)
        (self.root / 'guard.py').write_bytes(b'ENABLED = True\n')

    def stamp(self, source):
        return json.dumps({'release_id': 'synthetic-reviewed-display',
                           'deployment_id': str(uuid4()),
                           'source_digest': ns['release_digest'](self.root, source)}).encode()

    def test_stale_stamp_is_refused_without_changing_source(self):
        with self.assertRaisesRegex(AssertionError, 'reviewed stamp'):
            ns['verify_release_stamp'](self.root, self.new, self.stamp(self.old))
        self.assertEqual((self.root / 'server.py').read_bytes(), self.old)

    def test_exact_reviewed_pair_is_accepted_before_activation(self):
        stamp = self.stamp(self.new)
        self.assertEqual(ns['verify_release_stamp'](self.root, self.new, stamp), json.loads(stamp))
        self.assertEqual((self.root / 'server.py').read_bytes(), self.old)

    def test_other_runtime_drift_is_refused(self):
        stamp = self.stamp(self.new)
        (self.root / 'guard.py').write_bytes(b'ENABLED = False\n')
        with self.assertRaises(AssertionError):
            ns['verify_release_stamp'](self.root, self.new, stamp)

    def test_line_endings_and_nonruntime_data_follow_runtime_contract(self):
        digest = ns['release_digest'](self.root, self.new)
        (self.root / 'guard.py').write_bytes(b'ENABLED = True\r\n')
        for name in ['uploads', 'data', 'tests', '__pycache__', '.private']:
            (self.root / name).mkdir()
            (self.root / name / 'ignored.py').write_bytes(b'not runtime')
        self.assertEqual(ns['release_digest'](self.root, self.new.replace(b'\n', b'\r\n')), digest)

    def test_unknown_stamp_fields_and_invalid_identity_are_refused(self):
        stamp = json.loads(self.stamp(self.new))
        stamp['disable_checks'] = True
        with self.assertRaises(AssertionError):
            ns['verify_release_stamp'](self.root, self.new, json.dumps(stamp))
        del stamp['disable_checks']
        stamp['deployment_id'] = 'invalid'
        with self.assertRaises(ValueError):
            ns['verify_release_stamp'](self.root, self.new, json.dumps(stamp))


if __name__ == '__main__':
    unittest.main()
