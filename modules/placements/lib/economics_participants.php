<?php
/** Native participant creation/removal. API callers authorize placements.manage. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics.php';

function placementEconomicsNativeCreatePurpose(string $channel): string
{
    return $channel === 'ar' ? 'billing' : $channel;
}

function placementEconomicsNativeCreateCadence(int $tenantId, string $channel, mixed $raw): array
{
    $cadence = strtolower(trim((string) $raw));
    if (!in_array($cadence, ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) {
        throw new InvalidArgumentException('Frequency must be weekly, biweekly, semimonthly, monthly, or adhoc', 422);
    }
    $purpose = placementEconomicsNativeCreatePurpose($channel);
    if (!in_array($purpose, ['billing','ap','payroll'], true)) throw new InvalidArgumentException('This participant does not use a payment frequency', 422);
    $cycleId = placementEconomicsEnsureStandardCycle($tenantId, $purpose, $cadence);
    if (!$cycleId) throw new InvalidArgumentException('Could not resolve the requested payment frequency', 422);
    return ['cadence' => $cadence, 'cycle_id' => $cycleId, 'purpose' => $purpose];
}

function placementEconomicsCreateNativeParty(int $tenantId, int $placementId, array $body, array $user): array
{
    foreach ($body as $field => $value) {
        if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Invalid participant value for ' . $field, 422);
    }
    foreach (['role','display_name','settlement_channel'] as $field) {
        if (!isset($body[$field]) || trim((string)$body[$field]) === '') throw new InvalidArgumentException($field . ' required', 422);
    }
    return placementWithLockedRecord($tenantId, $placementId, static function (array $placement, PDO $pdo) use ($tenantId, $placementId, $body, $user): array {

        $channel = (string) $body['settlement_channel'];
        if (!in_array($channel, ['ar','ap','payroll','none'], true)) throw new InvalidArgumentException('Invalid settlement_channel', 422);
        $role = (string) $body['role'];
        if (!in_array($role, [
            'client','end_client','vendor','c2c_vendor','worker','employee',
            'msp','prime_vendor','sub_vendor','referrer','commission_recipient',
            'recruiter','account_manager','other',
        ], true)) {
            throw new InvalidArgumentException('Invalid economic party role', 422);
        }

        $companyId = !empty($body['company_id']) ? (int) $body['company_id'] : null;
        $personId = !empty($body['person_id']) ? (int) $body['person_id'] : null;
        $userId = !empty($body['user_id']) ? (int) $body['user_id'] : null;
        $displayName = trim((string) $body['display_name']);
        if ($personId) {
            $person = scopedFind(
                'SELECT id, CONCAT_WS(" ", first_name, last_name) AS name
                   FROM people WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $personId]
            );
            if (!$person) throw new InvalidArgumentException('person_id not found in this tenant', 422);
            $displayName = trim((string) $person['name']) ?: $displayName;
        }
        if ($userId) {
            $memberSt = getDB()->prepare(
                'SELECT u.id, u.name, u.email
                   FROM users u
                   JOIN ' . membershipReadSourceSql() . ' m ON m.user_id = u.id
                  WHERE m.tenant_id = :t AND u.id = :u AND u.is_active = 1
                  LIMIT 1'
            );
            $memberSt->execute(['t' => $tenantId, 'u' => $userId]);
            $member = $memberSt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$member) throw new InvalidArgumentException('user_id is not active in this tenant', 422);
            $displayName = trim((string) ($member['name'] ?: $member['email'])) ?: $displayName;
        }
        if ($channel === 'payroll' && !$userId && !$personId) {
            throw new InvalidArgumentException('Payroll participants must reference an internal user or person', 422);
        }
        if ($channel === 'payroll' && $companyId) {
            throw new InvalidArgumentException('Companies are paid through accounts payable, not payroll', 422);
        }
        if ($channel === 'ap' && $userId) {
            throw new InvalidArgumentException('Internal users are paid through payroll, not accounts payable', 422);
        }
        if ($channel === 'ar' && (!$companyId || !in_array($role, ['client','end_client'], true))) {
            throw new InvalidArgumentException('A client receivable must reference a client company', 422);
        }
        if ($channel === 'ar') {
            $receivable = getDB()->prepare(
                'SELECT id FROM placement_economic_parties
                  WHERE tenant_id = :t AND placement_id = :p AND active = 1
                    AND settlement_channel = "ar" LIMIT 1'
            );
            $receivable->execute(['t' => $tenantId, 'p' => $placementId]);
            if ($receivable->fetchColumn()) {
                throw new InvalidArgumentException('This placement already has a bill-to client. Edit its frequency and payment terms instead.', 409);
            }
        }
        if ($displayName === '') throw new InvalidArgumentException('display_name required', 422);
        if ($companyId) {
            $company = companiesGet($companyId, placementsCompanyDirectoryTenantId());
            if (!$company) throw new InvalidArgumentException('company_id not found in this tenant', 422);
            $displayName = (string) $company['name'];
            companiesAddRole($companyId, $channel === 'ar' ? 'client' : 'vendor');
        } elseif ($channel === 'ap' && !$personId) {
            $companyId = companiesUpsertByName(placementsCompanyDirectoryTenantId(), $displayName, [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['vendor']);
        }

        $terms = placementEconomicsNormaliseTerms((string) ($body['payment_terms'] ?? 'NET30'));
        if ($channel === 'ar' && placementEconomicsTermsArePwp($terms)) throw new InvalidArgumentException('Paid when paid applies to AP, not client invoices', 422);
        $pwp = $channel === 'ap' && (!empty($body['pwp_enabled']) || placementEconomicsTermsArePwp($terms));
        $frequency = null;
        if ($channel !== 'none') {
            $frequency = placementEconomicsNativeCreateCadence(
                $tenantId,
                $channel,
                $body['cadence'] ?? ($channel === 'ar' ? 'monthly' : 'biweekly')
            );
        }
        $vendor = null;
        if ($channel === 'ap') {
            $vendor = placementEconomicsEnsureVendor(
                $tenantId,
                $displayName,
                $companyId,
                $personId ? '1099_individual' : (string) ($body['vendor_type'] ?? 'w9_business'),
                $terms,
                $pwp,
                $placementId,
                currentTenantId()
            );
        }
        $feeBasis = (string) ($body['fee_basis'] ?? 'none');
        if ($channel === 'ap' && $feeBasis === 'none' && in_array($role, ['vendor','c2c_vendor','worker'], true)) {
            $placementRow = scopedFind(
                'SELECT engagement_type FROM placements WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $placementId]
            );
            $labor = getDB()->prepare(
                'SELECT 1 FROM placement_economic_parties
                  WHERE tenant_id = :t AND placement_id = :p AND active = 1
                    AND money_flow = "payable" AND fee_basis = "pay_rate" LIMIT 1'
            );
            $labor->execute(['t' => $tenantId, 'p' => $placementId]);
            $nonW2 = in_array(strtolower((string) ($placementRow['engagement_type'] ?? '')), ['1099','c2c','direct_hire'], true);
            if ($role === 'c2c_vendor' || $role === 'worker' || ($nonW2 && !$labor->fetchColumn())) {
                $feeBasis = 'pay_rate';
            }
        }
        $sourceRef = $role === 'c2c_vendor'
            ? 'corp:' . $placementId
            : 'manual:' . bin2hex(random_bytes(8));
        $partyId = placementEconomicsUpsertParty($tenantId, $placementId, [
            'source_ref' => $sourceRef,
            'source_type' => $role === 'c2c_vendor' ? 'corp' : 'manual',
            'source_id' => $role === 'c2c_vendor' ? $placementId : null,
            'role' => $role,
            'display_name' => $displayName,
            'company_id' => $companyId,
            'person_id' => $personId,
            'user_id' => $userId,
            'ap_vendor_id' => $vendor['id'] ?? null,
            'money_flow' => $channel === 'ar' ? 'receivable' : ($channel === 'none' ? 'informational' : 'payable'),
            'settlement_channel' => $channel,
            'fee_basis' => $feeBasis,
            'fee_pct' => $body['fee_pct'] ?? null,
            'fee_flat' => $body['fee_flat'] ?? null,
            'payment_terms' => in_array($channel, ['ar','ap'], true) ? $terms : null,
            'pwp_enabled' => $channel === 'ap' ? $pwp : false,
            'operating_cycle_id' => $frequency['cycle_id'] ?? null,
            'effective_from' => $body['effective_from'] ?? null,
            'effective_to' => $body['effective_to'] ?? null,
            'source_managed' => 0,
            'created_by_user_id' => $user['id'] ?? null,
        ]);
        $partyOverrides = ['operating_cycle_id' => $frequency['cycle_id'] ?? null];
        if (in_array($channel, ['ar','ap'], true)) $partyOverrides['payment_terms'] = $terms;
        if ($channel === 'ap') $partyOverrides['pwp_enabled'] = $pwp;
        placementEconomicsUpdateParty($tenantId, $partyId, $partyOverrides);
        if ($role === 'c2c_vendor' && $companyId && $vendor) {
            getDB()->prepare(
                'INSERT INTO placement_corp_details
                    (placement_id, tenant_id, company_id, ap_vendor_id, corp_legal_name,
                     payment_terms_override, pwp_enabled)
                 VALUES (:p, :t, :c, :v, :name, :terms, :pwp)
                 ON DUPLICATE KEY UPDATE
                    company_id = VALUES(company_id), ap_vendor_id = VALUES(ap_vendor_id),
                    corp_legal_name = VALUES(corp_legal_name),
                    payment_terms_override = VALUES(payment_terms_override),
                    pwp_enabled = VALUES(pwp_enabled)'
            )->execute([
                'p' => $placementId, 't' => $tenantId, 'c' => $companyId,
                'v' => (int) $vendor['id'], 'name' => $displayName,
                'terms' => $terms, 'pwp' => $pwp ? 1 : 0,
            ]);
            scopedUpdate('placements', $placementId, [
                'vendor_pay_cycle' => $frequency['cadence'] ?? 'biweekly',
                'ap_operating_cycle_id' => $frequency['cycle_id'] ?? null,
                'vendor_payment_terms_override' => $terms,
                'vendor_pwp_enabled' => $pwp ? 1 : 0,
            ]);
        }
        placementRequiredAudit('placement.economic_party.added', [
            'placement_id' => $placementId, 'economic_party_id' => $partyId,
            'role' => $role, 'company_id' => $companyId, 'ap_vendor_id' => $vendor['id'] ?? null,
        ], $placementId);
        return ['id' => $partyId, 'context' => placementEconomicsContext($tenantId, $placementId, false)];
    });
}

function placementEconomicsRemoveNativeParty(int $tenantId, int $partyId): array
{
    $pdo = getDB();
    $find = $pdo->prepare('SELECT placement_id FROM placement_economic_parties WHERE tenant_id=? AND id=?');
    $find->execute([$tenantId, $partyId]);
    $placementId = (int)$find->fetchColumn();
    if (!$placementId) throw new RuntimeException('Economic party not found', 404);
    return placementWithLockedRecord($tenantId, $placementId, static function (array $placement, PDO $pdo) use ($tenantId, $placementId, $partyId): array {
        $read = $pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND id=? FOR UPDATE');
        $read->execute([$tenantId, $placementId, $partyId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Economic party not found', 404);
        if (!empty($row['source_managed'])) throw new RuntimeException('Remove the source chain/referral/commission record instead of deleting its economic projection.', 409);
        if (!(int)$row['active']) return ['ok'=>true,'changed'=>false];
        if ((string)$row['source_type'] === 'corp') {
            $pdo->prepare('DELETE FROM placement_corp_details WHERE tenant_id = :t AND placement_id = :p')->execute(['t'=>$tenantId,'p'=>$placementId]);
        }
        placementEconomicsUpdateParty($tenantId, $partyId, ['active'=>0]);
        placementRequiredAudit('placement.economic_party.removed', [
            'placement_id'=>$placementId,'economic_party_id'=>$partyId,'before'=>$row,
        ], $placementId);
        return ['ok'=>true,'changed'=>true];
    });
}
