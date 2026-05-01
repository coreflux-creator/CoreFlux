<?php
/**
 * Companies module — Phase 1 contract smoke tests (static + library).
 * No live DB. All assertions pure-PHP.
 */
declare(strict_types=1);
require_once __DIR__ . '/../modules/people/lib/companies.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; } };

echo "Migration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/people/migrations/004_companies.sql');
$assert('migration exists',                    strlen($sql) > 0);
$assert('utf8mb4_unicode_ci',                  strpos($sql, 'utf8mb4_unicode_ci') !== false);
$assert('NOT 0900_ai_ci',                      strpos($sql, 'utf8mb4_0900_ai_ci') === false);
foreach (['companies','company_roles','company_contacts'] as $t) {
    $assert("table {$t}",                       strpos($sql, "CREATE TABLE IF NOT EXISTS {$t}") !== false);
}
$assert('roles enum has 8 roles',              strpos($sql, "ENUM('client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner')") !== false);
$assert('UNIQUE company name per tenant',      strpos($sql, 'uq_companies_tenant_name') !== false);
$assert('UNIQUE role per company',             strpos($sql, 'uq_company_role') !== false);
$assert('FK company_roles → companies',        strpos($sql, 'fk_company_roles_company') !== false);
$assert('FK company_contacts → companies',     strpos($sql, 'fk_company_contacts_company') !== false);
$assert('encrypted EIN column',                strpos($sql, 'ein_full_ct') !== false && strpos($sql, 'VARBINARY(512)') !== false);
$assert('use_count + last_used_at present',    strpos($sql, 'use_count') !== false && strpos($sql, 'last_used_at') !== false);
$assert('soft-delete column',                  strpos($sql, 'deleted_at') !== false);
$assert('idempotent ALTER placements',         strpos($sql, 'end_client_company_id') !== false);
$assert('idempotent ALTER chain',              strpos($sql, "TABLE_NAME='placement_client_chain' AND COLUMN_NAME='company_id'") !== false);
$assert('idempotent ALTER referrals',          strpos($sql, "TABLE_NAME='placement_referrals' AND COLUMN_NAME='referrer_company_id'") !== false);
$assert('backfill from chain',                 strpos($sql, "FROM placement_client_chain") !== false);
$assert('backfill from end_client_name',       strpos($sql, "FROM placements") !== false && strpos($sql, "end_client_name") !== false);
$assert('backfill from referrals',             strpos($sql, "referrer_vendor_name FROM placement_referrals") !== false);
$assert('backfill tags client role',           strpos($sql, "INSERT IGNORE INTO company_roles (company_id, role)") !== false);
$assert('backfill writes FK back to placements', strpos($sql, "UPDATE placements p") !== false);
$assert('backfill writes FK back to chain',    strpos($sql, "UPDATE placement_client_chain pcc") !== false);
$assert('backfill writes FK back to referrals', strpos($sql, "UPDATE placement_referrals pr") !== false);

echo "\nLibrary contract\n";
foreach (['companiesGet','companyRoles','companyContacts','companiesList','companiesUpsertByName','companiesAddRole','companiesRemoveRole','companiesBumpUsage','companiesAudit'] as $f) {
    $assert("fn: {$f}",                         function_exists($f));
}
$assert('COMPANY_ROLES const has 8 roles',     defined('COMPANY_ROLES') && count(COMPANY_ROLES) === 8);
$assert('COMPANY_ROLES includes client',       in_array('client', COMPANY_ROLES, true));
$assert('COMPANY_ROLES includes referrer',     in_array('referrer', COMPANY_ROLES, true));

echo "\ncompaniesAddRole rejects invalid role\n";
$thrown = false;
try { companiesAddRole(0, 'bogus'); } catch (\InvalidArgumentException $e) { $thrown = true; }
$assert('throws on invalid role',              $thrown);

echo "\nAPI file parses + actions wired\n";
$apiFile = __DIR__ . '/../modules/people/api/companies.php';
$assert('api file exists',                     is_file($apiFile));
$o = []; $rc = 0; @exec('php -l ' . escapeshellarg($apiFile) . ' 2>&1', $o, $rc);
$assert('api file parses',                     $rc === 0);
$apiSrc = (string) file_get_contents($apiFile);
foreach (['upsert','add-role','remove-role','add-contact'] as $a) {
    $assert("api action: {$a}",                 strpos($apiSrc, "action === '{$a}'") !== false);
}
$assert('GET typeahead by q+role',             strpos($apiSrc, "\$_GET['q']") !== false && strpos($apiSrc, "\$_GET['role']") !== false);
$assert('PATCH allows role rewrite',           strpos($apiSrc, "DELETE FROM company_roles") !== false);
$assert('DELETE soft-deletes (deleted_at)',    strpos($apiSrc, 'SET deleted_at = NOW()') !== false);
$assert('DELETE requires people.manage',       strpos($apiSrc, "RBAC::requirePermission(\$user, 'people.manage')") !== false);

echo "\nPlacements integration\n";
$placeApi = (string) file_get_contents(__DIR__ . '/../modules/placements/api/placements.php');
$assert('placements POST resolves end_client_company_id', strpos($placeApi, 'end_client_company_id') !== false);
$assert('placements POST tags company role=client',       strpos($placeApi, "'client'") !== false);
$assert('placements POST upserts company by name',        strpos($placeApi, 'companiesUpsertByName') !== false);
$assert('placements safeFields includes end_client_company_id', strpos((string) file_get_contents(__DIR__ . '/../modules/placements/lib/placements.php'), 'end_client_company_id') !== false);

$chainApi = (string) file_get_contents(__DIR__ . '/../modules/placements/api/chain.php');
$assert('chain POST accepts company_id',                  strpos($chainApi, "\$body['company_id']") !== false);
$assert('chain POST falls back to party_name + auto-creates company', strpos($chainApi, 'companiesUpsertByName') !== false);
$assert('chain POST tags vendor role from party_role',    strpos($chainApi, '$roleForDir') !== false);
$assert('chain POST refuses missing company_id AND party_name', strpos($chainApi, 'Either company_id') !== false);

$refApi = (string) file_get_contents(__DIR__ . '/../modules/placements/api/referrals.php');
$assert('referrals POST resolves referrer_company_id',    strpos($refApi, 'referrer_company_id') !== false);
$assert('referrals POST tags role=referrer',              strpos($refApi, "'referrer'") !== false);
$assert('referrals POST only resolves company for vendor referrer', strpos($refApi, "'vendor'") !== false);

echo "\nUI wiring (Clients + Vendors split — no 'Companies' in user-facing routes)\n";
foreach (['CompanyTypeahead.jsx','DirectoryModule.jsx','ClientsModule.jsx','VendorsModule.jsx'] as $c) {
    $assert("ui/{$c} exists",                   is_file(__DIR__ . "/../modules/people/ui/{$c}"));
}
$assert("OLD CompaniesModule.jsx removed",     !is_file(__DIR__ . '/../modules/people/ui/CompaniesModule.jsx'));
$dm = (string) file_get_contents(__DIR__ . '/../modules/people/ui/DirectoryModule.jsx');
foreach (['DirectoryList','DirectoryCreate','DirectoryDetail'] as $c) {
    $assert("DirectoryModule renders {$c}",     strpos($dm, "function {$c}") !== false);
}
$assert('mode-driven (clients/vendors)',        strpos($dm, "MODES = {") !== false && strpos($dm, "clients:") !== false && strpos($dm, "vendors:") !== false);
$assert('cross-link badge on detail',           strpos($dm, 'cross-link') !== false);
$assert('clients default terms NET30',          strpos($dm, "defaultTerms: 'NET30'") !== false);
$assert('vendors default terms NET45',          strpos($dm, "defaultTerms: 'NET45'") !== false);
$assert('detail testids parameterized by mode', strpos($dm, '${mode}-detail-name') !== false);
$assert('list testids parameterized by mode',   strpos($dm, '${mode}-list') !== false);

$cw = (string) file_get_contents(__DIR__ . '/../modules/people/ui/ClientsModule.jsx');
$vw = (string) file_get_contents(__DIR__ . '/../modules/people/ui/VendorsModule.jsx');
$assert('ClientsModule passes mode=clients',    strpos($cw, 'mode="clients"') !== false);
$assert('VendorsModule passes mode=vendors',    strpos($vw, 'mode="vendors"') !== false);

$ta = (string) file_get_contents(__DIR__ . '/../modules/people/ui/CompanyTypeahead.jsx');
$assert('typeahead debounces',                  strpos($ta, 'debounceRef') !== false);
$assert('typeahead create-on-the-fly',          strpos($ta, 'handleCreate') !== false);
$assert('typeahead role filter passed to API',  strpos($ta, "params.set('role', role)") !== false);
$assert('typeahead keyboard nav',               strpos($ta, 'ArrowDown') !== false && strpos($ta, 'ArrowUp') !== false);

$pm = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PeopleModule.jsx');
$assert('PeopleModule wires clients/*',         strpos($pm, '"clients/*"') !== false);
$assert('PeopleModule wires vendors/*',         strpos($pm, '"vendors/*"') !== false);
$assert('PeopleModule does NOT route companies/*', strpos($pm, '"companies/*"') === false);
$assert('PeopleModule imports ClientsModule',   strpos($pm, "import ClientsModule") !== false);
$assert('PeopleModule imports VendorsModule',   strpos($pm, "import VendorsModule") !== false);

$pd = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PersonDetail.jsx');
$assert('PersonDetail has + New placement CTA', strpos($pd, 'person-detail-new-placement') !== false);
$assert('CTA prefills ?person_id=',             strpos($pd, '?person_id=') !== false);

$pc = (string) file_get_contents(__DIR__ . '/../modules/placements/ui/PlacementCreate.jsx');
$assert('PlacementCreate uses CompanyTypeahead', strpos($pc, 'import CompanyTypeahead') !== false);
$assert('PlacementCreate end-client typeahead', strpos($pc, 'placement-create-end-client') !== false);
$assert('PlacementCreate accepts ?person_id',   strpos($pc, "search.get('person_id')") !== false);
$assert('PlacementCreate vendor chain editor', strpos($pc, 'placement-create-chain-add') !== false);
$assert('PlacementCreate initial rate fields', strpos($pc, 'placement-create-rate-bill') !== false);
$assert('PlacementCreate c2c corp section',    strpos($pc, "engagement_type === 'c2c'") !== false);
$assert('PlacementCreate posts company_id to chain', strpos($pc, 'company_id: c.company.id') !== false);
$assert('PlacementCreate uses bill_rate_unit/pay_rate_unit', strpos($pc, 'bill_rate_unit') !== false && strpos($pc, 'pay_rate_unit') !== false);
$assert('PlacementCreate uses corp_legal_name',  strpos($pc, 'corp_legal_name') !== false);

echo "\nSidebar (core/modules.php) — Clients + Vendors split\n";
$mods = (string) file_get_contents(__DIR__ . '/../core/modules.php');
$assert('Clients action under People',         strpos($mods, "'route' => 'clients'") !== false);
$assert('Vendors action under People',         strpos($mods, "'route' => 'vendors'") !== false);
$assert('NO Companies action in sidebar',      strpos($mods, "'route' => 'companies'") === false);

echo "\nv2 hardening migration (005_companies_v2.sql)\n";
$v2 = (string) file_get_contents(__DIR__ . '/../modules/people/migrations/005_companies_v2.sql');
$assert('v2 migration exists',                 strlen($v2) > 0);
$assert('v2 utf8mb4_unicode_ci',               strpos($v2, 'utf8mb4_unicode_ci') !== false);
$assert('v2 NOT 0900_ai_ci',                   strpos($v2, 'utf8mb4_0900_ai_ci') === false);
$assert('company_addresses table',             strpos($v2, 'CREATE TABLE IF NOT EXISTS company_addresses') !== false);
$assert('addresses kind enum (5 kinds)',       strpos($v2, "ENUM('hq','billing','remit_to','worksite','mailing')") !== false);
$assert('FK addresses → companies',            strpos($v2, 'fk_ca_company') !== false);
foreach (['account_manager_user_id','default_terms','currency','status','tax_classification','industry','employee_size_range','w9_on_file','w9_expires_on','coi_on_file','coi_expires_on','tags_json'] as $col) {
    $assert("v2 adds companies.{$col}",         strpos($v2, "COLUMN_NAME='{$col}'") !== false);
}
foreach (['mobile_phone','linkedin_url','department','decision_role','is_active'] as $col) {
    $assert("v2 adds company_contacts.{$col}",  strpos($v2, "TABLE_NAME='company_contacts' AND COLUMN_NAME='{$col}'") !== false);
}
$assert('v2 status enum has 4 states',         strpos($v2, "ENUM(\"prospect\",\"active\",\"inactive\",\"blacklisted\")") !== false);
$assert('v2 tax_classification enum 8 values', strpos($v2, '"c_corp","s_corp","llc","partnership","sole_prop","nonprofit","government","other"') !== false);
$assert('v2 decision_role enum',               strpos($v2, '"decision_maker","champion","influencer","blocker","gatekeeper","unknown"') !== false);

echo "\nLib + API hardening\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/people/lib/companies.php');
$assert('lib: companyAddresses fn',            strpos($lib, 'function companyAddresses') !== false);
$assert('companiesGet returns addresses',      strpos($lib, "row['addresses']") !== false);
$assert('companiesGet decodes tags_json',      strpos($lib, 'tags_json') !== false);

$apiSrc = (string) file_get_contents(__DIR__ . '/../modules/people/api/companies.php');
foreach (['add-address','address'] as $a) {
    $assert("api action: {$a}",                 strpos($apiSrc, "action === '{$a}'") !== false);
}
$assert('api: address kind validated',         strpos($apiSrc, "invalid kind") !== false);
$assert('api: at-most-one-primary per kind',   strpos($apiSrc, 'is_primary = 0') !== false);
$assert('api: tags accepted as array',         strpos($apiSrc, "tags_json") !== false);
$assert('api: account_manager_user_id POST',   strpos($apiSrc, 'account_manager_user_id') !== false);
$assert('api: w9_on_file boolean coerced',     strpos($apiSrc, 'w9_on_file') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
