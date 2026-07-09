<?php
/**
 * Placements module — CSV export.
 *
 *   GET /api/placements/csv_export → streams CSV of placements in tenant.
 *
 * Optional filters:
 *   ?status=draft|active|ended|cancelled
 *   ?engagement_type=w2|1099|c2c|temp_to_perm|direct_hire
 *
 * Built on Core\CsvExportService primitive per HARD_RULES (2026-02-XX).
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';

use Core\CsvExportService;

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'placements.view');

$where  = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
$params = [];
if (!empty($_GET['status']))          { $where[] = 'p.status = :s';           $params['s']  = $_GET['status']; }
if (!empty($_GET['engagement_type'])) { $where[] = 'p.engagement_type = :et'; $params['et'] = $_GET['engagement_type']; }

$rows = scopedQuery(
    'SELECT p.id AS placement_id,
            p.person_id,
            pe.email_primary AS person_email,
            CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
            p.title, p.engagement_type, p.status,
            p.start_date, p.end_date, p.actual_end_date, p.due_date,
            p.end_client_company_id,
            p.end_client_name, p.worksite_state, p.worksite_country, p.remote_policy,
            p.client_approver_name, p.client_approver_email,
            p.jobdiva_job_id, p.recruiter_name, p.recruiter_email,
            p.account_manager_name, p.account_manager_email,
            p.client_bill_cycle, p.client_bill_cycle_anchor,
            p.vendor_pay_cycle, p.vendor_pay_cycle_anchor,
            r.effective_from AS rate_effective_from,
            r.effective_to AS rate_effective_to,
            r.bill_rate, r.bill_rate_unit,
            r.pay_rate, r.pay_rate_unit,
            r.currency, r.ot_multiplier, r.dt_multiplier,
            r.adder_pct, r.background_fee_total,
            (SELECT c.party_name FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_name,
            (SELECT c.portal_fee_pct FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_fee_pct,
            (SELECT c.portal_fee_flat FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_fee_flat,
            (SELECT c.submittal_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_submittal_id,
            (SELECT c.vms_job_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'msp\' ORDER BY c.position LIMIT 1) AS msp_vms_job_id,
            (SELECT c.party_name FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_name,
            (SELECT c.portal_fee_pct FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_fee_pct,
            (SELECT c.portal_fee_flat FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_fee_flat,
            (SELECT c.submittal_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_submittal_id,
            (SELECT c.vms_job_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'prime_vendor\' ORDER BY c.position LIMIT 1) AS prime_vendor_vms_job_id,
            (SELECT c.party_name FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_name,
            (SELECT c.portal_fee_pct FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_fee_pct,
            (SELECT c.portal_fee_flat FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_fee_flat,
            (SELECT c.submittal_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_submittal_id,
            (SELECT c.vms_job_id FROM placement_client_chain c WHERE c.tenant_id = p.tenant_id AND c.placement_id = p.id AND c.party_role = \'sub_vendor\' ORDER BY c.position LIMIT 1) AS sub_vendor_vms_job_id,
            p.external_id, p.notes
       FROM placements p
       LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
       LEFT JOIN placement_rates r
              ON r.id = (
                    SELECT rr.id
                      FROM placement_rates rr
                     WHERE rr.tenant_id = p.tenant_id
                       AND rr.placement_id = p.id
                     ORDER BY (rr.approved_at IS NOT NULL) DESC, rr.effective_from DESC, rr.id DESC
                     LIMIT 1
                 )
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY p.start_date DESC, p.id DESC',
    $params
);

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
    'client_approver_name'  => 'Client approver name',
    'client_approver_email' => 'Client approver email',
    'jobdiva_job_id'        => 'JobDiva job ID',
    'recruiter_name'        => 'Recruiter name',
    'recruiter_email'       => 'Recruiter email',
    'account_manager_name'  => 'Account manager name',
    'account_manager_email' => 'Account manager email',
    'client_bill_cycle'     => 'Client bill cycle',
    'client_bill_cycle_anchor' => 'Client bill cycle anchor',
    'vendor_pay_cycle'      => 'Vendor pay cycle',
    'vendor_pay_cycle_anchor' => 'Vendor pay cycle anchor',
    'rate_effective_from'   => 'Rate effective from',
    'rate_effective_to'     => 'Rate effective to',
    'bill_rate'         => 'Bill rate ($/hr)',
    'bill_rate_unit'    => 'Bill rate unit',
    'pay_rate'          => 'Pay rate ($/hr)',
    'pay_rate_unit'     => 'Pay rate unit',
    'currency'          => 'Currency',
    'ot_multiplier'     => 'OT multiplier',
    'dt_multiplier'     => 'DT multiplier',
    'adder_pct'         => 'Adder %',
    'background_fee_total' => 'Background fee total',
    'msp_name'          => 'MSP name',
    'msp_fee_pct'       => 'MSP / discount fee %',
    'msp_fee_flat'      => 'MSP / discount fee flat',
    'msp_submittal_id'  => 'MSP submittal ID',
    'msp_vms_job_id'    => 'MSP VMS job ID',
    'prime_vendor_name' => 'Prime vendor name',
    'prime_vendor_fee_pct' => 'Prime vendor fee %',
    'prime_vendor_fee_flat' => 'Prime vendor fee flat',
    'prime_vendor_submittal_id' => 'Prime vendor submittal ID',
    'prime_vendor_vms_job_id' => 'Prime vendor VMS job ID',
    'sub_vendor_name'   => 'Sub-vendor name',
    'sub_vendor_fee_pct' => 'Sub-vendor fee %',
    'sub_vendor_fee_flat' => 'Sub-vendor fee flat',
    'sub_vendor_submittal_id' => 'Sub-vendor submittal ID',
    'sub_vendor_vms_job_id' => 'Sub-vendor VMS job ID',
    'external_id'       => 'External ID',
    'notes'             => 'Notes',
]))->stream($rows, 'placements_export_' . date('Y-m-d') . '.csv');
