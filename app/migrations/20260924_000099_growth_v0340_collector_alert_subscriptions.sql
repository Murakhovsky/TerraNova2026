CREATE TABLE IF NOT EXISTS tn_growth_collector_alert_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    subscription_id VARCHAR(80) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_name VARCHAR(191) NULL,
    locale VARCHAR(12) NOT NULL DEFAULT 'en',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_collector_alert_subscription (organization_id,subscription_id),
    UNIQUE KEY uq_growth_collector_alert_email (organization_id,recipient_email),
    KEY ix_growth_collector_alert_enabled (organization_id,enabled,recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.34.0',
    schema_version='0.31.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.33.0'
  AND schema_version='0.30.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000099_growth_v0340_collector_alert_subscriptions');
