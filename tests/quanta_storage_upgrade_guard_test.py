"""Configuration boundary checks for the isolated service conversion."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('upgrade', Path(__file__).resolve().parents[1] / 'deploy/upgrade_thunderhawk_quanta_storage.py')
upgrade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)

SERVICE = "  quanta-pilot-mongo:\n    image: mongo:7.0\n    command: [mongod, --bind_ip_all, --wiredTigerCacheSizeGB, '0.25']\n    mem_limit: 640m\n    volumes: [quanta-db:/data/db]\n    networks: [quanta-private]\n"


class StorageConfigBoundary(unittest.TestCase):
    def test_changes_only_one_service_command(self):
        other = SERVICE.replace('quanta-pilot-mongo:', 'other-mongo:')
        before = 'services:\n' + other + SERVICE + 'networks:\n  quanta-private:\n    internal: true\n'
        after = upgrade.candidate_compose(before)
        self.assertTrue(after.startswith('services:\n' + other))
        self.assertEqual(after.splitlines()[-3:], before.splitlines()[-3:])
        changes = [(a, b) for a, b in zip(before.splitlines(), after.splitlines()) if a != b]
        self.assertEqual(len(changes), 1)
        self.assertIn('corepilotquanta', changes[0][1])
        self.assertIn('"--oplogSize", "128"', changes[0][1])

    def test_missing_service_refused(self):
        with self.assertRaises(ValueError):
            upgrade.candidate_compose('services:\n' + SERVICE.replace('quanta-pilot-mongo:', 'other-mongo:'))

    def test_ambiguous_service_refused(self):
        with self.assertRaises(ValueError):
            upgrade.candidate_compose('services:\n' + SERVICE + SERVICE)

    def test_changed_command_refused(self):
        with self.assertRaises(ValueError):
            upgrade.candidate_compose('services:\n' + SERVICE.replace("'0.25'", "'0.5'"))

    def test_already_converted_refused(self):
        after = upgrade.candidate_compose('services:\n' + SERVICE)
        with self.assertRaises(ValueError):
            upgrade.candidate_compose(after)


if __name__ == '__main__':
    unittest.main()
