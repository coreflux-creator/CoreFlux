-- One provider payment can support only one complete Core AP payment receipt.
-- Do not include the credential fingerprint: rotating a key does not create
-- another payment. Company rebinding remains a separate reviewed operation.
-- Existing conflicting rows intentionally stop this migration; never choose
-- an owner, delete evidence, or release either reservation automatically.
SET @pp_receipt_owner_exists := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='purepay_payment_links' AND index_name='uq_pppl_payment_owner');
SET @pp_receipt_owner_sql := IF(@pp_receipt_owner_exists=0, 'ALTER TABLE purepay_payment_links MODIFY purepay_payment_id VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, ADD UNIQUE KEY uq_pppl_payment_owner (tenant_id,purepay_payment_id)', 'DO 0');
PREPARE pp_receipt_owner_stmt FROM @pp_receipt_owner_sql;
EXECUTE pp_receipt_owner_stmt;
DEALLOCATE PREPARE pp_receipt_owner_stmt;
