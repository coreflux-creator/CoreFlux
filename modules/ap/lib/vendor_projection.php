<?php
/** Public vendor detail shape. Private values require their separate permission and reveal path. */
declare(strict_types=1);

function apVendorPublicRecord(array $row): array
{
    return array_intersect_key($row, array_flip([
        'id','tenant_id','vendor_number','vendor_name','company_id','company_number','company_name','company_legal_name',
        'contact_email','external_id','source_system','vendor_type','vendor_category','payment_method',
        'remit_to_email','remit_to_phone','payment_account_last4','payment_routing_last4','payment_account_type',
        'default_terms','default_pwp','tax_id_last4','requires_1099','last_bill_at','placement_id_last','created_at','updated_at',
    ]));
}
