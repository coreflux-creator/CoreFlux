<?php
/**
 * Time API — entries (the atomic record)
 *
 *   GET    /api/time/entries                    → list with filters
 *   GET    /api/time/entries?id=N               → get one
 *   POST   /api/time/entries                    → create draft
 *   PATCH  /api/time/entries?id=N               → update (draft/pending_review/rejected only)
 *   POST   /api/time/entries?action=bulk_create  → create draft[] atomically
 *   POST   /api/time/entries?action=submit&id=N   → draft → pending_review
 *   POST   /api/time/entries?action=bulk_submit   → draft[] → pending_review atomically
 *   POST   /api/time/entries?action=approve&id=N  → pending_review → approved (locks rate_snapshot_id)
 *   POST   /api/time/entries?action=reject&id=N   → pending_review → rejected
 *   POST   /api/time/entries?action=correct&id=N  → create new entry, old → superseded
 *
 * SPEC: /app/modules/time/SPEC.md §5.1, §9
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';
require_once __DIR__ . '/../../staffing/lib/timesheets.php';
require_once __DIR__.'/../lib/native_entries.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

function timeApiAuthorizePerson(array $user,int $person): void
{
    $permission=$person===(int)($user['person_id']??0)?'time.entry.self':'time.entry.manage';
    if (!rbac_legacy_can($user,$permission)) throw new RuntimeException('You do not have permission to edit this worker’s time',403);
}

function timeApiCanPermission(array $ctx, string $permission): bool
{
    if (($ctx['role'] ?? '') === 'master_admin' || ($ctx['global_role'] ?? $ctx['user']['global_role'] ?? '') === 'master_admin' || !empty($ctx['is_global_admin'])) return true;
    return rbac_legacy_can((array)($ctx['user'] ?? []), $permission);
}

function timeApiFailure(Throwable $error): array
{
    if ($error instanceof PDOException || !($error instanceof RuntimeException || $error instanceof InvalidArgumentException)) {
        error_log('Native time operation failed: '.get_class($error).' '.$error->getMessage());
        return ['message'=>'Time could not be saved. Reload and try again.','status'=>503];
    }
    $status=(int)$error->getCode();
    return ['message'=>$error->getMessage(),'status'=>in_array($status,[403,404,409,422,503],true)?$status:422];
}

if ($method==='GET' && in_array($action,['correction_review','entry_history'],true)) {
    api_require_legacy_permission($ctx,$action==='correction_review'?'time.entry.manage':'time.view');
    $id=(int)api_query('id',0);if($id<=0)api_error('id required',422);
    header('Cache-Control: private, no-store');
    try {api_ok($action==='correction_review'?timeNativeCorrectionReview($id):timeNativeEntryHistory($id));}
    catch(Throwable $e){$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
}

// ─── Actions (POST) ───
if ($method === 'POST' && $action !== '') {
    if ($action === 'bulk_create') {
        $body=api_json_body();$rows=is_array($body['entries']??null)?array_values($body['entries']):[];
        try {
            $saved=timeNativeCreate($user,$rows,static fn(int $person)=>timeApiAuthorizePerson($user,$person),(int)($body['document_id']??0),true);
            api_ok($saved,201);
        } catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
    }

    if ($action === 'submit' || $action === 'bulk_submit') {
        if (!timeApiCanPermission($ctx, 'time.entry.self') && !timeApiCanPermission($ctx, 'time.entry.manage')) api_error('You do not have permission to submit time', 403);
        $body = $action === 'bulk_submit' ? api_json_body() : [];
        $ids = $action === 'bulk_submit'
            ? (is_array($body['ids'] ?? null) ? $body['ids'] : [])
            : [api_query('id', 0)];
        if (!$ids || max(array_map('intval', $ids)) <= 0) {
            api_error($action === 'bulk_submit' ? 'ids[] required' : 'id required', 422);
        }
        try {
            $result = timeSubmitEntries($ids, $user, timeApiCanPermission($ctx, 'time.entry.manage'));
        } catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
        api_ok(['ok' => true] + $result);
    }

    if ($action === 'bulk_approve' || $action === 'bulk_reject') {
        $body = api_json_body();
        $ids = is_array($body['ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $body['ids']), static fn ($id) => $id > 0)))
            : [];
        if (!$ids) api_error('ids[] required', 422);
        if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);

        $isApprove = $action === 'bulk_approve';
        if ($isApprove) {
            api_require_legacy_permission($ctx, 'time.approve');
        } else {
            api_require_legacy_permission($ctx, 'time.reject');
            if (trim((string) ($body['reason'] ?? '')) === '') api_error('reason required', 422);
        }

        $succeeded = 0;
        $failed = 0;
        $results = [];
        foreach ($ids as $entryId) {
            try {
                if ($isApprove) {
                    timeApproveEntry($entryId, $user, 'bulk_review');
                } else {
                    timeRejectEntry($entryId, $user, (string) $body['reason']);
                }
                $succeeded++;
                $results[] = ['id' => $entryId, 'ok' => true];
            } catch (\Throwable $e) {
                $failed++;
                $failure=timeApiFailure($e);
                $results[] = ['id' => $entryId, 'ok' => false, 'reason' => $failure['message']];
            }
        }
        api_ok([
            'ok' => true,
            'action' => $action,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    $entry = timeEntryGet($id);
    if (!$entry) api_error('Entry not found', 404);

    if ($action === 'approve') {
        api_require_legacy_permission($ctx, 'time.approve');
        try {
            $approvedEntry = timeApproveEntry($id, $user, 'manual');
        } catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
        api_ok(['ok' => true, 'entry' => $approvedEntry]);
    }

    if ($action === 'reject') {
        api_require_legacy_permission($ctx, 'time.reject');
        $body = api_json_body();
        api_require_fields($body, ['reason']);
        try {
            $rejectedEntry = timeRejectEntry($id, $user, (string) $body['reason']);
        } catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
        api_ok(['ok' => true, 'entry' => $rejectedEntry]);
    }

    if ($action === 'correct') {
        api_require_legacy_permission($ctx,'time.entry.manage');
        try {api_ok(timeNativeCorrect($id,api_json_body(),$user));}
        catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
    }

    api_error('Unknown action', 400);
}

// ─── GET ───
if ($method === 'GET') {
    rbac_legacy_require($user, 'time.view');
    $id = (int) api_query('id', 0);
    if ($id > 0) {
        $row = timeEntryGet($id);
        if (!$row) api_error('Not found', 404);
        api_ok(['entry' => $row]);
    }
    api_ok(timeEntriesList([
        'period_id'    => $_GET['period_id']    ?? null,
        'placement_id' => $_GET['placement_id'] ?? null,
        'person_id'    => $_GET['person_id']    ?? null,
        'status'       => $_GET['status']       ?? null,
        'source'       => $_GET['source']       ?? null,
        'category'     => $_GET['category']     ?? null,
        'work_date'    => $_GET['work_date']    ?? null,
        'work_date_from' => $_GET['work_date_from'] ?? null,
        'work_date_to'   => $_GET['work_date_to']   ?? null,
        'page'         => $_GET['page']         ?? 1,
        'per_page'     => $_GET['per_page']     ?? 50,
    ]));
}

// Native writes revalidate the original workspace, worker and placement inside the transaction.
if ($method === 'POST') {
    try {
        $saved=timeNativeCreate($user,[api_json_body()],static fn(int $person)=>timeApiAuthorizePerson($user,$person));
        api_ok(['entry'=>$saved['entries'][0]],201);
    } catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
}

if ($method === 'PATCH') {
    $id=(int)api_query('id',0);if($id<=0)api_error('id required',400);
    try {api_ok(['entry'=>timeNativePatch($id,api_json_body(),$user,static fn(int $person)=>timeApiAuthorizePerson($user,$person))]);}
    catch (Throwable $e) {$failure=timeApiFailure($e);api_error($failure['message'],$failure['status']);}
}

api_error('Method not allowed',405);
