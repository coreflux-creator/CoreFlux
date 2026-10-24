<?php
/** Field descriptions only. Never decrypts evidence or reads a business record. */
declare(strict_types=1);

function staffingWorkflowFieldTables(): array
{
    return ['integration_deliveries', 'staffing_onboarding_cases', 'staffing_onboarding_events',
        'quanta_onboarding_document_transfers', 'payroll_provider_results'];
}

/** Logical fields inside stored workflow envelopes, separately from physical SQL columns. */
function staffingWorkflowPayloadFields(array $columns): array
{
    $rows = [];
    $add = static function (string $workflow, string $container, string $storagePrefix, string $provider,
        string $route, string $direction, string $version, string $evidence, array $fields,
        string $owner, string $policy, string $remotePrefix = '') use (&$rows, $columns): void {
        foreach ($fields as $path => [$type, $definition]) {
            $storagePath = $storagePrefix . $path;
            $contracts = array_fill_keys(['jobdiva', 'quanta', 'purepay', 'purepayroll'], [
                'path'=>null, 'direction'=>null, 'status'=>'unverified', 'sync_enabled_by_dictionary'=>false,
            ]);
            $contracts[$provider] = [
                'path'=>$remotePrefix.$path, 'direction'=>$direction, 'status'=>'source_verified_hosted_acceptance_pending',
                'sync_enabled_by_dictionary'=>false,
                'mappings'=>[['route'=>$route, 'path'=>$remotePrefix.$path, 'direction'=>$direction,
                    'contract_version'=>$version, 'transform'=>'validated_workflow_envelope; '.$policy,
                    'write_policy'=>'specialized_workflow_only', 'source_evidence'=>['core'=>$evidence]]],
            ];
            $rows[] = [
                'key'=>$workflow.'.'.$path, 'kind'=>'workflow_payload_field', 'workflow'=>$workflow,
                'core_path'=>$container.'::'.$storagePath, 'storage_container'=>$container,
                'storage_path'=>$storagePath, 'type'=>$type, 'nullable'=>str_contains($type, '|null'),
                'core_schema_status'=>isset($columns[$container]) ? 'verified_container_present' : 'container_unverified',
                'definition'=>$definition, 'definition_status'=>'reviewed_core_definition',
                'owner'=>$owner, 'disposition'=>$direction==='core_to_quanta' ? 'specialized_workflow' : 'restricted',
                'sensitivity'=>$provider==='purepayroll' ? 'payroll_financial' : ($direction==='core_to_quanta' ? 'permissioned_portal_projection' : 'worker_evidence'),
                'write_policy'=>'specialized_workflow_only', 'required_stage'=>$workflow,
                'missing_value_policy'=>'validator_rejects_missing_required_fields',
                'null_policy'=>'preserve_explicit_null; never_infer_a_value',
                'conflict_policy'=>'reject_mismatched_identity_revision_or_receipt; preserve_original_evidence',
                'effective_date_policy'=>'retain_original_source_period_and_revision',
                'remote_contracts'=>$contracts,
            ];
        }
    };
    $reference = ['instance_id'=>['uuid','Original Core installation; a number alone never selects an installation.'],
        'directory_tenant_id'=>['integer','Owning directory, separate from the operating workspace.'],
        'entity_type'=>['enum','Typed canonical person or placement reference.'],
        'number'=>['string','Immutable canonical business number; never a provider ID or credential.']];
    $publication = [
        'contract_version'=>['string','Exact onboarding publication contract version.'],
        'core_case_id'=>['uuid','Original Core onboarding case.'],
        'operating_tenant_id'=>['integer','Core operating workspace; does not replace directory ownership.'],
        'core_version'=>['integer','Reviewed Core case revision.'],
        'source_revision'=>['sha256','Fingerprint of the current staffing source.'],
        'assignment_version'=>['integer','Verified Quanta assignment revision.'],
        'expected_version'=>['integer|null','Conditional provider update version; null means create.'],
        'state'=>['published|withdrawn','Publication state; does not activate work or payroll.'],
    ];
    foreach (['person','placement'] as $ref) foreach ($reference as $field=>$description) $publication[$ref.'.'.$field]=$description;
    foreach ([
        'id'=>['uuid','Stable requirement identity.'], 'version'=>['integer','Current requirement revision.'],
        'title'=>['string','Employer-reviewed requirement title.'], 'instructions'=>['string','Employer-reviewed worker instructions.'],
        'assignee'=>['worker|staff','Responsible role; staff tasks cannot be submitted as worker tasks.'],
        'evidence_kind'=>['document|attestation','Required evidence type, distinct from its approval.'],
        'required'=>['boolean','Whether this requirement contributes to onboarding readiness.'],
        'effective_state'=>['enum','Computed Core evidence/review/expiry state; not the raw stored task state.'],
    ] as $field=>$description) $publication['tasks[].'.$field]=$description;
    $add('quanta_onboarding_publication','integration_deliveries.payload_ct','body.','quanta',
        '/api/v1/integrations/coreflux/onboarding','core_to_quanta','2026-10-22.1',
        'core/quanta/onboarding_contract.php',$publication,'core_onboarding_workflow',
        'confirmed_plan_and_current_assignment_required; omits_private_evidence_and_reviewer_reasons');

    $receipt = [];
    foreach (['id','request_id','request_hash','worker_id','identity_id','case_id','core_case_id','source_revision',
        'projection_hash','task_id'] as $field) $receipt[$field]=['string','Original Quanta receipt '.$field.'; validated against the current publication and worker.'];
    foreach (['core_version','task_version'] as $field) $receipt[$field]=['integer','Original published '.$field.'; stale evidence is rejected.'];
    $receipt += [
        'kind'=>['attestation|document','Submitted evidence kind; must match the employer requirement.'],
        'acknowledged'=>['boolean','Must be true for this authenticated worker submission.'],
        'submitted_at'=>['UTC timestamp','Original Quanta submission time, preserved separately from Core import time.'],
        'status'=>['awaiting_core_review','Provider submission does not carry employer approval.'],
    ];
    foreach (['contract_version','id','filename','mime','sha256'] as $field)
        $receipt['document.'.$field]=['string','Optional document receipt '.$field.'; required for document-kind evidence and verified before private storage.'];
    $receipt['document.size_bytes']=['integer','Exact file byte length, positive and at most 25 MiB; not a display size.'];
    $add('quanta_onboarding_submission','staffing_onboarding_events.details_json','provider_receipt.','quanta',
        '/api/v1/integrations/coreflux/onboarding/{case_id}','quanta_to_core','2026-10-22.1',
        'core/quanta/onboarding_delivery.php',$receipt,'quanta_worker_evidence',
        'response_submissions_array; document_descriptor_version_2026-10-23.1; employer_review_stays_in_core','submissions[].');

    $result = [];
    foreach (['contract_version','organization_id','run_id','input_id','content_hash','status','calculation_mode',
        'currency','amount_basis','delivery_confirmation','result_hash'] as $field)
        $result[$field]=['string','Validated provider result '.$field.'; matched to the original encrypted input and authority.'];
    foreach (['period_start','period_end','pay_date'] as $field) $result[$field]=['date','Original payroll '.$field.'; calendar date, not a timestamp.'];
    foreach (['archived','execution_hold'] as $field) $result[$field]=['boolean','Observed provider '.$field.'; does not authorize Core execution.'];
    $result['observed_at']=['UTC timestamp','Provider observation timestamp; distinct from Core storage time.'];
    foreach (['receipt_version','state'] as $field) $result['authority_receipt.'.$field]=['string','Validated allocation receipt '.$field.'.'];
    foreach (['provider_usable','payroll_created','release_confirmed'] as $field) $result['authority_receipt.'.$field]=['boolean','Validated source-authority '.$field.'; observation alone never releases Core claims.'];
    $result['authority_receipt.release_receipt_id']=['string|null','Permanent cancellation receipt when released; otherwise null.'];
    foreach (['contract_version','id','instance_id','organization_id','input_id','content_hash'] as $field)
        $result['authority_receipt.authority.'.$field]=['string','Exact original Core source-authority '.$field.'.'];
    foreach (['time_tenant_id','components'] as $field) $result['authority_receipt.authority.'.$field]=['integer','Original reserved source '.$field.'.'];
    $result['authority_receipt.authority.source_time_reserved']=['boolean','Original reservation evidence; never reconstructed from current time.'];
    foreach (['run_id','request_hash','input_id','run_state'] as $field) $result['authority_receipt.allocation.'.$field]=['string','Bound provider allocation '.$field.'.'];
    $result['authority_receipt.allocation.archived']=['boolean','Observed allocation archive flag.'];
    $result['authority_receipt.allocation.fence_id']=['string|null','Permanent cancellation fence when released; otherwise null.'];
    $result['lines[].employee_id']=['string','Original provider employee ID; preserved independently of the Core person number.'];
    $result['lines[].components[].gross']=['decimal(2)','USD component gross recalculated from the original approved hours, rate and multiplier with half-up cent rounding.'];
    $component='lines[].components[].input_component.';
    foreach (['person','placement'] as $ref) foreach ($reference as $field=>$description) $result[$component.$ref.'.'.$field]=$description;
    foreach (['employee_id','assignment_id','person_link_id','placement_link_id'] as $field)
        $result[$component.$field]=['string','Unchanged original payroll-input '.$field.'; matched by complete component hash.'];
    foreach (['time_entry_id'=>['integer','Original Core time row in the authority time-owner workspace.'],
        'revision'=>['sha256','Original Core time revision.'], 'work_date'=>['date','Original source work date.'],
        'category'=>['enum','Original regular, overtime or paid-leave earning category.'],
        'hours'=>['decimal(2)','Original approved hours, not minutes.'],
        'source_system'=>['string','Original source system.'],
        'source_entry_id'=>['string|null','Original external entry ID; required provenance for Quanta.'],
        'source_component'=>['string|null','Original external time component.'],
        'source_revision'=>['string|null','Original external source revision.'],
        'source_workspace_id'=>['string|null','Original external workspace; never the currently selected workspace.'],
    ] as $field=>$description) $result[$component.'source.'.$field]=$description;
    foreach (['snapshot_id'=>['integer','Original approved placement-rate snapshot.'],
        'revision'=>['sha256','Original rate snapshot hash.'], 'pay_rate'=>['decimal(4)','Original hourly USD pay rate; never an employee default.'],
        'pay_rate_unit'=>['hour','Hourly inputs only for this result contract.'], 'currency'=>['USD','Result contract accepts USD only.'],
        'multiplier'=>['decimal(2)','Original earning multiplier; regular and leave categories require 1.00.'],
    ] as $field=>$description) $result[$component.'rate.'.$field]=$description;
    $amounts = ['source_gross','bonus','gross','reimbursements','federal_wh','social_security','medicare','state_wh',
        'local_wh','sdi_pfl','other_deductions','garnishments','net','employer_fica','employer_futa','employer_suta','employer_match','funding_required'];
    foreach ($amounts as $field) foreach (['lines[].amounts.','totals.'] as $prefix)
        $result[$prefix.$field]=['decimal(2)','Observed USD '.$field.'; exact cents and reconciled line/total arithmetic. '
            .($field==='garnishments'?'Included in other_deductions, never subtracted twice.':'Not payment delivery or liability-remittance proof.')];
    $add('purepayroll_result','payroll_provider_results.payload_ct','','purepayroll',
        '/api/v1/integrations/coreflux/payroll/authorities/{authority_id}/result','purepayroll_to_core','2026-10-22.1',
        'core/integrations/purepay_payroll_results.php',$result,'purepayroll_observed_result',
        'immutable_encrypted_observation; original_component_hash_and_money_arithmetic_required; no_posting_or_claim_release');
    return $rows;
}
