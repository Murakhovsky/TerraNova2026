CREATE TABLE IF NOT EXISTS cos_notification_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    delivery_id VARCHAR(80) NOT NULL,
    notification_id VARCHAR(80) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    recipient VARCHAR(500) NOT NULL,
    status VARCHAR(24) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    provider_message_id VARCHAR(191) NULL,
    error_message TEXT NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    delivered_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_notification_delivery (organization_id,delivery_id),
    KEY ix_cos_notification_notification (organization_id,notification_id,created_at),
    KEY ix_cos_notification_status (organization_id,status,updated_at),
    KEY ix_cos_notification_provider (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000088_platform_notification_runtime');
