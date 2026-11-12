<?php
/** Native commercial field ownership and exchange boundaries. Metadata grants no write access. */
declare(strict_types=1);

function staffingCommercialFieldDefinitions(): array
{
    $common = [
        'id'=>['Immutable local commercial row ID; separate from provider identity and canonical company/person numbers.','reference:commercial_row'],
        'tenant_id'=>['Placements directory that owns this rule or corporate record; not necessarily the operating child company.','reference:placements_directory'],
        'placement_id'=>['Exact parent placement in the owning directory. Native child edits lock and authorize this parent.','reference:placement'],
        'created_at'=>['Time the local record was created; not a work, agreement or payment date.','timestamp'],
        'updated_at'=>['Time the local record changed; not a new effective date or permission to rewrite approved history.','timestamp'],
    ];
    $terms = [
        'payment_terms_override'=>['Reviewed payment terms for this participant. Paid-when-paid terms must agree with the separate PWP setting; the value does not authorize payment.','payment-terms'],
        'pwp_enabled'=>['Paid-when-paid condition for this participant, independent of provider payment status. Does not establish receipt of client funds.','boolean'],
    ];
    return [
        'placement_client_chain'=>$common+$terms+[
            'position'=>['Unique nonnegative ordering position within this placement chain; not a provider identity.','integer:0..2147483647'],
            'party_name'=>['Company display name aligned to the explicitly selected company; a name alone does not establish an existing provider link.','text-max-255'],
            'party_role'=>['Commercial position of the company in the client chain; does not grant an application role.','end_client|msp|prime_vendor|sub_vendor|direct'],
            'company_id'=>['Company in the placement company directory; client/vendor role numbers remain distinct from this company ID.','reference:company'],
            'vendor_portal_id'=>['Selected active vendor-portal configuration in this directory. It is not a portal credential or a Quanta worksite.','nullable-reference:vendor_portal'],
            'portal_fee_pct'=>['Fraction of bill rate used for portal deductions; 0.05 means five percent. Flat and percentage components have separate fields.','fraction:0..1; scale-4'],
            'portal_fee_flat'=>['Fixed portal deduction in the per-hour contract margin calculation; not a one-time fee or an independently denominated payment.','amount/hour; scale-2; contract-currency'],
            'contract_storage_object_id'=>['Private contract object bound to this exact chain row and directory; not a transferable file URL or onboarding approval.','restricted-reference:storage_object'],
            'submittal_id'=>['Retained VMS submission reference; does not replace the placement number or establish a provider assignment link.','nullable-text-max-64'],
            'vms_job_id'=>['Retained VMS job reference; separate from the canonical Core job and JobDiva Start ID.','nullable-text-max-64'],
            'portal_credentials_ct'=>['Encrypted portal credential payload; available only through the dedicated credential workflow, excluded from ordinary chain output and rule audits.','encrypted-secret'],
            'kms_key_version'=>['Encryption-key version accompanying the private portal credentials; never copied as integration configuration.','restricted-encryption-metadata'],
            'is_payable'=>['Explicit chain payment flag considered with fee/model rules when deriving the economic party; not a payable document or proof of settlement.','boolean'],
        ],
        'placement_referrals'=>$common+$terms+[
            'referrer_type'=>['Explicit recipient kind; changing it clears incompatible hidden identity fields.','vendor|person|user'],
            'referrer_vendor_name'=>['Referring company name aligned to its selected company; only meaningful for vendor referrers.','nullable-text-max-255'],
            'referrer_company_id'=>['Referring company in the placement company directory; never a person or user ID.','nullable-reference:company'],
            'referrer_person_id'=>['Referring person in the reviewed People directory; a private child directory cannot be silently attached to a shared placement.','nullable-reference:person'],
            'referrer_user_id'=>['Active referring user reviewed in both the operating workspace and placement owner when those differ.','nullable-reference:user'],
            'fee_pct'=>['Fraction of the selected percentage basis; mutually exclusive with a positive fixed fee.','fraction:0..1; scale-4'],
            'fee_flat'=>['Positive fixed fee for the selected nonpercentage basis; no independent foreign-currency payout is established by this legacy field.','amount; scale-2; contract-currency'],
            'fee_basis'=>['Rule calculation selector, kept distinct from commission frequency and settlement-channel approval.','per_hour|per_invoice|one_time|pct_bill|pct_margin'],
            'duration_months'=>['Optional retained referral duration in months. It does not replace the separately validated start/end dates or prove recurring settlement.','nullable-integer:1..1200'],
            'start_date'=>['Inclusive date the referral rule begins applying; not the payment date.','date'],
            'end_date'=>['Optional rule end date on or after its start; does not terminate the worker placement.','nullable-date'],
            'notes'=>['Private source/review context for this referral; not executable terms or recipient identity evidence.','private-text'],
        ],
        'placement_commissions'=>$common+[
            'plan_id'=>['Retained legacy commission-plan reference. Native editing refuses a new nonempty plan because plans are not configured; it is not a verified recipient or calculation rule.','legacy-reference:commission_plan'],
            'role'=>['Recipient role in the commission rule, distinct from recipient identity and application permissions.','account_manager|lead|recruiter|team|other'],
            'user_id'=>['Reviewed active recipient in the operating workspace and placement directory. Recruiter names and provider IDs cannot supply this identity.','reference:user'],
            'split_pct'=>['Fraction of the reviewed calculation basis. Source allocation of a commission pool remains informational until the payable rule is reviewed.','fraction:0..1; scale-4'],
            'basis'=>['Calculation selector. Flat does not mean one time; current native payroll calculates net_margin and gross_margin as billed amount less worker pay, without other modeled costs.','net_margin|gross_margin|bill_rate|flat'],
            'flat_amount'=>['Fixed amount with at most two decimal places and separately reviewed currency and payment frequency; consumed rules cannot rewrite this amount.','amount; explicit-currency'],
            'currency'=>['Explicit commission denomination independent of placement currency. Null is unknown; foreign currency is retained without inventing conversion or USD eligibility.','USD|CAD|GBP|EUR|INR|AUD'],
            'payment_frequency'=>['One time per commission or once per recipient payroll run for a fixed amount; null requires review. Percentage rules accrue from their earned basis.','one_time|per_pay_period'],
            'due_month'=>['Due month of a one-time commission, retaining month precision without inventing a payment day.','nullable-YYYY-MM'],
            'effective_from'=>['Date the rule starts applying; distinct from its due month and actual settlement date.','date'],
            'effective_to'=>['Optional rule end date; consumed rules retain their original terms and cannot be deleted to erase settlement evidence.','nullable-date'],
            'notes'=>['Private commission source context; cannot substitute for currency, frequency, due month, recipient identity or approval.','private-text'],
        ],
        'placement_corp_details'=>$common+$terms+[
            'corp_legal_name'=>['Reviewed corporate payee legal name for a C2C placement, aligned to the company and economic party. Private tax/documents block an unreviewed payee replacement.','text-max-255'],
            'company_id'=>['Reviewed corporate company in the placement company directory; separate from its vendor role and provider company identity.','reference:company'],
            'ap_vendor_id'=>['Resolved AP vendor for the selected corporate company. Duplicate vendor matches require review; callers cannot select an arbitrary vendor through corporate editing.','reference:vendor'],
            'corp_ein_ct'=>['Encrypted corporate tax identifier, written only from validated private EIN input; excluded from public review and required audit payloads.','encrypted-tax-identifier'],
            'corp_ein_last4'=>['Masked suffix derived from the encrypted EIN; not a full tax identifier or an independently editable matching key.','restricted-last-four'],
            'kms_key_version'=>['Retained encryption-version metadata; not accepted as caller-selected encryption configuration by the native corporate editor.','restricted-encryption-metadata'],
            'corp_address_line1'=>['Reviewed corporate mailing address, independent of worker residence and assignment worksite.','nullable-text-max-255'],
            'corp_address_line2'=>['Additional corporate mailing address line.','nullable-text-max-255'],
            'corp_city'=>['Corporate address city; not the assignment city.','nullable-text-max-120'],
            'corp_state'=>['Corporate address state/province text; not verified payroll tax jurisdiction.','nullable-text-max-60'],
            'corp_postal_code'=>['Corporate address postal code, retained as text.','nullable-text-max-20'],
            'corp_country'=>['Uppercase two-letter corporate address code accepted by the native editor; the editor validates shape, not country existence.','nullable-alpha-2'],
            'corp_contact_name'=>['Reviewed corporate contact name; changing it grants no application or portal access.','nullable-text-max-200'],
            'corp_contact_email'=>['Validated corporate contact email; not provider identity or a worker login address.','nullable-email-max-255'],
            'corp_contact_phone'=>['Corporate contact phone retained as reviewed text.','nullable-text-max-40'],
            'msa_storage_object_id'=>['Private MSA object validated against an active document on this exact placement and directory.','restricted-reference:storage_object'],
            'coi_storage_object_id'=>['Private insurance evidence object validated against this exact placement and directory.','restricted-reference:storage_object'],
            'w9_storage_object_id'=>['Private W-9 object validated against this exact placement and directory; not permission for blanket provider document replication.','restricted-reference:storage_object'],
            'coi_expiry'=>['Recorded insurance expiry date; does not independently establish verified coverage or compliance approval.','nullable-date'],
            'corp_name'=>['Retained legacy corporate-name alias; the native editor uses corp_legal_name and does not treat this alias as a second authoritative payee.','legacy-text'],
        ],
        'placement_economic_parties'=>[
            'placement_id'=>$common['placement_id'],
            'source_id'=>['Local source rule or placement identity underlying this party. It is not the original provider ID or permission to rebind the party.','nullable-reference:commercial_source'],
            'source_external_id'=>['Original connector assignment reference retained with source_system; never interchangeable with Core source_id.','nullable-source-reference'],
            'source_managed'=>['Projection ownership marker. Native participant edits clear source management; reviewed handback can release ownership without changing current values.','boolean'],
            'currency'=>['Commission denomination projected from the reviewed source rule. Null stays unknown; no currency conversion occurs.','nullable-commission-currency'],
            'payment_frequency'=>['Commission frequency retained by economic projection and approved snapshots; not the operating-cycle cadence.','nullable-one_time|per_pay_period'],
            'due_month'=>['One-time commission due month retained by projection and approved snapshots; not an exact payment date.','nullable-YYYY-MM'],
        ],
    ];
}

function staffingCommercialFieldRules(string $table,string $field,array $rules): array
{
    $definitions=staffingCommercialFieldDefinitions();
    if (!isset($definitions[$table][$field])) return $rules;
    [$definition,$unit]=$definitions[$table][$field];
    // Ownership review must not rewrite an existing reviewed field definition
    // or its established unit identifier merely to add destination decisions.
    if (($rules['definition_status']??null)==='reviewed_core_definition') {
        $definition=$rules['definition'];$unit=$rules['unit'];
    }
    $kind=['placement_client_chain'=>'chain','placement_referrals'=>'referral','placement_commissions'=>'commission'][$table]??null;
    $restricted=str_starts_with($unit,'restricted-')||str_starts_with($unit,'encrypted-')||$unit==='private-text'||$rules['disposition']==='restricted';
    $internal=$restricted||in_array($field,['id','tenant_id','placement_id','created_at','updated_at','vendor_portal_id','plan_id','corp_name','source_managed','source_id','source_external_id'],true);
    foreach ($rules['remote_contracts'] as $provider=>&$remote) {
        if (!empty($remote['mappings'])) continue; // Retain concrete implemented adapter paths.
        $remote=['path'=>null,'direction'=>null,'status'=>'core_internal_only','sync_enabled_by_dictionary'=>false,
            'reason'=>'Native commercial configuration or evidence; provider exchange uses separately reviewed identity, assignment, time and financial contracts.'];
        if ($provider==='jobdiva'&&!$internal) $remote=array_replace($remote,[
            'direction'=>'jobdiva_to_core','status'=>'source_review_required',
            'reason'=>'The saved-source projector and configured mappings require exact tenant/source-field review. Native edits protect the accepted rule set or corporate identity; no outbound JobDiva write is inferred.',
        ]);
        if (in_array($provider,['purepay','purepayroll'],true)&&in_array($table,['placement_commissions','placement_economic_parties'],true)&&!$internal) $remote=array_replace($remote,[
            'status'=>'unsupported_with_gap',
            'reason'=>'No raw commission-rule receiver is implemented. Native Core USD earnings and held provider staffing inputs are separate workflows; foreign-currency commission payout remains unsupported.',
        ]);
    }
    unset($remote);
    $source=$kind?'modules/placements/lib/source_rule_edit.php':($table==='placement_corp_details'?'modules/placements/lib/corp_details.php':'modules/placements/lib/economics.php');
    $route=$kind?'/modules/placements/api/'.['chain'=>'chain','referral'=>'referrals','commission'=>'commissions'][$kind].'.php':($table==='placement_corp_details'?'/modules/placements/api/corp.php':'/modules/placements/api/economics.php');
    $evidence=[['source'=>$source,'route'=>$route],['source'=>'modules/placements/lib/commercial_ownership.php']];
    if ($kind) $evidence[]=['source'=>'modules/placements/lib/source_rule_review.php','route'=>'/modules/placements/api/source_rules.php'];
    if ($restricted&&$table==='placement_client_chain') $evidence[]=['source'=>'modules/placements/lib/placements.php'];
    $policy=$kind?'authorized_native_rule_edit; atomic_rule_set_ownership_and_audit; saved_source_replacement_requires_review':($table==='placement_corp_details'?'authorized_native_corporate_review; stale_review_rejected; identity_private_records_and_graph_updated_atomically':'native_economic_projection_or_reviewed_participant_edit; preserve_source_identity_and_consumed_terms');
    if (in_array($field,['id','tenant_id','placement_id','created_at','updated_at','source_id','source_external_id','source_managed'],true)) $policy='system_maintained_by_authorized_parent_or_projection_workflow; no_generic_caller_assignment';
    if ($table==='placement_client_chain'&&in_array($field,['portal_credentials_ct','kms_key_version'],true)) $policy='dedicated_authorized_portal_credential_workflow_only; no_generic_rule_input_or_remote_copy';
    if ($table==='placement_corp_details'&&in_array($field,['corp_ein_ct','corp_ein_last4','kms_key_version'],true)) $policy='validated_private_EIN_input_derives_ciphertext_and_suffix; no_caller_ciphertext_suffix_or_key_version';
    if ($field==='plan_id') $policy='retain_legacy_plan_for_review; new_nonempty_plan_binding_refused';
    if ($table==='placement_corp_details'&&$field==='corp_name') $policy='preserve_legacy_alias; native_corporate_editor_uses_corp_legal_name';
    return array_replace($rules,[
        'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition',
        'owner'=>'core_placement_contract','owner_directory_module'=>'placements',
        'ownership_status'=>'source_backed_native_workflow','ownership_evidence'=>$evidence,
        'disposition'=>$restricted?'restricted':(in_array($field,['id','tenant_id','created_at','updated_at','source_managed'],true)?'system_owned':($field==='source_external_id'?'source_reference':'specialized_workflow')),
        'write_policy'=>$policy,
        'effective_date_policy'=>'preserve_rule_effective_dates_due_month_and_approved_snapshots; later_edits_do_not_rewrite_consumed_obligations',
        'exchange_boundary'=>'This decision identifies the existing Core owner and limits. It grants no sync, portal access, payroll execution or payment permission; unsupported receivers remain explicit gaps.',
    ]);
}
