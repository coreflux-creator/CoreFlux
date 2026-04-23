<?php
/**
 * Template Module — Records API
 * Example endpoint showing the standard shape every module API follows.
 *
 * Copy to: /modules/<module>/api/<resource>.php
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        // Example: list records scoped to the current tenant.
        // $rows = scopedQuery('SELECT id, name FROM template_records WHERE tenant_id = :tenant_id ORDER BY id DESC');
        $rows = []; // placeholder
        api_ok(['records' => $rows]);
    }

    case 'POST': {
        $body = api_json_body();
        api_require_fields($body, ['name']);

        // $id = scopedInsert('template_records', [
        //     'name' => $body['name'],
        // ]);
        $id = 0; // placeholder
        api_ok(['id' => $id], 201);
    }

    case 'PUT':
    case 'PATCH': {
        $body = api_json_body();
        api_require_fields($body, ['id']);
        // scopedUpdate('template_records', (int) $body['id'], ['name' => $body['name'] ?? '']);
        api_ok(['ok' => true]);
    }

    case 'DELETE': {
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        // scopedDelete('template_records', $id);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
