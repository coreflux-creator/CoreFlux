<?php
/**
 * Sprint 7b smoke — Posting-rule sandbox UI + API entry point.
 *
 * Asserts the sandbox path is wired end-to-end: API endpoint accepts the
 * sandbox action, the React page is registered under /admin/rule-sandbox,
 * and the page exposes the testids the user/QA needs to verify behaviour.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Backend — sandbox action on accounting_events.php\n";
$api = (string) file_get_contents("{$ROOT}/api/accounting_events.php");
$assert('sandbox action recognised',
    strpos($api, "\$action === 'sandbox'") !== false
    || strpos($api, "\$pathAction === 'sandbox'") !== false);
$assert('sandbox calls accountingProcessEvent with dryRun=true',
    preg_match('#accountingProcessEvent\([^)]*true\s*\)#', $api) === 1);
$assert('sandbox surfaces failure as 200+failed (UX trade-off)',
    strpos($api, "'status' => 'failed'") !== false);

echo "\nFrontend — RuleSandbox.jsx page\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/RuleSandbox.jsx");
$assert('page file exists',                         strlen($jsx) > 0);
$assert('top-level testid: rule-sandbox-page',      strpos($jsx, 'data-testid="rule-sandbox-page"') !== false);
$assert('JSON editor testid',                       strpos($jsx, 'data-testid="rule-sandbox-json"') !== false);
$assert('Run button testid',                        strpos($jsx, 'data-testid="rule-sandbox-run"') !== false);
$assert('Sample chip — bank fee',                   strpos($jsx, 'data-testid="rule-sandbox-sample-bank-fee"') !== false);
$assert('Sample chip — interest',                   strpos($jsx, 'data-testid="rule-sandbox-sample-interest"') !== false);
$assert('Sample chip — bill approved',              strpos($jsx, 'data-testid="rule-sandbox-sample-bill-approved"') !== false);
$assert('result panel testid',                      strpos($jsx, 'data-testid="rule-sandbox-result"') !== false);
$assert('status testid',                            strpos($jsx, 'data-testid="rule-sandbox-status"') !== false);
$assert('preview block testid',                     strpos($jsx, 'data-testid="rule-sandbox-preview"') !== false);
$assert('lines table testid',                       strpos($jsx, 'data-testid="rule-sandbox-lines"') !== false);
$assert('total debit testid',                       strpos($jsx, 'data-testid="rule-sandbox-total-debit"') !== false);
$assert('total credit testid',                      strpos($jsx, 'data-testid="rule-sandbox-total-credit"') !== false);
$assert('error testid',                             strpos($jsx, 'data-testid="rule-sandbox-error"') !== false);
$assert('parse error testid',                       strpos($jsx, 'data-testid="rule-sandbox-parse-error"') !== false);
$assert('raw response testid (collapsed)',          strpos($jsx, 'data-testid="rule-sandbox-raw"') !== false);
$assert('hits sandbox endpoint',                    strpos($jsx, "/api/accounting_events.php?action=sandbox") !== false);
$assert('FlaskConical icon for the brand',          strpos($jsx, 'FlaskConical') !== false);
$assert('does NOT post real events (dry only)',     strpos($jsx, "'/api/accounting_events.php'") === false
                                                  || strpos($jsx, 'action=sandbox') !== false);

echo "\nAdminModule.jsx — Rule Sandbox wired\n";
$adm = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$assert('imports RuleSandbox',                      strpos($adm, "import RuleSandbox from './RuleSandbox'") !== false);
$assert('imports FlaskConical icon',                strpos($adm, 'FlaskConical') !== false);
$assert('overview ActionCard linked to /admin/rule-sandbox',
    strpos($adm, 'href="/admin/rule-sandbox"') !== false);
$assert('sidebar link wired',                       strpos($adm, "to: '/admin/rule-sandbox'") !== false);
$assert('Route mounted',                            strpos($adm, 'path="/rule-sandbox"') !== false);

echo "\nApp.jsx — sidebar entries for posting rules + events\n";
$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert("'Posting Rules' nav action",               strpos($app, "name: 'Posting Rules'") !== false);
$assert("'Rule Sandbox' nav action",                strpos($app, "name: 'Rule Sandbox'") !== false);
$assert("'Accounting Events' nav action",           strpos($app, "name: 'Accounting Events'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
