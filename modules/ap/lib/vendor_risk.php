<?php
/**
 * C3 — Vendor risk evaluator (Sprint 3 / Industry Layer 1).
 *
 * Composable rules — each rule contributes a score + a factor string.
 * Score → level:
 *   0–9   → none
 *   10–24 → low
 *   25–49 → medium
 *   50+   → high  (auto-flags requires_manual_review = 1)
 *
 * Rules (initial set):
 *   • new_vendor          : vendor created in the last 14 days  (+15)
 *   • bank_account_change : banking changed in the last 7 days (+25)
 *   • missing_w9          : 1099-eligible vendor lacks W-9 doc (+20)
 *   • missing_coi         : COI missing or expired              (+10)
 *   • high_volume         : > $50k billed in last 30 days       (+10)
 *   • sanctions_match     : OFAC / sanctions hit                (+50)  (stub)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

const AP_VENDOR_RISK_THRESHOLD_NONE   = 0;
const AP_VENDOR_RISK_THRESHOLD_LOW    = 10;
const AP_VENDOR_RISK_THRESHOLD_MEDIUM = 25;
const AP_VENDOR_RISK_THRESHOLD_HIGH   = 50;

/** Default record when no vendor lookup is possible (manual / unknown vendor). */
function apVendorRiskDefault(): array {
    return ['level' => 'none', 'score' => 0, 'factors' => [], 'requires_manual_review' => false];
}

/**
 * Read-through cache: returns the persisted ap_vendor_risk row, evaluating
 * fresh if the row is stale (> 1 hour old) or missing.
 *
 * @return array{level:string, score:int, factors:list<string>, requires_manual_review:bool}
 */
function apVendorRiskFor(int $tenantId, int $vendorId): array {
    $pdo = getDB();
    if (!$pdo) return apVendorRiskDefault();

    $stmt = $pdo->prepare(
        "SELECT risk_level, risk_score, factors_json, requires_manual_review, last_evaluated_at
           FROM ap_vendor_risk
          WHERE tenant_id = :t AND vendor_id = :v LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'v' => $vendorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $stale = !$row || empty($row['last_evaluated_at'])
        || strtotime((string) $row['last_evaluated_at']) < (time() - 3600);
    if ($stale) {
        return apVendorRiskRecompute($tenantId, $vendorId);
    }
    return [
        'level'                  => (string) $row['risk_level'],
        'score'                  => (int) $row['risk_score'],
        'factors'                => (array) (json_decode((string) ($row['factors_json'] ?? '[]'), true) ?: []),
        'requires_manual_review' => (bool) $row['requires_manual_review'],
    ];
}

/**
 * Evaluate every rule, persist the result. Idempotent.
 */
function apVendorRiskRecompute(int $tenantId, int $vendorId): array {
    $pdo = getDB();
    if (!$pdo) return apVendorRiskDefault();

    $factors = [];
    $score = 0;

    // Read vendor metadata. The schema may vary across deployments — read defensively.
    $vendor = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM ap_vendors WHERE tenant_id = :t AND id = :v LIMIT 1");
        $stmt->execute(['t' => $tenantId, 'v' => $vendorId]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { $vendor = null; }
    if (!$vendor) return apVendorRiskDefault();

    // Rule: new vendor (created < 14 days ago).
    if (!empty($vendor['created_at']) && strtotime((string) $vendor['created_at']) > (time() - 14 * 86400)) {
        $factors[] = 'new_vendor';
        $score += 15;
    }

    // Rule: missing W-9 (only for 1099-eligible vendors).
    if (!empty($vendor['is_1099_eligible']) && empty($vendor['w9_on_file'])) {
        $factors[] = 'missing_w9';
        $score += 20;
    }

    // Rule: missing or expired COI.
    if (empty($vendor['coi_on_file']) || (!empty($vendor['coi_expiry']) && strtotime((string) $vendor['coi_expiry']) < time())) {
        $factors[] = 'missing_coi';
        $score += 10;
    }

    // Rule: bank-account change in last 7 days (look in vendor portal docs / banking history).
    try {
        $bankStmt = $pdo->prepare(
            "SELECT updated_at FROM ap_vendor_banking
              WHERE tenant_id = :t AND vendor_id = :v
              ORDER BY updated_at DESC LIMIT 1"
        );
        $bankStmt->execute(['t' => $tenantId, 'v' => $vendorId]);
        $bankRow = $bankStmt->fetch(PDO::FETCH_ASSOC);
        if ($bankRow && !empty($bankRow['updated_at']) && strtotime((string) $bankRow['updated_at']) > (time() - 7 * 86400)) {
            $factors[] = 'bank_account_change';
            $score += 25;
        }
    } catch (\Throwable $_) { /* table may not exist yet */ }

    // Rule: high volume (last 30 days > $50k).
    try {
        $vol = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS s
               FROM ap_bills
              WHERE tenant_id = :t AND vendor_id = :v
                AND bill_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        $vol->execute(['t' => $tenantId, 'v' => $vendorId]);
        if ((float) $vol->fetchColumn() > 50000.0) {
            $factors[] = 'high_volume';
            $score += 10;
        }
    } catch (\Throwable $_) { /* table may not exist yet */ }

    // Rule: sanctions / OFAC match — STUB (real wiring in later sprint).
    // Hook: a future cron / API integration writes ap_vendor_sanction_hits.

    $level = 'none';
    if      ($score >= AP_VENDOR_RISK_THRESHOLD_HIGH)   $level = 'high';
    elseif  ($score >= AP_VENDOR_RISK_THRESHOLD_MEDIUM) $level = 'medium';
    elseif  ($score >= AP_VENDOR_RISK_THRESHOLD_LOW)    $level = 'low';

    $requiresManualReview = $level === 'high';

    // Persist.
    $upsert = $pdo->prepare(
        "INSERT INTO ap_vendor_risk
           (tenant_id, vendor_id, risk_level, risk_score, factors_json, requires_manual_review, last_evaluated_at)
         VALUES (:t, :v, :lv, :sc, :fj, :rm, NOW())
         ON DUPLICATE KEY UPDATE
           risk_level = VALUES(risk_level),
           risk_score = VALUES(risk_score),
           factors_json = VALUES(factors_json),
           requires_manual_review = VALUES(requires_manual_review),
           last_evaluated_at = NOW()"
    );
    $upsert->execute([
        't'  => $tenantId,
        'v'  => $vendorId,
        'lv' => $level,
        'sc' => $score,
        'fj' => json_encode($factors, JSON_UNESCAPED_SLASHES),
        'rm' => $requiresManualReview ? 1 : 0,
    ]);

    return [
        'level'                  => $level,
        'score'                  => $score,
        'factors'                => $factors,
        'requires_manual_review' => $requiresManualReview,
    ];
}
