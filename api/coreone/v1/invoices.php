<?php
/** Bearer-only CoreOne v1 invoice drafts; approval, sending and posting stay in Billing. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/accounting/coreone_invoices_v1.php';

$credential = coreoneV1Authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
if (!$credential) api_error('Invalid or expired service credential', 401);
if (!coreoneV1HasScope($credential, 'invoices:draft')) api_error('Service credential lacks invoice draft scope', 403);
setRequestTenantId((int) $credential['tenant_id']);
setRequestModuleScope('billing');

if (api_method() === 'GET') {
    if (array_diff(array_keys($_GET), ['source_record_id'])) api_error('Unsupported query field', 422);
    $sourceId = (string) api_query('source_record_id', '');
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9:_./-]{0,119}$#D', $sourceId)) {
        api_error('Valid source_record_id required', 422);
    }
    $invoice = coreoneV1GetInvoiceDraft($credential, $sourceId);
    if (!$invoice) api_error('Invoice not found for this entity', 404);
    api_ok($invoice);
}

if (api_method() === 'POST') {
    if ($_GET) api_error('Unsupported query field', 422);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) api_error('Invoice request is too large', 413);
    try {
        $result = coreoneV1CreateInvoiceDraft($credential, api_json_body());
        api_ok($result, $result['idempotent_replay'] ? 200 : 201);
    } catch (CoreOneInvoiceConflictException $e) {
        api_error($e->getMessage(), 409);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (Throwable $e) {
        error_log('[coreone invoice draft] ' . $e->getMessage());
        api_error('Invoice draft could not be created', 500);
    }
}

api_error('Method not allowed', 405);
