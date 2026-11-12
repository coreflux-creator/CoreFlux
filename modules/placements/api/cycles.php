<?php
/** Purpose-specific staffing operating cycles and placement assignments. */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';
require_once __DIR__ . '/../lib/cycle_assignments.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) placementsGraphTenantId((int) $ctx['tenant_id']);
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' || $method === 'POST') {
    rbac_legacy_require($user, $method === 'GET' ? 'placements.view' : 'placements.manage');
    try {
        if ($method === 'GET') {
            $purpose=(string)($_GET['purpose']??'');
            if ($purpose!==''&&!in_array($purpose,['billing','ap','payroll'],true)) api_error('Invalid purpose',422);
            if (!empty($_GET['placement_id'])) {
                $result=placementCycleReview($tenantId,(int)$_GET['placement_id']);
                $result['cycles']=array_values(array_filter($result['cycles'],static fn($cycle)=>($purpose===''||$cycle['purpose']===$purpose)&&(!empty($_GET['include_inactive'])||(int)$cycle['active']===1)));
                api_ok($result);
            }
            $q=getDB()->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? ORDER BY purpose,name');
            $q->execute([$tenantId]);
            api_ok(['cycles'=>array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn($cycle)=>($purpose===''||$cycle['purpose']===$purpose)&&(!empty($_GET['include_inactive'])||(int)$cycle['active']===1))),'placement'=>null]);
        }
        $body=api_json_body();
        if ($action === 'assign') {
            $placementId=$body['placement_id']??null;
            if (is_bool($placementId)||!preg_match('/^[1-9][0-9]*$/D',(string)$placementId)) api_error('placement_id required',422);
            $token=(string)($body['review_token']??'');unset($body['placement_id'],$body['review_token']);
            api_ok(placementCycleAssign($tenantId,(int)$placementId,$body,$token));
        }
        if ($action!=='') api_error('Unknown schedule action',400);
        $result=placementCycleCreate($tenantId,$body,$user);
        api_ok($result,$result['changed']?201:200);
    } catch (PDOException $e) {
        error_log('[native placement schedule] '.$e->getCode());
        api_error('The schedule change could not be saved. Reload and review its current settings.',503);
    } catch (InvalidArgumentException|RuntimeException $e) {
        api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);
    }
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $allowed = ['name','cadence','anchor_date','settlement_offset_days','default_payment_terms','active'];
    $updates = [];
    foreach ($allowed as $field) if (array_key_exists($field, $body)) $updates[$field] = $body[$field];
    if (!$updates) api_error('No fields to update', 422);
    if (isset($updates['cadence']) && !in_array($updates['cadence'], ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) {
        api_error('Invalid cadence', 422);
    }
    if (isset($updates['anchor_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $updates['anchor_date'])) {
        api_error('anchor_date must be YYYY-MM-DD', 422);
    }
    if (array_key_exists('default_payment_terms', $updates) && $updates['default_payment_terms'] !== null) {
        $updates['default_payment_terms'] = placementEconomicsNormaliseTerms((string) $updates['default_payment_terms']);
    }
    scopedUpdate('staffing_operating_cycles', $id, $updates);
    placementsAudit('staffing.operating_cycle.updated', ['cycle_id' => $id, 'fields' => array_keys($updates)], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
