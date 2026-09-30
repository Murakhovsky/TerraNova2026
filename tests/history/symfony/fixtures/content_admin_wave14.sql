ALTER TABLE tn_content_items
    ADD COLUMN external_id VARCHAR(120) NULL AFTER id,
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER content_type,
    ADD COLUMN focus_keyword VARCHAR(190) NULL AFTER meta_description,
    ADD COLUMN scheduled_at DATETIME NULL AFTER published_at,
    ADD COLUMN synced_at DATETIME NULL AFTER scheduled_at,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER synced_at;

CREATE TABLE IF NOT EXISTS tn_content_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    content_item_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    snapshot_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tn_content_revisions_item (content_item_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration VARCHAR(60) NOT NULL,
    direction VARCHAR(20) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'received',
    payload JSON NULL,
    response_payload JSON NULL,
    error_message TEXT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tn_webhook_deliveries_key (integration, direction, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_integration_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration VARCHAR(60) NOT NULL DEFAULT 'n8n',
    event_type VARCHAR(100) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id BIGINT UNSIGNED NULL,
    payload JSON NOT NULL,
    dedupe_key VARCHAR(190) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    lock_token CHAR(32) NULL,
    sent_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tn_integration_outbox_dedupe (dedupe_key),
    KEY idx_tn_integration_outbox_delivery (integration, status, available_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tn_webhook_deliveries (
    integration,direction,event_type,idempotency_key,request_hash,status,payload,processed_at
) VALUES (
    'n8n','inbound','content.fixture','wave14-content-fixture',
    REPEAT('a',64),'processed','{}',NOW()
)
ON DUPLICATE KEY UPDATE status=VALUES(status), processed_at=VALUES(processed_at);
