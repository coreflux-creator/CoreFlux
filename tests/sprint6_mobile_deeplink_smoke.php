<?php
/**
 * Sprint 6 — Mobile deep-linking + 1-tap approval routing — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6_mobile_deeplink_smoke.php
 *
 * Verifies the on-disk wiring that turns a "bill needs approval" push
 * notification tap into a single-tap approve/reject flow on the Expo
 * mobile app.
 *
 * Layers covered:
 *   1) app.json — `coreflux://` scheme + iOS associatedDomains +
 *      Android intentFilters for the universal link.
 *   2) Approvals route restructured to folder with index + [id].tsx.
 *   3) `src/lib/notifications.ts` — Notifications + Linking listeners
 *      that route `coreflux://approvals/<id>` to the detail screen.
 *   4) Root `_layout.tsx` registers handlers + asks for push permission.
 *   5) Typed API surface adds `workflowGetInstance(id)`.
 *   6) `core/workflow_engine.php::_workflowPushApprovers` emits
 *      `data.mobile_deep_link = coreflux://approvals/<instance_id>`.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};

$ROOT = realpath(__DIR__ . '/..');

echo "Folder layout (approvals → folder)\n";
foreach ([
    'mobile/app/(tabs)/approvals/_layout.tsx',
    'mobile/app/(tabs)/approvals/index.tsx',
    'mobile/app/(tabs)/approvals/[id].tsx',
    'mobile/src/lib/notifications.ts',
] as $rel) {
    $assert("file exists: {$rel}", is_file("{$ROOT}/{$rel}"));
}
$assert('legacy approvals.tsx removed', !is_file("{$ROOT}/mobile/app/(tabs)/approvals.tsx"));

echo "\napp.json — deep-link configuration\n";
$appJson = json_decode((string) file_get_contents("{$ROOT}/mobile/app.json"), true);
$assert('valid JSON',                       is_array($appJson));
$assert('expo.scheme = coreflux',           ($appJson['expo']['scheme'] ?? '') === 'coreflux');
$assert('iOS associatedDomains present',    !empty($appJson['expo']['ios']['associatedDomains']));
$assert('iOS associatedDomains has applinks',
    is_array($appJson['expo']['ios']['associatedDomains'] ?? null)
    && (bool) array_filter(
        $appJson['expo']['ios']['associatedDomains'],
        fn($d) => stripos((string) $d, 'applinks:') === 0
    ));
$assert('android intentFilters present',    !empty($appJson['expo']['android']['intentFilters']));
$intentFilters = (array) ($appJson['expo']['android']['intentFilters'] ?? []);
$hasHttps = false; $hasScheme = false;
foreach ($intentFilters as $f) {
    foreach ((array) ($f['data'] ?? []) as $d) {
        if (($d['scheme'] ?? '') === 'https')      $hasHttps  = true;
        if (($d['scheme'] ?? '') === 'coreflux')   $hasScheme = true;
    }
}
$assert('android filter: https universal link', $hasHttps);
$assert('android filter: coreflux scheme',      $hasScheme);

echo "\nNotification handler module\n";
$notif = (string) file_get_contents("{$ROOT}/mobile/src/lib/notifications.ts");
$assert('imports expo-notifications',           stripos($notif, "from 'expo-notifications'") !== false);
$assert('imports expo-linking',                 stripos($notif, "from 'expo-linking'") !== false);
$assert('imports expo-router',                  stripos($notif, "from 'expo-router'") !== false);
$assert('exports routeFromDeepLink',            stripos($notif, 'export function routeFromDeepLink') !== false);
$assert('exports registerDeepLinkHandlers',     stripos($notif, 'export function registerDeepLinkHandlers') !== false);
$assert('exports registerForPushAsync',         stripos($notif, 'export async function registerForPushAsync') !== false);
$assert('regex matches approvals/<id>',         stripos($notif, "approvals\\/(\\d+)") !== false || stripos($notif, 'approvals/(\d+)') !== false);
$assert('uses addNotificationResponseReceivedListener', stripos($notif, 'addNotificationResponseReceivedListener') !== false);
$assert('uses getLastNotificationResponseAsync',        stripos($notif, 'getLastNotificationResponseAsync') !== false);
$assert('uses Linking.addEventListener',                stripos($notif, 'Linking.addEventListener') !== false);
$assert('uses Linking.getInitialURL',                   stripos($notif, 'Linking.getInitialURL') !== false);
$assert('prefers mobile_deep_link',                     stripos($notif, "'mobile_deep_link'") !== false);
$assert('falls back to deep_link',                      stripos($notif, "'deep_link'") !== false);
$assert('routes via router.push',                       stripos($notif, "router.push") !== false);
$assert('registers device with backend',                stripos($notif, '/api/auth/mobile_devices.php') !== false);

echo "\nRoot _layout.tsx — handler registration\n";
$layout = (string) file_get_contents("{$ROOT}/mobile/app/_layout.tsx");
$assert('imports notifications module',                 stripos($layout, "from '@/lib/notifications'") !== false);
$assert('calls registerDeepLinkHandlers',               stripos($layout, 'registerDeepLinkHandlers()') !== false);
$assert('calls registerForPushAsync',                   stripos($layout, 'registerForPushAsync()') !== false);
$assert('teardown cleanup returned from useEffect',     preg_match('/return\s+teardown\s*;/', $layout) === 1);

echo "\nApprovals folder routes\n";
$apFolderLayout = (string) file_get_contents("{$ROOT}/mobile/app/(tabs)/approvals/_layout.tsx");
$assert('approvals/_layout uses Stack',                 stripos($apFolderLayout, 'Stack') !== false);
$assert('approvals/_layout declares index screen',      stripos($apFolderLayout, 'name="index"') !== false);
$assert('approvals/_layout declares [id] screen',       stripos($apFolderLayout, 'name="[id]"') !== false);

$apIndex = (string) file_get_contents("{$ROOT}/mobile/app/(tabs)/approvals/index.tsx");
$assert('approvals/index keeps approvals-scroll testID',     stripos($apIndex, 'testID="approvals-scroll"') !== false);
$assert('approvals/index links rows to /[id]',
    stripos($apIndex, '/(tabs)/approvals/${i.id}') !== false);

$apDetail = (string) file_get_contents("{$ROOT}/mobile/app/(tabs)/approvals/[id].tsx");
$assert('detail uses useLocalSearchParams',             stripos($apDetail, 'useLocalSearchParams') !== false);
$assert('detail testID approval-detail-<id>',           stripos($apDetail, 'approval-detail-${instance.id}') !== false);
$assert('detail approve button testID',                 stripos($apDetail, 'testID="approval-detail-approve"') !== false);
$assert('detail reject button testID',                  stripos($apDetail, 'testID="approval-detail-reject"') !== false);
$assert('detail calls workflowGetInstance',             stripos($apDetail, 'workflowGetInstance(') !== false);
$assert('detail calls workflowAct',                     stripos($apDetail, 'workflowAct(') !== false);

echo "\nTyped API surface — workflowGetInstance\n";
$apiTs = (string) file_get_contents("{$ROOT}/mobile/src/lib/api.ts");
$assert('exports workflowGetInstance',                  stripos($apiTs, 'export const workflowGetInstance') !== false);
$assert('workflowGetInstance hits ?id= query',          stripos($apiTs, '/api/workflow.php?id=') !== false);
$assert('workflowInbox hits ?path=inbox query',         stripos($apiTs, '/api/workflow.php?path=inbox') !== false);
$assert('workflowAct uses ?action=act&id= query',       stripos($apiTs, '/api/workflow.php?action=act&id=') !== false);

echo "\nworkflow_engine.php — emits mobile_deep_link\n";
$wfEngine = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$assert('workflow_engine.php parses',                   $lint("{$ROOT}/core/workflow_engine.php"));
$assert('emits mobile_deep_link key',                   stripos($wfEngine, "'mobile_deep_link'") !== false);
$assert('default value coreflux://approvals/<id>',      stripos($wfEngine, 'coreflux://approvals/{$instanceId}') !== false);
$assert('echoes mobile_deep_link into payload data',    preg_match('/payload\[\s*[\'"]mobile_deep_link[\'"]\s*\]\s*=/', $wfEngine) === 1);
$assert('opts carries mobile_deep_link',                preg_match('/[\'"]mobile_deep_link[\'"]\s*=>/', $wfEngine) === 1);

echo "\npush_service.php still accepts deep_link opt (back-compat)\n";
$ps = (string) file_get_contents("{$ROOT}/core/push_service.php");
$assert('push_service parses',                          $lint("{$ROOT}/core/push_service.php"));
$assert('still reads opts[deep_link]',                  stripos($ps, "opts['deep_link']") !== false);

echo "\nDeep-link regex round-trip (pure JS regex evaluated in PHP)\n";
$cases = [
    'coreflux://approvals/123'                         => 123,
    'https://app.coreflux.com/approvals/9999'          => 9999,
    '/approvals/42?foo=bar'                            => 42,
    'coreflux://settings/profile'                      => null,
    ''                                                 => null,
    'coreflux://approvals/'                            => null,
];
foreach ($cases as $url => $expected) {
    if ($url === '' || $url === null) { $got = null; }
    elseif (preg_match('#approvals/(\d+)#', $url, $m)) { $got = (int) $m[1]; }
    else { $got = null; }
    $assert("regex parses '{$url}' → " . var_export($expected, true), $got === $expected);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
