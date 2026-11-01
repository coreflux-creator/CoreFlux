"""Authenticate real multi-frame archives and reject corruption before native restore."""
import hashlib
import os
from pathlib import Path
import secrets
import struct
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
from core_database_archive import ArchiveWriter, ArchiveReader, Dpapi, AesGcm, CHUNK

directory = Path(tempfile.mkdtemp(prefix='core-private-archive-test-'))
cipher = Dpapi() if os.name == 'nt' else AesGcm(secrets.token_bytes(32))
checks = 0
for label, raw in [('empty', b''), ('binary', bytes(range(256)) * 9001), ('random', secrets.token_bytes(CHUNK + 137))]:
    path = directory / (label + '.encrypted')
    writer = ArchiveWriter(path, cipher)
    try:
        for offset in range(0, len(raw), 8191):
            writer.write(raw[offset:offset + 8191])
        receipt = writer.finish({'sql_bytes': len(raw), 'sql_sha256': hashlib.sha256(raw).hexdigest()})
    finally:
        writer.close()
    with ArchiveReader(path, cipher) as reader:
        reader.verify(receipt)
        restored = bytearray()
        while chunk := reader.read(524288):
            restored.extend(chunk)
        assert restored == raw
    checks += 1

original = (directory / 'binary.encrypted').read_bytes()
header = len(cipher.magic) + 16
first_end = header + 4 + struct.unpack('>I', original[header:header + 4])[0]
second_end = first_end + 4 + struct.unpack('>I', original[first_end:first_end + 4])[0]
variants = {
    'truncated': original[:-17],
    'tampered': original[:-20] + bytes([original[-20] ^ 1]) + original[-19:],
    'appended': original + b'extra',
    'duplicated': original[:first_end] + original[header:first_end] + original[first_end:],
    'reordered': original[:header] + original[first_end:second_end] + original[header:first_end] + original[second_end:],
    'missing': original[:header] + original[first_end:],
    'oversized': original[:header] + struct.pack('>I', CHUNK * 3) + original[header + 4:],
}
for label, data in variants.items():
    path = directory / (label + '.encrypted'); path.write_bytes(data)
    try:
        with ArchiveReader(path, cipher) as reader:
            while reader.read(CHUNK):
                pass
    except Exception:
        checks += 1
    else:
        raise AssertionError('Damaged archive accepted: ' + label)
path = directory / 'incomplete.encrypted'
writer = ArchiveWriter(path, cipher); writer.write(b'incomplete'); writer.close()
try:
    with ArchiveReader(path, cipher) as reader:
        reader.read(CHUNK)
except ValueError:
    checks += 1
else:
    raise AssertionError('Interrupted archive accepted')
print(f'PASS {checks} real {cipher.encryption} archive checks; synthetic bytes only')
