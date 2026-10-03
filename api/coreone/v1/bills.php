<?php
/** Bearer-only payable intake; approval, posting and payment stay in AP. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/accounting/coreone_bills_v1.php';

$credential = coreoneV1Authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
if (!$credential) api_error('Invalid or expired service credential', 401);
if (!coreoneV1HasScope($credential, 'bills:prepare')) api_error('Service credential lacks bill preparation scope', 403);
setRequestTenantId((int) $credential['tenant_id']);
setRequestModuleScope('ap');

if (api_method() === 'GET') {
    if (array_diff(array_keys($_GET), ['source_record_id'])) api_error('Unsupported query field', 422);
    $sourceId = (string) api_query('source_record_id', '');
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9:_./-]{0,119}$#D', $sourceId)) {
        api_error('Valid source_record_id required', 422);
    }
    $bill = coreoneV1GetBill($credential, $sourceId);
    if (!$bill) api_error('Bill not found for this entity', 404);
    api_ok($bill);
}

if (api_method() === 'POST') {
    if ($_GET) api_error('Unsupported query field', 422);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) api_error('Bill request is too large', 413);
    try {
        $result = coreoneV1PrepareBill($credential, api_json_body());
        api_ok($result, $result['idempotent_replay'] ? 200 : 201);
    } catch (CoreOneDocumentConflictException | DomainException $e) {
        api_error($e->getMessage(), 409);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (Throwable $e) {
        error_log('[coreone bill prepare] ' . $e->getMessage());
        api_error('Bill could not be prepared', 500);
    }
}

api_error('Method not allowed', 405);
