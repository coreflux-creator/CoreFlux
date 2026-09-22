<?php
/**
 * Placements module — CSV export.
 *
 *   GET /api/placements/csv_export → streams CSV of placements in tenant.
 *
 * Optional filters:
 *   ?status=draft|active|ended|cancelled
 *   ?engagement_type=w2|1099|c2c|temp_to_perm|direct_hire|internal|referral
 *   ?end_client_company_id=N
 *   ?q=person|title|client|placement-id
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../../../core/export_service.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$userId = (int) ($user['id'] ?? 0);
rbac_legacy_require($user, 'placements.view');

$datasetOptions = [
    'status'                => (string) ($_GET['status'] ?? ''),
    'engagement_type'       => (string) ($_GET['engagement_type'] ?? ''),
    'end_client_company_id' => (int) ($_GET['end_client_company_id'] ?? 0),
    'q'                     => trim((string) ($_GET['q'] ?? '')),
];

$rawMode = in_array(strtolower((string) ($_GET['raw'] ?? '')), ['1', 'true', 'yes'], true);
$templateId = (int) ($_GET['template_id'] ?? 0);
if (!$rawMode && $templateId <= 0) {
    $defaultTemplate = exportTemplateDefault($tenantId, 'placements_directory');
    $templateId = (int) ($defaultTemplate['id'] ?? 0);
}
if (!$rawMode && $templateId > 0) {
    try {
        exportTemplateStreamDatasetCsv(
            $tenantId,
            'placements_directory',
            $templateId,
            $datasetOptions,
            'placements',
            $userId ?: null,
            null,
            ['filename_parts' => [date('Y-m-d')]]
        );
        exit;
    } catch (ExportServiceException $e) {
        api_error($e->getMessage(), 422);
    }
}

$where  = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
$params = [];
if ($datasetOptions['status'] !== '')          { $where[] = 'p.status = :s';           $params['s']  = $datasetOptions['status']; }
if ($datasetOptions['engagement_type'] !== '') { $where[] = 'p.engagement_type = :et'; $params['et'] = $datasetOptions['engagement_type']; }
if ($datasetOptions['end_client_company_id'] > 0) {
    $where[] = 'p.end_client_company_id = :client_id';
    $params['client_id'] = $datasetOptions['end_client_company_id'];
}
if ($datasetOptions['q'] !== '') {
    $needle = '%' . $datasetOptions['q'] . '%';
    $where[] = '(p.title LIKE :q_title OR p.end_client_name LIKE :q_client
                 OR pe.first_name LIKE :q_first OR pe.last_name LIKE :q_last
                 OR p.external_id LIKE :q_external OR CAST(p.id AS CHAR) LIKE :q_id)';
    foreach (['q_title','q_client','q_first','q_last','q_external','q_id'] as $key) $params[$key] = $needle;
}
$params['people_tenant_id'] = effectiveTenantIdForModule('people', (int) ($ctx['tenant_id'] ?? currentTenantId())) ?? currentTenantId();

$rows = scopedQuery(
    'SELECT p.id AS placement_id,
            p.person_id,
            pe.email_primary AS person_email,
            CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
            p.title, p.engagement_type, p.status,
            p.start_date, p.end_date, p.actual_end_date, p.due_date,
            p.end_client_company_id,
            p.end_client_name, p.worksite_state, p.worksite_country, p.remote_policy,
            p.staffing_job_id, p.branch, p.service_line, p.workers_comp_class,
            p.department, p.cost_center, p.accounting_entity_id,
            p.client_approver_name, p.client_approver_email,
            p.jobdiva_job_id, p.recruiter_name, p.recruiter_email,
            p.account_manager_name, p.account_manager_email,
            p.client_bill_cycle, p.client_bill_cycle_anchor, p.client_payment_terms_override,
            p.vendor_pay_cycle, p.vendor_pay_cycle_anchor,
            p.vendor_payment_terms_override, p.vendor_pwp_enabled,
            r.effective_from AS rate_effective_from,
            r.effective_to AS rate_effective_to,
            r.bill_rate, r.bill_rate_unit,
            r.pay_rate, r.pay_rate_unit,
            CASE WHEN p.engagement_type = "referral" THEN r.bill_rate ELSE NULL END AS referral_client_rate,
            (SELECT pr.referrer_vendor_name FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_vendor_name,
            (SELECT pr.referrer_company_id FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_vendor_company_id,
            (SELECT pr.fee_flat FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_payout_rate,
            (SELECT pr.payment_terms_override FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_payment_terms,
            (SELECT pr.pwp_enabled FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_paid_when_paid,
            (SELECT pr.start_date FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_start_date,
            (SELECT pr.end_date FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_end_date,
            (SELECT pr.notes FROM placement_referrals pr WHERE pr.tenant_id = p.tenant_id AND pr.placement_id = p.id AND pr.referrer_type = "vendor" AND pr.fee_basis = "per_hour" ORDER BY pr.id LIMIT 1) AS referral_notes,
            r.currency, r.ot_multiplier, r.dt_multiplier,
            r.adder_pct, r.background_fee_total,
            r.bill_adder_pct, r.bill_adder_flat,
            r.bill_discount_pct, r.bill_discount_flat,
            r.workers_comp_pct, r.benefits_load_pct, r.c2c_overhead_pct,
            r.other_cost_per_hour, r.other_cost_flat,
            (SELECT c.party_name FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_name,
            (SELECT c.portal_fee_pct FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_fee_pct,
            (SELECT c.portal_fee_flat FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_fee_flat,
            (SELECT c.submittal_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_submittal_id,
            (SELECT c.vms_job_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_vms_job_id,
            (SELECT c.payment_terms_override FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_payment_terms,
            (SELECT c.pwp_enabled FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_paid_when_paid,
            (SELECT c.is_payable FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_is_payable,
            (SELECT c.party_name FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_name,
            (SELECT c.portal_fee_pct FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_fee_pct,
            (SELECT c.portal_fee_flat FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_fee_flat,
            (SELECT c.submittal_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_submittal_id,
            (SELECT c.vms_job_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_vms_job_id,
            (SELECT c.payment_terms_override FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_payment_terms,
            (SELECT c.pwp_enabled FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_paid_when_paid,
            (SELECT c.is_payable FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_is_payable,
            (SELECT c.party_name FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_name,
            (SELECT c.portal_fee_pct FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_fee_pct,
            (SELECT c.portal_fee_flat FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_fee_flat,
            (SELECT c.submittal_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_submittal_id,
            (SELECT c.vms_job_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_vms_job_id,
            (SELECT c.payment_terms_override FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_payment_terms,
            (SELECT c.pwp_enabled FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_paid_when_paid,
            (SELECT c.is_payable FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_is_payable,
            (SELECT pc.split_pct FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'recruiter\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS recruiter_commission_pct,
            (SELECT pc.flat_amount FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'recruiter\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS recruiter_commission_flat,
            (SELECT pc.basis FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'recruiter\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS recruiter_commission_basis,
            (SELECT pc.effective_from FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'recruiter\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS recruiter_commission_effective_from,
            (SELECT pc.effective_to FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'recruiter\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS recruiter_commission_effective_to,
            (SELECT pc.notes FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'recruiter\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS recruiter_commission_notes,
            (SELECT pc.split_pct FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'account_manager\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS account_manager_commission_pct,
            (SELECT pc.flat_amount FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'account_manager\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS account_manager_commission_flat,
            (SELECT pc.basis FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'account_manager\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS account_manager_commission_basis,
            (SELECT pc.effective_from FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'account_manager\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS account_manager_commission_effective_from,
            (SELECT pc.effective_to FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'account_manager\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS account_manager_commission_effective_to,
            (SELECT pc.notes FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'account_manager\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS account_manager_commission_notes,
            (SELECT pc.split_pct FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'lead\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS lead_commission_pct,
            (SELECT pc.flat_amount FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'lead\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS lead_commission_flat,
            (SELECT pc.basis FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'lead\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS lead_commission_basis,
            (SELECT pc.effective_from FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'lead\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS lead_commission_effective_from,
            (SELECT pc.effective_to FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'lead\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS lead_commission_effective_to,
            (SELECT pc.notes FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'lead\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS lead_commission_notes,
            (SELECT pc.split_pct FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'team\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS team_commission_pct,
            (SELECT pc.flat_amount FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'team\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS team_commission_flat,
            (SELECT pc.basis FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'team\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS team_commission_basis,
            (SELECT pc.effective_from FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'team\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS team_commission_effective_from,
            (SELECT pc.effective_to FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'team\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS team_commission_effective_to,
            (SELECT pc.notes FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'team\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS team_commission_notes,
            (SELECT pc.split_pct FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'other\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS other_commission_pct,
            (SELECT pc.flat_amount FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'other\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS other_commission_flat,
            (SELECT pc.basis FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'other\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS other_commission_basis,
            (SELECT pc.effective_from FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'other\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS other_commission_effective_from,
            (SELECT pc.effective_to FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'other\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS other_commission_effective_to,
            (SELECT pc.notes FROM placement_commissions pc WHERE pc.tenant_id = p.tenant_id AND pc.placement_id = p.id AND pc.role = \'other\' ORDER BY pc.effective_from DESC, pc.id DESC LIMIT 1) AS other_commission_notes,
            pcd.corp_legal_name, pcd.corp_ein_last4,
            pcd.corp_address_line1, pcd.corp_address_line2, pcd.corp_city,
            pcd.corp_state, pcd.corp_postal_code, pcd.corp_country,
            pcd.corp_contact_name, pcd.corp_contact_email, pcd.corp_contact_phone,
            pcd.coi_expiry, pcd.payment_terms_override AS corp_payment_terms,
            pcd.pwp_enabled AS corp_paid_when_paid,
            p.external_id, p.notes
       FROM placements p
       LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :people_tenant_id
       LEFT JOIN placement_rates r
              ON r.id = (
                    SELECT rr.id
                      FROM placement_rates rr
                     WHERE rr.tenant_id = p.tenant_id
                       AND rr.placement_id = p.id
                     ORDER BY (rr.approved_at IS NOT NULL) DESC, rr.effective_from DESC, rr.id DESC
                     LIMIT 1
                 )
       LEFT JOIN placement_corp_details pcd
              ON pcd.tenant_id = p.tenant_id
             AND pcd.placement_id = p.id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY p.start_date DESC, p.id DESC',
    $params
);

exportDatasetAudit($tenantId, $userId ?: null, 'placement.exported', null, exportDatasetAuditMeta([
    'dataset' => 'placements_directory',
    'format' => 'csv',
    'mode' => 'raw',
    'rows' => count($rows),
], $datasetOptions));

(new CsvExportService([
    'placement_id'      => 'Placement ID',
    'person_id'         => 'Person ID',
    'person_email'      => 'Person email',
    'person_name'       => 'Person name',
    'title'             => 'Title',
    'engagement_type'   => 'Engagement type',
    'status'            => 'Status',
    'start_date'        => 'Start date',
    'end_date'          => 'End date',
    'actual_end_date'   => 'Actual end date',
    'due_date'          => 'Due date',
    'end_client_company_id' => 'End client company ID',
    'end_client_name'   => 'End client name',
    'worksite_state'    => 'Worksite state',
    'worksite_country'  => 'Worksite country',
    'remote_policy'     => 'Remote policy',
    'staffing_job_id'   => 'CoreFlux job ID',
    'branch'            => 'Branch / business unit',
    'service_line'      => 'Service line',
    'workers_comp_class'=> 'WC class',
    'department'        => 'Department',
    'cost_center'       => 'Cost center',
    'accounting_entity_id' => 'Legal entity ID',
    'client_approver_name'  => 'Client approver name',
    'client_approver_email' => 'Client approver email',
    'jobdiva_job_id'        => 'JobDiva job ID',
    'recruiter_name'        => 'Recruiter name',
    'recruiter_email'       => 'Recruiter email',
    'account_manager_name'  => 'Account manager name',
    'account_manager_email' => 'Account manager email',
    'client_bill_cycle'     => 'Client bill cycle',
    'client_bill_cycle_anchor' => 'Client bill cycle anchor',
    'client_payment_terms_override' => 'Client payment terms',
    'vendor_pay_cycle'      => 'Vendor pay cycle',
    'vendor_pay_cycle_anchor' => 'Vendor pay cycle anchor',
    'vendor_payment_terms_override' => 'Primary vendor payment terms',
    'vendor_pwp_enabled'    => 'Primary vendor paid when paid',
    'rate_effective_from'   => 'Rate effective from',
    'rate_effective_to'     => 'Rate effective to',
    'bill_rate'         => 'Bill rate ($/hr)',
    'bill_rate_unit'    => 'Bill rate unit',
    'pay_rate'          => 'Pay rate ($/hr)',
    'pay_rate_unit'     => 'Pay rate unit',
    'referral_client_rate' => 'Hourly referral fee paid by client',
    'referral_vendor_name' => 'Referral vendor',
    'referral_vendor_company_id' => 'Referral vendor company ID',
    'referral_payout_rate' => 'Vendor referral payout',
    'referral_payment_terms' => 'Referral vendor payment terms',
    'referral_paid_when_paid' => 'Referral vendor paid when paid',
    'referral_start_date' => 'Referral payout start date',
    'referral_end_date' => 'Referral payout end date',
    'referral_notes' => 'Referral notes',
    'currency'          => 'Currency',
    'ot_multiplier'     => 'OT multiplier',
    'dt_multiplier'     => 'DT multiplier',
    'adder_pct'         => 'Adder %',
    'background_fee_total' => 'Background fee total',
    'bill_adder_pct'    => 'Client bill adder %',
    'bill_adder_flat'   => 'Client bill adder per unit',
    'bill_discount_pct' => 'Client discount %',
    'bill_discount_flat'=> 'Client discount per unit',
    'workers_comp_pct'  => 'Workers comp %',
    'benefits_load_pct' => 'Benefits load %',
    'c2c_overhead_pct'  => 'C2C overhead %',
    'other_cost_per_hour' => 'Other recurring cost per hour',
    'other_cost_flat'   => 'Other fixed cost',
    'msp_name'          => 'MSP name',
    'msp_fee_pct'       => 'MSP / discount fee %',
    'msp_fee_flat'      => 'MSP / discount fee flat',
    'msp_submittal_id'  => 'MSP submittal ID',
    'msp_vms_job_id'    => 'MSP VMS job ID',
    'msp_payment_terms'  => 'MSP payment terms',
    'msp_paid_when_paid' => 'MSP paid when paid',
    'msp_is_payable'     => 'MSP is payable',
    'prime_vendor_name' => 'Prime vendor name',
    'prime_vendor_fee_pct' => 'Prime vendor fee %',
    'prime_vendor_fee_flat' => 'Prime vendor fee flat',
    'prime_vendor_submittal_id' => 'Prime vendor submittal ID',
    'prime_vendor_vms_job_id' => 'Prime vendor VMS job ID',
    'prime_vendor_payment_terms' => 'Prime vendor payment terms',
    'prime_vendor_paid_when_paid' => 'Prime vendor paid when paid',
    'prime_vendor_is_payable' => 'Prime vendor is payable',
    'sub_vendor_name'   => 'Sub-vendor name',
    'sub_vendor_fee_pct' => 'Sub-vendor fee %',
    'sub_vendor_fee_flat' => 'Sub-vendor fee flat',
    'sub_vendor_submittal_id' => 'Sub-vendor submittal ID',
    'sub_vendor_vms_job_id' => 'Sub-vendor VMS job ID',
    'sub_vendor_payment_terms' => 'Sub-vendor payment terms',
    'sub_vendor_paid_when_paid' => 'Sub-vendor paid when paid',
    'sub_vendor_is_payable' => 'Sub-vendor is payable',
    'recruiter_commission_pct' => 'Recruiter commission %',
    'recruiter_commission_flat' => 'Recruiter commission flat',
    'recruiter_commission_basis' => 'Recruiter commission basis',
    'recruiter_commission_effective_from' => 'Recruiter commission effective from',
    'recruiter_commission_effective_to' => 'Recruiter commission effective to',
    'recruiter_commission_notes' => 'Recruiter commission notes',
    'account_manager_commission_pct' => 'Account manager commission %',
    'account_manager_commission_flat' => 'Account manager commission flat',
    'account_manager_commission_basis' => 'Account manager commission basis',
    'account_manager_commission_effective_from' => 'Account manager commission effective from',
    'account_manager_commission_effective_to' => 'Account manager commission effective to',
    'account_manager_commission_notes' => 'Account manager commission notes',
    'lead_commission_pct' => 'Lead commission %',
    'lead_commission_flat' => 'Lead commission flat',
    'lead_commission_basis' => 'Lead commission basis',
    'lead_commission_effective_from' => 'Lead commission effective from',
    'lead_commission_effective_to' => 'Lead commission effective to',
    'lead_commission_notes' => 'Lead commission notes',
    'team_commission_pct' => 'Team commission %',
    'team_commission_flat' => 'Team commission flat',
    'team_commission_basis' => 'Team commission basis',
    'team_commission_effective_from' => 'Team commission effective from',
    'team_commission_effective_to' => 'Team commission effective to',
    'team_commission_notes' => 'Team commission notes',
    'other_commission_pct' => 'Other commission %',
    'other_commission_flat' => 'Other commission flat',
    'other_commission_basis' => 'Other commission basis',
    'other_commission_effective_from' => 'Other commission effective from',
    'other_commission_effective_to' => 'Other commission effective to',
    'other_commission_notes' => 'Other commission notes',
    'corp_legal_name' => 'C2C corp legal name',
    'corp_ein_last4' => 'C2C corp EIN last 4',
    'corp_address_line1' => 'C2C corp address line 1',
    'corp_address_line2' => 'C2C corp address line 2',
    'corp_city' => 'C2C corp city',
    'corp_state' => 'C2C corp state',
    'corp_postal_code' => 'C2C corp postal code',
    'corp_country' => 'C2C corp country',
    'corp_contact_name' => 'C2C corp contact name',
    'corp_contact_email' => 'C2C corp contact email',
    'corp_contact_phone' => 'C2C corp contact phone',
    'coi_expiry' => 'COI expiry',
    'corp_payment_terms' => 'C2C corp payment terms',
    'corp_paid_when_paid' => 'C2C corp paid when paid',
    'external_id'       => 'External ID',
    'notes'             => 'Notes',
]))->stream($rows, 'placements_export_' . date('Y-m-d') . '.csv');
