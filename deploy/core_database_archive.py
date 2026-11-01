"""Bounded authenticated SQL archives. Production Windows keys remain user-bound in DPAPI."""
from __future__ import annotations

import ctypes
from ctypes import wintypes
import hashlib
import os
from pathlib import Path
import secrets
import struct
import zlib

CHUNK = 1024 * 1024


class Dpapi:
    encryption = 'windows-dpapi-chunked-v1'
    magic = b'CoreFlux-DPAPI-archive-v1\x00'

    def protect(self, value: bytes, context: bytes, decrypt: bool = False) -> bytes:
        if os.name != 'nt':
            raise RuntimeError('Windows user-bound archive requires Windows')

        class Blob(ctypes.Structure):
            _fields_ = [('size', wintypes.DWORD), ('data', ctypes.POINTER(ctypes.c_ubyte))]

        source_buffer = (ctypes.c_ubyte * len(value)).from_buffer_copy(value)
        context_buffer = (ctypes.c_ubyte * len(context)).from_buffer_copy(context)
        source, entropy, result = Blob(len(value), source_buffer), Blob(len(context), context_buffer), Blob()
        library = ctypes.WinDLL('crypt32', use_last_error=True)
        function = library.CryptUnprotectData if decrypt else library.CryptProtectData
        function.argtypes = [ctypes.POINTER(Blob), ctypes.c_void_p, ctypes.POINTER(Blob), ctypes.c_void_p,
                             ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(Blob)]
        function.restype = wintypes.BOOL
        if not function(ctypes.byref(source), None, ctypes.byref(entropy), None, None, 1, ctypes.byref(result)):
            raise OSError(ctypes.get_last_error(), 'Windows private data protection failed')
        try:
            return ctypes.string_at(result.data, result.size)
        finally:
            kernel = ctypes.WinDLL('kernel32', use_last_error=True)
            kernel.LocalFree.argtypes = [ctypes.c_void_p]
            kernel.LocalFree.restype = ctypes.c_void_p
            kernel.LocalFree(result.data)


class AesGcm:
    """Explicit outside-host Linux key. Never placed in a native owner configuration."""
    encryption = 'aes-256-gcm-chunked-v1'
    magic = b'CoreFlux-AESGCM-archive-v1\x00'

    def __init__(self, key: bytes):
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        if len(key) != 32:
            raise ValueError('AES-256 archive key required')
        self.cipher = AESGCM(key)

    def protect(self, value: bytes, context: bytes, decrypt: bool = False) -> bytes:
        if decrypt:
            return self.cipher.decrypt(value[:12], value[12:], context)
        nonce = secrets.token_bytes(12)
        return nonce + self.cipher.encrypt(nonce, value, context)


class ArchiveWriter:
    def __init__(self, path: Path, cipher=None):
        self.cipher = cipher or Dpapi()
        self.path = Path(path)
        self.stream = os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb')
        self.context = self.cipher.magic + secrets.token_bytes(16)
        self.stream.write(self.context)
        self.previous = bytes(32)
        self.digest = hashlib.sha256()
        self.count = self.total = 0
        self.buffer = bytearray()

    def _frame(self, payload: bytes) -> None:
        encrypted = self.cipher.protect(payload, self.context)
        self.stream.write(struct.pack('>I', len(encrypted)))
        self.stream.write(encrypted)
        self.previous = hashlib.sha256(encrypted).digest()

    def _chunk(self, raw: bytes) -> None:
        self.digest.update(raw)
        self.total += len(raw)
        self._frame(struct.pack('>Q32sI', self.count, self.previous, len(raw)) + zlib.compress(raw, 6))
        self.count += 1

    def write(self, raw: bytes) -> None:
        if self.stream.closed:
            raise ValueError('Archive is closed')
        self.buffer.extend(raw)
        while len(self.buffer) >= CHUNK:
            self._chunk(bytes(self.buffer[:CHUNK]))
            del self.buffer[:CHUNK]

    def finish(self, expected: dict) -> dict:
        if self.buffer:
            self._chunk(bytes(self.buffer)); self.buffer.clear()
        if self.total != expected['sql_bytes'] or self.digest.hexdigest() != expected['sql_sha256']:
            raise ValueError('Native SQL stream differs from encrypted input')
        self._frame(struct.pack('>Q32sIQ32s', self.count, self.previous, 0, self.total, self.digest.digest()))
        self.stream.flush(); os.fsync(self.stream.fileno()); self.stream.close()
        with ArchiveReader(self.path, self.cipher) as reader:
            while reader.read(CHUNK):
                pass
            if reader.total != self.total or reader.digest.hexdigest() != self.digest.hexdigest():
                raise ValueError('Decrypted archive readback differs from native SQL')
        with self.path.open('rb') as source:
            archive_hash = hashlib.file_digest(source, 'sha256').hexdigest()
        return {'encryption': self.cipher.encryption, 'sql_bytes': self.total,
                'sql_sha256': self.digest.hexdigest(), 'readback_sql_sha256': reader.digest.hexdigest(),
                'archive_bytes': self.path.stat().st_size, 'archive_sha256': archive_hash}

    def close(self) -> None:
        self.stream.close()  # Failed streams deliberately have no authenticated completion footer.


class ArchiveReader:
    def __init__(self, path: Path, cipher=None):
        self.cipher = cipher or Dpapi()
        self.stream = Path(path).open('rb')
        self.rewind()

    def rewind(self) -> None:
        self.stream.seek(0)
        self.context = self.stream.read(len(self.cipher.magic) + 16)
        if len(self.context) != len(self.cipher.magic) + 16 or not self.context.startswith(self.cipher.magic):
            raise ValueError('Archive header differs')
        self.previous = bytes(32)
        self.digest = hashlib.sha256()
        self.count = self.total = 0
        self.buffer = b''
        self.eof = False

    def _next(self) -> bytes:
        size = self.stream.read(4)
        if len(size) != 4:
            raise ValueError('Archive completion footer is missing')
        size = struct.unpack('>I', size)[0]
        if not 1 <= size <= 2 * CHUNK:
            raise ValueError('Unbounded archive frame refused')
        encrypted = self.stream.read(size)
        if len(encrypted) != size:
            raise ValueError('Truncated archive frame')
        payload = self.cipher.protect(encrypted, self.context, decrypt=True)
        if len(payload) < 44:
            raise ValueError('Incomplete authenticated frame')
        index, previous, size = struct.unpack('>Q32sI', payload[:44])
        if index != self.count or previous != self.previous:
            raise ValueError('Archive order differs')
        if size == 0:
            if len(payload) != 84:
                raise ValueError('Invalid archive footer')
            total, digest = struct.unpack('>Q32s', payload[44:])
            if total != self.total or digest != self.digest.digest() or self.stream.read(1):
                raise ValueError('Archive final integrity differs')
            self.eof = True
            return b''
        if size > CHUNK:
            raise ValueError('Unbounded archive plaintext refused')
        decoder = zlib.decompressobj()
        raw = decoder.decompress(payload[44:], CHUNK + 1)
        if len(raw) != size or not decoder.eof or decoder.unused_data:
            raise ValueError('Archive compression integrity differs')
        self.digest.update(raw); self.total += len(raw); self.count += 1
        self.previous = hashlib.sha256(encrypted).digest()
        return raw

    def read(self, size: int) -> bytes:
        if not 1 <= size <= CHUNK:
            raise ValueError('Bounded archive read required')
        while len(self.buffer) < size and not self.eof:
            self.buffer += self._next()
        result, self.buffer = self.buffer[:size], self.buffer[size:]
        return result

    def verify(self, expected: dict) -> None:
        if expected.get('encryption') != self.cipher.encryption or os.fstat(self.stream.fileno()).st_size != expected['archive_bytes']:
            raise ValueError('Archive format or size differs from the paired backup')
        while self.read(CHUNK):
            pass
        if self.total != expected['sql_bytes'] or self.digest.hexdigest() != expected['sql_sha256']:
            raise ValueError('Archive does not contain the paired native SQL')
        self.stream.seek(0)
        if hashlib.file_digest(self.stream, 'sha256').hexdigest() != expected['archive_sha256']:
            raise ValueError('Encrypted backup archive differs')
        self.rewind()  # Reuse the same open file, avoiding a path replacement between verification and restore.

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.stream.close()
