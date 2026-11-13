<?php
/** Company, client and vendor meanings from their native catalogs and actual adapters. */
declare(strict_types=1);

function staffingPartyFieldDefinitions(): array
{
    $d=[
        'companies'=>[
            'coreflux_source_state'=>['Internal source authority, observed proposals and explicit Core field ownership; private review metadata, never a provider-mapped business field.','restricted-source-ownership'],
            'name'=>['Company directory display name; not a provider ID or proof of the employing legal entity.','text'],
            'legal_name'=>['Recorded legal name, separate from the display name and placement-specific employer or bill-to role.','nullable-text'],
            'ein_last4'=>['Retained employer tax-identifier suffix; not a complete identifier or a company matching key.','restricted-last-four'],
            'ein_full_ct'=>['Encrypted company tax identifier; never a generic mapping or public directory field.','restricted-ciphertext'],
            'duns'=>['Recorded DUNS reference; does not replace CO number or establish a provider match.','nullable-source-reference'],
            'website'=>['Company website for directory use.','nullable-url-text'],
            'phone'=>['Company main telephone, independent of primary-contact and remittance phones.','nullable-phone-text'],
            'primary_contact_name'=>['Header contact display name; separate from named company_contacts rows and access permissions.','nullable-text'],
            'primary_contact_email'=>['Header contact address; not customer identity, worker login or payment authority.','nullable-email'],
            'primary_contact_phone'=>['Header contact telephone.','nullable-phone-text'],
            'msa_signed_at'=>['Recorded master agreement signature date; not proof of a valid document or placement approval.','nullable-date'],
            'msa_storage_object_id'=>['Master agreement object reference; document ownership and access need their own validation.','restricted-storage-reference'],
            'notes'=>['Internal company notes; not an instruction or evidence of signed contract terms.','restricted-internal-text'],
            'default_terms'=>['Company-level payment-terms label used as a default; approved placement and issued document terms retain their own history.','terms-label'],
            'payment_terms_days'=>['Retained numeric terms projection, distinct from the default_terms label and effective placement overrides.','nullable-days'],
            'currency'=>['Company default currency; does not convert amounts or replace a contract/document currency.','currency-code'],
            'status'=>['Company directory status, independent of client status, employment, portal access and existing financial obligations.','prospect|active|inactive|blacklisted'],
            'tax_classification'=>['Recorded company tax classification; not authority to recategorize a worker or execute a filing.','nullable-enum'],
            'industry'=>['Directory industry description.','nullable-text'],
            'employee_size_range'=>['Directory size range, not a live payroll employee count.','nullable-text'],
            'w9_on_file'=>['Recorded W-9 availability indicator; not proof that an attached object has been validated.','evidence-flag'],
            'w9_expires_on'=>['Recorded W-9 review/expiry date.','nullable-evidence-date'],
            'w9_storage_object_id'=>['Private tax-document reference requiring scoped document review.','restricted-storage-reference'],
            'coi_on_file'=>['Recorded insurance-certificate availability indicator; not a coverage determination.','evidence-flag'],
            'coi_expires_on'=>['Recorded insurance-certificate expiry date.','nullable-evidence-date'],
            'coi_storage_object_id'=>['Insurance-document reference requiring scoped document review.','restricted-storage-reference'],
            'tags_json'=>['Internal directory tags serialized by the native tags editor; not provider categories or routing rules.','restricted-internal-json'],
            'use_count'=>['System-maintained directory usage counter; not billable units or source authority.','system-counter'],
            'last_used_at'=>['System-maintained last directory-use time.','system-timestamp'],
            'account_manager_user_id'=>['Internal Core account association for company management; does not grant access or create a commission.','nullable-reference:user'],
        ],
        'company_roles'=>[
            'company_id'=>['Parent company identity. Scope follows the parent Companies directory; role rows have no independent tenant selector.','reference:company'],
            'role'=>['Company relationship role. A role label alone does not create a client/vendor profile, a placement party or application access.','client|customer|vendor|msp|prime_vendor|sub_vendor|referrer|partner'],
            'granted_at'=>['System-recorded time the company relationship role was added, not user-access approval.','system-timestamp'],
        ],
        'company_contacts'=>[
            'coreflux_source_state'=>['Internal contact source authority, proposals and native ownership decisions; never a public contact value.','restricted-source-ownership'],
            'company_id'=>['Parent company in the same Companies directory; not a person or provider contact ID.','reference:company'],
            'name'=>['Business-contact display name; separate from canonical People identity.','text'],
            'title'=>['Business-contact job title.','nullable-text'],
            'email'=>['Business-contact email; legacy intake matching by company/email still requires identity/conflict hardening.','nullable-email'],
            'phone'=>['Business-contact work telephone.','nullable-phone-text'],
            'mobile_phone'=>['Business-contact mobile telephone.','nullable-phone-text'],
            'linkedin_url'=>['Business-contact professional profile URL.','nullable-url-text'],
            'contact_role'=>['Business function such as AP, AR or approver; does not grant time approval or payment permission.','account_mgr|recruiter|ap|ar|approver|technical|executive|other'],
            'department'=>['Business-contact organizational department.','nullable-text'],
            'decision_role'=>['Sales relationship context; independent of application authority.','decision_maker|champion|influencer|blocker|gatekeeper|unknown'],
            'is_active'=>['Directory-contact availability, not a login enablement state.','boolean'],
            'is_primary'=>['Preferred contact indicator for the company, not default approval delegation.','boolean'],
            'notes'=>['Internal contact notes.','restricted-internal-text'],
        ],
        'company_addresses'=>[
            'company_id'=>['Parent company in the same Companies directory.','reference:company'],
            'kind'=>['Address purpose; a worksite label does not create a Quanta worksite or establish employment tax jurisdiction.','hq|billing|remit_to|worksite|mailing'],
            'label'=>['Optional operator label for this address.','nullable-text'],
            'line1'=>['First street-address line for the selected address purpose.','address-text'],
            'line2'=>['Additional street-address line.','nullable-address-text'],
            'city'=>['City/locality for this address.','address-text'],
            'state'=>['State/province for this address.','nullable-address-text'],
            'postal_code'=>['Postal/ZIP code retained as text.','nullable-address-text'],
            'country'=>['Stored two-character address country code; native creation defaults to US if omitted.','country-code'],
            'is_primary'=>['Preferred address of this kind; not a payment authorization or tax-site assignment.','boolean'],
            'notes'=>['Internal address context.','restricted-internal-text'],
        ],
        'staffing_clients'=>[
            'company_id'=>['Canonical company underlying the separate client/bill-to profile; never inferred from placement end-client identity.','reference:company'],
            'name'=>['Client display name; used for customer publication only when legal_name is empty.','text'],
            'legal_name'=>['Client legal name preferred by the existing PurePay customer publication contract.','nullable-text'],
            'industry'=>['Client industry description.','nullable-text'],
            'primary_contact_name'=>['Client contact display name; the current customer packet does not carry it.','nullable-text'],
            'primary_contact_email'=>['Client contact email carried by the reviewed customer creation packet; never used as the immutable customer key.','nullable-email'],
            'primary_contact_phone'=>['Client contact telephone carried by the reviewed customer creation packet.','nullable-phone-text'],
            'payment_terms_days'=>['Client default numeric payment terms; does not amend previously approved placement or issued invoice terms.','days'],
            'status'=>['Bill-to profile availability; independent of parent company status, existing invoice balances and provider activation.','active|prospect|on_hold|inactive|closed'],
            'notes'=>['Internal client notes excluded from customer publication.','restricted-internal-text'],
            'msa_status'=>['Recorded master agreement workflow state; not proof of signed-document ownership or placement approval.','none|draft|executed|expired'],
            'msa_executed_at'=>['Recorded master agreement execution date.','nullable-date'],
            'msa_expires_at'=>['Recorded master agreement expiration date.','nullable-date'],
        ],
        'ap_vendors_index'=>[
            'vendor_name'=>['AP payee display name; preserved V number and provider mappings establish identity.','text'],
            'company_id'=>['Canonical business company for this vendor; individual vendors may have no company.','nullable-reference:company'],
            'contact_email'=>['General vendor contact, separate from remittance delivery address.','nullable-email'],
            'vendor_type'=>['Recorded AP vendor type; distinct from the placement engagement classification and company role labels.','1099_individual|c2c_corp|w9_business|utility|other'],
            'vendor_category'=>['Operational hours/revenue versus service-provider category; does not by itself decide tax treatment.','hourly_labor|service_provider'],
            'payment_method'=>['Preferred payment method; saving it neither originates a payment nor grants rail access.','nullable-payment-method'],
            'remit_to_email'=>['Vendor remittance communication address; not a bank account or identity matching key.','nullable-email'],
            'remit_to_phone'=>['Vendor remittance contact telephone.','nullable-phone-text'],
            'payment_account_last4'=>['Masked retained account suffix; not enough to originate or verify a payment.','restricted-last-four'],
            'payment_routing_last4'=>['Masked retained routing suffix; not the full bank routing value.','restricted-last-four'],
            'payment_account_ct'=>['Encrypted bank account; omitted from public detail and all generic exchange.','restricted-ciphertext'],
            'payment_routing_ct'=>['Encrypted bank routing; omitted from public detail and all generic exchange.','restricted-ciphertext'],
            'payment_account_type'=>['Recorded bank account type, separate from legal/payee identity.','restricted-checking|savings'],
            'kms_key_version_payment'=>['Internal encryption-version metadata for payment details.','restricted-system-metadata'],
            'default_remit_to_json'=>['Legacy opaque remittance settings without a current public/editor exchange schema; retained privately without interpreting unknown keys.','restricted-opaque-json'],
            'default_terms'=>['Vendor default terms label; placement/AP document overrides and approved history remain separate.','terms-label'],
            'default_pwp'=>['Default pay-when-paid preference; does not prove client receipt or release a payable.','boolean'],
            'tax_id_last4'=>['Masked tax-identifier suffix, not an integration matching key.','restricted-last-four'],
            'tax_id_full_ct'=>['Encrypted tax identifier; full reveal requires its existing permission and audit path.','restricted-ciphertext'],
            'kms_key_version'=>['Internal tax-identifier encryption-version metadata.','restricted-system-metadata'],
            'requires_1099'=>['Recorded reporting-selection flag; not a completed tax review, filing or worker reclassification.','boolean'],
            'last_bill_at'=>['System-maintained most recent linked bill time, not a payment date.','system-timestamp'],
            'placement_id_last'=>['Last-linked placement hint; does not bind every vendor transaction to that placement.','system-reference:placement'],
        ],
    ];
    foreach(['companies'=>['address_line1','address_line2','city','state','postal_code','country'],
        'staffing_clients'=>['billing_address_line1','billing_address_line2','billing_city','billing_state','billing_postal_code','billing_country']]as$table=>$fields){
        foreach($fields as$field)$d[$table][$field]=[
            ($table==='companies'?'Company header':'Client billing').' address component '.$field.'; separate from company address rows, worksites and immutable document snapshots. Legacy intake/default country behavior needs explicit review.',
            str_ends_with($field,'country')?'country-code':'nullable-address-text'];
    }
    return $d;
}

function staffingPartyFieldRules(string $table,string $field,array $rules): array
{
    $definition=staffingPartyFieldDefinitions()[$table][$field]??null;
    if($definition===null)return $rules;
    [$meaning,$unit]=$definition;
    $module=$table==='staffing_clients'?'placements':($table==='ap_vendors_index'?'ap':'companies');
    $owner=$module==='placements'?'core_client_directory':($module==='ap'?'core_vendor_directory':'core_company_directory');
    $restricted=str_starts_with($unit,'restricted-')||$rules['disposition']==='restricted';
    $system=str_starts_with($unit,'system-');
    $evidence=$module==='ap'?'modules/ap/api/vendors.php':($module==='placements'?'modules/staffing/lib/clients.php':'modules/people/lib/companies.php');
    $jobdivaCompany=['name','legal_name','website','phone','duns','ein_last4','primary_contact_name','primary_contact_email','primary_contact_phone','address_line1','address_line2','city','state','postal_code','country','notes','msa_signed_at'];
    $jobdivaContact=['name','title','email','phone','mobile_phone','linkedin_url','contact_role','department','decision_role','is_active','is_primary','notes'];
    $legacyInbound=($table==='companies'&&in_array($field,$jobdivaCompany,true))||($table==='company_contacts'&&in_array($field,$jobdivaContact,true));
    foreach($rules['remote_contracts']as$provider=>&$remote){
        if(!empty($remote['mappings']))continue;
        if($provider==='jobdiva'&&$legacyInbound){
            $remote=['path'=>'tenant_field_registry:'.($table==='companies'?'company':'contact').'.'.$field,
                'direction'=>'jobdiva_to_core','status'=>'legacy_inbound_conflict_review_required','sync_enabled_by_dictionary'=>false,
                'source_evidence'=>['core'=>'core/jobdiva/sync.php','function'=>$table==='companies'?'jobdivaSyncCompanies':'jobdivaSyncUpsertContact'],
                'reason'=>'Existing source importer can overwrite accepted native values; per-field ownership and stable contact matching are not yet accepted. Registry candidates, legacy blank/default behavior and mapped provider IDs must be reviewed.'];
        }else{
            $remote=['path'=>null,'direction'=>null,'status'=>($restricted||$system)?'core_internal_only':'unsupported_with_gap','sync_enabled_by_dictionary'=>false,
                'reason'=>($restricted||$system)?'Private evidence, encrypted material or internal derived state; no blanket provider copy.':'No dedicated field transport is established in the current receiver contract. Preserve Core values and existing provider identities.'];
        }
    }unset($remote);
    return array_replace($rules,[
        'definition'=>$meaning,'definition_status'=>'reviewed_core_definition','unit'=>$unit,'owner'=>$owner,'owner_directory_module'=>$module,
        'ownership_status'=>$legacyInbound?'native_owner_with_legacy_inbound_gap':'source_backed_native_workflow',
        'ownership_evidence'=>array_merge($rules['ownership_evidence']??[],[['source'=>$evidence],['source'=>'core/jobdiva/sync.php'],['source'=>'core/integrations/purepay_customer_contract.php']]),
        'disposition'=>$restricted?'restricted':($system?'system_owned':'specialized_workflow'),
        'write_policy'=>$system?'system_derived_only':($legacyInbound?'native_owner; legacy_source_overwrite_conflict_review_still_required':($restricted?'dedicated_private_or_evidence_workflow_only':'authorized_native_catalog_or_existing_reviewed_adapter')),
        'effective_date_policy'=>'current_catalog_default_only; preserve_approved_placement_time_and_financial_document_history',
        'relationship_policy'=>'CO_company_CL_client_and_V_vendor_are_distinct; owner_directory_and_provider_mapping_required; contact_and_role_labels_do_not_grant_access',
        'implementation_gap'=>str_contains($unit,'storage-reference')?'native_document_pointer_validation_and_browser_upload_acceptance':($legacyInbound?'legacy_inbound_ownership_and_contact_identity_review':null),
    ]);
}
