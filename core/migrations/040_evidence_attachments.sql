-- Phase 1e — Evidence Attachments canonical pivot (Live Books Rails, 2026-02-14).
--
-- Replaces ad-hoc per-module attachment tables (bill_documents,
-- ap_attachments, billing_attachments, etc.) with ONE polymorphic pivot.
-- Every business document — bill image, signed contract PDF, bank
-- statement OCR, employee tax form, vendor W-9 — lives here.
--
-- The (subject_type, subject_id) tuple lets attachments hang off ANY
-- canonical object: accounting_event, ap_bill, billing_invoice,
-- journal_entry, person, placement, anything.
--
-- The architecture doc Section "Evidence Objects" (5.4) is realised here.
--
-- Idempotent.

DO 0;

CREATE TABLE IF NOT EXISTS evidence_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    -- Polymorphic subject — what this evidence is attached to.
    subject_type VARCHAR(60) NOT NULL,   -- 'accounting_event' | 'ap_bill' | 'billing_invoice' | 'journal_entry' | 'person' | 'placement' | etc.
    subject_id   BIGINT UNSIGNED NOT NULL,

    -- The document itself.
    document_type VARCHAR(60) NOT NULL,  -- 'bill_image' | 'signed_contract' | 'bank_statement' | 'w9' | 'ach_authorization' | 'receipt' | 'email_screenshot' | 'ocr_result' | 'ai_reasoning' | ...
    label         VARCHAR(255) NULL,     -- human-readable name ('Vendor invoice — Oct 2026.pdf')

    -- Where the file actually lives. CoreFlux uses S3-compatible object
    -- storage for the bytes; this column stores the bucket key. NULL when
    -- the evidence is structured metadata (e.g. an OCR JSON dump or AI
    -- reasoning blob stored inline in `payload`).
    storage_key   VARCHAR(512) NULL,
    storage_bucket VARCHAR(120) NULL,
    content_type  VARCHAR(120) NULL,     -- 'application/pdf' / 'image/jpeg' / 'application/json'
    size_bytes    BIGINT UNSIGNED NULL,
    sha256_hash   CHAR(64) NULL,         -- content addressable + dedupe key

    -- Structured payload for non-file evidence (OCR results, AI reasoning,
    -- email body snapshots, parsed bank statement rows).
    payload       JSON NULL,

    -- Source & lifecycle.
    source        VARCHAR(60) NULL,      -- 'manual_upload' | 'email_inbound' | 'plaid_sync' | 'jobdiva_sync' | 'ai_generated' | 'ocr_result' | 'system'
    attached_by_user_id BIGINT UNSIGNED NULL,
    attached_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_by_id BIGINT UNSIGNED NULL,   -- forward-link when a new version replaces this one
    deleted_at    DATETIME NULL,             -- soft-delete (audit-preserving)

    INDEX idx_evidence_subject (tenant_id, subject_type, subject_id, deleted_at),
    INDEX idx_evidence_hash    (tenant_id, sha256_hash),
    INDEX idx_evidence_type    (tenant_id, document_type),
    INDEX idx_evidence_source  (tenant_id, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
