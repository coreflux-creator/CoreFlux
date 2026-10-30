<?php
/** Field descriptions only. Never decrypts evidence or reads a business record. */
declare(strict_types=1);

function staffingWorkflowFieldTables(): array
{
    return ['integration_deliveries', 'staffing_onboarding_cases', 'staffing_onboarding_events',
        'quanta_onboarding_document_transfers', 'payroll_provider_results', 'payroll_native_observations', 'purepay_financial_observations'];
}

/** Logical fields inside stored workflow envelopes, separately from physical SQL columns. */
function staffingWorkflowPayloadFields(array $columns): array
{
    $rows = [];
    $add = static function (string $workflow, string $container, string $storagePrefix, string $provider,
        string $route, string $direction, string $version, string $evidence, array $fields,
        string $owner, string $policy, string $remotePrefix = '', ?string $sensitivity = null) use (&$rows, $columns): void {
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
                'sensitivity'=>$sensitivity ?? ($provider==='purepayroll' ? 'payroll_financial' : ($direction==='core_to_quanta' ? 'permissioned_portal_projection' : 'worker_evidence')),
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
    $customer = [
        'contract_version'=>['string','Exact customer creation contract version; requires separate customer-input permission.'],
        'name'=>['string','Trimmed client legal name, falling back to display name only when legal name is empty; maximum 500 Unicode characters.'],
        'email'=>['string','Optional trimmed client contact email, maximum 254 characters; empty stays empty and never selects identity.'],
        'phone'=>['string|null','Optional trimmed contact phone, maximum 40 Unicode characters; empty becomes null and never selects identity.'],
    ];
    foreach ($reference as $field=>$description) $customer['reference.'.$field]=$description;
    $customer['reference.entity_type']=['client','Canonical client role; never a company, person or provider ID.'];
    $customer['reference.number']=['string','Immutable CL- client number, scoped by installation and owning directory.'];
    $add('purepay_customer_input','integration_deliveries.payload_ct','body.','purepay',
        '/api/v1/integrations/coreflux/customers','core_to_purepay','2026-10-26.1',
        'core/integrations/purepay_customer_inputs.php',$customer,'core_client_directory',
        'reviewed_local_customer_create; preserve_original_attempt_and_provider_id; no_banking_QuickBooks_invoices_or_payments',
        '', 'permissioned_business_contact');
    $timeReview=[
        'contract_version'=>['string','Exact time-review observation contract version.'],
        'assignment_id'=>['string','Existing Quanta assignment, derived from the typed Core placement and provider workspace.'],
        'assignment_version'=>['integer','Current Quanta assignment revision, verified again by the provider.'],
        'source_entry_id'=>['string','Original imported Quanta entry ID; never a Core row ID.'],
        'source_revision'=>['sha256','Opaque hash of current approved source time, including whole-week classification.'],
        'core_tenant_id'=>['integer','Operating Core time workspace, distinct from person and placement directory ownership.'],
        'core_revision'=>['sha256','Exact Core import, route, status and native approval evidence observed for every component.'],
        'expected_version'=>['integer','Conditional provider review version; zero means no prior publication.'],
        'components[].entry_id'=>['integer','Original Core time row in the operating workspace.'],
        'components[].component'=>['enum','Original regular, overtime, doubletime or specific paid-leave component.'],
        'components[].status'=>['draft|pending_review|approved|rejected','Observed native Core review status; does not approve Quanta source time.'],
    ];
    $add('quanta_time_review','integration_deliveries.payload_ct','body.','quanta',
        '/api/v1/integrations/coreflux/time-reviews','core_to_quanta','2026-10-24.1',
        'core/quanta/time_review_contract.php',$timeReview,'core_time_review',
        'exact_import_and_native_approval_evidence; complete_components; original_receipt_recovery; no_time_or_financial_mutation');
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
    $observation=[];
    foreach(['contract_version','organization_id','run_id','record_version','amount_basis','delivery_confirmation','core_match','consistency','observation_hash'] as $field)
        $observation[$field]=['string','Validated native payroll observation '.$field.'; company-scoped and independent of Core execution authority.'];
    $observation['observed_at']=['UTC timestamp','Fresh provider read time; excluded from the stable observation hash.'];
    foreach(['period_start','period_end','pay_date'] as $field)$observation[$field]=['date|null','Stored provider '.$field.'; unknown remains null with an unavailable reason.'];
    foreach(['status','source','provider','currency','paid_at','created_at'] as $field)$observation[$field]=['string|null','Stored provider '.$field.'; no missing currency or timestamp zone is inferred.'];
    foreach(['native','manual','provider_mode','execution_hold'] as $field)$observation[$field]=['boolean|null','Observed provider '.$field.'; null never means false and conveys no authority.'];
    foreach(['period_start','period_end','pay_date','status','source','provider','currency','paid_at','created_at','native','manual','provider_mode','execution_hold'] as $field)
        $observation['unavailable_fields.'.$field]=['missing|invalid','Reason for null '.$field.'; omitted when valid.'];
    $observation['lines[].line_index']=['integer','Original sequential provider payroll line index.'];
    $observation['lines[].employee_id']=['string|null','Actual provider employee ID; Core match requires a separately completed identity receipt.'];
    foreach(['hours','ot_hours'] as $field)$observation['lines[].'.$field]=['signed decimal(4)|null','Stored provider '.$field.'; no source-time matching is implied.'];
    foreach(['employee_id','hours','ot_hours'] as $field)$observation['lines[].unavailable_fields.'.$field]=['missing|invalid','Reason for null line '.$field.'.'];
    foreach(array_diff($amounts,['source_gross']) as $field) {
        foreach(['totals.','lines[].amounts.'] as $prefix)$observation[$prefix.$field]=['signed decimal(2)|null','Stored '.$field.'; exact cents, negative corrections and unknown amounts preserved. Totals are not inferred from lines.'];
        foreach(['unavailable_totals.','lines[].unavailable_amounts.'] as $prefix)$observation[$prefix.$field]=['missing|invalid','Reason for null '.$field.'; omitted for a valid stored amount.'];
    }
    $lineage='lines[].source_time_lineage.';
    $observation[$lineage.'status']=['recorded|empty|not_recorded|partial|invalid','Completeness of actual stored source references; not a validated match to Core time.'];
    $observation[$lineage.'unavailable_entries[]']=['integer','Original index of an incomplete or invalid source reference.'];
    foreach(['id','entry_id','source','person_id','source_workspace_id','revision','core_input_id','date','hours','ot_hours'] as $field)
        $observation[$lineage.'entries[].'.$field]=['string|null','Actual provider source '.$field.'; omitted if absent and null if invalid.'];
    foreach(['core'=>['instance_id','time_tenant_id','time_entry_id'],'original_source'=>['system','workspace_id','entry_id','component','revision']] as $group=>$fields)
        foreach($fields as $field)$observation[$lineage.'entries[].'.$group.'.'.$field]=['string|null','Actual nested source '.$field.'; integer identifiers normalized to strings, never invented.'];
    $add('purepayroll_native_observation','payroll_native_observations.payload_ct','observation.','purepayroll',
        '/api/v1/integrations/coreflux/payroll/observations/{run_id}','purepayroll_to_core','2026-10-25.1',
        'core/integrations/purepay_observations.php',$observation,'purepayroll_observed_ledger',
        'immutable_encrypted_capture; missing_values_explicit; no_authority_creation_or_time_rate_match_or_posting');
    // Enumerate the exact versioned AP/AR contract, including nested amounts and references.
    $schemas=json_decode(file_get_contents(__DIR__.'/contracts/purepay_financial_observations_v2.json'),true,64,JSON_THROW_ON_ERROR);
    foreach($schemas as $kind=>$schema) {
        $fields=[];
        $walk=static function(array $node,string $path='')use(&$walk,&$fields):void {
            if($node['type']==='object')foreach($node['properties'] as $key=>$child)$walk($child,$path===''?$key:$path.'.'.$key);
            elseif($node['type']==='array')$walk($node['items']??['type'=>'string'],$path.'[]');
            else {
                $type=$node['type'].(($node['nullable']??false)?'|null':'');
                $units=str_ends_with($path,'_cents')?'Integer cents; never a decimal currency amount.':
                    (str_contains($node['pattern']??'','{2}')?'Exact decimal currency amount, two places.':
                    (str_contains($node['pattern']??'','{8}')?'Exact rate, quantity or hours, eight decimal places.':'Stored source value.'));
                $fields[$path]=[$type,$units.' Source-only evidence; no identity, settlement or posting authority. Null stays unknown.'];
            }
        };
        $walk($schema);
        $add('purepay_financial_'.$kind.'_observation','purepay_financial_observations.payload_ct','observation.','purepay',
            '/financial-observations/'.($kind==='bill'?'bills':'invoices').'/{document_id}','purepay_to_core','2026-10-30.2',
            'core/integrations/purepay_financial_observation_contract.php',$fields,'purepay',
            'reviewed_read_only_evidence; preserve_unknown_currency_and_original_units; no_execution','','financial_evidence');
    }
    return $rows;
}
