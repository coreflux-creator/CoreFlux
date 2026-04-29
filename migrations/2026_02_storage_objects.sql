-- Core StorageService schema
-- See /app/core/StorageService.SPEC.md §4.2.
-- Run order: after core base schema, before any module migration that references storage_objects.

CREATE TABLE IF NOT EXISTS `storage_objects` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`           BIGINT UNSIGNED NOT NULL,
  `module`              VARCHAR(40)     NOT NULL,
  `entity_type`         VARCHAR(40)     NOT NULL,
  `entity_id`           VARCHAR(64)     NOT NULL,
  `s3_key`              VARCHAR(1024)   NOT NULL,
  `s3_version_id`       VARCHAR(80)         NULL,
  `filename`            VARCHAR(255)    NOT NULL,
  `mime`                VARCHAR(120)        NULL,
  `size_bytes`          BIGINT UNSIGNED     NULL,
  `etag`                VARCHAR(80)         NULL,
  `lock_until`          DATE                NULL,
  `legal_hold`          TINYINT(1)      NOT NULL DEFAULT 0,
  `created_by_user_id`  BIGINT UNSIGNED     NULL,
  `created_at`          DATETIME        NOT NULL,
  `soft_deleted_at`     DATETIME            NULL,
  `tags_json`           TEXT                NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_storage_s3_key` (`s3_key`(255)),
  KEY `ix_storage_tenant_entity` (`tenant_id`, `module`, `entity_type`, `entity_id`(64)),
  KEY `ix_storage_tenant_created` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `storage_signed_url_audit` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`           BIGINT UNSIGNED NOT NULL,
  `storage_object_id`   BIGINT UNSIGNED NOT NULL,
  `actor_user_id`       BIGINT UNSIGNED     NULL,
  `purpose`             VARCHAR(120)        NULL,
  `ttl_seconds`         INT             NOT NULL,
  `cross_tenant`        TINYINT(1)      NOT NULL DEFAULT 0,
  `issued_at`           DATETIME        NOT NULL,
  `ip`                  VARCHAR(45)         NULL,
  PRIMARY KEY (`id`),
  KEY `ix_url_audit_tenant_time` (`tenant_id`, `issued_at`),
  KEY `ix_url_audit_object` (`storage_object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
