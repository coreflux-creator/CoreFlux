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

This proves a logical database backup and restoration, not the remaining storage
conversion or a production recovery guarantee. The installed Quanta runtime is
still `12ad6396`, Mongo is still standalone and operational time ownership remains
disabled. Core, PurePay, PurePayroll, production and the Arabella pilot were not
changed by this rehearsal.

The next storage step is to convert the existing isolated Mongo service to a
single-member replica set while preserving its named data volume. Before that
change, verify the current source still matches this recovery point or take a
fresh backup. Drain all pilot writers, preserve the original Compose settings,
verify the replacement uses the same volume and isolated network, and test both
commit and rollback in another unserved namespace. If conversion fails, return
the same service to its original standalone command and restart the unchanged
web process; do not overwrite changed business data with an older dump.

Only after storage and source preservation pass should the accepted coordinator
runtime be installed and tested on the hosted pilot. Historical associations,
explicit time permissions, synthetic lifecycle and full financial-result
reconciliation remain separate release requirements. No real payroll, payment,
financial posting or production cutover is authorized by this evidence.
