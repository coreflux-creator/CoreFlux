<?php
/**
 * Placement chain — submittal/VMS + encrypted portal credentials smoke.
 * Static contract checks on lib + API + React wiring.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "Schema (mig 006 Part C)\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/people/migrations/006_unify_and_extend.sql');
foreach (['submittal_id','vms_job_id','portal_credentials_ct','kms_key_version'] as $col) {
    $a("placement_client_chain.{$col}", strpos($mig, "TABLE_NAME='placement_client_chain' AND COLUMN_NAME='{$col}'") !== false);
}

echo "\nLib helpers\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/placements/lib/placements.php');
$a('placementChain SELECT is allow-listed',          strpos($lib, 'SELECT id, tenant_id, placement_id, position') !== false);
$a('placementChain hides ciphertext',                strpos($lib, 'SELECT * FROM placement_client_chain') === false);
$a('placementChain returns has_portal_credentials',  strpos($lib, 'has_portal_credentials') !== false);
$a('placementChainSetPortalCredentials() exists',    strpos($lib, 'function placementChainSetPortalCredentials') !== false);
$a('set requires encryption module',                 strpos($lib, "require_once __DIR__ . '/../../../core/encryption.php'") !== false);
$a('set persists kms_key_version v1',                strpos($lib, "'v' => 'v1'") !== false);
$a('placementChainClearPortalCredentials() exists',  strpos($lib, 'function placementChainClearPortalCredentials') !== false);
$a('clear nulls both ct + kms',                      strpos($lib, 'portal_credentials_ct = NULL, kms_key_version = NULL') !== false);
$a('placementChainRevealPortalCredentials() exists', strpos($lib, 'function placementChainRevealPortalCredentials') !== false);
$a('reveal returns null when missing',               strpos($lib, 'if (!$ct) return null') !== false);

echo "\nAPI surface\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/placements/api/chain.php');
$a('GET reveal_portal route',                        strpos($api, "GET' && \$action === 'reveal_portal'") !== false);
$a('reveal requires placements.portal_credentials.view', strpos($api, "'placements.portal_credentials.view'") !== false);
$a('reveal audits placement.chain.portal.viewed',    strpos($api, "'placement.chain.portal.viewed'") !== false);
$a('POST set_portal route',                          strpos($api, "POST' && \$action === 'set_portal'") !== false);
$a('set requires placements.manage',                 strpos($api, "rbac_legacy_require(\$user, 'placements.manage')") !== false);
$a('set audit lists field NAMES only (no plaintext)', strpos($api, "'fields'       => array_keys(\$clean)") !== false);
$a('POST clear_portal route',                        strpos($api, "POST' && \$action === 'clear_portal'") !== false);
$a('clear audits placement.chain.portal.cleared',    strpos($api, "'placement.chain.portal.cleared'") !== false);
$a('chain POST persists submittal_id',               strpos($api, "'submittal_id'    => \$body['submittal_id']") !== false);
$a('chain POST persists vms_job_id',                 strpos($api, "'vms_job_id'      => \$body['vms_job_id']") !== false);
$a('PATCH strips portal_credentials_ct',             strpos($api, "unset(\$body[\$k])") !== false
                                                      && strpos($api, "'portal_credentials_ct','kms_key_version','has_portal_credentials'") !== false);

echo "\nManifest registration\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/placements/manifest.php');
$a('declares placements.portal_credentials.view',    strpos($man, 'placements.portal_credentials.view') !== false);
$a('audit_events: placement.chain.portal.set',       strpos($man, 'placement.chain.portal.set') !== false);
$a('audit_events: placement.chain.portal.cleared',   strpos($man, 'placement.chain.portal.cleared') !== false);
$a('audit_events: placement.chain.portal.viewed',    strpos($man, 'placement.chain.portal.viewed') !== false);

echo "\nReact ChainTab UI\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/placements/ui/PlacementDetail.jsx');
$a('add-form: submittal input',                      strpos($ui, 'data-testid="chain-submittal"') !== false);
$ui_ok_vms = (strpos($ui, 'data-testid="chain-vms"') !== false) || (strpos($ui, 'data-testid="chain-vms-') !== false);
$a('add-form: vms input',                            strpos($ui, 'placeholder="VMS Job #"') !== false);
$a('row: inline edit submittal',                     strpos($ui, 'chain-submittal-${c.id}') !== false);
$a('row: inline edit vms',                           strpos($ui, 'chain-vms-${c.id}') !== false);
$a('inline edit Save+Cancel handlers',               strpos($ui, '-save') !== false && strpos($ui, '-cancel') !== false);
$a('row: portal button per chain row',               strpos($ui, 'chain-portal-btn-${c.id}') !== false);
$a('badge ↔ has_portal_credentials',                 strpos($ui, "c.has_portal_credentials ? '🔒 Manage' : '+ Set'") !== false);
$a('PortalCredsDialog rendered conditionally',       strpos($ui, '{portalFor && (') !== false);
$a('dialog testid',                                  strpos($ui, 'data-testid="chain-portal-dialog"') !== false);
$a('dialog reveal posts to action=reveal_portal',    strpos($ui, 'action=reveal_portal&id=') !== false);
$a('dialog save posts to action=set_portal',         strpos($ui, 'action=set_portal&id=') !== false);
$a('dialog clear posts to action=clear_portal',      strpos($ui, 'action=clear_portal&id=') !== false);
$a('reveal confirms (audit warning)',                strpos($ui, 'audit trail') !== false);
$a('password input is type=password',                strpos($ui, 'type="password"') !== false);
$a('dialog testids: url/username/password/notes',    strpos($ui, 'chain-portal-url') !== false
                                                      && strpos($ui, 'chain-portal-username') !== false
                                                      && strpos($ui, 'chain-portal-password') !== false
                                                      && strpos($ui, 'chain-portal-notes') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
