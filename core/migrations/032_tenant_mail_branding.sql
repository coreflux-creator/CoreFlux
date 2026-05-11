-- Tenant email branding — logo URL, accent colour, signature footer.
--
-- Pure presentation layer over the existing tenant_mail_settings. We keep
-- this separate so the SMTP / Resend credentials remain a distinct security
-- boundary from the cosmetic branding.

CREATE TABLE IF NOT EXISTS tenant_mail_branding (
    tenant_id          BIGINT       NOT NULL,
    logo_url           VARCHAR(500) NULL  COMMENT 'Absolute https:// URL hosted by the tenant. We never proxy.',
    accent_color       CHAR(7)      NULL  COMMENT 'CSS #rrggbb. Used for headings + buttons in templated emails.',
    signature_html     VARCHAR(800) NULL  COMMENT 'Optional HTML signature appended after every digest body.',
    show_powered_by    TINYINT(1)   NOT NULL DEFAULT 1
                              COMMENT 'When 0, suppresses the "Powered by CoreFlux" footer line.',
    updated_by_user_id BIGINT       NULL,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
