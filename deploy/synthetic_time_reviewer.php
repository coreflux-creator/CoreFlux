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
    // tenant-leak-allow: inspect every membership of the already verified reserved simulation user, to reject any foreign-company grant rather than hide it with a tenant filter.
    $st=$pdo->prepare('SELECT id,tenant_id,persona_type,persona_label,status,is_primary,linked_entity_type,linked_entity_id FROM tenant_memberships WHERE user_id=? ORDER BY id');$st->execute([$id]);
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
    foreach (['payroll.run.create','payroll.run.approve','payroll.run.disburse','payroll.run.post',
        'treasury.execute_payment','treasury.approve_transfer','accounting.je.post','ap.payment.send',
        'time.entry.create','time.entry.self','time.entry.manage'] as $permission) {
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

/** Retire only this verified temporary principal after the caller has reconciled the synthetic review. */
function syntheticTimeReviewerRetire(PDO $pdo,int $id): array
{
    if($pdo!==getDB()||$pdo->inTransaction())throw new RuntimeException('Dedicated native connection required');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$st->execute([$id]);
        $verified=syntheticTimeReviewerVerify($pdo,$id);$mid=$verified['membership_id'];
        RBACResolver::revokeModule($mid,'time',4);
        if(deactivateMembership($id,999)!==1)throw new RuntimeException('Unexpected membership retirement');
        $pdo->prepare('UPDATE users SET is_active=0 WHERE id=? AND tenant_id=999 AND is_active=1')->execute([$id]);
        $pdo->prepare('INSERT INTO membership_audit (tenant_id,membership_id,action,actor_user_id,target_user_id,detail)
            VALUES (999,?,"synthetic_time_reviewer_retired",4,?,?)')->execute([$mid,$id,
                json_encode(['scope'=>'synthetic_time_review_only','credentials_issued'=>false,'audit_identity_preserved'=>true],JSON_THROW_ON_ERROR)]);
        RBACResolver::resetCache();
        $st=$pdo->prepare('SELECT is_active FROM users WHERE id=?');$st->execute([$id]);
        $grants=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$grants->execute([$mid]);
        if((int)$st->fetchColumn()!==0 || membershipTenantCountForUser($id)!==0 || RBACResolver::activeMembership($id,999)!==null
            ||(int)$grants->fetchColumn()!==0)throw new RuntimeException('Reviewer retirement did not persist');
        $pdo->commit();return ['user_id'=>$id,'account_inactive'=>true,'active_memberships'=>0,'module_grants'=>0,'audit_identity_preserved'=>true];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();RBACResolver::resetCache();throw $e;}
}

/** Read-only terminal-state verification; never repair or reactivate this account. */
function syntheticTimeReviewerVerifyRetired(PDO $pdo,int $id): array
{
    $st=$pdo->prepare('SELECT tenant_id,email,name,role,is_active,is_global_admin FROM users WHERE id=?');$st->execute([$id]);
    $u=$st->fetch(PDO::FETCH_ASSOC);
    if(!$u||$id===4||(int)$u['tenant_id']!==999||$u['email']!==SYNTHETIC_REVIEWER_EMAIL||$u['name']!==SYNTHETIC_REVIEWER_NAME
        ||$u['role']!=='manager'||(int)$u['is_active']!==0||(int)$u['is_global_admin']!==0)throw new RuntimeException('Retired reviewer identity differs');
    // tenant-leak-allow: inspect only the verified reserved test identity to reject any foreign membership, including inactive ones.
    $st=$pdo->prepare('SELECT id,tenant_id,status,persona_type,persona_label FROM tenant_memberships WHERE user_id=?');$st->execute([$id]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);$m=$rows[0]??[];
    if(count($rows)!==1||(int)$m['tenant_id']!==999||$m['status']!=='revoked'||$m['persona_type']!=='manager'
        ||$m['persona_label']!=='Pilot time reviewer'||membershipTenantCountForUser($id)!==0
        ||isset(membershipRoleLabelsForTenant(999,$pdo)[$id]))throw new RuntimeException('Retired reviewer still has membership access');
    $st=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$st->execute([(int)$m['id']]);
    if((int)$st->fetchColumn()!==0)throw new RuntimeException('Retired reviewer still has module grants');
    $st=$pdo->prepare('SELECT detail FROM membership_audit WHERE tenant_id=999 AND membership_id=? AND target_user_id=? AND actor_user_id=4 AND action="synthetic_time_reviewer_retired"');
    $st->execute([(int)$m['id'],$id]);$receipts=$st->fetchAll(PDO::FETCH_COLUMN);
    if(count($receipts)!==1||(json_decode($receipts[0],true)['audit_identity_preserved']??null)!==true)throw new RuntimeException('Retirement audit receipt missing');
    return ['user_id'=>$id,'membership_id'=>(int)$m['id'],'account_inactive'=>true,'active_memberships'=>0,'module_grants'=>0,'audit_identity_preserved'=>true];
}
