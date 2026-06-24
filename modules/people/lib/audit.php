<?php
/**
 * People Module — Audit logger
 *
 * Thin wrapper around the shared platform audit writer. Modules call
 * peopleAudit('people.created', ['id' => 123, ...]) and the helper writes
 * canonical `audit_log` evidence with People-specific source/object metadata.
 * All people.* event slugs are declared in /app/modules/people/manifest.php.
 *
 * SPEC: /app/modules/people/SPEC.md §7
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/audit.php';

function peopleAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = []): void
{
    platformAuditLogWrite(
        currentTenantId(),
        isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null,
        $event,
        $targetId,
        $meta,
        array_merge([
            'object_type' => peopleAuditObjectType($event, $meta),
            'source' => 'people',
        ], $opts)
    );
}

function peopleAuditObjectType(string $event, array $meta = []): string
{
    if (str_contains($event, '.banking.')) return 'people_banking';
    if (str_contains($event, '.tax.')) return 'people_tax';
    if (str_contains($event, '.pii.')) return 'people_pii';
    if (str_contains($event, '.comp.')) return 'people_compensation';
    if (str_contains($event, '.employee.')) return 'people_employee';
    if (str_contains($event, '.document.')) return 'people_document';
    if (str_contains($event, '.custom_field.')) return 'people_custom_field';
    if (str_contains($event, '.graph.')) return 'people_graph';
    if (str_contains($event, '.access_review.')) return 'access_review';
    if (($meta['resource'] ?? null) === 'i9') return 'people_i9';
    return 'people_person';
}
