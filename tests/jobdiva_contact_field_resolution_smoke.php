<?php
/**
 * JobDiva Contact Field Resolution smoke — verifies the case- and
 * separator-insensitive field-pluck logic that fixes the production
 * "0 ok, 49 skip" symptom.
 *
 * Background:
 *   - JobDiva V2 BI /apiv2/bi/NewUpdatedContactRecords returns records
 *     with key shapes that drift across tenants/releases:
 *         "id", "ID", "Id", "contactId"
 *         "first name", "FIRST NAME", "FirstName", "firstname"
 *         "company id", "COMPANYID", "CompanyId", "companyId"
 *   - The pre-fix parser only matched a small handful of literal strings
 *     and silently skipped the rest.
 *   - jobdivaPluckField() now normalises both record keys AND candidate
 *     names to lowercase-alphanumeric, so a single canonical candidate
 *     list catches every variant.
 *
 * This smoke locks the helper signature, the contact parser's candidate
 * lists, and the sample-keys diagnostic that surfaces JobDiva's actual
 * shape in the audit log when records still fail the gate.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');
$path = "{$ROOT}/core/jobdiva/sync.php";
$src  = (string) file_get_contents($path);

echo "Helper — jobdivaPluckField()\n";
$assert('exported',                              strpos($src, 'function jobdivaPluckField(array $item, array $candidates): string') !== false);
$assert('normalises keys with preg_replace',     strpos($src, "preg_replace('/[^a-z0-9]/i', '', \$k)") !== false);
$assert('normalises candidates with preg_replace',
    strpos($src, "preg_replace('/[^a-z0-9]/i', '', \$cand)") !== false);
$assert('skips non-scalar values defensively',   strpos($src, 'if (is_scalar($v)) {') !== false);
$assert('trims and skips empty strings',         strpos($src, 'if ($s !== \'\') return $s;') !== false);

// Load + invoke the helper in-process. core/jobdiva/sync.php pulls in
// client.php which calls getDB() at require time only via function defs,
// so requiring the file is safe in CLI smoke context.
require_once $path;

echo "\nLive behaviour\n";
$assert('matches "first name" (space)',          jobdivaPluckField(['first name' => 'Alice'], ['first name']) === 'Alice');
$assert('matches "FIRSTNAME" via candidate "first name"',
    jobdivaPluckField(['FIRSTNAME' => 'Alice'], ['first name']) === 'Alice');
$assert('matches "FirstName" via candidate "firstName"',
    jobdivaPluckField(['FirstName' => 'Alice'], ['firstName']) === 'Alice');
$assert('matches "COMPANY ID" via candidate "companyId"',
    jobdivaPluckField(['COMPANY ID' => '123'], ['companyId']) === '123');
$assert('falls through to second candidate',
    jobdivaPluckField(['lastName' => 'Smith'], ['firstName', 'lastName']) === 'Smith');
$assert('returns empty string when nothing matches',
    jobdivaPluckField(['foo' => 'bar'], ['id', 'contactId']) === '');
$assert('skips array values (no stringification)',
    jobdivaPluckField(['id' => ['nested' => 1], 'contactId' => '42'], ['id', 'contactId']) === '42');
$assert('returns empty for whitespace-only scalar',
    jobdivaPluckField(['id' => '   '], ['id']) === '');
$assert('casts numerics to string',              jobdivaPluckField(['id' => 7], ['id']) === '7');

echo "\nLive behaviour — jobdivaNormaliseDate()\n";
$assert('epoch ms (13 digits) → Y-m-d',          jobdivaNormaliseDate('1779231290000') === gmdate('Y-m-d', 1779231290));
$assert('epoch ms as integer also works',        jobdivaNormaliseDate(1779231290000) === gmdate('Y-m-d', 1779231290));
$assert('epoch seconds (10 digits) → Y-m-d',     jobdivaNormaliseDate('1779231290') === gmdate('Y-m-d', 1779231290));
$assert('ISO date passes through',               jobdivaNormaliseDate('2026-05-22') === '2026-05-22');
$assert('ISO datetime trims to date',            jobdivaNormaliseDate('2026-05-22T08:32:43+0000') === '2026-05-22');
$assert('US slash format parses',                jobdivaNormaliseDate('5/22/2026') === '2026-05-22');
$assert('empty string → null',                   jobdivaNormaliseDate('') === null);
$assert('"0" → null (JobDiva placeholder)',      jobdivaNormaliseDate('0') === null);
$assert('"null" string → null',                  jobdivaNormaliseDate('null') === null);
$assert('garbage input → null (no fatal)',       jobdivaNormaliseDate('not-a-date') === null);
$assert('actual null → null',                    jobdivaNormaliseDate(null) === null);

echo "\nContacts driver — candidate lists\n";
$assert('extId candidate list includes id/contactId/contactID',
    strpos($src, "jobdivaPluckField(\$jd, ['id', 'contactId', 'contact_id', 'contactID']);") !== false);
$assert('companyExtId candidates include space + camel + ALL CAPS variants',
    strpos($src, "'company id', 'companyId', 'company_id', 'companyID',") !== false
    && strpos($src, "'CompanyId', 'COMPANYID', 'clientId', 'client_id',") !== false);
$assert('firstName candidates include space + camel + lower variants',
    strpos($src, "['first name', 'firstName', 'first_name', 'firstname']") !== false);
$assert('lastName candidates include space + camel + lower variants',
    strpos($src, "['last name',  'lastName',  'last_name',  'lastname']") !== false);
$assert('name candidates include fullName/contactName variants',
    strpos($src, "'fullName', 'full_name', 'contactName', 'contact_name'") !== false);
$assert("name falls back to firstName + ' ' + lastName when no direct name field",
    strpos($src, "if (\$name === '') \$name = trim(\$firstName . ' ' . \$lastName);") !== false);

echo "\nContacts driver — diagnostic surface\n";
$assert('captures sample keys for first 3 items',
    strpos($src, '$sampleKeys = [];') !== false
    && strpos($src, '$idx < 3 && is_array($jd)) $sampleKeys[$idx] = array_keys($jd);') !== false);
$assert('captures up to 2 redacted sample records on missing-field skip',
    strpos($src, '$sampleMissing = [];') !== false
    && strpos($src, 'count($sampleMissing) < 2') !== false);
$assert('truncates sample scalar values to 60 chars',
    strpos($src, 'substr((string) $v, 0, 60)') !== false);
$assert('summarises non-scalar sample fields by type',
    strpos($src, "'[' . gettype(\$v) . ']'") !== false);
$assert('audit detail surfaces sample_keys + sample_records',
    strpos($src, "'sample_keys'    => \$sampleKeys,") !== false
    && strpos($src, "'sample_records' => \$sampleMissing,") !== false);
$assert('errors[] for missing_fields includes sample_keys for UI panel',
    strpos($src, "'kind'   => 'missing_fields'") !== false
    && strpos($src, "'sample_keys'    => \$sampleKeys,") !== false);

echo "\nContact upsert helper — V2 BI key tolerance (slice 5: through registry fallback)\n";
// The plucker candidate lists were preserved verbatim — they're now the
// static-fn fallback the field-map registry calls when no tenant rule exists.
$assert('email lookup includes "email" + "primary email"',
    strpos($src, "jobdivaPluckField(\$jd, [\n            'email', 'emailAddress', 'email_address', 'primary email', 'primaryEmail',\n        ])") !== false);
$assert('phone lookup includes "phone 1" / camelCase / snake_case / work phone',
    strpos($src, "jobdivaPluckField(\$jd, [\n            'phone 1', 'phone', 'phoneNumber', 'phone_number', 'workPhone', 'work phone',\n        ])") !== false);
$assert('title lookup includes "job title" space variant',
    strpos($src, "jobdivaPluckField(\$jd, ['title', 'jobTitle', 'job_title', 'job title'])") !== false);
$assert('contact upsert routes through tenantIntegrationFieldMapPluckInternal',
    strpos($src, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'contact', 'email'") !== false);

echo "\nCompanies driver — V2 BI fallback (additive, preserves smoke contract)\n";
$assert('preserves existing legacy ?? chain for companyId',
    strpos($src, "(string) (\$jd['id'] ?? \$jd['companyId'] ?? \$jd['company_id'] ?? '')") !== false);
$assert('adds V2 BI pluck fallback for extId',
    strpos($src, "jobdivaPluckField(\$jd, ['id', 'companyId', 'company_id', 'companyID', 'CompanyId', 'COMPANYID'])") !== false);
$assert('adds V2 BI pluck fallback for name (now via registry $pluck helper)',
    strpos($src, "\$pluck('name', ['name', 'companyName', 'company_name', 'company name', 'COMPANY NAME']);") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
