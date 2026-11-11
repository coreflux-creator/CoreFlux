<?php
/** Real native commercial edits, outer rollback, no provider calls or posting. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) exit(2);
require_once __DIR__ . '/../modules/placements/lib/economics_edit.php';
require_once __DIR__ . '/../core/jobdiva/sync.php';
$pdo = getDB(); if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') exit(2);
$tenant = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn(); if ($tenant <= 0) exit(2);
setRequestTenantId($tenant); $_SESSION['tenant_id'] = $tenant;
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; };
$refused = static function (callable $fn, int $code): bool { try { $fn(); return false; } catch (RuntimeException|InvalidArgumentException $e) { return $e->getCode() === $code; } };
$read = static fn(string $table): array => getDB()->query('SELECT * FROM '.$table.' ORDER BY '.($table === 'placement_corp_details' ? 'placement_id' : 'id'))->fetchAll(PDO::FETCH_ASSOC);
$financial = [$read('accounting_events'), $read('accounting_journal_entries'), $read('accounting_journal_entry_lines')];
$pdo->beginTransaction();
try {
    $person = scopedInsert('people', ['first_name'=>'CI Party','last_name'=>'Owner','classification'=>'1099','email_primary'=>'ci-party-edit@example.invalid']);
    $company = companiesUpsertByName($tenant, 'CI Party Billing', [], ['client']);
    $client = staffingClientEnsureForCompany($tenant, $company, 'CI Party Billing')['client_id'];
    $placement = scopedInsert('placements', ['person_id'=>$person,'title'=>'CI party terms','status'=>'on_hold','engagement_type'=>'1099','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
        'external_id'=>'jd:99160001','coreflux_overridden_fields'=>'["notes"]','notes'=>'Keep Core note','client_id'=>$client,'end_client_company_id'=>$company,'end_client_name'=>'CI Party Billing',
        'client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly','vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0]);
    scopedInsert('placement_rates', ['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    $graph = placementEconomicsReconcile($tenant, $placement);
    $check($graph['available'] && !$graph['errors'], 'Synthetic commercial graph is complete');
    $party = static function (int $pid, string $ref) use ($tenant): array { $q=getDB()->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?'); $q->execute([$tenant,$pid,$ref]); return $q->fetch(PDO::FETCH_ASSOC); };
    $ar = $party($placement, 'placement:bill_to'); $ap = $party($placement, 'worker:'.$person);
    $before = placementAuditRow($placement);
    $edit = placementEconomicsApplyNativePartyEdit($tenant, (int) $ar['id'], ['payment_terms'=>'NET10','pwp_enabled'=>false]);
    $after = placementAuditRow($placement);
    $check($edit['changed'] && $edit['party']['payment_terms']==='NET10' && $after['client_payment_terms_override']==='NET10', 'Canonical bill-to edit updates participant and placement terms together');
    $check((int)$edit['party']['payment_terms_overridden']===1 && in_array('client_payment_terms_override',json_decode($after['coreflux_overridden_fields'],true),true) && in_array('notes',json_decode($after['coreflux_overridden_fields'],true),true), 'Native terms claim their ownership and preserve earlier flags');
    $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.economic_party.updated' ORDER BY id DESC LIMIT 1"); $q->execute([$tenant,$placement]); $meta=json_decode($q->fetchColumn(),true);
    $check($meta['before']===$ar && $meta['after']===$edit['party'] && $meta['placement_before']===$before && $meta['placement_after']===$after, 'Required audit captures exact party and placement before and after values');
    $stable=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement); $audit=$read('audit_log');
    $again=placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET10','pwp_enabled'=>false]);
    $check(!$again['changed'] && jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$stable && $read('audit_log')===$audit, 'Repeating an identical native edit changes neither graph nor audit');
    $cycle=scopedInsert('staffing_operating_cycles',['purpose'=>'billing','name'=>'CI reviewed weekly billing','cadence'=>'weekly','anchor_date'=>'2026-11-02']);
    $edit=placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['operating_cycle_id'=>$cycle]); $after=placementAuditRow($placement);
    $check((int)$edit['party']['operating_cycle_id']===$cycle && $after['client_bill_cycle']==='weekly' && $after['client_bill_cycle_anchor']==='2026-11-02' && (int)$after['billing_operating_cycle_id']===$cycle, 'Selecting an explicit cycle aligns its cadence and anchor with the placement');
    $edit=placementEconomicsApplyNativePartyEdit($tenant,(int)$ap['id'],['payment_terms'=>'PWP_NET15','pwp_enabled'=>true,'cadence'=>'weekly']); $after=placementAuditRow($placement);
    $check($edit['party']['payment_terms']==='PWP_NET15' && (int)$edit['party']['pwp_enabled']===1 && $after['vendor_payment_terms_override']==='PWP_NET15' && (int)$after['vendor_pwp_enabled']===1 && $after['vendor_pay_cycle']==='weekly', 'Contractor labor edit aligns AP terms PWP and frequency');
    $check((int)$edit['party']['payment_terms_overridden']===1 && (int)$edit['party']['pwp_overridden']===1 && (int)$edit['party']['cycle_overridden']===1, 'Labor terms PWP and cycle ownership remain independent flags');
    $payload=['startId'=>'99160001','candidateId'=>'99160002','jobId'=>'99160003','startDate'=>'2026-10-01','title'=>'Fresh source title','status'=>'on_hold','endClientName'=>'CI Party Billing','worksiteCountry'=>'United States','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99160001','engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','client_company_name'=>'CI Party Billing','end_client_name'=>'CI Party Billing','client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'monthly','client_payment_terms'=>'NET60','vendor_payment_terms'=>'NET60','paid_when_paid'=>false,'worksite_country'=>'United States']];
    jobdivaSyncUpsertPlacement($tenant,$person,$company,$payload,'99160001'); $after=placementAuditRow($placement);
    $check($after['client_payment_terms_override']==='NET10' && $after['vendor_payment_terms_override']==='PWP_NET15' && (int)$after['vendor_pwp_enabled']===1 && $after['client_bill_cycle_anchor']==='2026-11-02' && $after['title']==='Fresh source title', 'Later forced JobDiva refresh retains reviewed commercial values while unrelated fields refresh');
    $extra=placementEconomicsUpsertParty($tenant,$placement,['source_ref'=>'manual:extra_ar','source_type'=>'manual','source_managed'=>0,'role'=>'client','display_name'=>'CI Additional receivable','company_id'=>$company,'money_flow'=>'receivable','settlement_channel'=>'ar','payment_terms'=>'NET30']);
    $before=placementAuditRow($placement); placementEconomicsApplyNativePartyEdit($tenant,$extra,['payment_terms'=>'NET45']);
    $check(placementAuditRow($placement)===$before, 'Additional receivable does not acquire canonical bill-to authority');
    $before=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement); $audit=$read('audit_log');
    foreach ([['payment_terms'=>'PWP'],['payment_terms'=>'bad terms'],['payment_terms'=>null],['operating_cycle_id'=>null],['operating_cycle_id'=>$edit['party']['operating_cycle_id']],['cadence'=>'daily'],['pwp_enabled'=>'false'],['payment_terms'=>'NET15','pwp_enabled'=>true],['tenant_id'=>$tenant+1],['settlement_channel'=>'ap']] as $invalid) {
        $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],$invalid),422) && jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$before && $read('audit_log')===$audit, 'Invalid participant edit rolls back: '.implode(',',array_keys($invalid)));
    }
    $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['fee_basis'=>'flat']),409), 'Source-managed calculation cannot be silently replaced');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']); $before=placementAuditRow($placement);
    $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET20']),422) && placementAuditRow($placement)===$before, 'Malformed ownership blocks native party editing before writes');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'["notes"]']);
    $before=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement); $cycles=$read('staffing_operating_cycles');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try { $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET25','cadence'=>'adhoc']),503) && jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$before && $read('staffing_operating_cycles')===$cycles && $pdo->inTransaction(), 'Audit failure rolls back party terms cycle creation placement summary and ownership'); }
    finally { $pdo->exec('DROP TEMPORARY TABLE audit_log'); }
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE tenant_id=? AND id=?')->execute([$tenant,$company]);
    $before=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET25']),422) && jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$before, 'Commercial graph failure rolls back the whole native edit');
    $pdo->prepare('UPDATE companies SET deleted_at=NULL WHERE tenant_id=? AND id=?')->execute([$tenant,$company]);
    $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant+1000000,(int)$ar['id'],['payment_terms'=>'NET25']),404), 'Another tenant cannot edit the participant');
    $corpCompany=companiesUpsertByName($tenant,'CI Native Corporation',[],['vendor']);
    $corpPlacement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI corporate terms','status'=>'on_hold','engagement_type'=>'c2c','start_date'=>'2026-10-01','external_id'=>'jd:99160011']);
    $pdo->prepare('INSERT INTO placement_corp_details (tenant_id,placement_id,company_id,corp_legal_name,payment_terms_override,pwp_enabled) VALUES (?,?,?,?,?,?)')->execute([$tenant,$corpPlacement,$corpCompany,'CI Native Corporation','NET30',0]);
    placementEconomicsReconcile($tenant,$corpPlacement); $corpParty=$party($corpPlacement,'corp:'.$corpPlacement);
    $edit=placementEconomicsApplyNativePartyEdit($tenant,(int)$corpParty['id'],['payment_terms'=>'PWP_NET10','pwp_enabled'=>true,'cadence'=>'monthly']);
    $q=$pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=?');$q->execute([$tenant,$corpPlacement]);$corpRow=$q->fetch(PDO::FETCH_ASSOC);
    $check($corpRow['payment_terms_override']==='PWP_NET10' && (int)$corpRow['pwp_enabled']===1 && placementAuditRow($corpPlacement)['vendor_payment_terms_override']==='PWP_NET10' && $edit['party']['payment_terms']==='PWP_NET10', 'Corporate participant edit updates source corporation placement and party together');
    $w2=scopedInsert('placements',['person_id'=>$person,'title'=>'CI employee frequency','status'=>'on_hold','engagement_type'=>'w2','start_date'=>'2026-10-01','external_id'=>'jd:99160021']);
    placementEconomicsReconcile($tenant,$w2); $w2Party=$party($w2,'worker:'.$person);
    $edit=placementEconomicsApplyNativePartyEdit($tenant,(int)$w2Party['id'],['cadence'=>'semimonthly']);$row=placementAuditRow($w2);
    $check($row['vendor_pay_cycle']==='semimonthly' && (int)$row['payroll_operating_cycle_id']===(int)$edit['party']['operating_cycle_id'], 'Employee frequency remains on the payroll channel');
    $check($refused(fn()=>placementEconomicsApplyNativePartyEdit($tenant,(int)$w2Party['id'],['payment_terms'=>'NET30']),422), 'Payroll participant cannot be assigned invoice payment terms');
    $manual=placementEconomicsUpsertParty($tenant,$placement,['source_ref'=>'manual:fee','source_type'=>'manual','source_managed'=>0,'role'=>'other','display_name'=>'CI Fee','money_flow'=>'payable','settlement_channel'=>'ap','fee_basis'=>'per_hour','fee_flat'=>2]);
    $edit=placementEconomicsApplyNativePartyEdit($tenant,$manual,['fee_flat'=>3.25]);
    $check($edit['party']['fee_flat']==='3.2500' && $edit['changed'], 'Manual fee editing remains available without changing recipient identity');
    $before=jobdivaPlacementProjectionAuditSnapshot($tenant,$placement);
    $main=$pdo;
    $second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    // Expose only this uncommitted synthetic identity to exercise the parent
    // lock without committing fixture rows. FOR UPDATE remains a locking read.
    $second->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try { placementEconomicsApplyNativePartyEdit($tenant,(int)$ar['id'],['payment_terms'=>'NET25']);$check(false,'Competing edit must wait'); }
    catch(PDOException $e) { $check((int)($e->errorInfo[1]??0)===1205 && !$second->inTransaction(), 'Competing native party edit waits for parent lock and rolls back on timeout'); }
    finally { $pdo=$main;$second=null; }
    $check($pdo->inTransaction() && jobdivaPlacementProjectionAuditSnapshot($tenant,$placement)===$before, 'Lock contention preserves the caller transaction and graph');
    $check((int)placementAuditRow($placement)['person_id']===$person && placementAuditRow($placement)['external_id']==='jd:99160001', 'Native commercial editing preserves person and provider identities');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial, 'Native commercial editing creates no financial posting');
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); setRequestTenantId(null); }
echo "$checks economic-party native edit checks passed.\n";
