<?php
/** Explicit time ownership and storage semantics. Metadata only; grants no access. */
declare(strict_types=1);

function staffingTimeEvidenceTables(): array
{
    return ['quanta_time_approval_evidence', 'quanta_time_revisions', 'jobdiva_time_intake'];
}

function staffingTimeFieldDefinitions(): array
{
    static $definitions;
    if ($definitions !== null) return $definitions;
    return $definitions = [
        'time_entries' => [
            'placement_id' => ['Placement in the owning Core directory. External exchanges resolve its canonical placement reference; this SQL ID is not a provider assignment ID.', 'reference:placement'],
            'person_id' => ['Person in the owning Core directory. External exchanges resolve the canonical person reference; never match this SQL ID to a provider worker ID.', 'reference:person'],
            'period_id' => ['Core processing period containing the work date; separate from the worker weekly header and provider payroll run.', 'reference:time_period'],
            'timesheet_id' => ['Core weekly header for this person. A source correction keeps or moves this link and reconciles every affected week.', 'reference:staffing_timesheet'],
            'work_date' => ['Calendar date of work. Quanta may supply it directly or derive it from clock-in with explicit worksite timezone evidence.', 'date'],
            'external_id' => ['Connector-specific entry identity. Quanta initially hashes source entry/component; corrected replacements use a distinct reviewed revision identity.', 'source-reference'],
            'source_system' => ['Recorded origin system. A later Core review does not turn Quanta source time into manual time.', 'enum'],
            'category' => ['Core earning/billing category derived from the source component and assignment classification; it is not a payroll provider earning ID.', 'enum'],
            'hour_type' => ['Core hour bucket derived separately from billing category; preserve overtime, doubletime and paid-leave distinctions.', 'enum'],
            'billable' => ['Whether the entry contributes to billable work. This flag alone does not create an invoice.', 'boolean'],
            'payable' => ['Whether the entry is payable. This flag alone does not approve or execute payroll.', 'boolean'],
            'custom_category_id' => ['Tenant-defined time category; raw local identity has no automatic provider earning-code equivalent.', 'reference:tenant_time_category'],
            'hours' => ['Decimal hours for this work date and component, rounded to two places on Quanta normalization; never minutes.', 'hours'],
            'description' => ['Source or operator description retained on the time entry; changes are included in Quanta component revision checks.', 'text-max-500'],
            'source' => ['Core intake channel, separate from source_system. Imported Quanta time retains its provenance through correction.', 'enum'],
            'source_ref_id' => ['Optional originating Core intake record; not the durable Quanta entry ID stored in quanta_time_imports.', 'source-reference'],
            'status' => ['Core draft/review/approval/rejection/supersession state. Quanta approval starts Core review, not Core approval.', 'enum'],
            'rate_snapshot_id' => ['Approved effective-dated placement rate frozen for this Core approval. Replacement time must obtain its own approved rate evidence.', 'reference:placement_rate'],
            'dimension_snapshot_json' => ['Frozen accounting and staffing dimensions for this Core approval; preserve the original JSON and associated hash.', 'private-json'],
            'dimension_snapshot_hash' => ['SHA-256 of the exact frozen dimension JSON; detects changed evidence without choosing new dimensions.', 'sha256'],
            'approved_by_user_id' => ['Core approving account; distinct from the source approver and the creator. Original row evidence survives correction.', 'reference:user'],
            'approved_at' => ['Recorded Core approval time; not the source approval time or proof of payroll execution.', 'timestamp'],
            'approved_via' => ['Native Core approval channel, including manual or tokenized client approval. It is not inferred from Quanta status.', 'enum'],
            'client_approver_email' => ['Recorded client-approval contact for the native Core approval channel; not a login or an identity match key.', 'private-contact'],
            'rejected_reason' => ['Core reviewer reason for rejection; a separate value from the source status and correction reason.', 'private-text'],
            'superseded_by_id' => ['Replacement Core entry when one corresponding replacement exists. Removed components can have no successor; the correction receipt retains their history.', 'reference:time_entry|null'],
            'correction_reason' => ['Reason recorded on the new Core replacement. Quanta corrections also retain immutable before/after receipts.', 'private-text'],
            'bill_extracted_at' => ['Core billing extraction timestamp; any extraction evidence blocks ordinary source correction.', 'timestamp'],
            'bill_extracted_ref' => ['Core billing document reference created by extraction; actual document lineage is checked independently.', 'reference:billing_document'],
            'bill_extracted_by_user_id' => ['Core account that performed billing extraction; never a provider approver.', 'reference:user'],
            'ap_extracted_at' => ['Core payable extraction timestamp; any extraction evidence blocks ordinary source correction.', 'timestamp'],
            'ap_extracted_ref' => ['Core payable document reference created by extraction; actual document lineage is checked independently.', 'reference:ap_document'],
            'ap_extracted_by_user_id' => ['Core account that performed payable extraction.', 'reference:user'],
            'payroll_extracted_at' => ['Core payroll extraction timestamp. Source reservations and historical payroll observations are separate additional checks.', 'timestamp'],
            'payroll_extracted_ref' => ['Core payroll extraction reference; not proof of provider payment or the provider run ID.', 'reference:core_payroll'],
            'payroll_extracted_by_user_id' => ['Core account that performed payroll extraction.', 'reference:user'],
        ],
        'time_periods' => [
            'period_type' => ['Core processing calendar type; not necessarily the provider payroll schedule.', 'weekly|biweekly|semimonthly|monthly'],
            'start_date' => ['Inclusive Core processing-period start date.', 'date'],
            'end_date' => ['Inclusive Core processing-period end date.', 'date'],
            'label' => ['Core display label for the processing period; not an external identity.', 'text-max-40'],
            'status' => ['Native open/locked/closed control. Source correction requires affected periods to remain open.', 'enum'],
            'closed_at' => ['Recorded native period-close timestamp, independent of payroll or banking settlement.', 'timestamp'],
            'closed_by_user_id' => ['Core account that closed this period.', 'reference:user'],
        ],
        'time_downstream_feed' => [
            'period_id' => ['Core processing period whose approved entries were bundled.', 'reference:time_period'],
            'placement_id' => ['Core placement used to group approved time into this downstream bundle.', 'reference:placement'],
            'bundle_type' => ['Intended native AR, AP, payroll or revenue-recognition consumer; not permission to execute that consumer.', 'ar|ap|payroll|revrec'],
            'entries_json' => ['Retained source entry IDs, category totals and rate IDs. Actual entry lineage must be resolved before correction; no raw envelope is published automatically.', 'private-json'],
            'rate_snapshot_id' => ['Primary rate from the bundle calculation; entries_json retains all rate IDs when more than one rate applies.', 'reference:placement_rate|null'],
            'total_hours_billable' => ['Derived billable-hour total from the included approved entries.', 'hours'],
            'total_hours_nonbillable' => ['Derived nonbillable-hour total from the included approved entries.', 'hours'],
            'total_hours_pto' => ['Derived paid-leave-hour total from the included approved entries.', 'hours'],
            'total_amount_bill' => ['Derived bill amount from frozen entry rates. No currency column is stored on this total; currency must remain attributable to the original rate evidence.', 'amount; currency-from-rate-evidence'],
            'total_amount_pay' => ['Derived pay amount from frozen entry rates. This is not a paid amount or proof of cash delivery.', 'amount; currency-from-rate-evidence'],
            'status' => ['Native prepared/consumed/locked/superseded state. Even a prepared non-superseded bundle blocks source correction.', 'enum'],
            'consumed_at' => ['Recorded native consumption timestamp; not a bank settlement date.', 'timestamp'],
            'consumed_by_module' => ['Core module consuming the bundle.', 'module-code'],
            'consumed_ref_id' => ['Record created by the Core consumer in consumed_by_module; not a provider transaction ID.', 'reference:consumer_record'],
        ],
        'staffing_timesheets' => [
            'artifact_id' => ['Core artifact projection identity for the weekly header; separate from source and provider timesheet IDs.', 'uuid'],
            'worker_user_id' => ['Optional linked Core worker login; the canonical person remains person_id and no login is granted by this reference.', 'reference:user|null'],
            'person_id' => ['Canonical Core person whose week is represented.', 'reference:person'],
            'period_start' => ['First calendar day of the person week, according to the configured week boundary.', 'date'],
            'period_end' => ['Last calendar day of the person week.', 'date'],
            'status' => ['Core weekly review/downstream state, reconciled from active entries except for downstream-locked states.', 'enum'],
            'submitted_at' => ['Core weekly submission timestamp; not a source-provider approval time.', 'timestamp'],
            'approved_at' => ['Weekly native approval timestamp; reconciliation uses the latest active-entry approval when all entries are approved.', 'timestamp|null'],
            'approved_by_user_id' => ['Single Core reviewer only when all active approved entries identify that same reviewer. Mixed or missing reviewers remain null.', 'reference:user|null'],
            'rejected_at' => ['Recorded native weekly rejection timestamp.', 'timestamp|null'],
            'rejected_by_user_id' => ['Core weekly rejecting account.', 'reference:user|null'],
            'rejection_reason' => ['Core weekly rejection reason; original entry and source approval evidence remain separate.', 'private-text'],
            'locked_at' => ['Native downstream lock timestamp; source correction refuses an affected locked header.', 'timestamp|null'],
            'total_hours' => ['Sum of active, non-superseded entry hours; it is not necessarily the approved or paid total.', 'hours'],
            'notes' => ['Core weekly notes; not automatically copied to a worker portal or financial system.', 'private-text'],
            'workflow_instance_id' => ['Core workflow engine instance associated with this weekly header.', 'reference:workflow_instance|null'],
            'origin_source' => ['Recorded original Core intake channel for the header; does not replace each entry source.', 'source-code'],
            'origin_system' => ['Recorded original system for the header; entry-level origin and approval remain authoritative.', 'source-code|null'],
            'approved_via' => ['Native weekly approval channel; not the Quanta source approval channel.', 'channel-code'],
            'external_approver_email' => ['Recorded external weekly-approval contact; not permission to provision a login.', 'private-contact'],
            'external_approver_name' => ['Recorded external weekly-approver display name; does not identify a canonical account on its own.', 'private-contact'],
            'approval_note' => ['Core weekly approval note; not proof of a separate provider or client approval.', 'private-text'],
        ],
        'quanta_time_imports' => [
            'quanta_entry_id' => ['Original Quanta daily entry ID within the captured workspace; preserved across component corrections.', 'provider-reference'],
            'worker_id' => ['Original Quanta worker ID, linked explicitly to the Core person; never inferred from a name or email.', 'provider-reference'],
            'source_worksite_id' => ['Original Quanta worksite ID used by reviewed routing.', 'provider-reference|null'],
            'source_worksite_name' => ['Captured source worksite label for context; not a stable routing identity.', 'text|null'],
            'source_dimension_values_json' => ['Canonical source work-dimension values used in routing and revision hashing; distinct from frozen Core accounting dimensions.', 'private-json'],
            'component' => ['Original daily earning component: regular, overtime, doubletime or specific paid-leave bucket.', 'component-code'],
            'time_entry_id' => ['Core entry currently associated with this component pointer. Prior IDs survive in immutable correction receipts.', 'reference:time_entry'],
            'timesheet_id' => ['Core weekly header associated with this component pointer; not the Quanta source timesheet ID.', 'reference:staffing_timesheet'],
            'source_hash' => ['Fingerprint of normalized source component, its routed Core identities and classification; approval evidence has a separate hash.', 'sha256'],
            'source_timesheet_status' => ['Captured Quanta timesheet status; never copied into Core approval status.', 'source-status'],
            'imported_by_user_id' => ['Core operator who imported or applied the current source revision.', 'reference:user'],
            'imported_at' => ['Original Core import time; later source observations and corrections have their own receipts.', 'timestamp'],
            'source_workspace_id' => ['Captured Quanta workspace identity. Reconnecting Core must not relabel prior source evidence.', 'provider-reference'],
            'source_workspace_binding_hash' => ['Fingerprint of the verified company/workspace connection used for this capture.', 'sha256'],
            'source_workspace_verified_by' => ['Core reviewer of the captured source workspace binding.', 'reference:user'],
            'source_workspace_verified_at' => ['Recorded verification time for the captured source workspace binding.', 'timestamp'],
            'source_workspace_verification' => ['Stored workspace-verification method (import or explicit review); missing historical evidence is not automatically backfilled.', 'verification-method'],
            'source_approval_json' => ['Source-reported timesheet, approval, actor and assignment evidence. This is separate from Core approval and is preserved in observation history.', 'private-json'],
            'source_approval_hash' => ['SHA-256 of the canonical source approval JSON; original observations are retained separately.', 'sha256'],
            'is_current' => ['Whether this source component is currently imported. Removal retires its pointer; explicit reviewed correction alone restores it.', 'boolean'],
        ],
        'quanta_time_approval_evidence' => [
            'quanta_time_import_id' => ['Durable import-component pointer observed at capture time; later pointer changes do not rewrite this observation.', 'reference:quanta_time_import'],
            'source_hash' => ['Original observed source component revision.', 'sha256'],
            'source_approval_hash' => ['Fingerprint of the exact observed source approval JSON.', 'sha256'],
            'source_approval_json' => ['Retained original source approval evidence; it does not confer Core approval.', 'private-json'],
            'source_workspace_id' => ['Original workspace of this approval observation.', 'provider-reference'],
            'source_workspace_binding_hash' => ['Connection/workspace verification fingerprint at capture time.', 'sha256'],
            'observed_by_user_id' => ['Core account capturing this observation; distinct from either system approver.', 'reference:user'],
            'observed_at' => ['Core observation time, separate from the embedded source approval time.', 'timestamp'],
        ],
        'jobdiva_time_intake' => [
            'connection_id'=>['Original Core JobDiva connection identity; reconnecting does not silently adopt existing time.', 'reference:connection'],
            'connection_identity_hash'=>['Hash of the source client and account identity; not a credential or proof of source approval.', 'sha256'],
            'external_id'=>['Exact original JobDiva time-row identity; never replaced by a Core SQL ID.', 'provider-reference'],
            'mapping_id'=>['Exact stable source-to-Core time mapping used by this committed intake.', 'reference:external_mapping'],
            'time_entry_id'=>['Original Core time entry kept across mutable draft source revisions; protected revisions require separate review.', 'reference:time_entry'],
            'people_tenant_id'=>['Verified owning People directory, distinct from the operating tenant.', 'reference:tenant'],
            'placements_tenant_id'=>['Verified owning placement directory, distinct from the operating tenant.', 'reference:tenant'],
            'previous_id'=>['Previous retained intake receipt for this source identity; source history is not overwritten.', 'reference:jobdiva_time_intake|null'],
            'source_hash'=>['Canonical fingerprint of the exact retained source payload, advanced only with an accepted atomic intake.', 'sha256'],
            'source_ct'=>['Encrypted original source payload, including any provider approval assertions; those assertions remain unverified.', 'encrypted-evidence'],
            'entry_ct'=>['Encrypted resulting Core row; owned values must still match before a later source revision can apply.', 'encrypted-evidence'],
            'source_approval_state'=>['Unverified source assertion state; receipt storage does not establish source approval or grant Core approval.', 'enum'],
            'actor_user_id'=>['Core intake operator, or null for a system job; never the provider approving person.', 'reference:user|null'],
        ],
        'quanta_time_revisions' => [
            'source_entry_id' => ['Original Quanta entry ID; full workspace and canonical identity context remains in encrypted snapshots.', 'provider-reference'],
            'review_hash' => ['Fingerprint binding the reviewer, current source, route, original entries, weeks, periods and capacity. A stale review cannot apply.', 'sha256'],
            'request_hash' => ['Exact request identity including actor and reason; a lost-response retry returns its original receipt.', 'sha256'],
            'actor_user_id' => ['Core correction operator, distinct from the independent approver required for replacement time.', 'reference:user'],
            'reason' => ['Required operator reason retained with the immutable correction receipt.', 'private-text-max-500'],
            'before_ct' => ['Encrypted original Core/source snapshot, including prior entry IDs, rates, approvals, routing and weekly evidence; never republished as a raw envelope.', 'encrypted-evidence'],
            'after_ct' => ['Encrypted result IDs and replacement mapping; replacement entries begin pending Core review.', 'encrypted-evidence'],
            'source_ct' => ['Encrypted corrected source and fresh provider observation proof, including observation nonce/time.', 'encrypted-evidence'],
        ],
    ];
}

function staffingTimeFieldRules(string $table, string $field, array $rules): array
{
    $definitions = staffingTimeFieldDefinitions();
    if (!isset($definitions[$table])) return $rules;
    $common = [
        'id'=>['Local row identity; external references must preserve the owning installation and directory.', 'integer'],
        'tenant_id'=>['Owning Core operating tenant; not a provider workspace ID.', 'reference:tenant'],
        'created_at'=>['Native record creation time; not source approval or financial settlement.', 'timestamp'],
        'updated_at'=>['Native row update time; not an immutable approval or provider revision.', 'timestamp'],
        'created_by_user_id'=>['Core creator account. Independent approval checks use this separately from the approver.', 'reference:user'],
    ];
    $definition = $definitions[$table][$field] ?? $common[$field] ?? null;
    if ($definition === null) return $rules; // New columns still require an explicit decision.
    $evidence = match ($table) {
        'jobdiva_time_intake' => 'core/jobdiva/time_intake.php',
        'quanta_time_revisions' => 'core/quanta/time_revisions.php',
        'quanta_time_approval_evidence' => 'core/quanta/time_approval.php',
        'quanta_time_imports' => 'core/quanta/sync.php',
        'staffing_timesheets' => 'modules/staffing/lib/timesheets.php; modules/time/lib/time.php::timeReconcileTimesheetHeader',
        'time_periods' => 'modules/time/lib/time.php; modules/time/api/periods.php',
        default => 'modules/time/lib/time.php',
    };
    $private = str_starts_with($definition[1], 'private-') || $definition[1] === 'encrypted-evidence';
    $rules['definition'] = $definition[0];
    $rules['unit'] = $definition[1];
    $rules['definition_status'] = 'reviewed_core_definition';
    $rules['owner'] = str_starts_with($table, 'quanta_') ? 'quanta_source_evidence' : ($table==='jobdiva_time_intake'?'jobdiva_source_evidence':'core_time_review');
    $rules['owner_directory_module'] = 'time';
    $rules['ownership_status'] = 'source_backed_native_workflow';
    $rules['ownership_evidence'][] = ['source'=>$evidence,'direction'=>'native_core_workflow','write_policy'=>'specialized_workflow_only'];
    $rules['disposition'] = $private || $rules['disposition'] === 'restricted' ? 'restricted'
        : (in_array($rules['disposition'], ['system_owned','source_reference'], true) ? $rules['disposition'] : 'specialized_workflow');
    $rules['write_policy'] = 'specialized_workflow_only';
    $rules['ownership_policy'] = 'native_workflow_owns_accepted_state; original_source_and_approval_evidence_retained; no_generic_field_sync';
    $rules['effective_date_policy'] = 'retain_original_work_date_period_and_approval; closed_or_consumed_history_requires_reconciliation';
    foreach ($rules['remote_contracts'] as $provider => &$contract) {
        if ($contract['status'] !== 'unverified') continue; // Preserve every implemented adapter contract.
        $contract['status'] = $private ? 'restricted_not_exchanged' : 'not_exchanged_raw_storage';
        $contract['reason'] = $private ? 'Retained in the permissioned Core evidence workflow; no raw envelope or private note export.'
            : 'Native Core storage/control field. Only explicitly documented provider projections are exchanged; no raw column sync.';
        $contract['source_evidence'] = ['core'=>$evidence];
        $contract['sync_enabled_by_dictionary'] = false;
    }
    unset($contract);
    return $rules;
}

/** Describe implemented projections without activating them or declaring hosted acceptance. */
function staffingTimeExchangeMappings(callable $add): void
{
    $quanta = static function(string $key,string $path,string $transform,string $file='core/quanta/sync.php')use($add):void {
        $add($key,'quanta','/api/v1/time-entries/approved',$path,$transform,
            'approved_source_only; reviewed_route; retain_source_evidence; separate_Core_approval',
            '1',['core'=>$file],'quanta_to_core');
    };
    foreach([
        'quanta_time_imports.quanta_entry_id'=>['data[].id','preserve_original_entry_id; entry_id_alias_accepted'],
        'quanta_time_imports.worker_id'=>['data[].worker_id','preserve_worker_id; user_id_alias_accepted; explicit_person_link'],
        'quanta_time_imports.source_worksite_id'=>['data[].worksite_id','preserve_worksite_id; reviewed_route'],
        'quanta_time_imports.source_dimension_values_json'=>['data[].dimension_values','sorted_named_scalar_values; preserve_distinct_from_Core_accounting_dimensions'],
        'quanta_time_imports.source_timesheet_status'=>['data[].timesheet_status','must_be_approved; timesheet.status_alias_accepted; never_Core_approval'],
        'quanta_time_imports.component'=>['data[].regular_minutes|overtime_minutes|doubletime_minutes|pto_minutes','normalize_complete_breakdown; explicit_PTO_type; hour_breakdown_and_typed_duration_fallbacks'],
        'time_entries.hours'=>['data[].regular_minutes|overtime_minutes|doubletime_minutes|pto_minutes','minutes_divided_by_60; validate_breakdown_total; round_each_component_to_2_places; typed_hour_duration_fallbacks'],
        'time_entries.work_date'=>['data[].work_date','date_alias_or_clock_in_plus_explicit_worksite_timezone; valid_calendar_date'],
        'time_entries.description'=>['data[].notes','notes_else_description; trim_then_truncate_to_500_bytes'],
        'time_entries.category'=>['data[].regular_minutes|overtime_minutes|doubletime_minutes|pto_minutes','component_plus_explicit_placement_classification; paid_leave_nonbillable'],
        'time_entries.hour_type'=>['data[].regular_minutes|overtime_minutes|doubletime_minutes|pto_minutes','normalize_component_preserving_doubletime_and_leave_types'],
        'time_entries.billable'=>['data[].regular_minutes|overtime_minutes|doubletime_minutes|pto_minutes','derive_from_component_and_internal_placement_classification; not_provider_boolean'],
    ]as$key=>[$path,$transform])$quanta($key,$path,$transform);
    $quanta('quanta_time_imports.source_approval_json','data[].timesheet_id|timesheet_status|approved_at|approved_by_email|updated_at|core_assignment',
        'validate_explicit_timestamps_to_UTC; retain_nullable_original_approval_and_captured_assignment; append_immutable_observations','core/quanta/time_approval.php');
    $add('quanta_time_imports.source_worksite_name','quanta','/api/v1/worksites','items[].name',
        'lookup_by_original_worksite_id; trim_and_truncate_255_bytes; absent_name_null',
        'context_label_only; never_identity_match','1',['core'=>'core/quanta/sync.php'],'quanta_to_core');
    $add('quanta_time_imports.source_workspace_id','quanta','/api/v1/integrations/coreflux/context','workspace.id',
        'capture_verified_workspace_once; never_relabel_history_from_a_new_connection',
        'verified_workspace_binding_and_original_source_provenance','2026-10-20.1',
        ['core'=>'core/quanta/time_provenance.php'],'quanta_to_core');
    foreach(['time_entries.id'=>'components[].entry_id','time_entries.status'=>'components[].status',
        'time_entries.tenant_id'=>'core_tenant_id',
        'quanta_time_imports.time_entry_id'=>'components[].entry_id','quanta_time_imports.component'=>'components[].component',
        'quanta_time_imports.quanta_entry_id'=>'source_entry_id']as$key=>$path){
        $add($key,'quanta','/api/v1/integrations/coreflux/time-reviews',$path,
            'current_components_only; source_revision_and_route_must_match; Core_draft_pending_review_approved_rejected_only',
            'reviewed_status_observation; independent_Core_approval; no_source_approval_or_financial_execution',
            '2026-10-24.1',['core'=>'core/quanta/time_review_delivery.php'],'core_to_quanta');
    }
    foreach([
        'placement_id'=>['placementId|placement_id|startId','resolve_existing_JobDiva_placement_mapping; raw_SQL_ID_not_copied'],
        'work_date'=>['date|workDate|work_date|entryDate','explicit_calendar_date; conflicting_aliases_rejected; changed_date_requires_review'],
        'hours'=>['hours|totalHours|total_hours|regularHours','positive_decimal_hours_max_24_and_two_places; mixed_components_and_alias_conflicts_rejected'],
        'category'=>['category|hourType|hour_type|type','explicit_regular_overtime_holiday_vacation_sick_pto_map; unknown_rejected; internal_placement_nonbillable'],
        'hour_type'=>['category|hourType|hour_type|type','derive_from_explicit_category; unknown_rejected'],
        'billable'=>['category|hourType|hour_type|type','derive_from_explicit_category_and_placement_classification'],
        'payable'=>['category|hourType|hour_type|type','derive_from_explicit_supported_category'],
        'description'=>['description','source_description_or_null'],
    ]as$column=>[$path,$transform]){
        $add('time_entries.'.$column,'jobdiva','/apiv2/bi/NewUpdatedTimesheetRecords',$path,$transform,
            'atomic_owned_draft_only; required_source_receipt_history_audit; exact_replay; protected_changes_and_legacy_adoption_require_review; provider_approval_unverified',
            'existing_adapter',['core'=>'core/jobdiva/sync_time.php'],'jobdiva_to_core');
    }
    foreach(['placement_id'=>['placement_id','resolve_existing_provider_placement_mapping'],
        'work_date'=>['date','preserve_work_date'],'hours'=>['hours','legacy_float_cast'],
        'category'=>['category','legacy_category_map; unmapped_rejected; review_required'],
        'description'=>['description','preserve_description']]as$column=>[$path,$transform]){
        $add('time_entries.'.$column,'jobdiva','/apiv2/jobdiva/uploadTimesheet',$path,$transform,
            'approved_entries_only; existing_mapping_skips_even_changed_payload; no_body_update_by_ID',
            'existing_adapter',['core'=>'core/jobdiva/sync_time.php'],'core_to_jobdiva');
    }
}
