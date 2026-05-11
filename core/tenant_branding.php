<?php
/**
 * Tenant email branding — lightweight read-through cache + render helpers.
 *
 *   cf_tenant_branding(int $tenantId): array
 *     → ['logo_url'=>?str, 'accent_color'=>str, 'signature_html'=>str, 'show_powered_by'=>bool]
 *
 *   cf_branding_header_html(array $branding, string $title): string
 *     → Renders a logo + accent-coloured title banner used by digest emails.
 *
 *   cf_branding_footer_html(array $branding, string $tenantName): string
 *     → Renders the signature + optional "Powered by CoreFlux" line.
 *
 * Defaults are CoreFlux-neutral so existing emails stay readable for
 * tenants who haven't customised anything.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function cf_tenant_branding(int $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId])) return $cache[$tenantId];
    $out = [
        'logo_url'        => null,
        'accent_color'    => '#0f172a',
        'signature_html'  => '',
        'show_powered_by' => true,
    ];
    try {
        $st = getDB()->prepare(
            'SELECT logo_url, accent_color, signature_html, show_powered_by
               FROM tenant_mail_branding WHERE tenant_id = :t LIMIT 1'
        );
        $st->execute(['t' => $tenantId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            if (!empty($row['logo_url']) && preg_match('#^https://#i', (string) $row['logo_url'])) {
                $out['logo_url'] = (string) $row['logo_url'];
            }
            if (!empty($row['accent_color']) && preg_match('/^#[0-9a-f]{6}$/i', (string) $row['accent_color'])) {
                $out['accent_color'] = (string) $row['accent_color'];
            }
            if (!empty($row['signature_html'])) {
                $out['signature_html'] = (string) $row['signature_html'];
            }
            $out['show_powered_by'] = (bool) $row['show_powered_by'];
        }
    } catch (\Throwable $_) { /* table not migrated yet */ }
    $cache[$tenantId] = $out;
    return $out;
}

function cf_branding_header_html(array $branding, string $title): string
{
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $accent = (string) ($branding['accent_color'] ?? '#0f172a');
    $logo = $branding['logo_url']
        ? '<img src="' . $h($branding['logo_url']) . '" alt="" style="height:32px;display:block;margin-bottom:12px">'
        : '';
    return '<div style="border-left:4px solid ' . $h($accent) . ';padding-left:12px;margin-bottom:18px">'
         . $logo
         . '<h2 style="margin:0;color:' . $h($accent) . ';font-size:20px">' . $h($title) . '</h2>'
         . '</div>';
}

function cf_branding_footer_html(array $branding, string $tenantName): string
{
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $sig = trim((string) ($branding['signature_html'] ?? ''));
    // Note: signature_html is admin-supplied so it MAY contain markup. We
    // strip <script>/<iframe> defensively but otherwise trust it (it never
    // reaches an untrusted reader — only people who already auth'd into
    // the tenant can edit it).
    $sigClean = $sig === '' ? '' : preg_replace('#<(script|iframe|object|embed)[\s\S]*?</\1>#i', '', $sig);

    $out = '';
    if ($sigClean !== '') $out .= '<div style="margin-top:24px;color:#475569;font-size:13px;line-height:1.6">' . $sigClean . '</div>';
    if (!empty($branding['show_powered_by'])) {
        $out .= '<p style="margin-top:16px;color:#94a3b8;font-size:11px">— ' . $h($tenantName) . ' &middot; powered by CoreFlux</p>';
    } else {
        $out .= '<p style="margin-top:16px;color:#94a3b8;font-size:11px">— ' . $h($tenantName) . '</p>';
    }
    return $out;
}
