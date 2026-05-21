<?php
/**
 * Core tenant-mail helpers (Model B + per-purpose layer).
 *
 * Resolves the effective sender for an outbound email given (tenant, purpose).
 *
 * Model B rule: From is always the platform-wide RESEND_FROM_EMAIL, but the
 * display name and Reply-To can be tenant-overridden — first at the per-purpose
 * granularity (tenant_mail_senders), then falling back to the per-tenant
 * legacy columns (tenants.mail_from_name_override / mail_reply_to), then the
 * platform RESEND_FROM_NAME default. An additional `enabled` flag (per
 * purpose) hard-mutes a category — mailerSend returns {ok:false,
 * driver:'disabled'} without dispatching.
 *
 * Model C (tenant-verified custom From domain) will bolt on later.
 *
 * Returns:
 *   [
 *     'from'       => string|null  // always platform RESEND_FROM_EMAIL in Model B
 *     'from_name'  => ?string      // resolved per-purpose → tenant → platform
 *     'reply_to'   => ?string      // resolved per-purpose → tenant → null
 *     'enabled'    => bool         // per-purpose mute; true when no row exists
 *     'model'      => 'B'          // reserved for C later
 *     'source'     => 'purpose'|'tenant'|'platform'
 *   ]
 */

require_once __DIR__ . '/db.php';

/**
 * Canonical purpose registry. Keys here:
 *   - identify the purpose row in tenant_mail_senders
 *   - drive the default {tenant_name} {label} display name when no override
 *   - are referenced by mailerSend() callers via the 'purpose' arg
 *
 * Adding a purpose: append a row here + cover with smoke test. No migration
 * needed — purposes are free-text VARCHAR(40) in the table.
 */
if (!function_exists('cf_mail_purpose_registry')) {
    function cf_mail_purpose_registry(): array {
        return [
            ['key' => 'timesheets',    'module' => 'staffing', 'label' => 'Timesheets',     'description' => 'Timesheet approver notifications.'],
            ['key' => 'ap',            'module' => 'ap',       'label' => 'AP',             'description' => 'Bill approval requests sent to approvers.'],
            ['key' => 'vendor_portal', 'module' => 'ap',       'label' => 'Vendor Portal',  'description' => 'Magic-link invites to the vendor self-serve portal.'],
            ['key' => 'cfo',           'module' => 'cfo',      'label' => 'CFO',            'description' => 'CFO digests and ad-hoc reports.'],
            ['key' => 'payments',      'module' => 'treasury', 'label' => 'Payments',       'description' => 'Mercury payment lifecycle notifications.'],
        ];
    }
}

if (!function_exists('cf_mail_purpose_lookup')) {
    function cf_mail_purpose_lookup(string $purposeKey): ?array {
        foreach (cf_mail_purpose_registry() as $row) {
            if ($row['key'] === $purposeKey) return $row;
        }
        return null;
    }
}

if (!function_exists('cf_tenant_mail_sender')) {
    function cf_tenant_mail_sender(int $tenantId, string $purpose = 'core'): array
    {
        $platformFrom = getenv('RESEND_FROM_EMAIL') ?: (defined('RESEND_FROM_EMAIL') ? constant('RESEND_FROM_EMAIL') : (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : null));
        $platformName = getenv('RESEND_FROM_NAME')  ?: (defined('RESEND_FROM_NAME')  ? constant('RESEND_FROM_NAME')  : (defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : null));

        $fromName = $platformName;
        $replyTo  = null;
        $enabled  = true;
        $source   = 'platform';

        if ($tenantId > 0) {
            try {
                $pdo = getDB();
                if ($pdo) {
                    // Per-purpose override (preferred).
                    $stmt = $pdo->prepare(
                        'SELECT from_name, reply_to, enabled
                           FROM tenant_mail_senders
                          WHERE tenant_id = :t AND purpose = :p
                          LIMIT 1'
                    );
                    $stmt->execute(['t' => $tenantId, 'p' => $purpose]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                    if ($row) {
                        $enabled = (int) $row['enabled'] === 1;
                        if (!empty($row['from_name'])) { $fromName = (string) $row['from_name']; $source = 'purpose'; }
                        if (!empty($row['reply_to']))  { $replyTo  = (string) $row['reply_to'];  $source = 'purpose'; }
                    }
                    // Tenant-wide legacy override (only fills in slots not set by purpose row).
                    $stmt = $pdo->prepare(
                        'SELECT name, mail_reply_to, mail_from_name_override
                           FROM tenants WHERE id = :id LIMIT 1'
                    );
                    $stmt->execute(['id' => $tenantId]);
                    $trow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                    if ($trow) {
                        if ($fromName === $platformName && !empty($trow['mail_from_name_override'])) {
                            $fromName = (string) $trow['mail_from_name_override'];
                            if ($source === 'platform') $source = 'tenant';
                        }
                        if ($replyTo === null && !empty($trow['mail_reply_to'])) {
                            $replyTo = (string) $trow['mail_reply_to'];
                            if ($source === 'platform') $source = 'tenant';
                        }
                        // Final fallback: derive a per-purpose default of "{tenant_name} {Purpose Label}".
                        if ($source === 'platform' || $source === 'tenant') {
                            $purposeMeta = cf_mail_purpose_lookup($purpose);
                            if ($purposeMeta && !empty($trow['name'])) {
                                $derived = trim($trow['name'] . ' ' . $purposeMeta['label']);
                                // Only swap in the derived name when no explicit override is set.
                                if ($source === 'platform' || $fromName === $platformName) {
                                    $fromName = $derived;
                                    $source   = $source === 'platform' ? 'derived' : $source;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Schema not migrated yet? fall back to platform defaults.
                error_log('[tenant_mail] lookup failed: ' . $e->getMessage());
            }
        }

        return [
            'from'      => $platformFrom,
            'from_name' => $fromName,
            'reply_to'  => $replyTo,
            'enabled'   => $enabled,
            'model'     => 'B',
            'source'    => $source,
        ];
    }
}

/**
 * Admin helper — full list of {purpose, override_row?, resolved_preview}
 * for the tenant. Used by the Settings → Notifications UI.
 */
if (!function_exists('cf_mail_senders_list')) {
    function cf_mail_senders_list(int $tenantId): array {
        $registry = cf_mail_purpose_registry();
        $rows = [];
        try {
            $pdo = getDB();
            if ($pdo) {
                $stmt = $pdo->prepare('SELECT purpose, from_name, reply_to, enabled, updated_at
                                          FROM tenant_mail_senders WHERE tenant_id = :t');
                $stmt->execute(['t' => $tenantId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $rows[(string) $r['purpose']] = [
                        'from_name'  => $r['from_name'],
                        'reply_to'   => $r['reply_to'],
                        'enabled'    => (int) $r['enabled'] === 1,
                        'updated_at' => $r['updated_at'],
                    ];
                }
            }
        } catch (\Throwable $_) { /* table may not exist yet */ }

        $out = [];
        foreach ($registry as $purpose) {
            $resolved = cf_tenant_mail_sender($tenantId, $purpose['key']);
            $override = $rows[$purpose['key']] ?? null;
            $out[] = [
                'key'         => $purpose['key'],
                'label'       => $purpose['label'],
                'module'      => $purpose['module'],
                'description' => $purpose['description'],
                'override'    => $override,
                'resolved'    => [
                    'from'      => $resolved['from'],
                    'from_name' => $resolved['from_name'],
                    'reply_to'  => $resolved['reply_to'],
                    'enabled'   => $resolved['enabled'],
                    'source'    => $resolved['source'],
                    'display'   => $resolved['from_name']
                        ? sprintf('%s <%s>', $resolved['from_name'], $resolved['from'] ?: 'no-reply@unconfigured')
                        : ($resolved['from'] ?: 'no-reply@unconfigured'),
                ],
            ];
        }
        return $out;
    }
}

if (!function_exists('cf_mail_senders_upsert')) {
    function cf_mail_senders_upsert(int $tenantId, string $purpose, array $data, ?int $userId): void {
        if ($tenantId <= 0)  throw new InvalidArgumentException('tenant_id required');
        if (!cf_mail_purpose_lookup($purpose)) {
            throw new InvalidArgumentException('Unknown purpose: ' . $purpose);
        }
        $fromName = isset($data['from_name'])
            ? (($data['from_name'] === null || $data['from_name'] === '') ? null : trim((string) $data['from_name']))
            : null;
        $replyTo  = isset($data['reply_to'])
            ? (($data['reply_to'] === null || $data['reply_to'] === '') ? null : trim((string) $data['reply_to']))
            : null;
        $enabled  = array_key_exists('enabled', $data) ? (int) (bool) $data['enabled'] : 1;

        if ($fromName !== null) {
            if (strlen($fromName) > 120) throw new InvalidArgumentException('from_name too long (max 120)');
            if (preg_match('/[\r\n<>]/', $fromName)) throw new InvalidArgumentException('from_name has forbidden characters');
        }
        if ($replyTo !== null) {
            if (strlen($replyTo) > 255) throw new InvalidArgumentException('reply_to too long');
            if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('reply_to must be a valid email');
        }
        $pdo = getDB();
        if (!$pdo) throw new RuntimeException('No database connection');
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_mail_senders (tenant_id, purpose, from_name, reply_to, enabled, updated_by_user_id)
             VALUES (:t, :p, :fn, :rt, :en, :u)
             ON DUPLICATE KEY UPDATE
                 from_name = VALUES(from_name),
                 reply_to  = VALUES(reply_to),
                 enabled   = VALUES(enabled),
                 updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $stmt->execute([
            't'  => $tenantId,
            'p'  => $purpose,
            'fn' => $fromName,
            'rt' => $replyTo,
            'en' => $enabled,
            'u'  => $userId,
        ]);
    }
}

