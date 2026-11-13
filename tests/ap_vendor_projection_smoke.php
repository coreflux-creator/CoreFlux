<?php
declare(strict_types=1);
require_once __DIR__.'/../modules/ap/lib/vendor_projection.php';
$public=['id'=>7,'vendor_number'=>'V-7','company_number'=>'CO-12','vendor_name'=>'Synthetic Vendor',
    'payment_account_last4'=>'0000','payment_routing_last4'=>null,'tax_id_last4'=>'0123',
    'default_pwp'=>false,'default_terms'=>'NET30','requires_1099'=>0,'contact_email'=>''];
$private=['tax_id_full_ct'=>"\xffsecret",'payment_account_ct'=>"\xffbank",'payment_routing_ct'=>"\xffrouting",
    'kms_key_version'=>'private-version','kms_key_version_payment'=>'private-version',
    'default_remit_to_json'=>'{"unknown_secret":"hidden"}','tax_id_full'=>'111223333','future_private_field'=>'hidden'];
if(apVendorPublicRecord($public+$private)!==$public)throw new RuntimeException('Vendor projection must preserve permitted values exactly and exclude unreviewed/private fields.');
if(apVendorPublicRecord($private)!==[])throw new RuntimeException('Private-only source must reveal no fields.');
echo "Vendor projection: exact identity, masked fields, falsy values and unknown/private exclusion passed\n";
