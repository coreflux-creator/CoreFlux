<?php
/** Real MariaDB outbox/recovery with strictly synthetic Quanta transport. */
declare(strict_types=1);
define('QUANTA_PROFILE_RETURN_FIXTURE', true);
require __DIR__ . '/quanta_profile_review_mariadb.php';
require_once __DIR__ . '/../core/quanta/profile_return_delivery.php';
foreach (coreflux_split_sql_statements(file_get_contents(__DIR__ . '/../core/migrations/187_quanta_profile_returns.sql')) as $sql) $pdo->exec($sql);
$n = 0; $returned = []; $returnWrites = 0; $returnReads = 0; $returnMode = 'ok'; $returnCapability = true;
$oldRead = $GLOBALS['__quanta_transport']; $oldWrite = $GLOBALS['__quanta_core_write_transport'];
$GLOBALS['__quanta_transport'] = static function ($method, $url) use ($oldRead, &$returned, &$returnReads, &$returnCapability, &$returnMode, $pdo) {
    $path = parse_url($url, PHP_URL_PATH);
    if (str_ends_with($path, '/profile-change-context')) {
        $r = $oldRead($method, $url); $body = json_decode($r['body'], true);
        $body['decision_delivery'] = $returnCapability; $r['body'] = json_encode($body); return $r;
    }
    if (preg_match('~/profile-changes/(cpc_[a-f0-9]{64})/decision$~D', $path, $m)) {
        $returnReads++;
        if ($returnMode === 'read_scope_drift') $pdo->exec('INSERT INTO tenant_module_scope VALUES(11,"people","isolated")');
        return ['status' => isset($returned[$m[1]]) ? 200 : 404, 'body' => json_encode($returned[$m[1]] ?? [])];
    }
    return $oldRead($method, $url);
};
$GLOBALS['__quanta_core_write_transport'] = static function ($method, $url, $headers, $json) use ($oldWrite, &$returned, &$returnWrites, &$returnMode, &$proposals, $pdo, $workspace, $check) {
    if (!str_ends_with($url, '/decision')) return $oldWrite($method, $url, $headers, $json);
    $returnWrites++; $body = json_decode($json, true); $id = $body['change_id'];
    $check($method === 'PUT' && !$pdo->inTransaction(), 'Original PUT occurs after the local claim commits');
    $s = $pdo->prepare('SELECT state,body_hash,attempt_count FROM quanta_profile_returns WHERE tenant_id=11 AND change_id=?'); $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC);
    $check($row['state'] === 'sending' && $row['body_hash'] === quantaProfileDigest($body), 'Provider sees the durable exact-body claim');
    if ($returnMode === 'no_receipt') throw new RuntimeException('Synthetic transport interruption before arrival');
    if ($returnMode === 'reject') return ['status' => 403, 'body' => '{}'];
    if (isset($returned[$id])) $check($returned[$id]['request_hash'] === quantaProfileDigest($body), 'Every recovery uses the original exact body');
    $rid = 'cpd_' . quantaProfileDigest([$workspace, $id]);
    $outcome = ['id' => $rid, 'decision_hash' => quantaProfileDigest($body), 'decision' => $body['decision'],
        'reason' => $body['reason'], 'decided_at' => $body['decided_at'], 'received_at' => '2026-11-11T00:00:00Z', 'completed_at' => '2026-11-11T00:00:01Z',
        'contact_state' => $body['decision'] === 'rejected' ? 'rejected' : ($returnMode === 'conflict' ? 'conflict' : 'applied'),
        'native_changed_fields' => [], 'conflict_fields' => $returnMode === 'conflict' ? ['phone'] : []];
    $returned[$id] = ['id' => $rid, 'tenant_id' => $workspace, 'change_id' => $id, 'request_hash' => quantaProfileDigest($body),
        'decision' => $body, 'state' => $returnMode === 'received' ? 'received' : 'complete',
        'outcome' => $returnMode === 'received' ? null : $outcome];
    if ($returnMode === 'lost_ack') throw new RuntimeException('Synthetic lost response after effect');
    if ($returnMode === 'link_drift') $pdo->exec('UPDATE quanta_worker_links SET worker_id="wrong-worker" WHERE tenant_id=11 AND person_id=108');
    if ($returnMode === 'bad_receipt') { $bad = $returned[$id]; $bad['request_hash'] = str_repeat('0', 64); return ['status' => 200, 'body' => json_encode($bad)]; }
    return ['status' => 200, 'body' => json_encode($returned[$id])];
};
$prepareReturn = static function (array $fields, string $decision = 'accepted', string $reason = '') use ($send, $preview, $make, $review, $apply): string {
    $publication = $preview(108);
    if ($publication['status'] !== 'unchanged') $send($publication);
    $id = $make($fields); $apply($review($id), $decision, $reason); return $id;
};
$pushReturn = static fn($id, $resume = false) => quantaProfileReturnSend(11, 7, $id, $resume);
$inspectReturn = static fn($id) => quantaProfileReturnStatus(11, 7, $id);
$getReturn = static function ($id) use ($pdo) { $s = $pdo->prepare('SELECT * FROM quanta_profile_returns WHERE tenant_id=11 AND change_id=?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };

$id = $prepareReturn(['phone_primary' => '+1 202 555 1001']); $before = $personNow(); $savedDecisions = $pdo->query('SELECT * FROM quanta_profile_decisions')->fetchAll(PDO::FETCH_ASSOC);
$list = quantaProfileReturnPage(11);
$check(count(array_filter($list['rows'], static fn($r) => $r['change_id'] === $id && $r['state'] === 'pending')) === 1 && $returnWrites === 0, 'Local outbox lists saved decisions without provider writes');
putenv('COREFLUX_QUANTA_PROFILE_RETURN');
$check($fails(fn() => $pushReturn($id)) && !$getReturn($id) && $returnWrites === 0, 'Feature gate blocks claims and provider changes');
putenv('COREFLUX_QUANTA_PROFILE_RETURN=1');
$returnCapability = false;
$check($fails(fn() => $pushReturn($id)) && !$getReturn($id), 'Provider must advertise the decision receiver');
$returnCapability = true;
$pdo->exec('UPDATE people SET last_name="Unrelated later edit" WHERE id=108');
$sent = $pushReturn($id);
$check($sent['state'] === 'delivered' && $sent['outcome']['contact_state'] === 'applied' && $returnWrites === 1, 'Accepted decision completes while preserving unrelated Core changes');
$check($personNow()['last_name'] === 'Unrelated later edit' && $personNow()['phone_primary'] === $before['phone_primary'], 'Returning a decision never rewrites Core contacts');
$check($pdo->query('SELECT * FROM quanta_profile_decisions')->fetchAll(PDO::FETCH_ASSOC) === $savedDecisions, 'Delivery evidence never mutates the immutable original decision');
$row = $getReturn($id); $check(!str_contains($row['receipt_ct'], '202 555') && $row['claim_hash'] === null && $row['finished_at'] !== null, 'Completed receipt encrypted with no active claim');
$check($pushReturn($id)['state'] === 'delivered' && $inspectReturn($id)['state'] === 'delivered' && $returnWrites === 1, 'Completed replay returns the saved evidence without another PUT');
$check($fails(fn() => quantaProfileReturnSend(12, 7, $id)) && $fails(fn() => quantaProfileReturnSend(11, 0, $id)), 'Other tenant and missing actor cannot return the saved decision');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1002']); $returnMode = 'lost_ack'; $w = $returnWrites;
$check($fails(fn() => $pushReturn($id)) && $getReturn($id)['state'] === 'uncertain', 'Lost response preserves an uncertain claim');
$check($fails(fn() => $pushReturn($id)) && $returnWrites === $w + 1, 'Normal send cannot repeat an uncertain attempt');
$returnMode = 'ok';
$check($inspectReturn($id)['state'] === 'delivered' && $returnWrites === $w + 1, 'Receipt GET recovers a completed effect without resending');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1003']); $returnMode = 'received'; $r = $pushReturn($id);
$check($r['state'] === 'uncertain' && $r['receipt_state'] === 'received' && $r['outcome'] === null, 'Received without native outcome is not delivered');
$w = $returnWrites;
$check($inspectReturn($id)['resume_allowed'] === true && $returnWrites === $w, 'Inspection exposes explicit resume without sending');
$returnMode = 'ok';
$r = $pushReturn($id, true);
$check($r['state'] === 'delivered' && $r['attempt_count'] === 2, 'Explicit resume reuses the original decision and records both attempts');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1004']); $returnMode = 'no_receipt';
$check($fails(fn() => $pushReturn($id)) && $getReturn($id)['state'] === 'uncertain', 'Interrupted arrival is retained for inspection');
$r = $inspectReturn($id); $w = $returnWrites;
$check($r['observed_receipt_state'] === 'not_found' && $r['resume_allowed'] && $returnWrites === $w, 'Missing receipt does not automatically resend');
$returnMode = 'ok';
$check($pushReturn($id, true)['state'] === 'delivered', 'Explicit conditional retry after missing receipt uses the same immutable resource');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1005']); $returnMode = 'reject';
$check($fails(fn() => $pushReturn($id)) && $getReturn($id)['state'] === 'failed', 'Known refusal is distinct from unknown delivery');
$returnMode = 'ok'; $check($pushReturn($id, true)['state'] === 'delivered', 'Explicit retry after repaired provider permission uses original body');
$id = $prepareReturn(['phone_primary' => '+1 202 555 1006']); $returnMode = 'conflict'; $r = $pushReturn($id);
$check($r['state'] === 'delivered' && $r['outcome']['contact_state'] === 'conflict' && $r['outcome']['conflict_fields'] === ['phone'], 'A returned decision with native conflict is never presented as aligned contacts');
$returnMode = 'ok';
$id = $prepareReturn(['first_name' => 'Rejected requested value'], 'rejected', 'Please confirm your preferred contact name.');
$before = $personNow(); $r = $pushReturn($id);
$check($r['outcome']['contact_state'] === 'rejected' && $personNow() === $before, 'Rejection returns the review note without modifying native Core values');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1007']); $before = $personNow(); $w = $returnWrites;
$pdo->exec('UPDATE people SET phone_primary="Later Core phone" WHERE id=108');
$check($fails(fn() => $pushReturn($id)) && !$getReturn($id) && $returnWrites === $w, 'Newer same-field Core edit blocks stale accepted return before claim');
$pdo->prepare('UPDATE people SET phone_primary=? WHERE id=108')->execute([$before['phone_primary']]);
$pdo->exec('UPDATE people SET status="inactive" WHERE id=108');
$check($fails(fn() => $pushReturn($id)) && !$getReturn($id), 'Core offboarding prevents a new contact effect');
$pdo->exec('UPDATE people SET status="active" WHERE id=108');
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=11');
$check($fails(fn() => $pushReturn($id)) && !$getReturn($id), 'Connection rotation cannot reuse a captured outbox decision');
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision-1 WHERE tenant_id=11');
$returnMode = 'read_scope_drift';
$check($fails(fn() => $pushReturn($id)) && !$getReturn($id) && $returnWrites === $w, 'Directory switch during GET cannot reach provider PUT');
$pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11'); $returnMode = 'ok';
$check($pushReturn($id)['state'] === 'delivered', 'Restoring original verified scope permits the original return');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1008']); $returnMode = 'bad_receipt';
$check($fails(fn() => $pushReturn($id)) && $getReturn($id)['state'] === 'uncertain', 'Mismatched response cannot complete the local return');
$returnMode = 'ok'; $w = $returnWrites;
$check($inspectReturn($id)['state'] === 'delivered' && $returnWrites === $w, 'Valid receipt recovers from rejected malformed acknowledgement');
$id = $prepareReturn(['phone_primary' => '+1 202 555 1009']);
$pdo->exec("CREATE TRIGGER fail_return_receipt BEFORE UPDATE ON quanta_profile_returns FOR EACH ROW BEGIN IF NEW.state='delivered' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic receipt failure'; END IF; END");
$check($fails(fn() => $pushReturn($id)) && $getReturn($id)['state'] === 'uncertain', 'Local receipt failure does not lose or repeat the remote decision');
$pdo->exec('DROP TRIGGER fail_return_receipt'); $w = $returnWrites;
$check($inspectReturn($id)['state'] === 'delivered' && $returnWrites === $w, 'Receipt insertion failure recovers without a second native effect');

$id = $prepareReturn(['phone_primary' => '+1 202 555 1010']); $returnMode = 'link_drift';
$check($fails(fn() => $pushReturn($id)) && $getReturn($id)['state'] === 'uncertain', 'Link drift prevents attaching a receipt to the wrong worker');
$pdo->exec('UPDATE quanta_worker_links SET worker_id="worker-108" WHERE tenant_id=11 AND person_id=108'); $returnMode = 'ok'; $w = $returnWrites;
$check($inspectReturn($id)['state'] === 'delivered' && $returnWrites === $w, 'Original link restoration allows read-only recovery');
$pdo->exec('UPDATE people SET status="inactive" WHERE id=108');
$check($inspectReturn($id)['state'] === 'delivered', 'Historical completed receipts remain inspectable after offboarding');
$pdo->exec('UPDATE people SET status="active" WHERE id=108');
$list = quantaProfileReturnPage(11);
$check(count(array_filter($list['rows'], static fn($r) => $r['state'] === 'delivered')) === 11, 'Local outbox retains completed receipts and native conflicts');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links')->fetchColumn() === 8, 'Return workflow preserves all existing provider identities');
echo "Quanta profile return MariaDB: $n passed\n";
