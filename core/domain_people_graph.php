<?php
/**
 * Domain module bridge into People Graph.
 *
 * Domain modules own their records. People Graph owns cross-cutting authority,
 * responsibility, delegation, approval routing, and permission decisions.
 */

declare(strict_types=1);

require_once __DIR__ . '/ModuleRegistry.php';
require_once __DIR__ . '/people_graph.php';

function domainPeopleGraphContract(string $moduleId): array
{
    $registry = ModuleRegistry::getInstance();
    $module = $registry->getModule($moduleId);
    if (!$module) {
        throw new \InvalidArgumentException("Unknown module for People Graph contract: {$moduleId}");
    }
    $contract = $registry->getPeopleGraphContract($moduleId) ?? [];
    return array_merge([
        'module_id'    => $moduleId,
        'consumes'     => false,
        'mode'         => 'source_module_consumer',
        'object_types' => [],
    ], $contract, [
        'module_id' => $moduleId,
    ]);
}

function domainPeopleGraphContracts(): array
{
    return ModuleRegistry::getInstance()->getPeopleGraphContracts();
}

function domainPeopleGraphConsumes(string $moduleId): bool
{
    $contract = domainPeopleGraphContract($moduleId);
    return !empty($contract['consumes']) || !empty($contract['object_types']);
}

function domainPeopleGraphObjectTypes(string $moduleId): array
{
    $contract = domainPeopleGraphContract($moduleId);
    return is_array($contract['object_types'] ?? null) ? $contract['object_types'] : [];
}

function domainPeopleGraphObjectContract(string $moduleId, string $objectType): array
{
    $objectTypes = domainPeopleGraphObjectTypes($moduleId);
    if (!isset($objectTypes[$objectType]) || !is_array($objectTypes[$objectType])) {
        throw new \InvalidArgumentException("Module {$moduleId} does not declare People Graph object type: {$objectType}");
    }
    return $objectTypes[$objectType];
}

function domainPeopleGraphResponsibilitiesFor(string $moduleId, string $objectType): array
{
    $object = domainPeopleGraphObjectContract($moduleId, $objectType);
    $responsibilities = $object['responsibilities'] ?? [];
    if (!is_array($responsibilities)) {
        throw new \InvalidArgumentException("People Graph responsibilities must be an array for {$moduleId}.{$objectType}");
    }
    return array_values(array_unique(array_map('strval', $responsibilities)));
}

function domainPeopleGraphObjectRef(string $moduleId, string $objectType, string|int $objectId): array
{
    domainPeopleGraphObjectContract($moduleId, $objectType);
    $id = trim((string) $objectId);
    if ($id === '') {
        throw new \InvalidArgumentException('object_id is required');
    }
    return [
        'object_module' => $moduleId,
        'object_type'   => $objectType,
        'object_id'     => $id,
    ];
}

function domainPeopleGraphAssertResponsibilityAllowed(string $moduleId, string $objectType, string $responsibilityType): void
{
    if (!in_array($responsibilityType, PEOPLE_GRAPH_RESPONSIBILITY_TYPES, true)) {
        throw new \InvalidArgumentException("Unknown People Graph responsibility: {$responsibilityType}");
    }
    $allowed = domainPeopleGraphResponsibilitiesFor($moduleId, $objectType);
    if (!in_array('*', $allowed, true) && !in_array($responsibilityType, $allowed, true)) {
        throw new \InvalidArgumentException(
            "Responsibility {$responsibilityType} is not declared for {$moduleId}.{$objectType}"
        );
    }
}

function domainPeopleGraphAssignResponsibility(
    int $tenantId,
    string $moduleId,
    string $objectType,
    string|int $objectId,
    string $responsibilityType,
    string $actorType,
    int $actorId,
    array $opts = [],
    ?int $actorUserId = null
): array {
    $object = domainPeopleGraphObjectRef($moduleId, $objectType, $objectId);
    domainPeopleGraphAssertResponsibilityAllowed($moduleId, $objectType, $responsibilityType);

    $body = $object + [
        'responsibility_type' => $responsibilityType,
        'actor_type'          => $actorType,
        'actor_id'            => $actorId,
        'priority'            => $opts['priority'] ?? 100,
        'conditions'          => $opts['conditions'] ?? $opts['conditions_json'] ?? null,
        'status'              => $opts['status'] ?? 'active',
        'starts_at'           => $opts['starts_at'] ?? null,
        'ends_at'             => $opts['ends_at'] ?? null,
        'source'              => $opts['source'] ?? 'domain_module',
    ];

    return peopleGraphAssignResponsibility($tenantId, $body, $actorUserId);
}

function domainPeopleGraphResolve(
    int $tenantId,
    string $moduleId,
    string $objectType,
    string|int $objectId,
    string $question = 'who_owns',
    array $opts = []
): array {
    return peopleGraphResolve(
        $tenantId,
        $question,
        domainPeopleGraphObjectRef($moduleId, $objectType, $objectId),
        $opts
    );
}

function domainPeopleGraphResolveApprovers(
    int $tenantId,
    string $moduleId,
    string $objectType,
    string|int $objectId,
    array $context = [],
    array $opts = []
): array {
    domainPeopleGraphObjectContract($moduleId, $objectType);
    return peopleGraphResolveApprovers($tenantId, [
        'resource_module' => $moduleId,
        'resource_type'   => $objectType,
        'resource_id'     => (string) $objectId,
        'scope_type'      => $opts['scope_type'] ?? null,
        'scope_id'        => $opts['scope_id'] ?? null,
        'context'         => $context,
    ]);
}

function domainPeopleGraphCheckPermission(
    int $tenantId,
    string $moduleId,
    string $objectType,
    string|int|null $objectId,
    string $actorType,
    int $actorId,
    string $action,
    array $opts = [],
    ?int $actorUserId = null
): array {
    domainPeopleGraphObjectContract($moduleId, $objectType);
    return peopleGraphCheckPermission($tenantId, [
        'actor_type'      => $actorType,
        'actor_id'        => $actorId,
        'action'          => $action,
        'resource_module' => $moduleId,
        'resource_type'   => $objectType,
        'resource_id'     => $objectId === null ? null : (string) $objectId,
        'scope_type'      => $opts['scope_type'] ?? null,
        'scope_id'        => $opts['scope_id'] ?? null,
        'context'         => is_array($opts['context'] ?? null) ? $opts['context'] : [],
    ], $actorUserId);
}

function domainPeopleGraphWorkflowApproverResolution(
    string $moduleId,
    string $objectType,
    string|int $objectId,
    array $context = [],
    array $opts = []
): array {
    domainPeopleGraphObjectContract($moduleId, $objectType);
    return [
        'strategy'        => $opts['strategy'] ?? 'approval_policy',
        'resource_module' => $moduleId,
        'resource_type'   => $objectType,
        'resource_id'     => (string) $objectId,
        'context'         => $context,
    ];
}
