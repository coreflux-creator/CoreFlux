<?php
/** Extend the actual publication fixture through native Core contact decisions. */
declare(strict_types=1);
define('QUANTA_PROFILE_REVIEW_FIXTURE', true);
require __DIR__ . '/quanta_profile_baseline_mariadb.php';
require_once __DIR__ . '/../core/quanta/profile_review.php';
foreach (coreflux_split_sql_statements(file_get_contents(__DIR__ . '/../core/migrations/186_quanta_profile_decisions.sql')) as $sql) $pdo->exec($sql);
$pdo->exec('ALTER TABLE people ADD updated_at DATETIME NULL');
$n = 0; $mode = 'ok'; $send($preview(108)); $proposals = []; $reads = 0; $readMode = 'ok';
$baselineTransport = $GLOBALS['__quanta_transport'];
$GLOBALS['__quanta_transport'] = static function ($method, $url) use ($baselineTransport, &$proposals, &$reads, &$readMode, $pdo) {
    if (str_ends_with(parse_url($url, PHP_URL_PATH), '/profile-changes')) {
        return ['status' => 200, 'body' => json_encode(['items' => array_map(static fn($p) => ['proposal' => $p, 'outcome' => null], array_values($proposals)), 'has_more' => false, 'next_cursor' => null])];
    }
    if (!str_contains($url, '/profile-changes/')) return $baselineTransport($method, $url);
    $reads++; $id = basename(parse_url($url, PHP_URL_PATH));
    if ($readMode === 'offline') throw new RuntimeException('Synthetic provider unavailable');
    if ($readMode === 'scope_drift') $pdo->exec('INSERT INTO tenant_module_scope VALUES(11,"people","isolated")');
    if ($readMode === 'link_drift') $pdo->exec('UPDATE quanta_worker_links SET worker_id="changed-worker" WHERE tenant_id=11 AND person_id=108');
    if ($readMode === 'core_drift') $pdo->exec('UPDATE people SET first_name="Concurrent Core edit" WHERE id=108');
    $out = isset($proposals[$id]) ? ['proposal' => $proposals[$id], 'outcome' => null] : null;
    return ['status' => $out ? 200 : 404, 'body' => json_encode($out ?? [])];
};
$make = static function (array $fields, ?array $baseline = null) use (&$proposals, &$baselines, $workspace, $instance): string {
    $person = integrationRecordReference($instance, 10, 'person', 'P-9108');
    $baseline ??= $baselines['cfp_' . substr(quantaCoreRemoteId($workspace, $person), 4)];
    $id = 'cpc_' . hash('sha256', 'synthetic-request-' . count($proposals));
    $proposed = array_replace($baseline['profile'], $fields);
    $proposals[$id] = ['contract_version' => QUANTA_PROFILE_CHANGE_VERSION, 'id' => $id, 'tenant_id' => $workspace,
        'worker_id' => 'worker-108', 'person_link_id' => quantaCoreRemoteId($workspace, $person), 'person' => $person,
        'revision' => 1, 'origin' => 'quanta_portal', 'actor' => ['kind' => 'worker', 'user_id' => 'worker-108', 'identity_id' => 'identity-108'],
        'baseline' => $baseline, 'proposed' => $proposed, 'changed_fields' => quantaProfileChangedFields($baseline['profile'], $proposed),
        'request_hash' => hash('sha256', $id), 'created_at' => '2026-11-10T22:00:00Z'];
    return $id;
};
$review = static fn($id, $actor = 7) => quantaProfileReviewPreview(11, $actor, 108, $id);
$apply = static fn($r, $decision = 'accepted', $reason = '') => quantaProfileReviewApply(11, 7, $r['review_token'], $decision, $reason);
$personNow = static fn() => $pdo->query('SELECT * FROM people WHERE id=108')->fetch(PDO::FETCH_ASSOC);
$count = static fn() => (int)$pdo->query('SELECT COUNT(*) FROM quanta_profile_decisions')->fetchColumn();

$id = $make(['phone_primary' => '+1 202 555 0108']);
$pdo->exec('UPDATE people SET last_name="Core staff edit" WHERE id=108');
$r = $review($id); $before = $personNow();
$check($r['comparison']['state'] === 'change' && $personNow() === $before, 'Preview changes no contact records');
putenv('COREFLUX_QUANTA_PROFILE_RETURN');
$check($fails(fn() => $apply($r)) && $count() === 0, 'Application defaults off');
putenv('COREFLUX_QUANTA_PROFILE_RETURN=1');
$check($fails(fn() => quantaProfileReviewApply(11, 8, $r['review_token'], 'accepted', '')), 'Another reviewer cannot use a captured review');
$check($fails(fn() => quantaProfileReviewApply(12, 7, $r['review_token'], 'accepted', '')), 'Another company cannot use a captured review');
$result = $apply($r);
$check($personNow()['phone_primary'] === '+1 202 555 0108' && $personNow()['last_name'] === 'Core staff edit', 'Approval updates native contact and preserves unrelated Core changes');
$check($result['changed_fields'] === ['phone_primary'] && $result['quanta_return_state'] === 'pending' && $count() === 1, 'One mandatory decision and return payload saved with update');
$row = $pdo->query('SELECT * FROM quanta_profile_decisions')->fetch(PDO::FETCH_ASSOC);
$check(!str_contains($row['decision_ct'], '202 555') && !str_contains($row['proposal_ct'], 'example.com'), 'Before/after and source evidence encrypted at rest');
$readCount = $reads; $readMode = 'offline'; $savedPerson = $personNow();
$check($apply($r)['replayed'] === true && $reads === $readCount && $personNow() === $savedPerson && $count() === 1, 'Lost acknowledgement retry needs no provider IO or second update');
$check($fails(fn() => $apply($r, 'rejected', 'Changed my mind')) && $count() === 1, 'Contradictory retry cannot replace the decision');
$check(quantaProfileDigest(quantaProfileReviewStatus(11, 7, $r['review_token'])['result']) === quantaProfileDigest($result), 'Status returns the original committed result');
$readMode = 'ok'; $r2 = $review($id);
$check($apply($r2)['replayed'] === true && $count() === 1, 'A second preview of the same source request still applies once');

$id = $make(['first_name' => 'Requested name', 'phone_primary' => '+1 202 555 0208']); $r = $review($id); $before = $personNow();
$check($r['comparison']['state'] === 'conflict' && $fails(fn() => $apply($r)) && $personNow() === $before && $count() === 1, 'Same-field conflict blocks all changes including otherwise safe name');
$check($fails(fn() => $apply($r, 'rejected', '')), 'Rejection needs a reason');
$rejected = $apply($r, 'rejected', 'Please review the current phone number.');
$check($rejected['decision'] === 'rejected' && $rejected['changed_fields'] === [] && $personNow() === $before && $count() === 2, 'Rejection preserves contact values and records reason');

$id = $make(['phone_primary' => '+1 202 555 0108']); $r = $review($id); $before = $personNow(); $result = $apply($r);
$check($r['comparison']['state'] === 'already_current' && $result['changed_fields'] === [] && $personNow() === $before, 'Already-current approval records a decision without rewriting contact');
$id = $make(['email_primary' => 'updated-contact@example.com']); $r = $review($id); $before = $personNow();
$pdo->exec('UPDATE people SET first_name="Core edit after review" WHERE id=108');
$check($fails(fn() => $apply($r)) && $count() === 3 && $personNow()['email_primary'] === $before['email_primary'], 'Core edit after preview requires a fresh review');
$pdo->exec('UPDATE people SET first_name="Synthetic" WHERE id=108');
$r = $review($id); $proposals[$id]['proposed']['email_primary'] = 'tampered@example.com';
$check($fails(fn() => $apply($r)) && $count() === 3, 'Changed provider proposal cannot reuse a review');
$proposals[$id]['proposed']['email_primary'] = 'updated-contact@example.com';
$r = $review($id); $readMode = 'scope_drift';
$check($fails(fn() => $apply($r)) && $count() === 3, 'Directory change during provider read stops the decision');
$pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11'); $readMode = 'ok';
$r = $review($id); $readMode = 'link_drift';
$check($fails(fn() => $apply($r)) && $count() === 3, 'Worker reassignment during provider read stops the decision');
$pdo->exec('UPDATE quanta_worker_links SET worker_id="worker-108" WHERE tenant_id=11 AND person_id=108'); $readMode = 'ok';
$r = $review($id); $pdo->exec('UPDATE people SET status="inactive" WHERE id=108');
$check($fails(fn() => $apply($r)) && $count() === 3, 'Offboarding invalidates outstanding approval');
$pdo->exec('UPDATE people SET status="active" WHERE id=108');
$r = $review($id); $pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=11');
$check($fails(fn() => $apply($r)) && $count() === 3, 'Connection rotation invalidates an outstanding review');
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision-1 WHERE tenant_id=11');
$r = $review($id); $before = $personNow();
$pdo->exec("CREATE TRIGGER fail_profile_receipt BEFORE INSERT ON quanta_profile_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic receipt failure'");
$check($fails(fn() => $apply($r)) && $personNow() === $before && $count() === 3, 'Receipt insertion failure rolls back native contact update');
$pdo->exec('DROP TRIGGER fail_profile_receipt');
$check($apply($r)['after']['email_primary'] === 'updated-contact@example.com' && $count() === 4, 'Retry after a fully rolled-back failure succeeds once');
$check($personNow()['id'] === $before['id'] && $personNow()['status'] === $before['status']
    && quantaProfileLinkedWorker($pdo, 11, 108) === 'worker-108', 'Person and provider IDs and active status preserved');

$id = $make(['email_primary' => 'worker107@example.com']); $r = $review($id);
// This request conflicts with the accepted email first; use a fresh proven publication.
$send($preview(108)); $id = $make(['email_primary' => 'worker107@example.com']); $r = $review($id);
$check($fails(fn() => $apply($r)) && $count() === 4, 'Another person’s existing email cannot be assigned');
$id = $make(['phone_primary' => null]); $r = $review($id); $result = $apply($r);
$check($personNow()['phone_primary'] === null && $result['changed_fields'] === ['phone_primary'], 'An explicitly reviewed empty phone clears the native field');
$baseline = $proposals[$id]['baseline']; $baseline['version'] += 100;
$forged = $make(['last_name' => 'Invented baseline'], $baseline);
$check($fails(fn() => $review($forged)), 'A provider-only baseline cannot authorize a Core update');
$expired = $review($make(['first_name' => 'Expired request']));
$token = json_decode(decryptField(base64_decode($expired['review_token'], true)), true); $token['expires_at'] = time() - 1;
$expired['review_token'] = base64_encode(encryptField(integrationDeliveryJson($token)));
$check($fails(fn() => $apply($expired)) && $count() === 5, 'Expired uncommitted review cannot update contact');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links')->fetchColumn() === 8, 'No additional provider identity created by decisions');
$listed = quantaProfileReviewPage(11);
$check(count(array_filter($listed['rows'], static fn($r) => $r['state'] === 'accepted')) === 4
    && count(array_filter($listed['rows'], static fn($r) => $r['state'] === 'rejected')) === 1, 'Request list identifies the saved native decisions');
$check($listed['unavailable_count'] === 1, 'Unproven provider baseline is withheld from request list');
$foreign = $make(['first_name' => 'Foreign directory']);
$proposals[$foreign]['person']['directory_tenant_id'] = 12;
$listed = quantaProfileReviewPage(11);
$check($listed['unavailable_count'] === 2 && !str_contains(json_encode($listed), 'Foreign directory'), 'Other directory contact details never enter the review list');
echo "Quanta profile review MariaDB: $n passed\n";
