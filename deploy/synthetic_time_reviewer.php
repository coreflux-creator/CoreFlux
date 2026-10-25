<?php
/** Bounded pilot provisioning; callers load the installed membership and RBAC services. */
declare(strict_types=1);

const SYNTHETIC_REVIEWER_EMAIL = 'core-time-reviewer-20261025@example.invalid';
const SYNTHETIC_REVIEWER_NAME = 'Core Pilot Test Approver';
const SYNTHETIC_REVIEWER_MARKER = 'synthetic_time_reviewer_20261025';

function syntheticTimeReviewerVerify(PDO $pdo, int $id): array
{
    RBACResolver::resetCache();
    $st=$pdo->prepare('SELECT id,tenant_id,name,email,role,is_active,is_global_admin,password,password_hash FROM users WHERE id=?');
    $st->execute([$id]);$user=$st->fetch(PDO::FETCH_ASSOC);
    if (!$user || $id===4 || (int)$user['tenant_id']!==999 || $user['email']!==SYNTHETIC_REVIEWER_EMAIL
        || $user['name']!==SYNTHETIC_REVIEWER_NAME || $user['role']!=='manager'
        || (int)$user['is_active']!==1 || (int)$user['is_global_admin']!==0
        || !is_string($user['password_hash']) || ($user['password_hash']!==$user['password'])
        || (password_get_info($user['password_hash'])['algoName']??'unknown')==='unknown') {
        throw new RuntimeException('Synthetic reviewer identity differs');
    }
    unset($user['password'],$user['password_hash']);
    $st=$pdo->prepare('SELECT * FROM tenant_memberships WHERE user_id=? ORDER BY id');$st->execute([$id]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);$m=$rows[0]??[];
    if (count($rows)!==1 || (int)$m['tenant_id']!==999 || $m['persona_type']!=='manager'
        || $m['persona_label']!=='Pilot time reviewer' || $m['status']!=='active' || (int)$m['is_primary']!==1
        || $m['linked_entity_type']!==null || $m['linked_entity_id']!==null
        || membershipTenantCountForUser($id)!==1 || (membershipRoleLabelsForTenant(999,$pdo)[$id]??null)!=='manager') {
        throw new RuntimeException('Synthetic reviewer membership differs');
    }
    $mid=(int)$m['id'];
    $st=$pdo->prepare('SELECT module_key,access_level,sub_tenant_scope,granted_by_user_id FROM membership_module_access WHERE membership_id=?');
    $st->execute([$mid]);$grants=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($grants)!==1 || $grants[0]['module_key']!=='time' || $grants[0]['access_level']!=='admin'
        || json_decode((string)$grants[0]['sub_tenant_scope'],true)!==[999] || (int)$grants[0]['granted_by_user_id']!==4) {
        throw new RuntimeException('Synthetic reviewer grants differ');
    }
    $user['global_role']=$user['role'];$user['role']=RBACResolver::personaTypeOf($id,999);
    foreach (['time.view','time.review','time.approve','time.reject'] as $permission) {
        [$module,$action]=RbacLegacyMap::resolve($permission);
        if (!RBAC::hasPermission($user,$permission) || !RBACResolver::can($user,999,$module,$action,999)) {
            throw new RuntimeException('Synthetic reviewer lacks native time permission');
        }
    }
    foreach (['people','placements','staffing','companies','billing','ap','accounting','payroll','treasury','reports','integrations','ai','tenant'] as $module) {
        if (RBACResolver::can($user,999,$module,'read')) throw new RuntimeException('Unexpected module access');
    }
    foreach (['payroll.runs.execute','treasury.payments.release','accounting.journal.post','time.entry.self','time.entry.manage','tenant.users.manage'] as $permission) {
        if (RBAC::hasPermission($user,$permission)) throw new RuntimeException('Unexpected legacy execution permission');
    }
    $st=$pdo->prepare('SELECT detail FROM membership_audit WHERE tenant_id=999 AND membership_id=? AND target_user_id=? AND actor_user_id=4 AND action=?');
    $st->execute([$mid,$id,SYNTHETIC_REVIEWER_MARKER]);$audits=$st->fetchAll(PDO::FETCH_COLUMN);
    if (count($audits)!==1 || (json_decode($audits[0],true)['scope']??null)!=='synthetic_time_review_only') {
        throw new RuntimeException('Synthetic reviewer audit receipt missing');
    }
    return ['user_id'=>$id,'membership_id'=>$mid,'display_name'=>SYNTHETIC_REVIEWER_NAME,
        'tenant_id'=>999,'time_review_allowed_in_both_models'=>true,'other_modules_denied'=>true,
        'global_admin'=>false,'other_active_tenant_memberships'=>0,'audit_verified'=>true];
}

function syntheticTimeReviewerProvision(PDO $pdo): array
{
    if ($pdo!==getDB() || $pdo->inTransaction()) throw new RuntimeException('Dedicated native connection required');
    $locked=false;
    try {
        $locked=(int)$pdo->query("SELECT GET_LOCK('core_sim_999_time_reviewer_20261025',10)")->fetchColumn()===1;
        if (!$locked) throw new RuntimeException('Reviewer provisioning already running');
        $pdo->beginTransaction();
        if ((int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1 FOR UPDATE')->fetchColumn()!==1
            || !$pdo->query("SELECT id FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetchColumn()) {
            throw new RuntimeException('Simulation workspace or operator differs');
        }
        $st=$pdo->prepare('SELECT id FROM users WHERE email=? FOR UPDATE');$st->execute([SYNTHETIC_REVIEWER_EMAIL]);
        $ids=$st->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids)>1) throw new RuntimeException('Ambiguous reviewer identity');
        $created=count($ids)===0;
        if ($created) {
            // Generate and discard the password in-process. No credentials or invitations are issued.
            // This is not a claim that all passwordless/SSO login paths are disabled.
            $hash=password_hash(bin2hex(random_bytes(48)),PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (tenant_id,name,email,password,password_hash,role,is_active,is_global_admin,created_at)
                VALUES (999,?,?,?, ?,"manager",1,0,NOW())')->execute([SYNTHETIC_REVIEWER_NAME,SYNTHETIC_REVIEWER_EMAIL,$hash,$hash]);
            unset($hash);$id=(int)$pdo->lastInsertId();
            $m=provisionMembership($id,999,'manager',['is_primary'=>true,'persona_label'=>'Pilot time reviewer','status'=>'active']);
            RBACResolver::grantModule((int)$m['membership_id'],'time','admin',[999],4);
            // Unlike the native best-effort audits, this receipt is mandatory and transactional.
            $pdo->prepare('INSERT INTO membership_audit (tenant_id,membership_id,action,actor_user_id,target_user_id,detail)
                VALUES (999,?,?,4,?,?)')->execute([(int)$m['membership_id'],SYNTHETIC_REVIEWER_MARKER,$id,
                    json_encode(['scope'=>'synthetic_time_review_only','automation_test'=>true,
                        'independent_human_review'=>false,'credentials_issued'=>false,'invitation_sent'=>false],JSON_THROW_ON_ERROR)]);
        } else $id=(int)$ids[0];
        $report=syntheticTimeReviewerVerify($pdo,$id);
        $pdo->commit();return $report+['created'=>$created,'credentials_issued'=>false,'invitation_sent'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        RBACResolver::resetCache();throw $e;
    } finally {
        if ($locked) $pdo->query("SELECT RELEASE_LOCK('core_sim_999_time_reviewer_20261025')");
    }
}
