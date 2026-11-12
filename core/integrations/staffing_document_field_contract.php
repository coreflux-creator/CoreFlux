<?php
/** Source-backed vault and employment-event definitions; no blanket document replication. */
declare(strict_types=1);
function staffingDocumentFieldRules(string $table,string $field,array $rules):array
{
    if ($table==='document_ownership_transfers') {
        $definitions=[
            'id'=>'Immutable local transfer receipt ID.',
            'tenant_id'=>'Owning document directory, unchanged by the merge.',
            'operating_tenant_id'=>'Company from which the authorized merge was performed.',
            'module'=>'People or Placements directory; never interchangeable.',
            'document_id'=>'Original document row, retained across transfers.',
            'storage_object_id'=>'Original physical-object registry identity, never rewritten by the transfer.',
            'sequence_no'=>'Per-document transfer order; gaps or repeats invalidate the history.',
            'from_record_id'=>'Reviewed previous person or placement in this directory.',
            'to_record_id'=>'Reviewed surviving person or placement in this directory.',
            'object_sha256'=>'Fingerprint of the unchanged object identity, key and recorded physical metadata.',
            'previous_sha256'=>'Prior transfer receipt fingerprint; null only for the first transfer.',
            'receipt_sha256'=>'Integrity fingerprint covering this transfer and its predecessor; not a digital signature.',
            'actor_user_id'=>'Authenticated merge operator, nullable for an existing JobDiva system consolidation.',
            'source'=>'Specific authorized merge workflow; does not grant transfer authority.',
            'created_at'=>'UTC recording time of this transfer, separate from original upload time.',
        ];
        if (!isset($definitions[$field])) return $rules;
        foreach($rules['remote_contracts']as&$remote)$remote=['path'=>null,'direction'=>null,'status'=>'core_internal_only','sync_enabled_by_dictionary'=>false];unset($remote);
        return array_replace($rules,[
            'definition'=>$definitions[$field],'unit'=>'native-transfer-evidence','definition_status'=>'reviewed_core_definition',
            'owner'=>'core_document_ownership_history','owner_directory_module'=>'record.module',
            'ownership_status'=>'source_backed_native_workflow','ownership_evidence'=>[['source'=>'core/storage/document_ownership.php']],
            'disposition'=>'system_owned','write_policy'=>'append_only_authorized_merge; original_storage_identity_and_bytes_unchanged',
            'effective_date_policy'=>'retain_original_document_dates_and_all_previous_transfer_receipts',
            'exchange_boundary'=>'Internal ownership evidence only; no provider identity, onboarding approval or document-publication change.',
        ]);
    }
    $common=[
        'id'=>['Immutable local document identity; separate from storage and provider document IDs.','reference:document'],
        'tenant_id'=>['Owning module directory, which can differ from the operating child company.','reference:directory'],
        'storage_object_id'=>['Registry object validated against the exact directory, module, entity and storage key, with physical evidence checked before registration or download.','reference:storage_object'],
        'file_name'=>['Reviewed display filename; not an object path or storage authority.','text-max-255'],
        'uploaded_by_user_id'=>['Authenticated Core operator recording the document; not a worker signature or approval.','reference:user'],
        'created_at'=>['Core document-row creation time, distinct from effective and signature dates.','timestamp'],
        'deleted_at'=>['Local document retirement time; physical evidence is retained and cannot be revived by upload retry.','nullable-timestamp'],
    ];
    $person=$common+[
        'person_id'=>['Canonical person in the owning People directory; never inferred from a legacy employee ID.','reference:person'],
        'doc_type'=>['Reviewed person-vault classification; does not by itself complete onboarding.','resume|offer|i9|w4|w9|nda|contract|passport|visa|license|other'],
        'signed'=>['Operator-maintained signature metadata; not cryptographic verification, employer acceptance, or time approval.','boolean'],
        'signed_at'=>['Recorded signature time; no signature authenticity or onboarding acceptance is established by this value alone.','nullable-timestamp'],
        'expires_at'=>['Recorded document expiry used when reviewing onboarding evidence.','nullable-timestamp'],
    ];
    $placement=$common+[
        'placement_id'=>['Exact placement in the owning Placements directory; a file cannot be rebound merely because its tenant matches.','reference:placement'],
        'doc_type'=>['Reviewed placement-document classification; independent of person-vault document types.','msa|sow|work_order|rate_sheet|timesheet_template|poc|noc|other'],
        'effective_from'=>['Optional inclusive document start date; not an assignment start or approved rate change.','nullable-date'],
        'effective_to'=>['Optional inclusive document end date, on or after its start; does not terminate the assignment.','nullable-date'],
    ];
    $legacy=[
        'employee_id'=>['Retained legacy employment reference; migration never equates it to canonical person_id.','legacy-reference:employment'],
        'kind'=>['Retained legacy document classification; requires reviewed conversion before use as canonical doc_type.','legacy-enum'],
        'filename'=>['Retained legacy filename, separate from canonical file_name.','legacy-text'],
        'mime_type'=>['Retained legacy MIME assertion; not verified physical object metadata.','legacy-text'],
        'size_bytes'=>['Retained legacy size assertion; not proof of physical file presence or length.','legacy-bytes'],
        'storage_path'=>['Untrusted legacy storage locator retained for review; never a trusted download or canonical registry link.','private-legacy-locator'],
        'uploaded_by'=>['Retained legacy uploader reference; not substituted for the current authenticated operator.','legacy-reference:user'],
        'uploaded_at'=>['Retained legacy upload timestamp; not rewritten as a canonical creation or signature time.','legacy-timestamp'],
        'signed_by'=>['Retained legacy signer reference; not verification of a signature or Core approval.','legacy-reference:user'],
        'notes'=>['Retained private legacy document notes; not executable mapping, approval or evidence of a signed agreement.','private-text'],
    ];
    $history=[
        'id'=>['Immutable local employment-history row; not a person or provider ID.','reference:employment_history'],
        'tenant_id'=>['Owning People directory for this historical employment event.','reference:people_directory'],
        'employee_id'=>['Employment record to which the event belongs; resolve a person through the reviewed employment link.','reference:employment'],
        'event_type'=>['Historical event type. Native reviewed setup writes hire on activation; the employee route records termination. Other schema-supported types do not imply an implemented exchange.','hire|transfer|promotion|demotion|status_change|termination|rehire'],
        'effective_date'=>['Event effective date; separate from the recorded creation time and each assignment date.','date'],
        'job_title'=>['Employment job-title snapshot at this event; not a current placement title.','nullable-text'],
        'department'=>['Employment department snapshot; not a placement-routing dimension.','nullable-text'],
        'location'=>['Historical employment location label; not a validated tax jurisdiction.','nullable-text'],
        'manager_id'=>['Historical employment-manager reference when present; current setup does not populate this field.','nullable-reference:employment'],
        'status'=>['Employment-status snapshot at the event; not current worker availability, placement status or payroll approval.','nullable-enum'],
        'reason'=>['Recorded private explanation for the historical event.','private-text'],
        'notes'=>['Retained private event notes; not executable changes or remote approval.','private-text'],
        'created_at'=>['Time the history row was recorded; distinct from event effective date.','timestamp'],
        'created_by'=>['Core operator who recorded the history event; not the worker or payroll approver.','reference:user'],
    ];
    $legacyField=$table==='people_documents'&&isset($legacy[$field]);
    $definitions=$table==='people_documents'?($person+$legacy):($table==='placement_documents'?$placement:($table==='people_employment_history'?$history:[]));
    if(!isset($definitions[$field]))return $rules;
    [$definition,$unit]=$definitions[$field];
    $isHistory=$table==='people_employment_history';
    $module=$table==='placement_documents'?'placements':'people';
    foreach($rules['remote_contracts']as&$remote)$remote=['path'=>null,'direction'=>null,'status'=>'core_internal_only','sync_enabled_by_dictionary'=>false];unset($remote);
    $private=in_array($field,['storage_path','notes','reason'],true);
    return array_replace($rules,[
        'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition',
        'owner'=>$legacyField?'core_legacy_document_review':($isHistory?'core_people_employment_history':'core_'.$module.'_document_vault'),
        'owner_directory_module'=>$module,'ownership_status'=>'source_backed_native_workflow',
        'ownership_evidence'=>$legacyField?[['source'=>'modules/people/migrations/009_document_vault_alignment.sql']]:($isHistory?[
            ['source'=>'modules/people/lib/employment_setup.php','route'=>'/modules/people/api/employment_setup.php'],
            ['source'=>'modules/people/api/employees.php','route'=>'/modules/people/api/employees.php'],
        ]:[['source'=>'modules/'.$module.'/lib/documents.php','route'=>'/modules/'.$module.'/api/documents.php']]),
        'disposition'=>$private?'restricted':(in_array($rules['disposition'],['system_owned','source_reference','restricted'],true)?$rules['disposition']:'specialized_workflow'),
        'write_policy'=>$legacyField?'preserve_legacy; explicit_identity_and_storage_review_before_canonical_binding':($isHistory?'native_event_history; no_generic_sync_or_retroactive_rewrite':'native_owned_vault; exact_entity_and_verified_storage; required_atomic_change_audit; preserve_recorded_physical_identity; no_blanket_remote_replication'),
        'effective_date_policy'=>$isHistory?'preserve_event_effective_date_and_original_history':($legacyField?'preserve_original_legacy_values':'document_dates_do_not_change_assignment_or_payroll_state'),
        'exchange_boundary'=>$isHistory?'No employment-history receiver is implemented. Current employment setup and provider employment identity use their separate reviewed workflows.':'Quanta onboarding uses its separately reviewed evidence-transfer workflow. This dictionary does not authorize replication of the vault or legacy files.',
    ]);
}
