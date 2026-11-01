"""Actual outside-host encrypted backup/readback and explicit native recovery transfer."""
from pathlib import Path

from core_database_archive import ArchiveWriter, ArchiveReader


def capture(controller, path: Path, guard_sha256: str, *, cipher=None) -> dict:
    writer = ArchiveWriter(path, cipher)
    try:
        native = controller.call(1004, 'capture-database-stream', {'guard_sha256': guard_sha256},
                                 capture_sink=writer.write, timeout=3600)
        archive = writer.finish(native)
        return native | archive | {'restoring_database_user': 'grcudkpvcd'}
    finally:
        writer.close()


def restore(controller, path: Path, expected: dict, guard_sha256: str, expectation_sha256: str, *, cipher=None) -> dict:
    # Authenticate the entire archive before the first recovery mutation. The native
    # owner independently requires the already established, matching recovery intent.
    with ArchiveReader(path, cipher) as reader:
        reader.verify(expected)
        binding = {'guard_sha256': guard_sha256, 'expectation_sha256': expectation_sha256}
        empty = controller.call(1004, 'empty-recovery-database', binding, timeout=3600)
        restored = controller.call(1004, 'restore-database-stream', binding, restore_source=reader, timeout=3600)
        return {'empty_target': empty, 'native_restore': restored,
                'requires_independent_native_fingerprint_confirmation': True}
