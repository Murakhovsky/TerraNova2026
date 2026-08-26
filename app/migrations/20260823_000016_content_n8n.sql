CREATE TABLE IF NOT EXISTS tn_content_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    external_id VARCHAR(120) NULL,
    content_type ENUM('blog_post', 'seo_landing') NOT NULL DEFAULT 'blog_post',
    source ENUM('manual', 'n8n', 'aida') NOT NULL DEFAULT 'manual',
    status ENUM('draft', 'review', 'published', 'archived') NOT NULL DEFAULT 'draft',
    title VARCHAR(240) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    category VARCHAR(120) NULL,
    excerpt TEXT NULL,
    body_html LONGTEXT NOT NULL,
    featured_image_url VARCHAR(700) NULL,
    featured_image_alt VARCHAR(240) NULL,
    meta_title VARCHAR(240) NULL,
    meta_description VARCHAR(500) NULL,
    focus_keyword VARCHAR(190) NULL,
    canonical_url VARCHAR(700) NULL,
    og_image_url VARCHAR(700) NULL,
    robots VARCHAR(60) NOT NULL DEFAULT 'index,follow',
    schema_json JSON NULL,
    tags_json JSON NULL,
    author_user_id BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_content_items_external (external_id),
    UNIQUE KEY uq_tn_content_items_type_slug (content_type, slug),
    KEY idx_tn_content_items_public (content_type, status, published_at),
    KEY idx_tn_content_items_schedule (status, scheduled_at),
    KEY idx_tn_content_items_author (author_user_id),
    CONSTRAINT fk_tn_content_items_author FOREIGN KEY (author_user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_content_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_item_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    source ENUM('manual', 'n8n', 'aida', 'system') NOT NULL DEFAULT 'manual',
    snapshot_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_content_revisions_item (content_item_id, created_at),
    CONSTRAINT fk_tn_content_revisions_item FOREIGN KEY (content_item_id) REFERENCES tn_content_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_content_revisions_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration VARCHAR(60) NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    status ENUM('received', 'processed', 'rejected', 'failed') NOT NULL DEFAULT 'received',
    payload JSON NULL,
    response_payload JSON NULL,
    error_message TEXT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_webhook_deliveries_key (integration, direction, idempotency_key),
    KEY idx_tn_webhook_deliveries_status (integration, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_integration_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration VARCHAR(60) NOT NULL DEFAULT 'n8n',
    event_type VARCHAR(100) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id BIGINT UNSIGNED NULL,
    payload JSON NOT NULL,
    dedupe_key VARCHAR(190) NULL,
    status ENUM('pending', 'processing', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    lock_token CHAR(32) NULL,
    sent_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_integration_outbox_dedupe (dedupe_key),
    KEY idx_tn_integration_outbox_delivery (integration, status, available_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260823_000016_content_n8n');
