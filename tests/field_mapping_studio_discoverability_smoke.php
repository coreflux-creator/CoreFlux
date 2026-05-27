<?php
/**
 * field_mapping_studio_discoverability_smoke.php
 *
 * Verifies the operator can actually find the Field Mapping Studio from
 * every natural entry point. Triggered after operator feedback "I still
 * don't see the updated JobDiva payload and field mapping tool" — root
 * cause was that the Studio existed but nothing linked to it from
 * JobDiva Settings, Integrations Hub, the Admin sidebar, or the Admin
 * overview.
 *
 * Run:  php -d zend.assertions=1 tests/field_mapping_studio_discoverability_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "Field Mapping Studio — discoverability smoke\n";
echo "=========================================\n";

// 1) JobDiva Settings has a prominent Studio CTA.
echo "\n1. JobDivaSettings.jsx CTA banner\n";
$jds = file_get_contents("$root/dashboard/src/pages/JobDivaSettings.jsx");
$a('imports Link from react-router-dom',
    str_contains($jds, "import { Link } from 'react-router-dom'"));
$a('renders jobdiva-settings-field-map-cta banner',
    str_contains($jds, 'data-testid="jobdiva-settings-field-map-cta"'));
$a('CTA links to the Studio with integration=jobdiva pre-fill',
    str_contains($jds, '/admin/integrations/field-map/studio?integration=jobdiva&entity_type=placement'));
$a('CTA button testid present',
    str_contains($jds, 'data-testid="jobdiva-settings-field-map-studio-link"'));
$a('CTA copy mentions Field Mapping Studio by name',
    str_contains($jds, 'Field Mapping Studio'));

// 2) IntegrationsHub has a Field Mapping section + cards.
echo "\n2. IntegrationsHub.jsx Field Mapping section\n";
$hub = file_get_contents("$root/dashboard/src/pages/IntegrationsHub.jsx");
$a('imports Sparkles icon',
    str_contains($hub, "Sparkles"));
$a('Field Mapping section rendered',
    str_contains($hub, 'title="Field Mapping"'));
$a('Studio card present',
    str_contains($hub, 'testid="integration-card-field-map-studio"')
    && str_contains($hub, 'title="Field Mapping Studio"'));
$a('Studio card links to /admin/integrations/field-map/studio',
    preg_match('#"/admin/integrations/field-map/studio"#', $hub) === 1);
$a('Legacy table card present',
    str_contains($hub, 'testid="integration-card-field-map-legacy"'));

// 3) AdminModule sidebar + overview both list the Studio.
echo "\n3. AdminModule.jsx sidebar + overview entries\n";
$am = file_get_contents("$root/dashboard/src/pages/AdminModule.jsx");
$a('Admin overview ActionCard for Field Mapping Studio',
    str_contains($am, 'title="Field Mapping Studio"')
    && str_contains($am, 'href="/admin/integrations/field-map/studio"'));
$a('Sidebar nav entry for Field Mapping Studio',
    str_contains($am, "label: 'Field Mapping Studio'")
    && str_contains($am, "to: '/admin/integrations/field-map/studio'"));
$a('Existing /integrations/field-map/studio route still mounted',
    str_contains($am, 'path="/integrations/field-map/studio"'));

// 4) FieldMappingStudio respects URL query params for default integration.
echo "\n4. FieldMappingStudio.jsx URL-param defaulting\n";
$fms = file_get_contents("$root/dashboard/src/pages/FieldMappingStudio.jsx");
$a('imports useLocation',
    str_contains($fms, "import { Link, useLocation } from 'react-router-dom'"));
$a('reads ?integration= from query string',
    str_contains($fms, "queryParams.get('integration')"));
$a('reads ?entity_type= from query string',
    str_contains($fms, "queryParams.get('entity_type')"));

// 5) Empty-state CTA points operator at the right sync screen.
echo "\n5. FieldMappingStudio.jsx empty-state CTA\n";
$a('empty-state surface has data-testid="fms-paths-empty"',
    str_contains($fms, 'data-testid="fms-paths-empty"'));
$a('empty-state links to JobDiva when integration=jobdiva',
    str_contains($fms, 'data-testid="fms-paths-empty-jobdiva-link"')
    && str_contains($fms, '/admin/integrations/jobdiva'));
$a('empty-state links to QBO when integration=quickbooks',
    str_contains($fms, 'data-testid="fms-paths-empty-qbo-link"')
    && str_contains($fms, '/admin/integrations/qbo'));
$a('empty-state covers zoho_books and airtable',
    str_contains($fms, 'data-testid="fms-paths-empty-zoho-link"')
    && str_contains($fms, 'data-testid="fms-paths-empty-airtable-link"'));

// 5.5) Joined-entity grouping for the source pane.
echo "\n5.5 Grouped source paths (joined entities)\n";
$a('PATH_GROUPS declares the JobDiva enrichment buckets',
    str_contains($fms, "PATH_GROUPS = [")
    && str_contains($fms, "'_jd_candidate'")
    && str_contains($fms, "'_jd_job'")
    && str_contains($fms, "'_jd_customer'")
    && str_contains($fms, "'_jd_contact'")
    && str_contains($fms, "'_jd_start'"));
$a('groupPathsByNamespace helper exists',
    str_contains($fms, 'function groupPathsByNamespace(paths)'));
$a('groupedPaths memo derives groups from filteredPaths',
    str_contains($fms, 'const groupedPaths = useMemo(() => groupPathsByNamespace(filteredPaths)'));
$a('grouped UI surface rendered with data-testid="fms-paths-grouped"',
    str_contains($fms, 'data-testid="fms-paths-grouped"'));
$a('per-group toggle button has stable testid',
    str_contains($fms, 'data-testid={`fms-paths-group-toggle-${grp.meta.key}`}'));
$a('per-group container exposes data-open attribute',
    str_contains($fms, "data-open={isOpen ? 'yes' : 'no'}"));
$a('placement explainer appears for jobdiva/placement',
    str_contains($fms, 'data-testid="fms-paths-explainer"'));
$a('selecting a path from a joined-entity group auto-sets linked_entity',
    str_contains($fms, "if (grp.meta.linked && grp.meta.linked !== 'self') {")
    && str_contains($fms, 'setLinkedEntity(grp.meta.linked)'));
$a('Person group routes to linked_entity=person',
    preg_match("/'_jd_candidate'.*linked:\s*'person'/s", $fms) === 1);
$a('End-client group routes to linked_entity=end_client_company',
    preg_match("/'_jd_customer'.*linked:\s*'end_client_company'/s", $fms) === 1);

// 5.6) Entity-type dropdown is data-driven (not the old hardcoded list).
echo "\n5.6 Data-driven entity-type dropdown\n";
$a('dropdown options derive from indexed sources',
    str_contains($fms, 'sources') && str_contains($fms, 's.integration === integration'));
$a('fallback list includes JobDiva entity types',
    str_contains($fms, "jobdiva:") && str_contains($fms, "'jobdiva_customer'") && str_contains($fms, "'time_entry'"));
$a('fallback list includes QBO entity types',
    str_contains($fms, "quickbooks:") && str_contains($fms, "'journal_entry'") && str_contains($fms, "'customer'"));
$a('old hardcoded gl_account-only dropdown removed',
    !str_contains($fms, "['placement', 'person', 'company', 'contact', 'gl_account', 'journal_entry', 'bill', 'invoice', 'payment'].map(et =>"));

// 6) PHP syntax sanity on touched JSX-adjacent PHP (none changed in this
//    slice, just confirm bundle sync left the deploy-version coherent).
echo "\n6. Deploy version + dist coherence\n";
$dv = file_get_contents("$root/.deploy-version");
$a('.deploy-version has expected_bundle: block',
    str_contains($dv, 'expected_bundle:'));
$distHtml = file_get_contents("$root/dashboard/dist/index.html");
preg_match('#/spa-assets/(index-[A-Za-z0-9_\-]+\.js)#', $distHtml, $m);
$bundle = $m[1] ?? '';
$a('dist/index.html references a built JS bundle',
    $bundle !== '');
$a('.deploy-version expected_bundle matches dist bundle',
    $bundle !== '' && str_contains($dv, $bundle));
$a('spa-assets/ contains the built JS bundle',
    $bundle !== '' && file_exists("$root/spa-assets/$bundle"));

echo "\n=========================================\n";
echo "Discoverability smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
