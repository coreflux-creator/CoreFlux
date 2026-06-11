<?php
/**
 * People Graph MVP smoke test.
 *
 * Static contract lock for the P2.0 People Graph foundation. It does not
 * require a live database; DB behavior is covered by runtime smoke once the
 * migration has applied on a tenant.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../core/api_router.php';
require_once __DIR__ . '/../core/people_graph.php';
require_once __DIR__ . '/../core/rbac/legacy_map.php';

$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};

echo "People Graph vocabulary\n";
$vocab = peopleGraphVocabulary();
$a('actor types include person', in_array('person', $vocab['actor_types'] ?? [], true));
$a('actor types include user', in_array('user', $vocab['actor_types'] ?? [], true));
$a('actor types include ai_worker', in_array('ai_worker', $vocab['actor_types'] ?? [], true));
$a('relationship types include reports_to', in_array('reports_to', $vocab['relationship_types'] ?? [], true));
$a('relationship types include supervises_ai', in_array('supervises_ai', $vocab['relationship_types'] ?? [], true));
$a('responsibility types include approver', in_array('approver', $vocab['responsibility_types'] ?? [], true));
$a('responsibility types include ai_supervisor', in_array('ai_supervisor', $vocab['responsibility_types'] ?? [], true));
$a('delegation types include approval', in_array('approval', $vocab['delegation_types'] ?? [], true));
$a('permission actions include approve', in_array('approve', $vocab['permission_actions'] ?? [], true));
$a('approval strategies include responsibility', in_array('responsibility', $vocab['approval_strategies'] ?? [], true));
$a('AI forbidden actions include release', in_array('release', $vocab['ai_forbidden_actions'] ?? [], true));
$a('resolver supports who_approves', isset($vocab['resolver_questions']['who_approves']));
$a('resolver supports who_reviews_ai', isset($vocab['resolver_questions']['who_reviews_ai']));

echo "\nCore service contract\n";
foreach ([
    'peopleGraphCreateActorLink',
    'peopleGraphCreateRelationship',
    'peopleGraphAssignResponsibility',
    'peopleGraphCreateDelegation',
    'peopleGraphResolve',
    'peopleGraphListResponsibilities',
    'peopleGraphRevokeDelegation',
    'peopleGraphGrantPermission',
    'peopleGraphListPermissionGrants',
    'peopleGraphRevokePermissionGrant',
    'peopleGraphCheckPermission',
    'peopleGraphCreateApprovalPolicy',
    'peopleGraphCreateApprovalRule',
    'peopleGraphResolveApprovers',
] as $fn) {
    $a("function exists: {$fn}", function_exists($fn));
}

$map = peopleGraphResolverQuestionMap();
$a('who_owns resolves owner/accountable', ($map['who_owns'] ?? []) === ['owner', 'accountable']);
$a('who_approves resolves approver', ($map['who_approves'] ?? []) === ['approver']);
$a('who_reviews_ai resolves ai_supervisor first', ($map['who_reviews_ai'][0] ?? null) === 'ai_supervisor');
$a('approver delegates by approval', peopleGraphDelegationTypeForResponsibility('approver') === 'approval');
$a('ai_supervisor delegates by supervision', peopleGraphDelegationTypeForResponsibility('ai_supervisor') === 'supervision');
$a('approve action delegates by approval', peopleGraphDelegationTypeForAction('approve') === 'approval');
$a('AI post action requires human', peopleGraphAiActionRequiresHuman('accounting.je.post') === true);
$a('AI recommend action allowed by guardrail', peopleGraphAiActionRequiresHuman('recommend') === false);

echo "\nMigration contract\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/112_people_graph.sql');
$a('migration exists', $mig !== '');
foreach ([
    'people_graph_organizations',
    'people_graph_actor_links',
    'people_graph_teams',
    'people_graph_team_memberships',
    'people_graph_roles',
    'people_graph_role_assignments',
    'people_graph_relationships',
    'people_graph_responsibility_assignments',
    'people_graph_delegations',
    'people_graph_permission_grants',
    'people_graph_approval_policies',
    'people_graph_approval_policy_rules',
    'people_graph_notification_preferences',
    'people_graph_audit_log',
] as $table) {
    $a("CREATE TABLE {$table}", str_contains($mig, "CREATE TABLE IF NOT EXISTS {$table}"));
}
$a('relationship enum has supervises_ai', str_contains($mig, "'supervises_ai'"));
$a('responsibility enum has ai_supervisor', str_contains($mig, "'ai_supervisor'"));
$a('delegation enum has supervision', str_contains($mig, "'supervision'"));
$a('actor enum has ai_worker', str_contains($mig, "'ai_worker'"));
$a('approval policies require human for AI', str_contains($mig, 'requires_human_for_ai'));
$a('approval rules support separation of duties', str_contains($mig, 'separation_of_duties_required'));

echo "\nManifest and RBAC contract\n";
$reg = ModuleRegistry::reset(__DIR__ . '/../modules');
$people = $reg->getModule('people') ?? [];
$perms = array_keys($people['permissions'] ?? []);
foreach (['people.graph.view', 'people.graph.manage', 'people.graph.delegate'] as $perm) {
    $a("manifest permission {$perm}", in_array($perm, $perms, true));
}
$events = $people['audit_events'] ?? [];
foreach ([
    'people.graph.actor_linked',
    'people.graph.relationship.created',
    'people.graph.responsibility.assigned',
    'people.graph.delegation.created',
    'people.graph.delegation.revoked',
    'people.graph.permission.granted',
    'people.graph.permission.revoked',
    'people.graph.permission.checked',
    'people.graph.approval_policy.upserted',
    'people.graph.approval_rule.created',
    'people.graph.resolved',
] as $event) {
    $a("manifest audit event {$event}", in_array($event, $events, true));
}
$routes = array_column($people['actions'] ?? [], 'route');
$a('manifest action graph', in_array('graph', $routes, true));
$a('people.graph.view maps to people/read', RbacLegacyMap::resolve('people.graph.view') === ['people', 'read']);
$a('people.graph.manage maps to people/admin', RbacLegacyMap::resolve('people.graph.manage') === ['people', 'admin']);
$a('people.graph.delegate maps to people/admin', RbacLegacyMap::resolve('people.graph.delegate') === ['people', 'admin']);

echo "\nAPI and router contract\n";
foreach ([
    __DIR__ . '/../core/people_graph.php',
    __DIR__ . '/../api/people_graph.php',
] as $path) {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    $a('php -l ' . basename($path), $rc === 0);
}
$api = (string) file_get_contents(__DIR__ . '/../api/people_graph.php');
$a('API gates view permission', str_contains($api, "people.graph.view"));
$a('API gates manage permission', str_contains($api, "people.graph.manage"));
$a('API gates delegate permission', str_contains($api, "people.graph.delegate"));
$a('API exposes permission check', str_contains($api, "peopleGraphCheckPermission"));
$a('API exposes approval resolution', str_contains($api, "peopleGraphResolveApprovers"));
$ui = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PeopleGraph.jsx');
$moduleUi = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PeopleModule.jsx');
$a('PeopleGraph UI exists', $ui !== '');
$a('PeopleGraph UI calls v1 graph API', str_contains($ui, '/api/v1/people/graph/resolve'));
$a('PeopleGraph UI can check permission', str_contains($ui, '/api/v1/people/graph/check-permission'));
$a('PeopleGraph UI can resolve approvers', str_contains($ui, '/api/v1/people/graph/resolve-approvers'));
$a('PeopleModule routes graph page', str_contains($moduleUi, 'path="graph"'));

$r = apiRouterParse('', '/api/v1/people/graph/resolve');
$a('router parses people graph v1 path', $r['ok'] === true && $r['module_id'] === 'people' && $r['endpoint'] === 'graph');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$a('v1 compatibility sets action=resolve', ($_GET['action'] ?? null) === 'resolve');
$_GET = [];
$file = apiRouterResolveFile('people', 'graph');
$a('router resolves people graph alias', $file !== null && str_ends_with($file, '/api/people_graph.php'));

echo "\nPeople Graph smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
