<?php
/**
 * Artifact Graph -> People Graph smoke.
 *
 * Static contract for the P2 adoption slice: artifacts remain the provenance
 * and lifecycle objects, while People Graph owns artifact people roles.
 */

declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};

$art = (string) file_get_contents("{$ROOT}/core/ai/artifacts.php");
$pg = (string) file_get_contents("{$ROOT}/core/people_graph.php");
$mig = (string) file_get_contents("{$ROOT}/core/migrations/116_people_graph_artifact_roles.sql");
$docs = (string) file_get_contents("{$ROOT}/docs/PEOPLE_GRAPH.md");
$arch = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Artifact helper contract\n";
$out = [];
$rc = 0;
exec('php -l ' . escapeshellarg("{$ROOT}/core/ai/artifacts.php") . ' 2>&1', $out, $rc);
$a('artifacts.php parses', $rc === 0);
$a('requires People Graph', str_contains($art, "require_once __DIR__ . '/../people_graph.php';"));
$a('declares artifact role vocabulary', str_contains($art, 'ARTIFACT_PEOPLE_GRAPH_RESPONSIBILITIES'));
foreach ([
    'artifactAssignPeopleGraph',
    'artifactSyncPeopleGraph',
    'artifactPeopleGraph',
    'artifactResolvePeopleGraph',
] as $fn) {
    $a("exports {$fn}", str_contains($art, "function {$fn}("));
}
$a('artifactCreate accepts people_graph assignment option', str_contains($art, "\$opts['people_graph']"));
$a('artifact assignment calls peopleGraphAssignResponsibility', str_contains($art, 'peopleGraphAssignResponsibility('));
$a('artifact read calls peopleGraphListResponsibilities', str_contains($art, 'peopleGraphListResponsibilities('));
$a('artifact resolver calls peopleGraphResolve', str_contains($art, 'peopleGraphResolve('));
$a('lineage includes people_graph', str_contains($art, "'people_graph'  => \$peopleGraph"));
$a('artifact event records people_graph.assigned', str_contains($art, "'people_graph.assigned'"));

echo "\nPeople Graph vocabulary and migration\n";
foreach (['preparer', 'requester', 'recipient', 'ai_creator'] as $role) {
    $a("People Graph responsibility includes {$role}", str_contains($pg, "'{$role}'"));
    $a("migration expands enum with {$role}", str_contains($mig, "'{$role}'"));
}
$a('resolver supports who_prepares', str_contains($pg, "'who_prepares'"));
$a('resolver supports who_created_ai', str_contains($pg, "'who_created_ai'"));
$a('resolver supports who_receives', str_contains($pg, "'who_receives'"));
$a('migration alters responsibility assignments', str_contains($mig, 'ALTER TABLE people_graph_responsibility_assignments'));
$a('migration alters approval rules', str_contains($mig, 'ALTER TABLE people_graph_approval_policy_rules'));

echo "\nDocs\n";
$a('People Graph docs mention artifact roles', str_contains($docs, 'Artifact ownership, reviewers, approvers, and AI supervisors'));
$a('Architecture docs mention Artifact Graph consumption', str_contains($arch, 'Artifact Graph') && str_contains($arch, 'people roles'));

echo "\nArtifact People Graph smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
