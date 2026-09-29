<?php
/** One-time, guarded correction of misclassified Connecteam history in Arabella Quanta. */
declare(strict_types=1);

function quantaSoieWorkerName(array $worker): string
{
    $name = trim((string) ($worker['full_name'] ?? $worker['name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($worker['first_name'] ?? '') . ' ' . (string) ($worker['last_name'] ?? ''));
    }
    return preg_replace('/\s+/', ' ', $name) ?: '';
}

/** Return only the 26 expected changes; any source drift aborts the whole batch. */
function quantaSoiePlan(array $workers, array $worksites, array $entries): array
{
    $targets = [
        'Alice Mesonzhnik' => ['worksite' => 'CT-BOC', 'client' => 'BOC_BOC_CAPITAL', 'department' => 'BOC', 'count' => 1, 'minutes' => 180],
        'Erica Maye' => ['worksite' => 'CT-INTERNAL', 'client' => 'INTERNAL', 'department' => 'Internal', 'count' => 25, 'minutes' => 5400],
    ];
    $sites = [];
    foreach ($worksites as $site) {
        $code = (string) ($site['code'] ?? '');
        $id = (string) ($site['id'] ?? '');
        if ($code !== '' && $id !== '') {
            if (isset($sites[$code])) throw new RuntimeException("Duplicate Quanta worksite code {$code}");
            $sites[$code] = $id;
        }
    }
    foreach (['CT-SOIE', 'CT-BOC', 'CT-INTERNAL'] as $code) {
        if (!isset($sites[$code])) throw new RuntimeException("Missing Quanta worksite {$code}");
    }

    $targetWorkers = [];
    foreach ($workers as $worker) {
        $name = quantaSoieWorkerName($worker);
        if (!isset($targets[$name])) continue;
        if (isset($targetWorkers[$name])) throw new RuntimeException("Duplicate Quanta worker {$name}");
        $id = (string) ($worker['id'] ?? '');
        if ($id === '') throw new RuntimeException("Quanta worker {$name} has no ID");
        $targetWorkers[$name] = $id;
    }
    if (count($targetWorkers) !== count($targets)) {
        throw new RuntimeException('Alice and Erica must each resolve to exactly one Quanta worker');
    }
    $namesById = array_flip($targetWorkers);
    $counts = array_fill_keys(array_keys($targets), 0);
    $minutes = array_fill_keys(array_keys($targets), 0);
    $patches = [];
    foreach ($entries as $entry) {
        if ((string) ($entry['worksite_id'] ?? '') !== $sites['CT-SOIE']) continue;
        $name = $namesById[(string) ($entry['worker_id'] ?? '')] ?? null;
        if ($name === null) throw new RuntimeException('SOIE entry belongs to an unexpected worker');
        $id = (string) ($entry['id'] ?? '');
        $duration = $entry['duration_minutes'] ?? null;
        $dimensions = $entry['dimension_values'] ?? null;
        if ($id === '' || !is_numeric($duration) || (int) $duration <= 0 || (float) $duration !== (float) (int) $duration) {
            throw new RuntimeException("SOIE entry for {$name} has an invalid ID or duration");
        }
        if (!is_array($dimensions) || ($dimensions['client'] ?? null) !== 'SOIE') {
            throw new RuntimeException("SOIE entry for {$name} has unexpected client dimensions");
        }
        $status = strtolower((string) ($entry['timesheet_status'] ?? $entry['timesheet']['status'] ?? 'draft'));
        if ($status !== 'draft') throw new RuntimeException("SOIE entry for {$name} is no longer draft");
        $dimensions['client'] = $targets[$name]['client'];
        $dimensions['department'] = $targets[$name]['department'];
        $patches[] = [
            'id' => $id, 'worker' => $name,
            'worksite_id' => $sites[$targets[$name]['worksite']],
            'dimension_values' => $dimensions,
            'original_worksite_id' => $sites['CT-SOIE'],
            'original_dimensions' => $entry['dimension_values'],
            'duration_minutes' => (int) $duration,
            'timesheet_status' => $status,
        ];
        $counts[$name]++;
        $minutes[$name] += (int) $duration;
    }
    foreach ($targets as $name => $expected) {
        if ($counts[$name] !== $expected['count'] || $minutes[$name] !== $expected['minutes']) {
            throw new RuntimeException("Unexpected SOIE source total for {$name}: {$counts[$name]} entries, {$minutes[$name]} minutes");
        }
    }
    if (count($patches) !== 26 || count(array_unique(array_column($patches, 'id'))) !== 26) {
        throw new RuntimeException('SOIE correction requires 26 unique entries');
    }
    return $patches;
}

function quantaSoiePatch(string $key, array $patch): void
{
    $id = $patch['id'];
    if (!preg_match('/^[a-zA-Z0-9-]{1,128}$/', $id)) throw new RuntimeException('Invalid Quanta entry ID');
    $body = json_encode([
        'worksite_id' => $patch['worksite_id'],
        'dimension_values' => $patch['dimension_values'],
    ], JSON_THROW_ON_ERROR);
    $ch = curl_init('https://helloquanta.app/api/v1/time-entries/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("Quanta refused entry {$id} update (HTTP {$status}); stop and reconcile before retrying");
    }
    $saved = quantaGet($key, '/time-entries/' . $id);
    if (($saved['worksite_id'] ?? null) !== $patch['worksite_id']
        || ($saved['dimension_values'] ?? null) !== $patch['dimension_values']
        || (int) ($saved['duration_minutes'] ?? -1) !== $patch['duration_minutes']
        || strtolower((string) ($saved['timesheet_status'] ?? $saved['timesheet']['status'] ?? 'draft')) !== $patch['timesheet_status']) {
        throw new RuntimeException("Quanta entry {$id} did not read back with the requested context");
    }
}

function quantaSoieMain(array $argv): void
{
    if (count($argv) !== 2 || !in_array($argv[1], ['--preview', '--apply'], true)) {
        throw new InvalidArgumentException('Use --preview or --apply');
    }
    require_once __DIR__ . '/../core/api_bootstrap.php';
    require_once __DIR__ . '/../core/quanta/client.php';
    $rows = getDB()->query(
        "SELECT c.tenant_id, t.name FROM quanta_connections c JOIN tenants t ON t.id = c.tenant_id
          WHERE c.status = 'active' AND t.name LIKE 'Arabella%'"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) throw new RuntimeException('Expected exactly one active Arabella Quanta connection');
    $key = quantaApiKey((int) $rows[0]['tenant_id']);
    $workers = quantaListAll($key, '/workers', [], 5000);
    $worksites = quantaListAll($key, '/worksites', [], 2000);
    $entries = quantaListAll($key, '/time-entries', [], 10000);
    $patches = quantaSoiePlan($workers, $worksites, $entries);
    $sample = quantaGet($key, '/time-entries/' . $patches[0]['id']);
    if (($sample['worksite_id'] ?? null) !== $patches[0]['original_worksite_id']
        || ($sample['dimension_values'] ?? null) !== $patches[0]['original_dimensions']
        || (int) ($sample['duration_minutes'] ?? -1) !== $patches[0]['duration_minutes']) {
        throw new RuntimeException('Quanta entry detail differs from the list; no correction was applied');
    }
    echo "Arabella SOIE preview: Alice 1 entry/180 minutes -> BOC; Erica 25 entries/5400 minutes -> Internal.\n";
    if ($argv[1] === '--preview') return;
    foreach ($patches as $patch) quantaSoiePatch($key, $patch);
    echo "Verified 26 Quanta entry updates; original entry IDs, hours and approval status preserved.\n";
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try { quantaSoieMain($argv); }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
