-- AP Module — Pay-When-Paid (PWP) terms.
-- Many staffing AP bills (1099 / C2C contractor pay) carry "paid when paid"
-- terms: the agency only releases the contractor's pay once the matching
-- AR invoice has been collected from the client. We track this explicitly
-- so the billing module's cash-application path can auto-release the
-- linked AP bill.
--
-- Idempotent. utf8mb4_unicode_ci. MySQL 5.7+ compatible.

-- 1. payment_terms override on ap_bills.
--    NULL  → fall back to vendor's default_terms (which falls back to tenant ap_default_terms).
--    Values: 'NET15','NET30','NET45','NET60','PWP' (immediately due once AR clears),
--            'PWP_NET<N>' (e.g. 'PWP_NET10' = 10 days after AR clears).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND COLUMN_NAME='payment_terms');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_bills ADD COLUMN payment_terms VARCHAR(40) NULL AFTER due_date',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. The AR invoice this bill is gated on. NULL until the AR side is
--    issued + linked. Set during AR invoice creation (when both came from
--    the same time bundle) or manually via /api/ap/pwp.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND COLUMN_NAME='linked_ar_invoice_id');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_bills ADD COLUMN linked_ar_invoice_id BIGINT UNSIGNED NULL AFTER payment_terms',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. PWP lifecycle status:
--    not_pwp           — vendor is on standard fixed terms (default)
--    awaiting_ar       — vendor is on PWP terms and the AR invoice has not yet cleared
--    triggered         — AR invoice fully paid; AP bill released for payment
--    partial_triggered — AR invoice partially paid; reserved for future use
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND COLUMN_NAME='pwp_status');
SET @sql := IF(@col=0,
  "ALTER TABLE ap_bills ADD COLUMN pwp_status ENUM('not_pwp','awaiting_ar','triggered','partial_triggered') NOT NULL DEFAULT 'not_pwp' AFTER linked_ar_invoice_id",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. When the AR side cleared and we released this bill.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND COLUMN_NAME='pwp_released_at');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_bills ADD COLUMN pwp_released_at DATETIME NULL AFTER pwp_status',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Helpful indexes for the trigger path:
--    "Find PWP bills gated on this AR invoice that are still awaiting"
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills' AND INDEX_NAME='idx_apb_pwp_linked');
SET @sql := IF(@idx=0,
  'ALTER TABLE ap_bills ADD INDEX idx_apb_pwp_linked (linked_ar_invoice_id, pwp_status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Vendor-level default PWP toggle. When set, new bills for this vendor
--    default to 'PWP' terms unless an explicit override is provided.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_vendors_index' AND COLUMN_NAME='default_pwp');
SET @sql := IF(@col=0,
  'ALTER TABLE ap_vendors_index ADD COLUMN default_pwp TINYINT(1) NOT NULL DEFAULT 0 AFTER default_terms',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
