<?php
/** Person profile ownership, separate from employment, portal access and settlement identity. */
declare(strict_types=1);

function staffingPeopleFieldDefinitions(): array
{
    $fields=[
        'entity_id'=>['Core legal-entity association selected for this person; not proof of the employer on every placement or a provider organization ID.','nullable-reference:legal_entity'],
        'first_name'=>['Current first name in the canonical People directory. Existing provider identity remains attached to the person number.','text-max-100'],
        'middle_name'=>['Optional middle name; current worker and payroll person packets do not send this separately.','nullable-text-max-100'],
        'last_name'=>['Current last name in the canonical People directory; names alone cannot rebind employment or provider identities.','text-max-100'],
        'preferred_name'=>['Optional display preference, distinct from legal/payroll names and portal sign-in identity.','nullable-text-max-100'],
        'email_primary'=>['Directory contact email. A change is not a provider login change or permission to merge people by email.','email-max-255'],
        'email_secondary'=>['Additional contact address; not an alternative integration identity or portal sign-in.','nullable-email-max-255'],
        'phone_primary'=>['Primary person contact number, retained as text with country prefix when supplied.','nullable-text-max-40'],
        'phone_secondary'=>['Additional person contact number; independent of primary provider contact.','nullable-text-max-40'],
        'classification'=>['Directory relationship category. Candidate, W-2 and other classifications do not activate employment, approve payroll or choose a placement payee.','w2|1099|c2c|temp|perm|candidate|alumni'],
        'status'=>['Current directory availability; distinct from employment activation, placement lifecycle and worker login access.','active|bench|inactive|do_not_rehire'],
        'termination_date'=>['Recorded person-level termination date; saving it does not end every placement, revoke portal access or rewrite employment history.','nullable-date'],
        'pay_frequency'=>['Person-level payroll cadence preference; does not replace the reviewed placement cycle or provider payroll calendar.','nullable-weekly|biweekly|semimonthly|monthly'],
        'gender'=>['Private demographic information; requires the same restricted permissions for writes and reads.','private-text-max-40'],
        'marital_status'=>['Private demographic information, separate from reviewed payroll tax elections.','private-text-max-40'],
        'dob'=>['Private date of birth; not inferable from employment dates.','private-date'],
        'ssn_last4'=>['Private retained tax-identifier suffix; not a complete identifier or identity-matching key.','private-last-four'],
        'work_auth_status'=>['Recorded work-authorization category; not a completed I-9 review or proof of current eligibility.','citizen|green_card|h1b|opt|cpt|tn|other|unknown'],
        'work_auth_expiry'=>['Recorded work-authorization expiry, independent of the placement end date and document review.','nullable-date'],
        'requires_sponsorship'=>['Recorded sponsorship requirement; false does not prove authorization or establish employment classification.','boolean'],
        'linkedin_url'=>['Person professional-profile link, not identity evidence or an executable instruction.','nullable-url-text-max-255'],
        'resume_storage_object_id'=>['Private resume object reference. Generic profile input cannot attach arbitrary storage objects; use scoped document ownership.','restricted-reference:storage_object'],
        'recruiter_notes'=>['Internal recruiting context, excluded from worker-facing profile and payroll-person packets.','private-internal-text'],
        'source'=>['Human-readable intake-origin label, distinct from verified provider mapping and immutable source evidence.','nullable-text-max-120'],
        'external_id'=>['Retained source reference; does not replace the canonical person number or provider-scoped identity mapping.','nullable-source-reference'],
        'referred_by_person_id'=>['Recorded referring Core person; does not create a referral commission, establish a payee or grant access.','nullable-reference:person'],
        'worker_class'=>['Legacy downstream routing category, distinct from directory classification and reviewed employment setup. Generic profile editing cannot change financial routing.','employee|w2_temp|contractor_1099|c2c|eor|referral|vendor_backed'],
        'worker_class_meta_json'=>['Retained legacy routing metadata. No generic profile or remote write contract is defined; preserve for explicit routing review.','restricted-routing-json'],
        'user_id'=>['Explicit Core account association, not a sign-in invitation or a payroll recipient by itself. Reviewed employment identity is separately required.','restricted-reference:user'],
    ];
    foreach(['home'=>'Home/residential','mailing'=>'Mailing'] as $prefix=>$label) {
        foreach(['address_line1'=>'Street address','address_line2'=>'Additional address line','city'=>'City','state'=>'State/province','postal_code'=>'Postal code','country'=>'Country code'] as $part=>$meaning) {
            $fields[$prefix.'_'.$part]=[$label.' '.$meaning.'. Private person data; independent of assignment worksite and company address.','private-address-text'];
        }
    }
    return $fields;
}

function staffingPeopleFieldRules(string $table,string $field,array $rules): array
{
    $definition=staffingPeopleFieldDefinitions()[$field]??null;
    if($table!=='people'||$definition===null)return $rules;
    [$meaning,$unit]=$definition;
    $restricted=str_starts_with($unit,'private-')||str_starts_with($unit,'restricted-')||$rules['disposition']==='restricted';
    $internal=$restricted||in_array($field,['entity_id','source','external_id','referred_by_person_id','worker_class','work_auth_status','work_auth_expiry','requires_sponsorship','termination_date','pay_frequency','classification'],true);
    foreach($rules['remote_contracts'] as $provider=>&$remote) {
        if(!empty($remote['mappings']))continue;
        $remote=['path'=>null,'direction'=>null,'status'=>$internal?'core_internal_only':'unsupported_with_gap','sync_enabled_by_dictionary'=>false,
            'reason'=>$internal?'Core profile, restricted evidence or routing fact; exchange requires a separately authorized and reviewed contract.':'The current person adapter does not carry this field. Retain it in Core; do not collapse it into a different name/contact field or overwrite provider identity.'];
    }
    unset($remote);
    $policy='authorized_native_profile_edit; preserve_provider_identity; inbound_changes_require_existing_adapter_review';
    if($restricted)$policy='dedicated_authorized_private_profile_or_document_workflow; no_generic_mapping_or_blanket_remote_copy';
    if(in_array($field,['user_id','worker_class','worker_class_meta_json','resume_storage_object_id'],true))$policy='generic_profile_input_refused; preserve_existing_association_for_dedicated_identity_routing_or_document_review';
    return array_replace($rules,[
        'definition'=>$rules['definition_status']==='reviewed_core_definition'?$rules['definition']:$meaning,
        'unit'=>$rules['definition_status']==='reviewed_core_definition'?$rules['unit']:$unit,
        'definition_status'=>'reviewed_core_definition','owner'=>'core_people_directory','owner_directory_module'=>'people',
        'ownership_status'=>$rules['ownership_status']??'source_backed_native_workflow',
        'ownership_evidence'=>array_merge($rules['ownership_evidence']??[],[['source'=>'modules/people/lib/people.php','route'=>'/modules/people/api/people.php'],['source'=>'modules/people/lib/profile_input.php'],['source'=>'modules/people/lib/worker_class.php']]),
        'disposition'=>$restricted?'restricted':($field==='external_id'?'source_reference':'specialized_workflow'),
        'write_policy'=>$policy,'effective_date_policy'=>'current_profile_only; preserve_approved_assignment_time_payroll_and_onboarding_evidence',
        'exchange_boundary'=>'No new provider permissions, login access or financial execution. Employment type and hire date retain the dedicated employment-setup contract.',
    ]);
}
