-- The minimal simulation/bootstrap tenant table predates fields used by
-- getUserTenants() and tenant routing. Existing installations already have
-- these columns; only clean installs need the additive repair.

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN logo_url VARCHAR(500) NULL',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tenants'
      AND column_name = 'logo_url'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The legacy module catalog is a platform table, not an accounting module
-- table. A fresh simulation database needs it before login can resolve the
-- active workspace, even when no subscriptions have been configured yet.
CREATE TABLE IF NOT EXISTS modules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    link VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_modules_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_modules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_admin_modules_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN subdomain VARCHAR(255) NOT NULL DEFAULT ""',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tenants'
      AND column_name = 'subdomain'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE tenants ADD COLUMN slug VARCHAR(190) NULL',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tenants'
      AND column_name = 'slug'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
