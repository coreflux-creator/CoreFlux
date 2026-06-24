<?php
/**
 * Audit-log enterprise controls smoke.
 *
 * Locks the platform audit contract across schema migration, normalized API
 * reads, admin/auditor access, CSV evidence export, dashboard filters, and docs.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  ok {$name}\n";
        $pass++;
    } else {
        echo "  FAIL {$name}" . ($hint ? " ({$hint})" : '') . "\n";
        $fail++;
    }
};
$lint = function (string $p): bool {
    $o = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (stripos($haystack, (string) $needle) === false) return false;
    }
    return true;
};

$ROOT = realpath(__DIR__ . '/..');

echo "Audit writer\n";
$writerPath = "{$ROOT}/core/audit.php";
$writer = (string) file_get_contents($writerPath);
$assert('shared writer parses', $lint($writerPath));
$assert('shared writer detects audit_log schema',
    $containsAll($writer, ['function platformAuditLogColumns', 'SHOW COLUMNS FROM audit_log']));
$assert('shared writer emits canonical and legacy fields',
    $containsAll($writer, [
        'actor_user_id',
        'user_id',
        'actor_type',
        'actor_email',
        'event',
        'action',
        'target_id',
        'entity_id',
        'object_type',
        'entity',
        'before_json',
        'after_json',
        'request_id',
        'source',
        'user_agent',
    ]));
$assert('shared writer keeps request/source searchable on old schemas',
    $containsAll($writer, ["\$metaForSearch['request_id']", "\$metaForSearch['source']", "\$metaForSearch['object_type']"]));

echo "Audit API\n";
$apiPath = "{$ROOT}/api/audit_log.php";
$api = (string) file_get_contents($apiPath);
$assert('api parses', $lint($apiPath));
$assert('tenant scoped', stripos($api, 'al.tenant_id = :t') !== false);
$assert('admin and auditor read roles',
    $containsAll($api, ["'master_admin'", "'tenant_admin'", "'admin'", "'auditor'", "'external_auditor'"]));
$assert('legacy/canonical actor normalization',
    $containsAll($api, ['auditLogCoalesce', 'actor_user_id', 'user_id', 'actor_user_id/user_id']));
$assert('legacy/canonical event and object normalization',
    $containsAll($api, ['event/action', 'target_id/entity_id', 'object_type', 'entity']));
$assert('canonical actor filters',
    $containsAll($api, ['actor_type', 'actor_email', ':actor_type', ':actor_email']));
$assert('canonical object/request/source filters',
    $containsAll($api, ['object_type', 'target_id', 'request_id', 'source', ':object_type', ':target_id']));
$assert('request/source meta fallback',
    $containsAll($api, ['meta_json LIKE :request_id_like', 'meta_json LIKE :source_like']));
$assert('ip/date/limit filters',
    $containsAll($api, ['ip_address LIKE :ip', 'created_at >= :f', 'created_at < :to', 'max(1']));
$assert('csv evidence columns',
    $containsAll($api, ["'actor_type'", "'actor_email'", "'object_type'", "'request_id'", "'source'", "'before'", "'after'", "'user_agent'"]));
$assert('normalizes decoded snapshots for JSON response',
    $containsAll($api, ['auditLogNormalizeRow', 'before_json', 'after_json', 'json_decode']));

echo "\nMigrations\n";
$m097 = (string) file_get_contents("{$ROOT}/core/migrations/097_audit_log_event_column.sql");
$m124 = (string) file_get_contents("{$ROOT}/core/migrations/124_audit_log_enterprise_fields.sql");
foreach (['actor_type','actor_email','object_type','before_json','after_json','request_id','source','user_agent'] as $column) {
    $assert("097 fresh schema has {$column}", stripos($m097, "`{$column}`") !== false);
    $assert("124 upgrade adds {$column}", stripos($m124, $column) !== false && stripos($m124, 'information_schema.COLUMNS') !== false);
}
$assert('request id indexed on fresh and upgrade',
    stripos($m097, 'idx_audit_request_id') !== false && stripos($m124, 'idx_audit_request_id') !== false);

echo "\nDashboard viewer\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AuditLogViewer.jsx");
foreach ([
    'audit-filter-event',
    'audit-filter-user',
    'audit-filter-actor-type',
    'audit-filter-actor-email',
    'audit-filter-object-type',
    'audit-filter-target',
    'audit-filter-request',
    'audit-filter-source',
    'audit-filter-ip',
    'audit-filter-from',
    'audit-filter-to',
    'audit-export-csv',
] as $tid) {
    $assert("viewer testid {$tid}", stripos($ui, "data-testid=\"{$tid}\"") !== false);
}
$assert('viewer sends canonical query params',
    $containsAll($ui, ["p.set('actor_type'", "p.set('actor_email'", "p.set('object_type'", "p.set('request_id'", "p.set('source'", "p.set('ip'"]));
$assert('viewer renders canonical row evidence',
    $containsAll($ui, ['actor_user_id', 'actor_email', 'object_type', 'request_id', 'source', 'ip_address']));
$assert('viewer expanded metadata includes snapshots',
    $containsAll($ui, ['formatMeta', 'before_json', 'after_json', 'user_agent']));

echo "\nDocs\n";
$doc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");
$assert('audit governance doc exists', strlen($doc) > 0);
$assert('doc states canonical event shape',
    $containsAll($doc, ['Canonical Event Shape', 'actor_type', 'object_type', 'request_id', 'before_json', 'after_json']));
$assert('doc states shared write model',
    $containsAll($doc, ['Write Model', 'core/audit.php', 'platformAuditLogWrite', 'legacy aliases']));
$assert('doc states access/export model',
    $containsAll($doc, ['Access Model', 'external_auditor', 'CSV export', 'same endpoint']));
$assert('alignment record links audit controls',
    $containsAll($alignment, ['Audit And Enterprise Controls', 'unified audit log', 'auditor roles']));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
