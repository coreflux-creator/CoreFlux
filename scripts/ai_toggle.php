<?php
/**
 * scripts/ai_toggle.php — CLI for flipping per-tenant AI on/off.
 *
 *   php scripts/ai_toggle.php on  <tenant_id_or_subdomain>
 *   php scripts/ai_toggle.php off <tenant_id_or_subdomain>
 *   php scripts/ai_toggle.php status [tenant_id_or_subdomain]    # default: every tenant
 *   php scripts/ai_toggle.php full-content-logging on  <tenant>  # opt-in to logging prompts/responses (compliance heavy)
 *   php scripts/ai_toggle.php full-content-logging off <tenant>
 *   php scripts/ai_toggle.php feature <class> on|off <tenant>    # e.g. classification, extraction, summary, narrative, draft, deep_reasoning
 *
 * Exists so any operator can unblock AI features without waiting on the
 * SPA build / deploy. The /admin/ai-settings UI does the same thing for
 * tenant_admins / master_admins; this CLI is the engineer-side mirror.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';

function _aiToggleUsage(): void {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/ai_toggle.php on  <tenant_id_or_subdomain>\n");
    fwrite(STDERR, "  php scripts/ai_toggle.php off <tenant_id_or_subdomain>\n");
    fwrite(STDERR, "  php scripts/ai_toggle.php status [tenant_id_or_subdomain]\n");
    fwrite(STDERR, "  php scripts/ai_toggle.php full-content-logging on|off <tenant>\n");
    fwrite(STDERR, "  php scripts/ai_toggle.php feature <class> on|off <tenant>\n");
    exit(2);
}

function _aiToggleResolveTenant(PDO $pdo, string $ref): array {
    if (ctype_digit($ref)) {
        $stmt = $pdo->prepare('SELECT id, name, subdomain, ai_enabled, ai_full_content_logging FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $ref]);
    } else {
        $stmt = $pdo->prepare('SELECT id, name, subdomain, ai_enabled, ai_full_content_logging FROM tenants WHERE subdomain = :sd LIMIT 1');
        $stmt->execute(['sd' => $ref]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "Tenant not found for ref '$ref'\n");
        exit(3);
    }
    return $row;
}

function _aiTogglePrintRow(array $row): void {
    $on   = (int) $row['ai_enabled'] ? 'ON ' : 'off';
    $log  = (int) $row['ai_full_content_logging'] ? 'ON ' : 'off';
    printf("  [%4d] %-30s subdomain=%-20s ai=%s  full-content-log=%s\n",
        (int) $row['id'], $row['name'], $row['subdomain'] ?? '', $on, $log);
}

$argv = $_SERVER['argv'] ?? [];
$cmd  = $argv[1] ?? '';

// Print usage immediately if no command given — don't try to connect to the DB.
if ($cmd === '') _aiToggleUsage();

$pdo = getDB();
if (!$pdo) { fwrite(STDERR, "No DB connection\n"); exit(1); }

if ($cmd === 'status') {
    if (!empty($argv[2])) {
        _aiTogglePrintRow(_aiToggleResolveTenant($pdo, (string) $argv[2]));
    } else {
        $rows = $pdo->query('SELECT id, name, subdomain, ai_enabled, ai_full_content_logging FROM tenants ORDER BY id ASC')
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo "AI status across " . count($rows) . " tenant(s):\n";
        foreach ($rows as $r) _aiTogglePrintRow($r);
    }
    exit(0);
}

if ($cmd === 'on' || $cmd === 'off') {
    if (empty($argv[2])) _aiToggleUsage();
    $t = _aiToggleResolveTenant($pdo, (string) $argv[2]);
    $val = $cmd === 'on' ? 1 : 0;
    $pdo->prepare('UPDATE tenants SET ai_enabled = :v WHERE id = :id')
        ->execute(['v' => $val, 'id' => (int) $t['id']]);
    $t['ai_enabled'] = $val;
    echo "Updated:\n";
    _aiTogglePrintRow($t);
    exit(0);
}

if ($cmd === 'full-content-logging') {
    $sub = $argv[2] ?? '';
    if (!in_array($sub, ['on', 'off'], true) || empty($argv[3])) _aiToggleUsage();
    $t = _aiToggleResolveTenant($pdo, (string) $argv[3]);
    $val = $sub === 'on' ? 1 : 0;
    $pdo->prepare('UPDATE tenants SET ai_full_content_logging = :v WHERE id = :id')
        ->execute(['v' => $val, 'id' => (int) $t['id']]);
    $t['ai_full_content_logging'] = $val;
    echo "Updated:\n";
    _aiTogglePrintRow($t);
    exit(0);
}

if ($cmd === 'feature') {
    $cls = $argv[2] ?? '';
    $sub = $argv[3] ?? '';
    $ref = $argv[4] ?? '';
    $known = ['classification','extraction','summary','narrative','draft','deep_reasoning'];
    if (!in_array($cls, $known, true) || !in_array($sub, ['on','off'], true) || $ref === '') _aiToggleUsage();
    $t = _aiToggleResolveTenant($pdo, $ref);
    $val = $sub === 'on' ? 1 : 0;
    $pdo->prepare(
        'INSERT INTO ai_tenant_features (tenant_id, feature_class, enabled, updated_at)
         VALUES (:t, :f, :e, NOW())
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()'
    )->execute(['t' => (int) $t['id'], 'f' => $cls, 'e' => $val]);
    echo "Updated feature '$cls' to " . ($val ? 'ON' : 'off') . " for tenant " . (int) $t['id'] . " ({$t['name']})\n";
    exit(0);
}

_aiToggleUsage();
