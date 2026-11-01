<?php
/** Versioned, entity-scoped CoreOne general journal boundary. No human login is accepted. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/accounting/coreone_v1.php';

$credential = coreoneV1Authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
if (!$credential) api_error('Invalid or expired service credential', 401);
if (!coreoneV1HasScope($credential, 'journals:write')) api_error('Service credential lacks journal scope', 403);
setRequestTenantId((int) $credential['tenant_id']);
setRequestModuleScope('accounting');

$method = api_method();
$action = (string) api_query('action', '');

if ($method === 'GET' && $action === '') {
    $sourceId = (string) api_query('source_record_id', '');
    if ($sourceId === '') api_error('source_record_id required', 422);
    $row = coreoneV1GetJournal($credential, $sourceId);
    if (!$row) api_error('Journal not found for this entity', 404);
    api_ok($row);
}

if ($method === 'POST' && $action === 'reverse') {
    $body = api_json_body();
    try {
        $result = coreoneV1ReverseJournal($credential,
            (string) ($body['source_record_id'] ?? ''), (string) ($body['reason'] ?? ''));
        api_ok($result);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), str_contains($e->getMessage(), 'not found') ? 404 : 409);
    }
}

if ($method === 'POST' && $action === '') {
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 131072) api_error('Journal request is too large', 413);
    $body = api_json_body();
    try {
        $result = coreoneV1PostJournal($credential, $body);
        api_ok($result, !empty($result['idempotent_replay']) ? 200 : 201);
    } catch (AccountingEventConflictException $e) {
        api_error('Source ID already exists with different journal details', 409);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 409);
    }
}

api_error('Method not allowed', 405);
