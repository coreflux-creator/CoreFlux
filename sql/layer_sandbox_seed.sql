-- =============================================================================
-- LayerFi sandbox demo seed — two tenants + three users for the evaluation.
-- Idempotent: safe to re-run. Tables guarded with IF NOT EXISTS so this works
-- even before the full migration suite has run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tenants` (
    `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`                   VARCHAR(190) NOT NULL,
    `status`                 VARCHAR(32)  NOT NULL DEFAULT 'active',
    `accounting_je_prefix`   VARCHAR(16)  NOT NULL DEFAULT 'JE',
    `accounting_next_je_seq` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tenants` (`id`, `name`) VALUES
    (1, 'Acme Corp (Sandbox)'),
    (2, 'Beta Industries (Sandbox)')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(150) NOT NULL,
    `email`         VARCHAR(255) NOT NULL,
    `password`      VARCHAR(255) NULL,
    `password_hash` VARCHAR(255) NULL,
    `role`          VARCHAR(40)  NOT NULL DEFAULT 'employee',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `avatar`        VARCHAR(255) NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `role`, `is_active`) VALUES
    (1, 'Demo Admin',  'admin@coreflux.demo',  'master_admin', 1),
    (2, 'Tenant Admin', 'tadmin@coreflux.demo', 'tenant_admin', 1),
    (3, 'View Only',   'viewer@coreflux.demo', 'employee',     1)
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`), `name` = VALUES(`name`);

CREATE TABLE IF NOT EXISTS `user_tenants` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `tenant_id`      INT UNSIGNED NOT NULL,
    `role`           VARCHAR(40)  NOT NULL DEFAULT 'employee',
    `is_default`     TINYINT(1)   NOT NULL DEFAULT 0,
    `status`         ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    `last_active_at` DATETIME NULL DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_tenants_pair` (`user_id`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user_tenants` (`user_id`, `tenant_id`, `role`, `is_default`, `status`) VALUES
    (1, 1, 'master_admin', 1, 'active'),
    (1, 2, 'master_admin', 0, 'active'),
    (2, 1, 'tenant_admin', 1, 'active'),
    (2, 2, 'tenant_admin', 0, 'active'),
    (3, 1, 'employee',     1, 'active'),
    (3, 2, 'employee',     0, 'active')
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);
