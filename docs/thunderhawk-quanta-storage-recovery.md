# Quanta pilot storage recovery

On November 11, the existing isolated Quanta pilot database was backed up after
briefly draining its web process. The same unchanged application was restarted
before the restore rehearsal. No new container or externally accessible service
was created. The restore used a new unserved database on the existing isolated
Mongo service; that temporary namespace was removed after verification.

All 66 collections, 1,324 records and their indexes matched the consistent source
snapshot. The original 12 time entries, application files and other services
were preserved. An independent read verified the archive fingerprint, restored
snapshot, private permissions, unchanged 179 runtime files, temporary namespace
removal and healthy web service with background work held. The 90,126-byte archive
stays in the existing root-private pilot directory; no database contents or keys
are included in this repository.

The first attempt stopped before creating an archive because the mongosh cursor
was not synchronously iterable. The web service recovered. The corrected reader
was tested read-only before the successful second attempt. The receipt is
`deploy/thunderhawk-quanta-storage-backup-20261111.json`; the guarded reusable
helper is `deploy/backup_thunderhawk_quanta_storage.py`.

This proves a logical database backup and restoration, not a production recovery
guarantee. The original backup was taken while Mongo was standalone. The storage
conversion recorded below subsequently passed. The installed application remains
`12ad6396` and operational time ownership remains disabled.

## Transaction storage conversion, November 11

`upgrade_thunderhawk_quanta_storage.py` verified that the original database still
matched the restored backup, then drained only Quanta's web process. It converted
the existing Mongo service into single-member replica set `corepilotquanta`,
using the same image, named data volume, private internal network and 640 MiB
memory limit. Its bounded oplog is 128 MiB. No database port was published and no
additional service was created. Mongo's container was recreated, as expected.

The procedure actually returned the service to its original standalone command,
verified every original record and index, and then returned it to the replica
set. The existing unchanged web application restarted successfully. Through its
installed Python driver, an unserved temporary database proved that two writes
were invisible before commit, visible after commit, and unchanged after an
aborted update/delete transaction. That temporary database was removed.

All 66 collections, 1,324 records, 12 time entries and 179 application files were
preserved. Other service identities and start times were unchanged. A separate
read-only verifier rechecked the full database snapshot, live primary, internal
network, original volume, absence of temporary databases and healthy held web
runtime. The receipt is `deploy/thunderhawk-quanta-storage-upgrade-20261111.json`.

The original Compose file is backed up in the private receipt directory. Exactly
one command line in its Quanta Mongo service was changed; the resolved Compose
configuration differs only at that command. This deliberately updates the normal
Compose file instead of relying on an optional overlay that a later restart
could omit. The HTTPS Compose file and web environment were unchanged. The
conversion follows [MongoDB's documented conversion procedure](https://www.mongodb.com/docs/v7.0/tutorial/convert-standalone-to-replica-set/).
Five local boundary checks cover preserving unrelated services and refusing a
missing, duplicate, changed or already-converted target.

This is a single-host pilot, not redundant production storage. Future rollback
must preserve newer writes and the application's coordination requirement; the
pre-conversion dump must never be blindly restored over changed business data.
The one-time converter intentionally refuses the now-upgraded host on replay.

Storage and source preservation have passed. The accepted coordinator runtime
still needs installation and testing on the hosted pilot. Historical associations,
explicit time permissions, synthetic lifecycle and full financial-result
reconciliation remain separate release requirements. No real payroll, payment,
financial posting or production cutover is authorized by this evidence.
