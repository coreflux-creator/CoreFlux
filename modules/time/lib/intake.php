<?php
/**
 * Time — Email intake helpers (shared between the poll path and the
 * webhook path).  Both paths produce a `time_intake_events` row, optionally
 * register attachments to S3, then call AI extraction in bulk mode.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/StorageService.php';
require_once __DIR__ . '/../../../core/storage_register.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/time.php';

/**
 * Look up a foreman/sender by their from-address. Returns:
 *   ['user_id' => int|null, 'person_id' => int|null, 'person_name' => string|null]
 * Match path: users.email → people.email_primary inside the tenant.
 */
function timeIntakeResolveSenderContext(int $tenantId, string $fromAddress): array
{
    $out = ['user_id' => null, 'person_id' => null, 'person_name' => null];
    if ($fromAddress === '') return $out;
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id,
                p.id AS person_id,
                TRIM(CONCAT_WS(' ', p.first_name, p.last_name)) AS person_name
           FROM users u
           JOIN user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :t
           LEFT JOIN people p
                  ON p.tenant_id = :t
                 AND p.deleted_at IS NULL
                 AND LOWER(p.email_primary) = LOWER(u.email)
          WHERE LOWER(u.email) = LOWER(:em)
          LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'em' => $fromAddress]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        $out['user_id']     = (int) $row['user_id'];
        $out['person_id']   = $row['person_id'] ? (int) $row['person_id'] : null;
        $out['person_name'] = $row['person_name'] ?: null;
    }
    return $out;
}

/**
 * Resolve a from-address to a user in the tenant. Returns user_id or null.
 */
function timeIntakeResolveSender(int $tenantId, string $fromAddress): ?int
{
    return timeIntakeResolveSenderContext($tenantId, $fromAddress)['user_id'];
}

/**
 * Enrich an AI-extracted draft with sender context so the review UI can
 * "1-click confirm" for known foremen.
 *
 *   - Prepends the sender to match_candidates of the lone person-card (or
 *     of any group whose person_name fuzzy-matches the sender).
 *   - Pre-fills `placement_id_hint` on each line when:
 *       * the sender has exactly 1 active placement, OR
 *       * the line's `project` text fuzzy-matches a placement title.
 */
function timeIntakeEnrichDraftWithSender(\PDO $pdo, int $tenantId, array $draft, array $sender): array
{
    if (empty($sender['person_id'])) return $draft;
    $personId   = (int) $sender['person_id'];
    $personName = (string) ($sender['person_name'] ?? '');

    $stmt = $pdo->prepare(
        "SELECT id, title, end_client_name FROM placements
          WHERE tenant_id = :t AND person_id = :p AND status = 'active'
          ORDER BY id DESC LIMIT 50"
    );
    $stmt->execute(['t' => $tenantId, 'p' => $personId]);
    $placements = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (empty($placements)) return $draft;

    $apply = function (array $group) use ($personId, $personName, $placements) {
        // Prepend sender to match_candidates so the picker defaults to the foreman.
        if (!isset($group['match_candidates']) || !is_array($group['match_candidates'])) {
            $group['match_candidates'] = [];
        }
        $alreadyHas = false;
        foreach ($group['match_candidates'] as $c) {
            if ((int) ($c['id'] ?? 0) === $personId) { $alreadyHas = true; break; }
        }
        if (!$alreadyHas) {
            array_unshift($group['match_candidates'], [
                'id'    => $personId,
                'name'  => $personName,
                'email' => null,
                'auto_resolved_from_sender' => true,
            ]);
        }
        // Pre-fill placement_id_hint per line.
        $singlePlacement = count($placements) === 1 ? (int) $placements[0]['id'] : null;
        if (is_array($group['lines'] ?? null)) {
            foreach ($group['lines'] as &$ln) {
                if (!empty($ln['placement_id_hint'])) continue;
                if ($singlePlacement) {
                    $ln['placement_id_hint'] = $singlePlacement;
                    continue;
                }
                $proj = strtolower((string) ($ln['project'] ?? ''));
                if ($proj === '') continue;
                foreach ($placements as $p) {
                    $hay = strtolower(($p['title'] ?? '') . ' ' . ($p['end_client_name'] ?? ''));
                    if ($hay !== '' && str_contains($hay, $proj)) {
                        $ln['placement_id_hint'] = (int) $p['id'];
                        break;
                    }
                }
            }
            unset($ln);
        }
        return $group;
    };

    if (isset($draft['people']) && is_array($draft['people'])) {
        $count = count($draft['people']);
        $draft['people'] = array_map(function ($g) use ($apply, $count, $personName) {
            // Apply auto-resolve only when group is the lone person OR the
            // extracted name fuzzy-matches the sender. Other groups are left
            // alone (they'll need manual person mapping).
            if ($count === 1) return $apply($g);
            $extracted = strtolower(trim((string) ($g['person_name'] ?? '')));
            $senderLow = strtolower($personName);
            if ($extracted !== '' && $senderLow !== '' && (
                $extracted === $senderLow || str_contains($extracted, $senderLow) || str_contains($senderLow, $extracted)
            )) {
                return $apply($g);
            }
            return $g;
        }, $draft['people']);
        $draft['sender_resolved'] = true;
        $draft['sender_person_id'] = $personId;
        $draft['sender_person_name'] = $personName;
    } elseif (isset($draft['lines']) && is_array($draft['lines'])) {
        // Single mode draft — wrap as a one-group apply.
        $g = $apply(['person_name' => $personName, 'lines' => $draft['lines'], 'match_candidates' => []]);
        $draft['lines'] = $g['lines'];
        $draft['sender_resolved']  = true;
        $draft['sender_person_id'] = $personId;
        $draft['sender_person_name'] = $personName;
    }
    return $draft;
}

/**
 * Create a `time_intake_events` row from a normalised inbound message.
 *
 *   $msg = [
 *     'source'              => 'poll_m365' | 'webhook_sendgrid' | ...
 *     'folder_id'           => ?int,
 *     'connection_id'       => ?int,
 *     'provider_message_id' => ?string,
 *     'from_address'        => string,
 *     'from_name'           => ?string,
 *     'subject'             => ?string,
 *     'body_preview'        => ?string,
 *     'received_at'         => ?string (ISO),
 *     'has_attachments'     => bool,
 *     'attachment_count'    => int,
 *     'raw_meta'            => array,
 *   ]
 *
 * Idempotent on (tenant_id, source, provider_message_id) — re-poll is safe.
 * Returns the intake event id (existing or new).
 */
function timeIntakeRecordEvent(int $tenantId, array $msg): int
{
    $pdo = getDB();
    if (!empty($msg['provider_message_id'])) {
        $stmt = $pdo->prepare(
            'SELECT id FROM time_intake_events
              WHERE tenant_id = :t AND source = :s AND provider_message_id = :pm LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 's' => $msg['source'], 'pm' => $msg['provider_message_id']]);
        $existing = $stmt->fetchColumn();
        if ($existing) return (int) $existing;
    }

    $senderId = timeIntakeResolveSender($tenantId, (string) ($msg['from_address'] ?? ''));
    $pdo->prepare(
        'INSERT INTO time_intake_events
            (tenant_id, source, folder_id, connection_id, provider_message_id,
             from_address, from_name, sender_user_id, subject, body_preview,
             received_at, has_attachments, attachment_count, status, raw_meta_json)
         VALUES
            (:t, :s, :fid, :cid, :pm, :fa, :fn, :su, :sj, :bp,
             :rcv, :ha, :ac, "received", :rm)'
    )->execute([
        't'   => $tenantId,
        's'   => (string) $msg['source'],
        'fid' => $msg['folder_id'] ?? null,
        'cid' => $msg['connection_id'] ?? null,
        'pm'  => $msg['provider_message_id'] ?? null,
        'fa'  => $msg['from_address'] ?? null,
        'fn'  => $msg['from_name'] ?? null,
        'su'  => $senderId,
        'sj'  => $msg['subject'] ?? null,
        'bp'  => isset($msg['body_preview']) ? mb_substr((string) $msg['body_preview'], 0, 1000) : null,
        'rcv' => $msg['received_at'] ?? null,
        'ha'  => !empty($msg['has_attachments']) ? 1 : 0,
        'ac'  => (int) ($msg['attachment_count'] ?? 0),
        'rm'  => isset($msg['raw_meta']) ? json_encode($msg['raw_meta']) : null,
    ]);
    $intakeId = (int) $pdo->lastInsertId();

    // Mark seen in the dedupe ledger if this came from a polled folder.
    if (!empty($msg['folder_id']) && !empty($msg['provider_message_id'])) {
        $pdo->prepare(
            'INSERT IGNORE INTO mail_messages_seen
                (tenant_id, folder_id, provider_message_id, intake_event_ref)
             VALUES (:t, :f, :pm, :ref)'
        )->execute([
            't'   => $tenantId,
            'f'   => (int) $msg['folder_id'],
            'pm'  => (string) $msg['provider_message_id'],
            'ref' => 'time_intake_events#' . $intakeId,
        ]);
    }

    timeAudit('time.intake.received', [
        'intake_id'       => $intakeId,
        'source'          => $msg['source'],
        'from'            => $msg['from_address'] ?? null,
        'attachment_count'=> (int) ($msg['attachment_count'] ?? 0),
    ], $intakeId);

    return $intakeId;
}

/**
 * Take an array of pre-fetched attachments (each `{file_name, mime_type,
 * content}`), upload to S3, register storage objects, create
 * `time_uploaded_documents` rows, run bulk AI extract, mark intake row
 * as `extracted`. Returns array of created document ids.
 */
function timeIntakeIngestAttachments(int $tenantId, int $intakeId, array $attachments, ?int $uploadedByUserId = null): array
{
    $pdo = getDB();
    $svc = \Core\StorageService::getInstance();

    // Resolve sender context once per intake — used to enrich each draft.
    $senderCtx = ['user_id' => null, 'person_id' => null, 'person_name' => null];
    $intakeRow = $pdo->prepare('SELECT from_address FROM time_intake_events WHERE id = :id AND tenant_id = :t');
    $intakeRow->execute(['id' => $intakeId, 't' => $tenantId]);
    $fromAddress = (string) ($intakeRow->fetchColumn() ?: '');
    if ($fromAddress !== '') {
        $senderCtx = timeIntakeResolveSenderContext($tenantId, $fromAddress);
    }
    if (!$uploadedByUserId && !empty($senderCtx['user_id'])) $uploadedByUserId = (int) $senderCtx['user_id'];

    $docIds = [];
    foreach ($attachments as $att) {
        $name = (string) ($att['file_name'] ?? 'timesheet.pdf');
        $mime = (string) ($att['mime_type'] ?? 'application/pdf');
        if (!timeIntakeIsTimesheetAttachment($name, $mime)) continue; // skip .ics / signature gifs

        // Upload via storage driver; accepts either raw bytes or a fetch URL.
        $key = $svc->build_key('time', $tenantId, 'intake_' . $intakeId, $uploadedByUserId ?? 0, $name);
        if (isset($att['content'])) {
            $svc->put_object($key, (string) $att['content'], $mime);
        } elseif (!empty($att['fetch_url'])) {
            $bytes = @file_get_contents($att['fetch_url']);
            if ($bytes === false) continue;
            $svc->put_object($key, $bytes, $mime);
        } else {
            continue;
        }

        $storageObjectId = registerStorageObject([
            'tenant_id' => $tenantId,
            's3_key'    => $key,
            'file_name' => $name,
            'kind'      => 'time_intake_upload',
        ]);

        $pdo->prepare(
            'INSERT INTO time_uploaded_documents
                (tenant_id, uploaded_by_user_id, file_name, storage_object_id,
                 storage_key, mime_type, extraction_status, created_at)
             VALUES (:t, :u, :fn, :so, :sk, :mt, "pending", NOW())'
        )->execute([
            't' => $tenantId, 'u' => $uploadedByUserId ?? 0,
            'fn' => $name, 'so' => $storageObjectId,
            'sk' => $key,   'mt' => $mime,
        ]);
        $docId = (int) $pdo->lastInsertId();
        $docIds[] = $docId;

        // AI extract — bulk mode (intake usually = foreman log).
        try {
            $signed = $svc->get_signed_url($key);
            $schema = '{"week_ending":string|null,'
                . '"people":[{"person_name":string,"lines":['
                . '{"work_date":string,"project":string|null,"client":string|null,'
                . '"category":"regular_billable"|"regular_nonbillable"|"OT_billable"|"OT_nonbillable"|"holiday"|"vacation"|"sick"|"bereavement"|"unpaid_leave"|null,'
                . '"hours":number,"description":string|null}]}]}';
            $res = aiExtract([
                'feature_key' => 'time.timesheet.from_intake',
                'instruction' => 'Extract a multi-person paper or PDF timesheet (foreman daily log / crew sign-in). Group rows by person_name. work_date MUST be ISO YYYY-MM-DD.',
                'schema_hint' => $schema,
                'images'      => [['url' => $signed, 'mime' => $mime]],
            ]);
            $draft = $res['data'] ?? [];
            // Pre-resolve each person_name against people directory.
            if (function_exists('timeUploadResolvePeople') && !empty($draft['people'])) {
                $draft['people'] = timeUploadResolvePeople($pdo, $tenantId, $draft['people']);
            }
            // Auto-fill sender context (person + placement hints) if the
            // foreman's email maps to a known user/person.
            if (!empty($senderCtx['person_id'])) {
                $draft = timeIntakeEnrichDraftWithSender($pdo, $tenantId, $draft, $senderCtx);
            }
            $allLines = [];
            foreach (($draft['people'] ?? []) as $p) {
                if (is_array($p['lines'] ?? null)) foreach ($p['lines'] as $ln) $allLines[] = $ln;
            }
            $confidence = function_exists('timeUploadConfidence') ? timeUploadConfidence($allLines) : 0.0;
            $pdo->prepare(
                'UPDATE time_uploaded_documents
                    SET extraction_status = "extracted",
                        ai_extracted_json = :j, ai_confidence = :c, ai_model = :m
                  WHERE id = :id'
            )->execute([
                'j' => json_encode($draft), 'c' => $confidence,
                'm' => $res['model'] ?? null, 'id' => $docId,
            ]);
        } catch (\Throwable $e) {
            $pdo->prepare(
                'UPDATE time_uploaded_documents
                    SET extraction_status = "failed", ai_error = :e WHERE id = :id'
            )->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $docId]);
        }
    }

    $finalStatus = $docIds ? 'extracted' : 'failed';
    $pdo->prepare(
        'UPDATE time_intake_events
            SET status = :s, processed_at = NOW(),
                upload_document_ids_json = :ids
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's'   => $finalStatus,
        'ids' => json_encode($docIds),
        'id'  => $intakeId,
        't'   => $tenantId,
    ]);

    timeAudit('time.intake.parsed', ['intake_id' => $intakeId, 'document_ids' => $docIds], $intakeId);
    return $docIds;
}

/**
 * Filter out signature gifs / calendar invites. Accept PDF + images only.
 */
function timeIntakeIsTimesheetAttachment(string $fileName, string $mime): bool
{
    if (str_starts_with(strtolower($mime), 'image/'))                           return true;
    if (in_array(strtolower($mime), ['application/pdf', 'application/x-pdf'])) return true;
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf','png','jpg','jpeg','heic','heif','webp','tiff'], true);
}

/**
 * Verify HMAC on a webhook request. Returns true if valid OR if no secret
 * is configured (for dev). Provider-specific signature header names handled
 * by callers.
 */
function timeIntakeVerifyWebhookHmac(string $secret, string $signature, string $rawBody): bool
{
    if ($secret === '') return true;
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signature);
}

/**
 * Look up the tenant from a SendGrid Inbound Parse `to` header.
 *
 * Convention: `time+t{TENANT_ID}-{slug}@inbound.example.com` OR a
 * tenant-specific `time_intake_email_address` exact match.
 */
function timeIntakeTenantFromAddress(string $toAddress): ?int
{
    $pdo = getDB();
    // Try exact match.
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE LOWER(time_intake_email_address) = LOWER(:a) LIMIT 1');
    $stmt->execute(['a' => $toAddress]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;
    // Try `time+t{ID}-...@...` plus-addressing convention.
    if (preg_match('/^[^+]+\+t(\d+)/', $toAddress, $m)) {
        $tenantId = (int) $m[1];
        $stmt = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $tenantId]);
        if ($stmt->fetchColumn()) return $tenantId;
    }
    return null;
}
