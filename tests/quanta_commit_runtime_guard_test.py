"""Exercise the exact installed helper's record-preservation gate without a host."""
import ast
from pathlib import Path
import unittest

source = (Path(__file__).resolve().parents[1] / 'deploy/install_thunderhawk_quanta_commit_runtime.py').read_text()
tree = ast.parse(source)
function = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == 'verify_original_records')
namespace = {}
exec(compile(ast.Module(body=[function], type_ignores=[]), 'preservation-guard', 'exec'), namespace)
verify = namespace['verify_original_records']


class OriginalRecordGuard(unittest.TestCase):
    def setUp(self):
        self.before = {'time_entries': {'count': 12, 'sha256': 'original', 'indexes': ['original']}}

    def test_empty_new_schema_is_allowed(self):
        self.assertEqual(verify(self.before, {**self.before, 'pace_nudges': {'count': 0}}), ['pace_nudges'])

    def test_missing_collection_refused(self):
        with self.assertRaises(AssertionError): verify(self.before, {})

    def test_changed_rows_refused(self):
        with self.assertRaises(AssertionError):
            verify(self.before, {'time_entries': {**self.before['time_entries'], 'sha256': 'changed'}})

    def test_changed_index_refused(self):
        with self.assertRaises(AssertionError):
            verify(self.before, {'time_entries': {**self.before['time_entries'], 'indexes': ['changed']}})

    def test_new_business_rows_refused(self):
        with self.assertRaises(AssertionError): verify(self.before, {**self.before, 'pace_nudges': {'count': 1}})

    def test_unreviewed_schema_refused(self):
        with self.assertRaises(AssertionError): verify(self.before, {**self.before, 'unexpected': {'count': 0}})

    def test_accidental_coordination_activation_refused(self):
        with self.assertRaises(AssertionError): verify(self.before, {**self.before, 'coreflux_commit_fences': {'count': 1}})


if __name__ == '__main__': unittest.main()
