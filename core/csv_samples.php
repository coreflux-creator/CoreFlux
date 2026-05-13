<?php
/**
 * CoreFlux — CSV sample row library.
 *
 * Ship realistic onboarding data so new tenants can click "Download sample"
 * on the Bulk CSV Import wizard, get a coherent 5-row dataset per entity,
 * and load it to see the platform fully populated in 30 seconds — BEFORE
 * importing their real books.
 *
 * Every entry uses field KEYS (not display labels) — CsvImportService::
 * buildSample() looks up the label from the registered schema.
 *
 * Data is fictional and FK-coherent across entities so reruns are safe:
 *   - vendor_name in bills.csv matches a row in vendors.csv
 *   - client_name in invoices.csv matches a row in clients.csv
 *   - person_email in placements.csv matches a row in people.csv
 *   - placement_external_id in time.csv matches a row in placements.csv
 */

return [
    'people' => [
        ['first_name'=>'Asha','last_name'=>'Patel','email_primary'=>'asha.patel@example.com',
         'phone_primary'=>'212-555-0110','classification'=>'w2','status'=>'active','work_auth_status'=>'citizen',
         'requires_sponsorship'=>0,'source'=>'LinkedIn','external_id'=>'EMP-1001'],
        ['first_name'=>'Diego','last_name'=>'Ramirez','email_primary'=>'diego.ramirez@example.com',
         'phone_primary'=>'415-555-0111','classification'=>'1099','status'=>'active','work_auth_status'=>'green_card',
         'requires_sponsorship'=>0,'source'=>'Referral','external_id'=>'EMP-1002'],
        ['first_name'=>'Mei','last_name'=>'Tanaka','email_primary'=>'mei.tanaka@example.com',
         'phone_primary'=>'408-555-0112','classification'=>'c2c','status'=>'active','work_auth_status'=>'h1b',
         'work_auth_expiry'=>'2027-06-30','requires_sponsorship'=>1,'source'=>'Job board','external_id'=>'EMP-1003'],
        ['first_name'=>'James','last_name'=>'O\'Connor','email_primary'=>'james.oconnor@example.com',
         'phone_primary'=>'617-555-0113','classification'=>'w2','status'=>'bench','work_auth_status'=>'citizen',
         'requires_sponsorship'=>0,'external_id'=>'EMP-1004'],
        ['first_name'=>'Priya','last_name'=>'Singh','email_primary'=>'priya.singh@example.com',
         'phone_primary'=>'512-555-0114','classification'=>'perm','status'=>'active','work_auth_status'=>'opt',
         'work_auth_expiry'=>'2026-12-31','requires_sponsorship'=>1,'external_id'=>'EMP-1005'],
    ],

    'ap_vendors' => [
        ['vendor_name'=>'Northwind Cloud Services','vendor_type'=>'c2c_corp','vendor_category'=>'service_provider',
         'default_terms'=>'NET30','remit_to_email'=>'ar@northwindcloud.example','payment_method'=>'ach',
         'tax_id_last4'=>'4321','requires_1099'=>0],
        ['vendor_name'=>'Diego Ramirez (1099)','vendor_type'=>'1099_individual','vendor_category'=>'hourly_labor',
         'default_terms'=>'NET15','remit_to_email'=>'diego.ramirez@example.com','payment_method'=>'ach',
         'tax_id_last4'=>'1099','requires_1099'=>1],
        ['vendor_name'=>'Tanaka Consulting LLC','vendor_type'=>'c2c_corp','vendor_category'=>'hourly_labor',
         'default_terms'=>'NET30','remit_to_email'=>'billing@tanakaconsulting.example','payment_method'=>'wire',
         'tax_id_last4'=>'5544','requires_1099'=>0],
        ['vendor_name'=>'PG&E','vendor_type'=>'utility','vendor_category'=>'service_provider',
         'default_terms'=>'NET21','payment_method'=>'ach','requires_1099'=>0],
        ['vendor_name'=>'Iron Office Supplies','vendor_type'=>'w9_business','vendor_category'=>'service_provider',
         'default_terms'=>'NET30','remit_to_email'=>'ap@ironoffice.example','payment_method'=>'check',
         'requires_1099'=>0],
    ],

    'staffing_clients' => [
        ['name'=>'Globex Industries','legal_name'=>'Globex Industries, Inc.','industry'=>'Manufacturing',
         'primary_contact_name'=>'Sandra Lee','primary_contact_email'=>'sandra.lee@globex.example',
         'primary_contact_phone'=>'212-555-0210','billing_city'=>'New York','billing_state'=>'NY','billing_country'=>'US',
         'payment_terms_days'=>30,'status'=>'active'],
        ['name'=>'Initech','legal_name'=>'Initech Software Inc','industry'=>'Software',
         'primary_contact_name'=>'Bill Lumbergh','primary_contact_email'=>'bill.lumbergh@initech.example',
         'primary_contact_phone'=>'512-555-0220','billing_city'=>'Austin','billing_state'=>'TX','billing_country'=>'US',
         'payment_terms_days'=>45,'status'=>'active'],
        ['name'=>'Wayne Enterprises','industry'=>'Defense',
         'primary_contact_name'=>'Lucius Fox','primary_contact_email'=>'lucius.fox@wayne.example',
         'billing_city'=>'Gotham','billing_state'=>'NJ','billing_country'=>'US',
         'payment_terms_days'=>60,'status'=>'active'],
        ['name'=>'Stark Industries','industry'=>'Aerospace',
         'primary_contact_name'=>'Pepper Potts','primary_contact_email'=>'pepper.potts@stark.example',
         'billing_city'=>'Malibu','billing_state'=>'CA','billing_country'=>'US',
         'payment_terms_days'=>30,'status'=>'prospect'],
        ['name'=>'Acme Logistics','industry'=>'Logistics',
         'primary_contact_email'=>'ap@acmelogistics.example',
         'billing_city'=>'Chicago','billing_state'=>'IL','billing_country'=>'US',
         'payment_terms_days'=>30,'status'=>'active'],
    ],

    'placements' => [
        ['person_email'=>'asha.patel@example.com','title'=>'Senior Frontend Engineer','engagement_type'=>'w2',
         'start_date'=>'2026-01-06','end_client_name'=>'Globex Industries','worksite_state'=>'NY',
         'worksite_country'=>'US','remote_policy'=>'hybrid','bill_rate'=>140,'pay_rate'=>95,'external_id'=>'PL-2001'],
        ['person_email'=>'diego.ramirez@example.com','title'=>'Mobile iOS Developer','engagement_type'=>'1099',
         'start_date'=>'2026-01-13','end_client_name'=>'Initech','worksite_state'=>'TX',
         'worksite_country'=>'US','remote_policy'=>'remote','bill_rate'=>130,'pay_rate'=>105,'external_id'=>'PL-2002'],
        ['person_email'=>'mei.tanaka@example.com','title'=>'DevOps Architect','engagement_type'=>'c2c',
         'start_date'=>'2026-02-02','due_date'=>'2026-08-02','end_client_name'=>'Wayne Enterprises','worksite_state'=>'NJ',
         'worksite_country'=>'US','remote_policy'=>'onsite','bill_rate'=>175,'pay_rate'=>140,'external_id'=>'PL-2003'],
        ['person_email'=>'priya.singh@example.com','title'=>'Backend Engineer (Direct Hire)','engagement_type'=>'direct_hire',
         'start_date'=>'2026-02-17','end_client_name'=>'Stark Industries','worksite_state'=>'CA',
         'worksite_country'=>'US','remote_policy'=>'hybrid','external_id'=>'PL-2004'],
        ['person_email'=>'james.oconnor@example.com','title'=>'Cloud SRE','engagement_type'=>'w2',
         'start_date'=>'2026-02-24','end_client_name'=>'Acme Logistics','worksite_state'=>'IL',
         'worksite_country'=>'US','remote_policy'=>'remote','bill_rate'=>120,'pay_rate'=>82,'external_id'=>'PL-2005'],
    ],

    'time' => [
        ['placement_external_id'=>'PL-2001','work_date'=>'2026-02-10','category'=>'regular_billable','hours'=>8,
         'description'=>'Sprint planning + dashboard wiring'],
        ['placement_external_id'=>'PL-2001','work_date'=>'2026-02-11','category'=>'regular_billable','hours'=>8,
         'description'=>'KPI tile component'],
        ['placement_external_id'=>'PL-2002','work_date'=>'2026-02-10','category'=>'regular_billable','hours'=>7.5,
         'description'=>'iOS push notification bug'],
        ['placement_external_id'=>'PL-2003','work_date'=>'2026-02-12','category'=>'OT_billable','hours'=>2,
         'description'=>'Production deploy hotfix'],
        ['placement_external_id'=>'PL-2005','work_date'=>'2026-02-12','category'=>'regular_billable','hours'=>8,
         'description'=>'Pager rotation tuning'],
    ],

    'ap_bills' => [
        // Bill #1 — 2 lines
        ['bill_number'=>'BILL-3001','vendor_name'=>'Northwind Cloud Services','vendor_type'=>'c2c_corp',
         'bill_date'=>'2026-02-01','due_date'=>'2026-03-03','currency'=>'USD',
         'line_no'=>1,'line_description'=>'AWS reserved compute (Feb)','line_quantity'=>1,'line_unit'=>'month',
         'line_unit_price'=>4800,'line_subtotal'=>4800,'line_tax_amount'=>0,'line_total'=>4800],
        ['bill_number'=>'BILL-3001','line_no'=>2,'line_description'=>'CloudFront data transfer',
         'line_quantity'=>1,'line_unit'=>'month','line_unit_price'=>320,'line_subtotal'=>320,
         'line_tax_amount'=>0,'line_total'=>320],
        // Bill #2 — 1 line, 1099 individual
        ['bill_number'=>'BILL-3002','vendor_name'=>'Diego Ramirez (1099)','vendor_type'=>'1099_individual',
         'bill_date'=>'2026-02-10','due_date'=>'2026-02-24','currency'=>'USD',
         'line_no'=>1,'line_description'=>'iOS contractor hours week ending 2026-02-08','line_quantity'=>38,
         'line_unit'=>'hour','line_unit_price'=>105,'line_subtotal'=>3990,'line_tax_amount'=>0,'line_total'=>3990],
    ],

    'billing_invoices' => [
        // Invoice #1 — 2 lines
        ['invoice_number'=>'INV-4001','client_name'=>'Globex Industries','currency'=>'USD',
         'issue_date'=>'2026-02-12','due_date'=>'2026-03-14','aggregation'=>'per_placement',
         'line_no'=>1,'line_description'=>'Asha Patel — Senior Frontend Engineer — 40 hrs',
         'line_quantity'=>40,'line_unit'=>'hour','line_unit_price'=>140,
         'line_subtotal'=>5600,'line_tax_amount'=>0,'line_total'=>5600],
        ['invoice_number'=>'INV-4001','line_no'=>2,'line_description'=>'Asha Patel — OT — 2 hrs',
         'line_quantity'=>2,'line_unit'=>'hour','line_unit_price'=>210,
         'line_subtotal'=>420,'line_tax_amount'=>0,'line_total'=>420],
        // Invoice #2 — 1 line
        ['invoice_number'=>'INV-4002','client_name'=>'Initech','currency'=>'USD',
         'issue_date'=>'2026-02-12','due_date'=>'2026-03-29','aggregation'=>'per_placement',
         'line_no'=>1,'line_description'=>'Diego Ramirez — Mobile iOS Developer — 37.5 hrs',
         'line_quantity'=>37.5,'line_unit'=>'hour','line_unit_price'=>130,
         'line_subtotal'=>4875,'line_tax_amount'=>0,'line_total'=>4875],
    ],
];
