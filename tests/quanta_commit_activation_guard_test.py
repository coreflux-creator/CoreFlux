"""A startup coordinator may be rolled back only before any later writes."""
import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('activation', Path(__file__).resolve().parents[1] / 'deploy/activate_thunderhawk_quanta_commits.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)


class StartupGuardRecovery(unittest.TestCase):
    def setUp(self):
        self.before = {'time_entries': {'count': 12, 'sha256': 'original', 'indexes': ['original']}}
        self.after = {**copy.deepcopy(self.before), module.GUARD: {'count': 1, 'indexes': [{'name': '_id_'}]}}
        self.guard = {'_id': module.GUARD_ID, 'revision': 1}

    def test_initial_activation(self): module.verify_preservation(self.before, self.after, self.guard)

    def test_unused_guard_can_rollback(self):
        for revision in [0, 1]:
            module.verify_preservation(self.before, self.after, {**self.guard, 'revision': revision}, rollback=True)

    def test_no_guard_yet_can_recover(self): module.verify_preservation(self.before, self.before, None, rollback=True)

    def test_later_write_blocks_rollback(self):
        with self.assertRaises(AssertionError):
            module.verify_preservation(self.before, self.after, {**self.guard, 'revision': 2}, rollback=True)

    def test_changed_original_record_blocks_rollback(self):
        self.after['time_entries']['sha256'] = 'changed'
        with self.assertRaises(AssertionError): module.verify_preservation(self.before, self.after, self.guard, rollback=True)

    def test_new_records_outside_guard_block_rollback(self):
        self.after['unexpected'] = {'count': 1}
        with self.assertRaises(AssertionError): module.verify_preservation(self.before, self.after, self.guard, rollback=True)

    def test_additional_guard_rows_block_rollback(self):
        self.after[module.GUARD]['count'] = 2
        with self.assertRaises(AssertionError): module.verify_preservation(self.before, self.after, self.guard, rollback=True)

    def test_existing_guard_not_claimed(self):
        with self.assertRaises(AssertionError): module.verify_preservation(self.after, self.after, self.guard, rollback=True)

    def test_changed_guard_fields_block_rollback(self):
        with self.assertRaises(AssertionError): module.verify_preservation(self.before, self.after, {**self.guard, 'owner': 'other'}, rollback=True)


if __name__ == '__main__': unittest.main()
