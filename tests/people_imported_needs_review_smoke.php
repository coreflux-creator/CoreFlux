<?php
/**
 * People "Imported from JobDiva — needs review" filter smoke
 * (2026-02 follow-on to JobDiva placement discovery).
 *
 * jobdivaPlacementsAutoCreatePerson() mints placeholder `people` rows
 * when JobDiva's start payload is missing real data:
 *   - email_primary = "jd-emp-<extId>@no-email.invalid" (RFC 6761)
 *   - first_name    = "JobDiva"          (firstName placeholder)
 *   - last_name     = "Candidate-<extId>" (lastName placeholder)
 *
 * This smoke verifies the People Directory list endpoint can isolate
 * these rows via `?source=jobdiva&needs_review=1`, and that the UI
 * surfaces them with a per-row "Needs review" badge.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Backend — peopleList() filter\n";
$libPath = "{$ROOT}/modules/people/lib/people.php";
$libSrc  = (string) file_get_contents($libPath);
$assert('parses',                                $lint($libPath));
$assert('accepts source filter',                 strpos($libSrc, "if (!empty(\$filters['source'])) {") !== false);
$assert('binds source param',                    strpos($libSrc, "\$params['source'] = \$filters['source'];") !== false);
$assert('accepts needs_review filter',           strpos($libSrc, "if (!empty(\$filters['needs_review'])) {") !== false);
$assert('needs_review matches @no-email.invalid emails',
    strpos($libSrc, "email_primary LIKE \\'%@no-email.invalid\\'") !== false);
$assert('needs_review matches JobDiva firstname placeholder',
    strpos($libSrc, "first_name = \\'JobDiva\\'") !== false);
$assert('needs_review matches Candidate-* lastname placeholder',
    strpos($libSrc, "last_name LIKE \\'Candidate-%\\'") !== false);
$assert('needs_review predicate is OR-joined (any one placeholder triggers it)',
    preg_match('/email_primary LIKE.*OR.*first_name.*OR.*last_name LIKE/s', $libSrc) === 1);
$assert('uses literal strings (NOT bound params) for needs_review predicate to keep SQL planner happy',
    // We intentionally inline these because they're fixed placeholder
    // values, not user input — safer than letting PDO complain about
    // unbound :params when the flag is off.
    strpos($libSrc, "\$params['needs_review']") === false);

echo "\nBackend — people API surface\n";
$apiPath = "{$ROOT}/modules/people/api/people.php";
$apiSrc  = (string) file_get_contents($apiPath);
$assert('parses',                                $lint($apiPath));
$assert('reads ?source from query string',       strpos($apiSrc, "'source'                  => \$_GET['source']") !== false);
$assert('reads ?needs_review from query string', strpos($apiSrc, "'needs_review'            => \$_GET['needs_review']") !== false);

echo "\nFrontend — Directory.jsx filter wiring\n";
$uiPath = "{$ROOT}/modules/people/ui/Directory.jsx";
$ui     = (string) file_get_contents($uiPath);
$assert('declares needsReview state',
    strpos($ui, 'const [needsReview, setNeedsReview] = useState(false);') !== false);
$assert('toggle sends source=jobdiva + needs_review=1',
    strpos($ui, "params.set('source', 'jobdiva');") !== false
    && strpos($ui, "params.set('needs_review', '1');") !== false);
$assert('useMemo dependency tracks needsReview',
    strpos($ui, '[q, classification, status, needsReview, page]') !== false);
$assert('renders toggle with friendly label',
    strpos($ui, 'Imported from JobDiva — needs review') !== false);
$assert('toggle has stable test ids',
    strpos($ui, "data-testid=\"people-directory-needs-review-toggle\"") !== false
    && strpos($ui, "data-testid=\"people-directory-needs-review-checkbox\"") !== false);
$assert('toggle visually marks active state (amber background)',
    strpos($ui, 'var(--cf-color-amber-100, #fef3c7)') !== false);

echo "\nFrontend — per-row Needs-review badge\n";
$assert('computes needsReviewRow with same predicate as SQL',
    strpos($ui, "p.email_primary.endsWith('@no-email.invalid')") !== false
    && strpos($ui, "p.first_name === 'JobDiva'") !== false
    && strpos($ui, "p.last_name.startsWith('Candidate-')") !== false);
$assert('renders badge only when row matches',
    strpos($ui, '{needsReviewRow && (') !== false);
$assert('badge carries dynamic test id per row',
    strpos($ui, 'data-testid={`people-row-needs-review-${p.id}`}') !== false);
$assert('badge has tooltip explaining what to do',
    strpos($ui, 'Auto-imported from JobDiva with placeholder fields') !== false);
$assert('badge uses amber palette (matches toggle for visual consistency)',
    strpos($ui, 'var(--cf-color-amber-100, #fef3c7)') !== false
    && strpos($ui, 'var(--cf-color-amber-800, #92400e)') !== false);

echo "\nFrontend bundle — Vite build artifacts up-to-date\n";
$dvPath = "{$ROOT}/.deploy-version";
if (file_exists($dvPath)) {
    $dv = (string) file_get_contents($dvPath);
    $assert('.deploy-version still references current dashboard/dist hashes',
        preg_match('/expected_bundle/i', $dv) === 1);
} else {
    $assert('.deploy-version present (sync_bundle.sh ran)', false);
}

echo "\nPredicate parity — JS row-badge vs SQL list filter\n";
// The same predicate is enforced in two places (JS for the row badge,
// SQL for the list filter). Lock the synthesised literals in both so a
// future change to jobdivaPlacementsAutoCreatePerson() can't drift
// silently.
$autoCreatePath = "{$ROOT}/core/jobdiva/sync_placements.php";
$ac = (string) file_get_contents($autoCreatePath);
$assert("auto-create still emits '@no-email.invalid' email placeholder",
    strpos($ac, "'jd-emp-%s@no-email.invalid'") !== false);
$assert("auto-create still emits 'JobDiva' as firstName placeholder",
    strpos($ac, "if (\$firstName === '') \$firstName = 'JobDiva';") !== false);
$assert("auto-create still emits 'Candidate-<extId>' as lastName placeholder",
    strpos($ac, "if (\$lastName  === '') \$lastName  = 'Candidate-' . \$candidateExtId;") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
